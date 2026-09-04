<?php

namespace App\Domain\Executions;

use App\Domain\Academic\AcademicPreview;
use App\Domain\Executions\DTOs\StartExecutionResult;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Exceptions\IdempotencyKeyConflict;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestExecutionValidation
{
    public function __construct(
        private readonly AcademicPreview $preview,
        private readonly ExecutionLifecycle $lifecycle,
        private readonly ExecutionEventRecorder $events,
        private readonly ExecutionCommandDispatcher $dispatcher,
    ) {}

    public function queueInitial(Execution $execution): ExecutionCommand
    {
        $snapshot = $this->preview->ensureSnapshot($execution);
        $execution->proposal_version = 0;
        $execution->review_fingerprint = $snapshot->fingerprint;
        $execution->validated_proposal_version = null;
        $execution->validated_fingerprint = null;
        $execution->save();

        $payload = $this->payload($execution, 0, $snapshot->fingerprint);
        $command = $execution->commands()->firstOrCreate(
            ['idempotency_scope' => "execution:{$execution->getKey()}:validate:0"],
            [
                'step_key' => 'verification',
                'attempt' => 1,
                'command_type' => ExecutionCommandType::VALIDATE,
                'idempotency_key' => "auto:{$execution->uuid}:verification:0",
                'payload_hash' => $this->payloadHash($payload),
                'payload' => $payload,
                'created_by' => $execution->created_by,
            ],
        );

        $verifying = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::VERIFYING);
        $this->events->recordNormalized($verifying, new NormalizedToolEvent(
            'verification.queued',
            stepKey: 'verification',
            progress: null,
            message: 'El procesamiento terminó y la verificación inicial fue persistida para ejecutarse en cola.',
            payload: ['proposal_version' => 0, 'fingerprint' => $snapshot->fingerprint],
        ));
        $this->dispatchAfterCommit($command);

        return $command;
    }

    public function request(Execution $execution, User $actor, string $idempotencyKey): StartExecutionResult
    {
        $result = DB::transaction(function () use ($execution, $actor, $idempotencyKey): StartExecutionResult {
            $seed = Execution::query()->select(['id', 'project_id'])->findOrFail((int) $execution->getKey());
            $project = Project::query()->lockForUpdate()->findOrFail($seed->project_id);
            $locked = Execution::query()->lockForUpdate()->findOrFail((int) $seed->getKey());
            $locked->setRelation('project', $project);
            $this->authorize($locked, $actor);

            if ($locked->review_fingerprint === null) {
                throw ValidationException::withMessages(['execution' => 'La ejecución no tiene una previsualización académica persistida.']);
            }

            $payload = $this->payload($locked, $locked->proposal_version, $locked->review_fingerprint);
            $payloadHash = $this->payloadHash($payload);
            $existingKey = ExecutionCommand::query()
                ->where('execution_id', $locked->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingKey !== null) {
                if ($existingKey->command_type !== ExecutionCommandType::VALIDATE
                    || ! hash_equals((string) $existingKey->payload_hash, $payloadHash)
                ) {
                    throw new IdempotencyKeyConflict;
                }

                return new StartExecutionResult($locked, false);
            }

            $existingVersion = $locked->commands()
                ->where('command_type', ExecutionCommandType::VALIDATE)
                ->where('attempt', $locked->proposal_version + 1)
                ->lockForUpdate()
                ->first();

            if ($existingVersion !== null) {
                return new StartExecutionResult($locked, false);
            }

            if ($locked->status !== ExecutionStatus::REVIEW) {
                throw ValidationException::withMessages([
                    'execution' => $locked->status === ExecutionStatus::VERIFYING
                        ? 'Ya existe una validación activa para esta ejecución.'
                        : 'La validación sólo puede solicitarse desde REVIEW.',
                ]);
            }

            $command = $locked->commands()->create([
                'step_key' => 'verification',
                'attempt' => $locked->proposal_version + 1,
                'command_type' => ExecutionCommandType::VALIDATE,
                'idempotency_key' => $idempotencyKey,
                'idempotency_scope' => "execution:{$locked->getKey()}:validate:{$locked->proposal_version}",
                'payload_hash' => $payloadHash,
                'payload' => $payload,
                'created_by' => $actor->getKey(),
            ]);
            $step = $locked->steps()->where('step_key', 'verification')->lockForUpdate()->firstOrFail();
            $step->status = ExecutionStepStatus::PENDING;
            $step->progress = null;
            $step->started_at = null;
            $step->finished_at = null;
            $step->save();
            $verifying = $this->lifecycle->transitionForWorker($locked, ExecutionStatus::VERIFYING);

            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'project_id' => $project->getKey(),
                'execution_id' => $verifying->getKey(),
                'action' => 'EXECUTION_VALIDATION_REQUESTED',
                'auditable_type' => $command->getMorphClass(),
                'auditable_id' => $command->getKey(),
                'payload' => ['proposal_version' => $verifying->proposal_version, 'fingerprint' => $verifying->review_fingerprint],
            ]);
            $this->events->recordNormalized($verifying, new NormalizedToolEvent(
                'verification.queued',
                stepKey: 'verification',
                progress: null,
                message: 'La nueva validación fue persistida y se ejecutará sobre la misma ejecución.',
                payload: ['proposal_version' => $verifying->proposal_version, 'fingerprint' => $verifying->review_fingerprint],
            ));

            return new StartExecutionResult($verifying, true);
        }, attempts: 3);

        $command = $result->execution->commands()
            ->where('command_type', ExecutionCommandType::VALIDATE)
            ->where('attempt', $result->execution->proposal_version + 1)
            ->first();

        if ($command !== null) {
            $this->dispatchAfterCommit($command);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function payload(Execution $execution, int $version, string $fingerprint): array
    {
        return [
            'operation' => 'VALIDATE',
            'execution_uuid' => $execution->uuid,
            'proposal_version' => $version,
            'fingerprint' => $fingerprint,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function dispatchAfterCommit(ExecutionCommand $command): void
    {
        if ($command->processed_at !== null || $command->dispatched_at !== null) {
            return;
        }

        if (DB::transactionLevel() > 0) {
            $id = (int) $command->getKey();
            DB::afterCommit(function () use ($id): void {
                $pending = ExecutionCommand::query()->find($id);

                if ($pending !== null && $pending->processed_at === null && $pending->dispatched_at === null) {
                    $this->dispatcher->dispatch($pending);
                }
            });
        } else {
            $this->dispatcher->dispatch($command);
        }
    }

    private function authorize(Execution $execution, User $actor): void
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
