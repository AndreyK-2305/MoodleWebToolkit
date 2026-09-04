<?php

namespace App\Domain\Executions;

use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Domain\Tools\FakeToolAdapter;
use App\Enums\EventSeverity;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStepStatus;
use App\Exceptions\ExecutionCommandLeaseLost;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\ExecutionStep;
use RuntimeException;

class FakeExecutionProvider implements ExecutionProvider
{
    public function __construct(
        private readonly ExecutionCommandLease $leases,
        private readonly ExecutionUnitState $state,
    ) {}

    public function execute(ExecutionCommand $command, ToolAdapter $adapter): void
    {
        $claimed = $this->leases->claim((int) $command->getKey());

        if ($claimed === null) {
            return;
        }

        try {
            if ($claimed->command->command_type === ExecutionCommandType::CANCEL) {
                $this->state->cancel((int) $claimed->command->getKey(), $claimed->owner);

                return;
            }

            $execution = $this->state->begin((int) $claimed->command->getKey(), $claimed->owner);
            $step = $this->stepFor($claimed->command, $execution);

            if ($adapter instanceof FakeToolAdapter && $step->step_key === 'operation') {
                $scenario = $adapter->scenario($execution);

                if (in_array($scenario, ['WARNING', 'INTERVENTION'], true)
                    && $claimed->command->command_type !== ExecutionCommandType::RESOLVE_CONFLICT
                ) {
                    $this->state->applyEvent(
                        (int) $claimed->command->getKey(),
                        $claimed->owner,
                        (int) $step->getKey(),
                        new NormalizedToolEvent('phase.started', $step->step_key, progress: 25, message: 'Comenzó el procesamiento simulado.'),
                    );
                    $this->state->applyEvent(
                        (int) $claimed->command->getKey(),
                        $claimed->owner,
                        (int) $step->getKey(),
                        new NormalizedToolEvent('progress', $step->step_key, progress: 35, message: 'Se alcanzó un punto seguro antes de solicitar intervención.'),
                    );
                    $warning = $scenario === 'WARNING';
                    $this->state->waitForUser(
                        (int) $claimed->command->getKey(),
                        $claimed->owner,
                        (int) $step->getKey(),
                        $warning ? 'runtime.capacity-warning' : 'runtime.manual-intervention',
                        $warning ? 'WARNING_ACCEPTANCE' : 'MANUAL_INTERVENTION',
                        $warning
                            ? 'La capacidad simulada es ajustada y exige aceptación explícita.'
                            : 'Confirme que la intervención manual simulada fue completada.',
                        [$warning ? 'ACCEPT' : 'CONFIRM_COMPLETED'],
                    );

                    return;
                }

                if ($scenario === 'FAILURE' && $execution->resumed_from_execution_id === null) {
                    $this->state->applyEvent(
                        (int) $claimed->command->getKey(),
                        $claimed->owner,
                        (int) $step->getKey(),
                        new NormalizedToolEvent('phase.started', $step->step_key, progress: 25, message: 'Comenzó el escenario de fallo recuperable.'),
                    );
                    $this->state->applyEvent(
                        (int) $claimed->command->getKey(),
                        $claimed->owner,
                        (int) $step->getKey(),
                        new NormalizedToolEvent(
                            'progress',
                            $step->step_key,
                            EventSeverity::INFO,
                            35,
                            'Se conservó trabajo previo antes del fallo simulado.',
                        ),
                    );
                    $this->state->failWithCheckpoint(
                        (int) $claimed->command->getKey(),
                        $claimed->owner,
                        (int) $step->getKey(),
                        $adapter->checkpointToken($execution, $step),
                    );

                    return;
                }
            }

            foreach ($adapter->executeUnit($execution->refresh(), $step) as $event) {
                if ($claimed->command->command_type === ExecutionCommandType::RESOLVE_CONFLICT && $event->type === 'phase.started') {
                    continue;
                }

                if ($this->state->applyEvent(
                    (int) $claimed->command->getKey(),
                    $claimed->owner,
                    (int) $step->getKey(),
                    $event,
                )) {
                    return;
                }
            }

            throw new RuntimeException('El adaptador terminó la unidad sin emitir phase.completed.');
        } catch (ExecutionCommandLeaseLost) {
            // Una cancelación u otra transición válida ganó la carrera. El
            // comando vencedor ya revocó este lease y no debe marcarse FAILED.
            $this->state->discard((int) $claimed->command->getKey(), $claimed->owner);
        }
    }

    private function stepFor(ExecutionCommand $command, Execution $execution): ExecutionStep
    {
        if ($command->step_key !== '__execution__') {
            return $execution->steps()->where('step_key', $command->step_key)->firstOrFail();
        }

        return $execution->steps()
            ->where('status', ExecutionStepStatus::PENDING->value)
            ->orderBy('position')
            ->firstOrFail();
    }
}
