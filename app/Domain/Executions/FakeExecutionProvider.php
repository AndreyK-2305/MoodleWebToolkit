<?php

namespace App\Domain\Executions;

use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Enums\ExecutionStepStatus;
use App\Models\ExecutionCommand;
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

        $execution = $this->state->begin((int) $claimed->command->getKey(), $claimed->owner);

        $step = $execution->steps()
            ->where('status', ExecutionStepStatus::PENDING->value)
            ->orderBy('position')
            ->firstOrFail();

        foreach ($adapter->executeUnit($execution->refresh(), $step) as $event) {
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
    }
}
