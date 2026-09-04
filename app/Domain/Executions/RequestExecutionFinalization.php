<?php

namespace App\Domain\Executions;

use App\Domain\Executions\DTOs\StartExecutionResult;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Exceptions\IdempotencyKeyConflict;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestExecutionFinalization
{
    public function __construct(
        private readonly ExecutionEventRecorder $events,
        private readonly ExecutionCommandDispatcher $dispatcher,
    ) {}

    public function request(Execution $execution, User $actor, string $idempotencyKey): StartExecutionResult
    {
        $result = DB::transaction(function () use ($execution, $actor, $idempotencyKey): StartExecutionResult {
            $seed = Execution::query()->select(['id', 'project_id'])->findOrFail((int) $execution->getKey());
            $project = Project::query()->lockForUpdate()->findOrFail($seed->project_id);
            $locked = Execution::query()->lockForUpdate()->findOrFail((int) $seed->getKey());
            $locked->setRelation('project', $project);
            $this->authorize($locked, $actor);
            $payload = [
                'operation' => 'FINALIZE',
                'execution_uuid' => $locked->uuid,
                'proposal_version' => $locked->proposal_version,
                'fingerprint' => $locked->review_fingerprint,
            ];
            $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $existingKey = $locked->commands()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existingKey !== null) {
                if ($existingKey->command_type !== ExecutionCommandType::FINALIZE
                    || ! hash_equals((string) $existingKey->payload_hash, $payloadHash)
                ) {
                    throw new IdempotencyKeyConflict;
                }

                return new StartExecutionResult($locked, false);
            }

            $existingFinalization = $locked->commands()->where('command_type', ExecutionCommandType::FINALIZE)->lockForUpdate()->first();

            if ($existingFinalization !== null) {
                return new StartExecutionResult($locked, false);
            }

            if ($locked->status !== ExecutionStatus::REVIEW || $project->status->value !== ExecutionStatus::REVIEW->value) {
                throw ValidationException::withMessages(['execution' => 'La ejecución sólo puede finalizarse desde REVIEW.']);
            }

            $verification = $locked->verifications()
                ->where('proposal_version', $locked->proposal_version)
                ->where('approved', true)
                ->lockForUpdate()
                ->first();

            if ($verification === null
                || $locked->review_fingerprint === null
                || $locked->validated_proposal_version !== $locked->proposal_version
                || $locked->validated_fingerprint === null
                || ! hash_equals($locked->review_fingerprint, $locked->validated_fingerprint)
                || $verification->fingerprint === null
                || ! hash_equals($locked->review_fingerprint, $verification->fingerprint)
            ) {
                throw ValidationException::withMessages(['verification' => 'La última versión de propuestas no tiene una validación aprobada y vigente.']);
            }

            if ($locked->commands()->whereNull('processed_at')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['execution' => 'Existe otra operación pendiente para esta ejecución.']);
            }

            $command = $locked->commands()->create([
                'step_key' => 'finalization',
                'attempt' => 1,
                'command_type' => ExecutionCommandType::FINALIZE,
                'idempotency_key' => $idempotencyKey,
                'idempotency_scope' => "execution:{$locked->getKey()}:finalize",
                'payload_hash' => $payloadHash,
                'payload' => $payload,
                'created_by' => $actor->getKey(),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'project_id' => $project->getKey(),
                'execution_id' => $locked->getKey(),
                'action' => 'EXECUTION_FINALIZATION_REQUESTED',
                'auditable_type' => $command->getMorphClass(),
                'auditable_id' => $command->getKey(),
                'payload' => ['proposal_version' => $locked->proposal_version, 'fingerprint' => $locked->review_fingerprint],
            ]);
            $this->events->recordNormalized($locked, new NormalizedToolEvent(
                'finalization.queued',
                stepKey: 'finalization',
                progress: $locked->progress,
                message: 'La finalización idempotente fue persistida y se procesará en cola.',
                payload: ['proposal_version' => $locked->proposal_version, 'fingerprint' => $locked->review_fingerprint],
            ));

            return new StartExecutionResult($locked, true);
        }, attempts: 3);

        $command = $result->execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->first();

        if ($command !== null && $command->processed_at === null && $command->dispatched_at === null) {
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(fn () => $this->dispatcher->dispatch($command));
            } else {
                $this->dispatcher->dispatch($command);
            }
        }

        return $result;
    }

    private function authorize(Execution $execution, User $actor): void
    {
        if (! $actor->is_active || (! $actor->isAdmin() && $actor->role->value !== 'OPERATOR')) {
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
