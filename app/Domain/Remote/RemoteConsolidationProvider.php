<?php

namespace App\Domain\Remote;

use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\ExecutionCommandLease;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Exceptions\ExecutionCommandLeaseLost;
use App\Exceptions\ToolOperationBlocked;
use App\Models\ExecutionCommand;
use Illuminate\Support\Facades\DB;

/**
 * Processes the Execution commands of a remote consolidation. Each command
 * only registers intent with the agent; the scheduler observes the run.
 */
final class RemoteConsolidationProvider implements ExecutionProvider
{
    public function __construct(
        private readonly ExecutionCommandLease $leases,
        private readonly RemoteConsolidationWorkflow $workflow,
    ) {}

    public function execute(ExecutionCommand $command, ToolAdapter $adapter): void
    {
        if (! $adapter instanceof RemoteConsolidatorAdapter || ! RemoteConsolidationPreparation::selected($command->execution->toolBinding)) {
            throw new ToolOperationBlocked('El comando no pertenece a una consolidación remota.');
        }
        $claimed = $this->leases->claim($command->id);
        if ($claimed === null) {
            return;
        }
        $execution = $claimed->command->execution;
        match ($claimed->command->command_type) {
            ExecutionCommandType::START => $execution->status === ExecutionStatus::CANCELLING
                ? $this->workflow->cancel($execution)
                : ($execution->status->isTerminal() ? null : $this->workflow->start($execution)),
            ExecutionCommandType::CANCEL => $this->workflow->cancel($execution),
            default => throw new ToolOperationBlocked('La consolidación remota todavía no admite este comando.'),
        };
        DB::transaction(function () use ($claimed): void {
            $locked = $this->leases->lockCommand($claimed->command->id);
            if ($locked !== null && $locked->processed_at === null) {
                if (! $this->leases->isOwnedAndActive($locked, $claimed->owner)) {
                    throw new ExecutionCommandLeaseLost;
                }
                $this->leases->finish($locked);
            }
        }, attempts: 3);
    }
}
