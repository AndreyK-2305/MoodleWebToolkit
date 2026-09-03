<?php

namespace App\Domain\Executions;

use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\ExecutionStep;
use Illuminate\Support\Facades\DB;

class FakeExecutionProvider implements ExecutionProvider
{
    public function __construct(
        private readonly ExecutionLifecycle $lifecycle,
        private readonly ExecutionEventRecorder $events,
    ) {}

    public function execute(ExecutionCommand $command, ToolAdapter $adapter): void
    {
        $claimed = DB::transaction(function () use ($command): ?ExecutionCommand {
            $locked = ExecutionCommand::query()
                ->with('execution')
                ->lockForUpdate()
                ->findOrFail((int) $command->getKey());

            if ($locked->processed_at !== null || $locked->processing_started_at !== null) {
                return null;
            }

            $locked->processing_started_at = now();
            $locked->save();

            return $locked;
        }, attempts: 3);

        if ($claimed === null) {
            return;
        }

        $execution = $claimed->execution;
        DB::transaction(function () use ($execution): void {
            $running = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::RUNNING);
            $this->events->recordNormalized($running, new NormalizedToolEvent(
                'execution.started',
                progress: null,
                message: 'El worker tomó la ejecución de la cola.',
            ));
        });

        $step = $execution->steps()
            ->where('status', ExecutionStepStatus::PENDING->value)
            ->orderBy('position')
            ->firstOrFail();

        foreach ($adapter->executeUnit($execution->refresh(), $step) as $event) {
            $this->persistEventState($execution, $step, $event);
        }

        DB::transaction(function () use ($claimed, $execution): void {
            $lockedCommand = ExecutionCommand::query()->lockForUpdate()->findOrFail((int) $claimed->getKey());
            $lockedCommand->processed_at = now();
            $lockedCommand->save();

            $this->events->recordNormalized($execution, new NormalizedToolEvent(
                'iteration_1d.boundary',
                progress: 25,
                message: 'La demostración de 1D termina aquí; la ejecución permanece en RUNNING.',
                payload: ['remaining_units' => 3],
            ));
        });
    }

    private function persistEventState(
        Execution $execution,
        ExecutionStep $step,
        NormalizedToolEvent $event,
    ): void {
        DB::transaction(function () use ($execution, $step, $event): void {
            $lockedExecution = Execution::query()->lockForUpdate()->findOrFail((int) $execution->getKey());
            $lockedStep = ExecutionStep::query()->lockForUpdate()->findOrFail((int) $step->getKey());

            if ($event->type === 'phase.started') {
                $lockedStep->status = ExecutionStepStatus::RUNNING;
                $lockedStep->started_at ??= now();
            }

            if ($event->progress !== null) {
                $progress = max(0, min(100, $event->progress));
                $lockedExecution->progress = $progress;
                $lockedStep->progress = $progress;
            }

            if ($event->type === 'phase.completed') {
                $lockedStep->status = ExecutionStepStatus::SUCCESS;
                $lockedStep->finished_at = now();
            }

            $lockedStep->save();
            $lockedExecution->save();
            $this->events->recordNormalized($lockedExecution, $event);
        }, attempts: 3);
    }
}
