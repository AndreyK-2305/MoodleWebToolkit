<?php

namespace App\Domain\Executions;

use App\Domain\Executions\DTOs\StartExecutionResult;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\EventSeverity;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
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

class RequestExecutionCancellation
{
    public function __construct(
        private readonly ExecutionLifecycle $lifecycle,
        private readonly ExecutionEventRecorder $events,
        private readonly ExecutionCommandDispatcher $dispatcher,
    ) {}

    public function request(Execution $execution, User $actor, string $idempotencyKey): StartExecutionResult
    {
        $scope = "execution:{$execution->getKey()}:cancel";
        $payload = ['operation' => 'CANCEL', 'execution_uuid' => $execution->uuid];
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($execution, $actor, $idempotencyKey, $scope, $payload, $payloadHash): StartExecutionResult {
            $seed = Execution::query()->select(['id', 'project_id'])->findOrFail((int) $execution->getKey());
            $project = Project::query()->lockForUpdate()->findOrFail($seed->project_id);
            $locked = Execution::query()->lockForUpdate()->findOrFail((int) $seed->getKey());
            $locked->setRelation('project', $project);
            $this->authorizeLocked($locked, $actor);

            $existing = $locked->commands()
                ->where('command_type', ExecutionCommandType::CANCEL)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->idempotency_key === $idempotencyKey && ! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new IdempotencyKeyConflict;
                }

                return new StartExecutionResult($locked, false);
            }

            if (! in_array($locked->status, [
                ExecutionStatus::QUEUED,
                ExecutionStatus::RUNNING,
                ExecutionStatus::WAITING_USER_ACTION,
                ExecutionStatus::VERIFYING,
            ], true)) {
                throw ValidationException::withMessages([
                    'execution' => 'El estado vigente de la ejecución no permite solicitar cancelación.',
                ]);
            }

            $command = $locked->commands()->create([
                'step_key' => '__cancel__',
                'attempt' => 1,
                'command_type' => ExecutionCommandType::CANCEL,
                'idempotency_key' => $idempotencyKey,
                'idempotency_scope' => $scope,
                'payload_hash' => $payloadHash,
                'payload' => $payload,
                'created_by' => $actor->getKey(),
            ]);
            $locked->cancel_requested_at = now();
            $locked->save();
            $cancelling = $this->lifecycle->transitionForWorker($locked, ExecutionStatus::CANCELLING);
            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'project_id' => $project->getKey(),
                'execution_id' => $cancelling->getKey(),
                'action' => 'EXECUTION_CANCELLATION_REQUESTED',
                'auditable_type' => $cancelling->getMorphClass(),
                'auditable_id' => $cancelling->getKey(),
                'payload' => ['command_id' => $command->getKey()],
            ]);
            $this->events->recordNormalized($cancelling, new NormalizedToolEvent(
                'execution.cancellation_requested',
                severity: EventSeverity::WARNING,
                progress: $cancelling->progress,
                message: 'La cancelación fue solicitada; el worker debe confirmar un punto seguro.',
            ));

            return new StartExecutionResult($cancelling, true);
        }, attempts: 3);

        $command = $result->execution->commands()->where('command_type', ExecutionCommandType::CANCEL)->firstOrFail();
        $this->dispatchAfterCommit($command);

        return $result;
    }

    private function dispatchAfterCommit(ExecutionCommand $command): void
    {
        if ($command->processed_at !== null || $command->dispatched_at !== null) {
            return;
        }

        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->dispatcher->dispatch($command));
        } else {
            $this->dispatcher->dispatch($command);
        }
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
