<?php

namespace App\Domain\Executions;

use App\Domain\Executions\DTOs\StartExecutionResult;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\ConflictStatus;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Exceptions\IdempotencyKeyConflict;
use App\Models\AuditLog;
use App\Models\Conflict;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveExecutionConflict
{
    public function __construct(
        private readonly ExecutionEventRecorder $events,
        private readonly ExecutionCommandDispatcher $dispatcher,
    ) {}

    public function resolve(
        Execution $execution,
        Conflict $conflict,
        User $actor,
        string $decision,
        int $version,
        string $idempotencyKey,
    ): StartExecutionResult {
        $scope = "execution:{$execution->getKey()}:conflict:{$conflict->getKey()}:resolve";
        $payload = [
            'operation' => 'RESOLVE_CONFLICT',
            'execution_uuid' => $execution->uuid,
            'conflict_key' => $conflict->key,
            'conflict_version' => $version,
            'decision' => $decision,
        ];
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($execution, $conflict, $actor, $decision, $version, $idempotencyKey, $scope, $payload, $payloadHash): StartExecutionResult {
            $seed = Execution::query()->select(['id', 'project_id'])->findOrFail((int) $execution->getKey());
            $project = Project::query()->lockForUpdate()->findOrFail($seed->project_id);
            $lockedExecution = Execution::query()->lockForUpdate()->findOrFail((int) $seed->getKey());
            $lockedExecution->setRelation('project', $project);
            $this->authorizeLocked($lockedExecution, $actor);
            $lockedConflict = Conflict::query()
                ->where('execution_id', $lockedExecution->getKey())
                ->lockForUpdate()
                ->findOrFail((int) $conflict->getKey());

            $existing = ExecutionCommand::query()
                ->where('idempotency_scope', $scope)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new IdempotencyKeyConflict;
                }

                return new StartExecutionResult($lockedExecution, false);
            }

            if ($lockedExecution->status !== ExecutionStatus::WAITING_USER_ACTION
                || $lockedConflict->status !== ConflictStatus::OPEN
                || $lockedConflict->version !== $version
            ) {
                throw ValidationException::withMessages([
                    'conflict' => 'La incidencia o ejecución cambió mientras se esperaba la decisión. Actualice el seguimiento.',
                ]);
            }

            $details = $lockedConflict->details;
            $allowed = is_array($details['allowed_decisions'] ?? null) ? $details['allowed_decisions'] : [];

            if (! in_array($decision, $allowed, true)) {
                throw ValidationException::withMessages(['decision' => 'La decisión no corresponde a la incidencia vigente.']);
            }

            $lockedConflict->status = ConflictStatus::RESOLVED;
            $lockedConflict->resolution = ['decision' => $decision, 'resolved_version' => $version];
            $lockedConflict->resolved_by = $actor->getKey();
            $lockedConflict->resolved_at = now();
            $lockedConflict->version = $lockedConflict->version + 1;
            $lockedConflict->save();
            $hasOtherBlockers = $lockedExecution->conflicts()
                ->where('status', ConflictStatus::OPEN)
                ->lockForUpdate()
                ->exists();

            $command = $lockedExecution->commands()->create([
                'step_key' => $lockedConflict->step->step_key,
                'attempt' => (int) $lockedConflict->getKey(),
                'command_type' => ExecutionCommandType::RESOLVE_CONFLICT,
                'idempotency_key' => $idempotencyKey,
                'idempotency_scope' => $scope,
                'payload_hash' => $payloadHash,
                'payload' => [...$payload, 'continues_execution' => ! $hasOtherBlockers],
                'created_by' => $actor->getKey(),
                'processed_at' => $hasOtherBlockers ? now() : null,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'project_id' => $project->getKey(),
                'execution_id' => $lockedExecution->getKey(),
                'action' => $lockedConflict->type === 'WARNING_ACCEPTANCE' ? 'EXECUTION_WARNING_ACCEPTED' : 'EXECUTION_CONFLICT_RESOLVED',
                'auditable_type' => $lockedConflict->getMorphClass(),
                'auditable_id' => $lockedConflict->getKey(),
                'payload' => [
                    'conflict_key' => $lockedConflict->key,
                    'decision' => $decision,
                    'version' => $version,
                    'remaining_blockers' => $hasOtherBlockers,
                    'command_id' => $command->getKey(),
                ],
            ]);
            $this->events->recordNormalized($lockedExecution, new NormalizedToolEvent(
                'conflict.resolved',
                $lockedConflict->step->step_key,
                progress: $lockedExecution->progress,
                message: $hasOtherBlockers
                    ? 'La decisión fue auditada; aún existen incidencias bloqueantes.'
                    : 'La decisión fue auditada y se persistió un comando de continuación.',
                payload: ['conflict_key' => $lockedConflict->key, 'remaining_blockers' => $hasOtherBlockers],
            ));

            return new StartExecutionResult($lockedExecution, true);
        }, attempts: 3);

        $command = ExecutionCommand::query()
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
