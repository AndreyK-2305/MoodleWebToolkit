<?php

namespace App\Domain\Executions;

use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\ConflictStatus;
use App\Enums\EventSeverity;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Exceptions\ExecutionCommandLeaseLost;
use App\Models\AuditLog;
use App\Models\Conflict;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\ExecutionLog;
use App\Models\ExecutionStep;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExecutionUnitState
{
    public function __construct(
        private readonly ExecutionCommandLease $leases,
        private readonly ExecutionLifecycle $lifecycle,
        private readonly ExecutionEventRecorder $events,
        private readonly ExecutionCommandDispatcher $dispatcher,
        private readonly RequestExecutionValidation $validation,
    ) {}

    public function begin(int $commandId, string $owner): Execution
    {
        return DB::transaction(function () use ($commandId, $owner): Execution {
            $command = $this->ownedCommand($commandId, $owner);
            $execution = $command->execution;

            if (in_array($command->command_type, [ExecutionCommandType::START, ExecutionCommandType::RESUME], true)) {
                if ($execution->status !== ExecutionStatus::QUEUED) {
                    throw new ExecutionCommandLeaseLost;
                }

                $execution = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::RUNNING);
                $this->events->recordNormalized($execution, new NormalizedToolEvent(
                    $command->command_type === ExecutionCommandType::RESUME ? 'execution.resumed' : 'execution.started',
                    progress: $execution->progress,
                    message: $command->command_type === ExecutionCommandType::RESUME
                        ? 'El nuevo intento comenzó desde un checkpoint validado.'
                        : 'El worker tomó la ejecución de la cola.',
                ));

                return $execution;
            }

            if ($command->command_type === ExecutionCommandType::CONTINUE) {
                if ($execution->status !== ExecutionStatus::RUNNING) {
                    throw new ExecutionCommandLeaseLost;
                }

                return $execution;
            }

            if ($command->command_type === ExecutionCommandType::RESOLVE_CONFLICT) {
                if ($execution->status !== ExecutionStatus::WAITING_USER_ACTION) {
                    throw new ExecutionCommandLeaseLost;
                }

                $step = $execution->steps()
                    ->where('step_key', $command->step_key)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($step->status !== ExecutionStepStatus::WAITING_USER || $execution->conflicts()->where('status', ConflictStatus::OPEN)->exists()) {
                    throw new ExecutionCommandLeaseLost;
                }

                $step->status = ExecutionStepStatus::RUNNING;
                $step->save();
                $execution = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::RUNNING);
                $this->events->recordNormalized($execution, new NormalizedToolEvent(
                    'execution.user_action_resolved',
                    $step->step_key,
                    progress: $execution->progress,
                    message: 'La decisión vigente fue validada; continúa la misma ejecución.',
                ));

                return $execution;
            }

            throw new ExecutionCommandLeaseLost;
        }, attempts: 3);
    }

    public function applyEvent(int $commandId, string $owner, int $stepId, NormalizedToolEvent $event): bool
    {
        return DB::transaction(function () use ($commandId, $owner, $stepId, $event): bool {
            $command = $this->ownedCommand($commandId, $owner);
            $execution = $command->execution;

            if ($execution->status !== ExecutionStatus::RUNNING) {
                throw new ExecutionCommandLeaseLost;
            }

            $step = ExecutionStep::query()
                ->where('execution_id', $execution->getKey())
                ->lockForUpdate()
                ->findOrFail($stepId);

            if ($event->type === 'phase.started') {
                if ($step->status !== ExecutionStepStatus::PENDING) {
                    throw new RuntimeException('El paso sólo puede comenzar desde PENDING.');
                }

                $step->status = ExecutionStepStatus::RUNNING;
                $step->started_at = now();
            }

            if ($event->progress !== null) {
                $progress = max(0, min(100, $event->progress));
                $execution->progress = $progress;
                $step->progress = $progress;
            }

            $completed = $event->type === 'phase.completed';

            if ($completed) {
                if ($step->status !== ExecutionStepStatus::RUNNING) {
                    throw new RuntimeException('El paso sólo puede completarse desde RUNNING.');
                }

                $step->status = ExecutionStepStatus::SUCCESS;
                $step->finished_at = now();
            }

            $step->save();
            $execution->save();
            $this->events->recordNormalized($execution, $event);

            if ($completed) {
                $this->leases->finish($command);
                $next = $execution->steps()
                    ->where('position', '<', 3)
                    ->where('status', ExecutionStepStatus::PENDING)
                    ->orderBy('position')
                    ->lockForUpdate()
                    ->first();

                if ($next !== null) {
                    $this->queueContinuation($execution, $next);
                    $this->events->recordNormalized($execution, new NormalizedToolEvent(
                        'execution.command_queued',
                        $next->step_key,
                        progress: $execution->progress,
                        message: 'La siguiente unidad se persistió antes de enviarse a Redis.',
                    ));
                } else {
                    $this->validation->queueInitial($execution);
                }
            }

            return $completed;
        }, attempts: 3);
    }

    /** @param list<string> $allowedDecisions */
    public function waitForUser(
        int $commandId,
        string $owner,
        int $stepId,
        string $conflictKey,
        string $type,
        string $message,
        array $allowedDecisions,
    ): void {
        DB::transaction(function () use ($commandId, $owner, $stepId, $conflictKey, $type, $message, $allowedDecisions): void {
            $command = $this->ownedCommand($commandId, $owner);
            $execution = $command->execution;

            if ($execution->status !== ExecutionStatus::RUNNING) {
                throw new ExecutionCommandLeaseLost;
            }

            $step = ExecutionStep::query()->where('execution_id', $execution->getKey())->lockForUpdate()->findOrFail($stepId);

            if ($step->status !== ExecutionStepStatus::RUNNING) {
                throw new RuntimeException('Sólo un paso activo puede esperar intervención.');
            }

            $conflict = $execution->conflicts()->firstOrCreate(
                ['key' => $conflictKey],
                [
                    'execution_step_id' => $step->getKey(),
                    'type' => $type,
                    'status' => ConflictStatus::OPEN,
                    'version' => 1,
                    'details' => [
                        'message' => $message,
                        'allowed_decisions' => $allowedDecisions,
                    ],
                ],
            );

            if ($conflict->status !== ConflictStatus::OPEN) {
                throw new RuntimeException('La incidencia simulada ya no está vigente.');
            }

            $step->status = ExecutionStepStatus::WAITING_USER;
            $step->save();
            $waiting = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::WAITING_USER_ACTION);
            $this->leases->finish($command);

            AuditLog::query()->create([
                'actor_id' => null,
                'project_id' => $waiting->project_id,
                'execution_id' => $waiting->getKey(),
                'action' => 'EXECUTION_USER_ACTION_REQUIRED',
                'auditable_type' => $conflict->getMorphClass(),
                'auditable_id' => $conflict->getKey(),
                'payload' => ['conflict_key' => $conflict->key, 'type' => $type, 'version' => $conflict->version],
            ]);
            $this->events->recordNormalized($waiting, new NormalizedToolEvent(
                'user_action_required',
                $step->step_key,
                EventSeverity::WARNING,
                $waiting->progress,
                $message,
                ['conflict_key' => $conflict->key, 'conflict_version' => $conflict->version, 'type' => $type],
            ));
        }, attempts: 3);
    }

    public function failWithCheckpoint(int $commandId, string $owner, int $stepId, string $resumeToken): void
    {
        DB::transaction(function () use ($commandId, $owner, $stepId, $resumeToken): void {
            $command = $this->ownedCommand($commandId, $owner);
            $execution = $command->execution;
            $step = ExecutionStep::query()->where('execution_id', $execution->getKey())->lockForUpdate()->findOrFail($stepId);

            if ($execution->status !== ExecutionStatus::RUNNING || $step->status !== ExecutionStepStatus::RUNNING) {
                throw new ExecutionCommandLeaseLost;
            }

            $checkpoint = $execution->checkpoints()->create([
                'step_key' => $step->step_key,
                'type' => 'FAKE_TOOL_STATE',
                'adapter_key' => 'fake',
                'resume_token' => $resumeToken,
                'validated' => true,
                'metadata' => [
                    'source_workspace_key' => $execution->workspace_key,
                    'reusable_step_keys' => $execution->steps()
                        ->where('status', ExecutionStepStatus::SUCCESS)
                        ->orderBy('position')
                        ->pluck('step_key')
                        ->all(),
                    'validated_artifact_ids' => [],
                ],
            ]);

            $step->status = ExecutionStepStatus::FAILED;
            $step->finished_at = now();
            $step->save();
            $failed = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::FAILED);
            $this->leases->finish($command);

            ExecutionLog::query()->create([
                'execution_id' => $failed->getKey(),
                'execution_step_id' => $step->getKey(),
                'stream' => 'SYSTEM',
                'level' => 'ERROR',
                'message' => 'El escenario simulado falló después de emitir un checkpoint validado.',
                'context' => ['reason' => 'SIMULATED_FAILURE', 'checkpoint_id' => $checkpoint->getKey()],
            ]);
            AuditLog::query()->create([
                'actor_id' => null,
                'project_id' => $failed->project_id,
                'execution_id' => $failed->getKey(),
                'action' => 'EXECUTION_SIMULATED_FAILURE',
                'auditable_type' => $failed->getMorphClass(),
                'auditable_id' => $failed->getKey(),
                'payload' => ['step_key' => $step->step_key, 'checkpoint_id' => $checkpoint->getKey()],
            ]);
            $this->events->recordNormalized($failed, new NormalizedToolEvent(
                'tool.failed',
                $step->step_key,
                EventSeverity::ERROR,
                $failed->progress,
                'El procesamiento simulado falló. Existe un checkpoint validado para reanudar.',
                ['checkpoint_id' => $checkpoint->getKey(), 'resumable' => true],
            ));
        }, attempts: 3);
    }

    public function cancel(int $commandId, string $owner): void
    {
        DB::transaction(function () use ($commandId, $owner): void {
            $command = $this->ownedCommand($commandId, $owner);
            $execution = $command->execution;

            if ($command->command_type !== ExecutionCommandType::CANCEL || $execution->status !== ExecutionStatus::CANCELLING) {
                throw new ExecutionCommandLeaseLost;
            }

            $execution->steps()
                ->whereIn('status', [ExecutionStepStatus::PENDING->value, ExecutionStepStatus::RUNNING->value, ExecutionStepStatus::WAITING_USER->value])
                ->lockForUpdate()
                ->get()
                ->each(function (ExecutionStep $step): void {
                    $step->status = ExecutionStepStatus::CANCELLED;
                    $step->finished_at = now();
                    $step->save();
                });

            $execution->conflicts()
                ->where('status', ConflictStatus::OPEN)
                ->lockForUpdate()
                ->get()
                ->each(function (Conflict $conflict) use ($command): void {
                    $conflict->status = ConflictStatus::IGNORED;
                    $conflict->resolution = ['decision' => 'CANCELLED'];
                    $conflict->resolved_by = $command->created_by;
                    $conflict->resolved_at = now();
                    $conflict->version = $conflict->version + 1;
                    $conflict->save();
                });

            $execution->commands()
                ->whereNull('processed_at')
                ->lockForUpdate()
                ->get()
                ->each(function (ExecutionCommand $open): void {
                    $this->leases->finish($open);
                });

            $cancelled = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::CANCELLED);
            AuditLog::query()->create([
                'actor_id' => $command->created_by,
                'project_id' => $cancelled->project_id,
                'execution_id' => $cancelled->getKey(),
                'action' => 'EXECUTION_CANCELLED',
                'auditable_type' => $cancelled->getMorphClass(),
                'auditable_id' => $cancelled->getKey(),
                'payload' => ['command_id' => $command->getKey(), 'safe_point' => true],
            ]);
            $this->events->recordNormalized($cancelled, new NormalizedToolEvent(
                'execution.cancelled',
                severity: EventSeverity::WARNING,
                progress: $cancelled->progress,
                message: 'El worker confirmó un punto seguro y completó la limpieza simulada.',
            ));
        }, attempts: 3);
    }

    public function discard(int $commandId, string $owner): void
    {
        DB::transaction(function () use ($commandId, $owner): void {
            $command = $this->leases->lockCommand($commandId);

            if ($command !== null && $this->leases->isOwnedAndActive($command, $owner)) {
                $this->leases->finish($command);
            }
        }, attempts: 3);
    }

    private function queueContinuation(Execution $execution, ExecutionStep $step): void
    {
        $scope = "execution:{$execution->getKey()}:step:{$step->step_key}:continue";
        $key = "auto:{$execution->uuid}:{$step->step_key}";
        $payload = [
            'operation' => 'CONTINUE',
            'execution_uuid' => $execution->uuid,
            'step_key' => $step->step_key,
            'workspace_key' => $execution->workspace_key,
        ];
        $command = $execution->commands()->firstOrCreate(
            ['idempotency_scope' => $scope, 'idempotency_key' => $key],
            [
                'step_key' => $step->step_key,
                'attempt' => 1,
                'command_type' => ExecutionCommandType::CONTINUE,
                'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'payload' => $payload,
                'created_by' => $execution->created_by,
            ],
        );

        $commandId = (int) $command->getKey();
        DB::afterCommit(function () use ($commandId): void {
            $pending = ExecutionCommand::query()->find($commandId);

            if ($pending !== null && $pending->processed_at === null && $pending->dispatched_at === null) {
                $this->dispatcher->dispatch($pending);
            }
        });
    }

    private function ownedCommand(int $commandId, string $owner): ExecutionCommand
    {
        $command = $this->leases->lockCommand($commandId);

        if ($command === null || ! $this->leases->isOwnedAndActive($command, $owner)) {
            throw new ExecutionCommandLeaseLost;
        }

        return $command;
    }
}
