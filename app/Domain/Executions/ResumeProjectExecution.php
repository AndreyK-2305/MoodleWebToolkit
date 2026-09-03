<?php

namespace App\Domain\Executions;

use App\Domain\Executions\DTOs\StartExecutionResult;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Domain\Tools\FakeToolAdapter;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ExecutionAlreadyActive;
use App\Exceptions\IdempotencyKeyConflict;
use App\Models\AuditLog;
use App\Models\Checkpoint;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResumeProjectExecution
{
    public function __construct(
        private readonly ToolAdapter $adapter,
        private readonly ExecutionCommandDispatcher $dispatcher,
    ) {}

    public function resume(
        Execution $previous,
        Checkpoint $checkpoint,
        User $actor,
        string $idempotencyKey,
    ): StartExecutionResult {
        $scope = "execution:{$previous->getKey()}:resume";
        $payload = [
            'operation' => 'RESUME',
            'previous_execution_uuid' => $previous->uuid,
            'checkpoint_id' => $checkpoint->getKey(),
        ];
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($previous, $checkpoint, $actor, $idempotencyKey, $scope, $payload, $payloadHash): StartExecutionResult {
            $seed = Execution::query()->select(['id', 'project_id'])->findOrFail((int) $previous->getKey());
            $project = Project::query()->lockForUpdate()->findOrFail($seed->project_id);
            $lockedPrevious = Execution::query()->lockForUpdate()->findOrFail((int) $seed->getKey());
            $lockedPrevious->setRelation('project', $project);
            $this->authorizeLocked($lockedPrevious, $actor);

            $existing = ExecutionCommand::query()
                ->where('idempotency_scope', $scope)
                ->where('idempotency_key', $idempotencyKey)
                ->with('execution')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new IdempotencyKeyConflict;
                }

                return new StartExecutionResult($existing->execution, false);
            }

            if ($lockedPrevious->status !== ExecutionStatus::FAILED || $project->status !== ProjectStatus::FAILED) {
                throw ValidationException::withMessages(['execution' => 'Sólo una ejecución fallida vigente puede reanudarse.']);
            }

            if ($project->executions()->whereIn('status', collect(ExecutionStatus::cases())
                ->filter(fn (ExecutionStatus $status): bool => $status->isActive())
                ->map(fn (ExecutionStatus $status): string => $status->value))->exists()) {
                throw new ExecutionAlreadyActive;
            }

            $lockedCheckpoint = Checkpoint::query()
                ->where('execution_id', $lockedPrevious->getKey())
                ->lockForUpdate()
                ->find((int) $checkpoint->getKey());

            if ($lockedCheckpoint === null
                || ! $this->adapter->capabilities()['resume']
                || ! $lockedCheckpoint->validated
                || ! $this->adapterValidates($lockedCheckpoint)
            ) {
                throw ValidationException::withMessages(['checkpoint' => 'El adaptador no validó un checkpoint reanudable para esta ejecución.']);
            }

            $attempt = ((int) $project->executions()->max('attempt')) + 1;
            $resumed = $project->executions()->create([
                'attempt' => $attempt,
                'status' => ExecutionStatus::QUEUED,
                'progress' => $this->reusedProgress($lockedPrevious, $lockedCheckpoint),
                'created_by' => $actor->getKey(),
                'resumed_from_execution_id' => $lockedPrevious->getKey(),
                'resume_checkpoint_id' => $lockedCheckpoint->getKey(),
            ]);
            $this->copyValidatedPlan($lockedPrevious, $resumed, $lockedCheckpoint);
            $command = $resumed->commands()->create([
                'step_key' => $lockedCheckpoint->step_key,
                'attempt' => 1,
                'command_type' => ExecutionCommandType::RESUME,
                'idempotency_key' => $idempotencyKey,
                'idempotency_scope' => $scope,
                'payload_hash' => $payloadHash,
                'payload' => [...$payload, 'workspace_key' => $resumed->workspace_key, 'adapter' => $this->adapter->key()],
                'created_by' => $actor->getKey(),
            ]);
            $project->transitionTo(ProjectStatus::QUEUED);
            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'project_id' => $project->getKey(),
                'execution_id' => $resumed->getKey(),
                'action' => 'EXECUTION_RESUMED_FROM_CHECKPOINT',
                'auditable_type' => $resumed->getMorphClass(),
                'auditable_id' => $resumed->getKey(),
                'payload' => [
                    'previous_execution_uuid' => $lockedPrevious->uuid,
                    'checkpoint_id' => $lockedCheckpoint->getKey(),
                    'command_id' => $command->getKey(),
                    'workspace_key' => $resumed->workspace_key,
                ],
            ]);

            return new StartExecutionResult($resumed, true);
        }, attempts: 3);

        $command = $result->execution->commands()
            ->where('idempotency_scope', $scope)
            ->where('idempotency_key', $idempotencyKey)
            ->firstOrFail();

        if ($command->processed_at === null && $command->dispatched_at === null) {
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(fn () => $this->dispatcher->dispatch($command));
            } else {
                $this->dispatcher->dispatch($command);
            }
        }

        return $result;
    }

    private function adapterValidates(Checkpoint $checkpoint): bool
    {
        return $this->adapter instanceof FakeToolAdapter && $this->adapter->validatesCheckpoint($checkpoint);
    }

    private function reusedProgress(Execution $previous, Checkpoint $checkpoint): ?int
    {
        $reusable = $this->reusableStepKeys($checkpoint);

        return $previous->steps()
            ->whereIn('step_key', $reusable)
            ->where('status', ExecutionStepStatus::SUCCESS)
            ->max('progress');
    }

    private function copyValidatedPlan(Execution $previous, Execution $resumed, Checkpoint $checkpoint): void
    {
        $reusable = $this->reusableStepKeys($checkpoint);

        foreach ($previous->steps()->orderBy('position')->get() as $source) {
            $reuse = in_array($source->step_key, $reusable, true)
                && $source->status === ExecutionStepStatus::SUCCESS;
            $resumed->steps()->create([
                'step_key' => $source->step_key,
                'attempt' => 1,
                'name' => $source->name,
                'position' => $source->position,
                'status' => $reuse ? ExecutionStepStatus::REUSED : ExecutionStepStatus::PENDING,
                'progress' => $reuse ? $source->progress : null,
                'metadata' => $reuse
                    ? [
                        'adapter' => $this->adapter->key(),
                        'reused_from_execution_uuid' => $previous->uuid,
                        'checkpoint_id' => $checkpoint->getKey(),
                    ]
                    : ['adapter' => $this->adapter->key()],
                'started_at' => $reuse ? now() : null,
                'finished_at' => $reuse ? now() : null,
            ]);
        }
    }

    /** @return list<string> */
    private function reusableStepKeys(Checkpoint $checkpoint): array
    {
        $metadata = $checkpoint->metadata ?? [];
        $keys = is_array($metadata['reusable_step_keys'] ?? null) ? $metadata['reusable_step_keys'] : [];

        return array_values(array_filter($keys, 'is_string'));
    }

    private function authorizeLocked(Execution $execution, User $actor): void
    {
        if (! $actor->can('control', $execution)) {
            throw new AuthorizationException;
        }

        if (! $actor->isAdmin() && ! ProjectAssignment::query()
            ->where('project_id', $execution->project_id)
            ->where('user_id', $actor->getKey())
            ->lockForUpdate()
            ->exists()) {
            throw new AuthorizationException;
        }
    }
}
