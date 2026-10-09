<?php

namespace App\Domain\Collector;

use App\Domain\Executions\DTOs\StartExecutionResult;
use App\Domain\Executions\ExecutionCommandDispatcher;
use App\Domain\Projects\ProjectExecutionManager;
use App\Domain\Tools\CollectorAdapter;
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

/** An export from zero, preserving the failed attempt and its evidence. */
final class RetryCollectorExecution
{
    public function __construct(private readonly CollectorPreflight $preflight,
        private readonly CollectorExecutionPreparation $preparation, private readonly ProjectExecutionManager $manager,
        private readonly ExecutionCommandDispatcher $dispatcher) {}

    public function retry(Execution $previous, User $actor, string $key, int $configurationVersion, bool $acceptLaboratory): StartExecutionResult
    {
        $scope = 'execution:'.$previous->id.':collector-fresh-export';
        $payload = ['operation' => 'COLLECTOR_FRESH_EXPORT', 'previous_execution_uuid' => $previous->uuid,
            'configuration_version' => $configurationVersion, 'accept_laboratory' => $acceptLaboratory];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $result = DB::transaction(function () use ($previous, $actor, $scope, $key, $payload, $hash, $configurationVersion, $acceptLaboratory): StartExecutionResult {
            $project = Project::query()->whereKey($previous->project_id)->lockForUpdate()->firstOrFail();
            $locked = Execution::query()->whereKey($previous->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('project', $project);
            if (! $actor->is_active || (! $actor->isAdmin() && ($actor->role->value !== 'OPERATOR' || ! ProjectAssignment::query()
                ->where('project_id', $project->id)->where('user_id', $actor->id)->lockForUpdate()->exists()))) {
                throw new AuthorizationException;
            }
            if ($locked->toolBinding?->adapter_key !== CollectorExecutionPreparation::ADAPTER_KEY) {
                throw ValidationException::withMessages(['execution' => 'El nuevo intento LAB requiere una ejecución del Recolector real.']);
            }
            $existing = ExecutionCommand::query()->where('idempotency_scope', $scope)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new IdempotencyKeyConflict;
                }

                return new StartExecutionResult($existing->execution, false);
            }
            if (! $acceptLaboratory || ! CollectorAdapter::httpEnabled()
                || ! in_array($locked->status, [ExecutionStatus::FAILED, ExecutionStatus::CANCELLED], true)
                || ! in_array($project->status, [ProjectStatus::FAILED, ProjectStatus::CANCELLED], true)
                || (int) $project->executions()->max('attempt') !== $locked->attempt
                || $locked->remoteOperations()->where('communication_state', '!=', 'TERMINATED')->exists()) {
                throw ValidationException::withMessages(['execution' => 'Se requiere el último intento terminado y aceptar una nueva exportación LAB desde cero.']);
            }
            $configuration = $project->configuration()->lockForUpdate()->firstOrFail();
            if ($configuration->version !== $configurationVersion) {
                throw ValidationException::withMessages(['configuration_version' => 'La configuración cambió; revise la versión del nuevo intento.']);
            }
            $checks = $this->preflight->evaluate($project, $configuration);
            if (collect($checks)->contains(fn (array $check): bool => $check['result'] === 'ERROR')) {
                throw ValidationException::withMessages(['preflight' => 'El preflight real vigente bloquea el nuevo intento.']);
            }
            $fingerprint = $this->preflight->fingerprint($project, $configuration);
            $execution = $this->manager->queue($project, $actor);
            $execution->update(['retried_from_execution_id' => $locked->id, 'resume_checkpoint_id' => null]);
            $this->preparation->prepare($execution, $configuration, $actor);
            foreach ($this->preparation->plan() as $step) {
                $execution->steps()->create(['step_key' => $step->key, 'attempt' => 1, 'name' => $step->name,
                    'position' => $step->position, 'status' => 'PENDING', 'progress' => null,
                    'metadata' => ['adapter' => CollectorExecutionPreparation::ADAPTER_KEY]]);
            }
            $command = $execution->commands()->create(['step_key' => '__execution__', 'attempt' => 1, 'command_type' => 'START',
                'idempotency_scope' => $scope, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'payload' => [...$payload, 'adapter' => CollectorExecutionPreparation::ADAPTER_KEY,
                    'configuration_hash' => $fingerprint, 'fresh_export' => true], 'created_by' => $actor->id]);
            AuditLog::query()->create(['actor_id' => $actor->id, 'project_id' => $project->id, 'execution_id' => $execution->id,
                'action' => 'COLLECTOR_FRESH_EXPORT_APPROVED', 'payload' => [...$payload, 'configuration_hash' => $fingerprint,
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
}
