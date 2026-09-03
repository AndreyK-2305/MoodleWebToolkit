<?php

namespace App\Domain\Executions;

use App\Domain\Executions\DTOs\StartExecutionResult;
use App\Domain\Projects\ConfirmedProjectValidator;
use App\Domain\Projects\ProjectExecutionManager;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Exceptions\ExecutionAlreadyActive;
use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\NewExecutionBlocked;
use App\Models\AuditLog;
use App\Models\ExecutionCommand;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class StartProjectExecution
{
    public function __construct(
        private readonly ConfirmedProjectValidator $validator,
        private readonly ProjectExecutionManager $executionManager,
        private readonly ToolAdapter $adapter,
        private readonly ExecutionCommandDispatcher $dispatcher,
    ) {}

    public function start(
        Project $project,
        User $actor,
        string $idempotencyKey,
        int $configurationVersion,
    ): StartExecutionResult {
        $scope = "project:{$project->getKey()}:start";
        $requestPayload = [
            'operation' => 'START',
            'project_uuid' => $project->uuid,
            'configuration_version' => $configurationVersion,
        ];
        $payloadHash = hash('sha256', json_encode($requestPayload, JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use (
            $project,
            $actor,
            $idempotencyKey,
            $configurationVersion,
            $scope,
            $requestPayload,
            $payloadHash,
        ): StartExecutionResult {
            $lockedProject = Project::query()
                ->with('configuration')
                ->lockForUpdate()
                ->findOrFail((int) $project->getKey());

            $this->authorizeLocked($lockedProject, $actor);

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

            if ($lockedProject->status->blocksNewExecution()) {
                throw new NewExecutionBlocked($lockedProject->status);
            }

            $activeStatuses = collect(ExecutionStatus::cases())
                ->filter(fn (ExecutionStatus $status): bool => $status->isActive())
                ->map(fn (ExecutionStatus $status): string => $status->value)
                ->all();

            if ($lockedProject->executions()->whereIn('status', $activeStatuses)->exists()) {
                throw new ExecutionAlreadyActive;
            }

            $snapshot = $this->validator->validateForStart($lockedProject, $configurationVersion);
            $execution = $this->executionManager->queue($lockedProject, $actor);

            foreach ($this->adapter->plan($lockedProject) as $definition) {
                $execution->steps()->create([
                    'step_key' => $definition->key,
                    'attempt' => 1,
                    'name' => $definition->name,
                    'position' => $definition->position,
                    'status' => 'PENDING',
                    'progress' => null,
                    'metadata' => ['adapter' => $this->adapter->key()],
                ]);
            }

            $command = $execution->commands()->create([
                'step_key' => '__execution__',
                'attempt' => 1,
                'command_type' => ExecutionCommandType::START,
                'idempotency_key' => $idempotencyKey,
                'idempotency_scope' => $scope,
                'payload_hash' => $payloadHash,
                'payload' => [...$requestPayload, ...$snapshot, 'adapter' => $this->adapter->key()],
                'created_by' => $actor->getKey(),
            ]);

            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'project_id' => $lockedProject->getKey(),
                'execution_id' => $execution->getKey(),
                'action' => 'EXECUTION_STARTED',
                'auditable_type' => $execution->getMorphClass(),
                'auditable_id' => $execution->getKey(),
                'payload' => [
                    'status' => 'QUEUED',
                    'configuration_version' => $configurationVersion,
                    'command_id' => $command->getKey(),
                ],
            ]);

            return new StartExecutionResult($execution, true);
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

    private function authorizeLocked(Project $project, User $actor): void
    {
        if (! $actor->can('startExecution', $project)) {
            throw new AuthorizationException;
        }

        if ($actor->isAdmin()) {
            return;
        }

        $assigned = ProjectAssignment::query()
            ->where('project_id', $project->getKey())
            ->where('user_id', $actor->getKey())
            ->lockForUpdate()
            ->exists();

        if (! $assigned) {
            throw new AuthorizationException;
        }
    }
}
