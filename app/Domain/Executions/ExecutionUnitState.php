<?php

namespace App\Domain\Executions;

use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Exceptions\ExecutionCommandLeaseLost;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\ExecutionStep;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExecutionUnitState
{
    public function __construct(
        private readonly ExecutionCommandLease $leases,
        private readonly ExecutionLifecycle $lifecycle,
        private readonly ExecutionEventRecorder $events,
    ) {}

    public function begin(int $commandId, string $owner): Execution
    {
        return DB::transaction(function () use ($commandId, $owner): Execution {
            $command = $this->ownedCommand($commandId, $owner);
            $execution = $command->execution;

            if ($execution->status !== ExecutionStatus::QUEUED) {
                throw new ExecutionCommandLeaseLost;
            }

            $running = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::RUNNING);
            $this->events->recordNormalized($running, new NormalizedToolEvent(
                'execution.started',
                progress: null,
                message: 'El worker tomó la ejecución de la cola.',
            ));

            return $running;
        }, attempts: 3);
    }

    public function applyEvent(
        int $commandId,
        string $owner,
        int $stepId,
        NormalizedToolEvent $event,
    ): bool {
        return DB::transaction(function () use ($commandId, $owner, $stepId, $event): bool {
            $command = $this->ownedCommand($commandId, $owner);
            $execution = $command->execution;
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
                $this->events->recordNormalized($execution, new NormalizedToolEvent(
                    'iteration_1d.boundary',
                    progress: 25,
                    message: 'La demostración de 1D termina aquí; la ejecución permanece en RUNNING.',
                    payload: ['remaining_units' => 3],
                ));
            }

            return $completed;
        }, attempts: 3);
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
