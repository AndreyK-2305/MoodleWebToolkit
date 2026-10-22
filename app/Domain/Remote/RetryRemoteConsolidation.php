<?php

namespace App\Domain\Remote;

use App\Domain\Executions\DTOs\StartExecutionResult;
use App\Domain\Executions\ExecutionCommandDispatcher;
use App\Domain\Projects\ProjectExecutionManager;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
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

/**
 * A new attempt of a remote consolidation that failed or was cancelled. The
 * Consolidator keeps its checkpoints on the destination, so the new Execution
 * continues where the last one stopped instead of starting over; the failed
 * attempt and its evidence are preserved.
 */
final class RetryRemoteConsolidation
{
    public function __construct(
        private readonly RemoteConsolidationPreflight $preflight,
        private readonly RemoteConsolidationPreparation $preparation,
        private readonly ProjectExecutionManager $manager,
        private readonly ExecutionCommandDispatcher $dispatcher,
    ) {}

    public function retry(Execution $previous, User $actor, string $key, int $configurationVersion): StartExecutionResult
    {
        $scope = 'execution:'.$previous->id.':consolidation-retry';
        $payload = ['operation' => 'CONSOLIDATION_RETRY', 'previous_execution_uuid' => $previous->uuid, 'configuration_version' => $configurationVersion];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $result = DB::transaction(function () use ($previous, $actor, $scope, $key, $payload, $hash, $configurationVersion): StartExecutionResult {
            $project = Project::query()->whereKey($previous->project_id)->lockForUpdate()->firstOrFail();
            $locked = Execution::query()->whereKey($previous->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('project', $project);
            if (! $actor->is_active || (! $actor->isAdmin() && ($actor->role->value !== 'OPERATOR' || ! ProjectAssignment::query()
                ->where('project_id', $project->id)->where('user_id', $actor->id)->lockForUpdate()->exists()))) {
                throw new AuthorizationException;
            }
            if (! RemoteConsolidationPreparation::selected($locked->toolBinding)) {
                throw ValidationException::withMessages(['execution' => 'El nuevo intento requiere una consolidación remota.']);
            }
            $existing = ExecutionCommand::query()->where('idempotency_scope', $scope)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new IdempotencyKeyConflict;
                }

                return new StartExecutionResult($existing->execution, false);
            }
            if (! self::retryable($locked, $project)) {
                throw ValidationException::withMessages(['execution' => 'Se requiere el último intento de la consolidación terminado y sin operaciones activas.']);
            }
            $configuration = $project->configuration()->lockForUpdate()->firstOrFail();
            if ($configuration->version !== $configurationVersion) {
                throw ValidationException::withMessages(['configuration_version' => 'La configuración cambió; revise la versión del nuevo intento.']);
            }
            $checks = $this->preflight->evaluate($project, $configuration, true);
            if (collect($checks)->contains(fn (array $check): bool => $check['result'] === 'ERROR')) {
                throw ValidationException::withMessages(['preflight' => 'El preflight vigente bloquea el nuevo intento.']);
            }
            $fingerprint = $this->preflight->fingerprint($project, $configuration);
            $execution = $this->manager->queue($project, $actor);
            $execution->update(['retried_from_execution_id' => $locked->id, 'resume_checkpoint_id' => null]);
            $this->preparation->prepare($execution, $configuration, $actor);
            foreach ($this->preparation->plan() as $step) {
                $execution->steps()->create(['step_key' => $step->key, 'attempt' => 1, 'name' => $step->name,
                    'position' => $step->position, 'status' => 'PENDING', 'progress' => null,
                    'metadata' => ['adapter' => RemoteConsolidationPreparation::ADAPTER_KEY]]);
            }
            $command = $execution->commands()->create(['step_key' => '__execution__', 'attempt' => 1, 'command_type' => 'START',
                'idempotency_scope' => $scope, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'payload' => [...$payload, 'adapter' => RemoteConsolidationPreparation::ADAPTER_KEY,
                    'configuration_hash' => $fingerprint, 'retry' => true], 'created_by' => $actor->id]);
            AuditLog::query()->create(['actor_id' => $actor->id, 'project_id' => $project->id, 'execution_id' => $execution->id,
                'action' => 'CONSOLIDATION_RETRY_APPROVED', 'payload' => [...$payload, 'configuration_hash' => $fingerprint,
                    'preflight' => $checks, 'command_id' => $command->id]]);

            return new StartExecutionResult($execution->refresh(), true);
        }, attempts: 3);
        $command = $result->execution->commands()->where('idempotency_scope', $scope)->where('idempotency_key', $key)->sole();
        if ($command->processed_at === null && $command->dispatched_at === null) {
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(fn () => $this->dispatcher->dispatch($command));
            } else {
                $this->dispatcher->dispatch($command);
            }
        }

        return $result;
    }

    /**
     * Only the project's latest attempt, terminated and with no active operation, and only
     * after at least one run: the destination's writes are then this consolidation's own.
     */
    public static function retryable(Execution $execution, Project $project): bool
    {
        return in_array($execution->status, [ExecutionStatus::FAILED, ExecutionStatus::CANCELLED], true)
            && in_array($project->status, [ProjectStatus::FAILED, ProjectStatus::CANCELLED], true)
            && (int) $project->executions()->max('attempt') === $execution->attempt
            && ! $execution->remoteOperations()->where('communication_state', '!=', 'TERMINATED')->exists()
            && $execution->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::COMMAND)->exists();
    }
}
