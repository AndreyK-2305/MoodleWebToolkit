<?php

namespace App\Domain\Executions;

use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\EventSeverity;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Models\AuditLog;
use App\Models\ExecutionLog;
use App\Models\ExecutionStep;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExecutionFailureCloser
{
    public function __construct(
        private readonly ExecutionCommandLease $leases,
        private readonly ExecutionLifecycle $lifecycle,
        private readonly ExecutionEventRecorder $events,
    ) {}

    public function closeWorkerFailure(int $commandId, ?Throwable $exception = null): bool
    {
        return $this->close($commandId, 'WORKER_FAILURE', $exception, false);
    }

    public function closeAbandoned(int $commandId): bool
    {
        return $this->close($commandId, 'ABANDONED', null, true);
    }

    private function close(
        int $commandId,
        string $reason,
        ?Throwable $exception,
        bool $mustBeAbandoned,
    ): bool {
        return DB::transaction(function () use ($commandId, $reason, $exception, $mustBeAbandoned): bool {
            $command = $this->leases->lockCommand($commandId);

            if ($command === null || $command->processed_at !== null) {
                return false;
            }

            if ($mustBeAbandoned && ! $this->leases->isAbandoned($command)) {
                return false;
            }

            $execution = $command->execution;

            if ($command->command_type === ExecutionCommandType::FINALIZE
                && $execution->status === ExecutionStatus::REVIEW
            ) {
                $this->leases->releaseForRetry($command);
                ExecutionLog::query()->create([
                    'execution_id' => $execution->getKey(),
                    'stream' => 'SYSTEM',
                    'level' => 'ERROR',
                    'message' => 'La generación de artefactos no terminó; el cierre permanece en REVIEW y puede reintentarse idempotentemente.',
                    'context' => ['reason' => $reason, 'exception_type' => $exception === null ? null : $exception::class],
                ]);

                return true;
            }

            if ($execution->status === ExecutionStatus::CANCELLING
                && $command->command_type !== ExecutionCommandType::CANCEL
            ) {
                $this->leases->finish($command);

                return true;
            }

            if (! $execution->status->isActive()) {
                return false;
            }

            $activeStep = ExecutionStep::query()
                ->where('execution_id', $execution->getKey())
                ->where('status', ExecutionStepStatus::RUNNING->value)
                ->orderBy('position')
                ->lockForUpdate()
                ->first();

            if ($activeStep !== null) {
                $activeStep->status = ExecutionStepStatus::FAILED;
                $activeStep->finished_at = now();
                $activeStep->save();
            }

            $failed = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::FAILED);
            $this->leases->finish($command);

            ExecutionLog::query()->create([
                'execution_id' => $failed->getKey(),
                'execution_step_id' => $activeStep?->getKey(),
                'stream' => 'SYSTEM',
                'level' => 'ERROR',
                'message' => $reason === 'ABANDONED'
                    ? 'El comando excedió su arrendamiento y fue cerrado por el reconciliador.'
                    : 'La unidad asíncrona falló. Consulte el log técnico del worker.',
                'context' => [
                    'reason' => $reason,
                    'exception_type' => $exception === null ? null : $exception::class,
                ],
            ]);

            AuditLog::query()->create([
                'actor_id' => null,
                'project_id' => $failed->project_id,
                'execution_id' => $failed->getKey(),
                'action' => $reason === 'ABANDONED' ? 'EXECUTION_ABANDONED' : 'EXECUTION_WORKER_FAILED',
                'auditable_type' => $failed->getMorphClass(),
                'auditable_id' => $failed->getKey(),
                'payload' => [
                    'command_id' => $command->getKey(),
                    'step_key' => $activeStep?->step_key,
                    'reason' => $reason,
                ],
            ]);

            $this->events->recordNormalized($failed, new NormalizedToolEvent(
                $reason === 'ABANDONED' ? 'execution.abandoned' : 'tool.failed',
                stepKey: $activeStep?->step_key,
                severity: EventSeverity::ERROR,
                progress: $failed->progress,
                message: $reason === 'ABANDONED'
                    ? 'La unidad abandonada fue cerrada sin volver a ejecutar el adaptador.'
                    : 'La unidad asíncrona no pudo completarse.',
                payload: ['reason' => $reason],
            ));

            return true;
        }, attempts: 3);
    }
}
