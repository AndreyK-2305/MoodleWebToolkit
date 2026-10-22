<?php

namespace App\Domain\Remote;

use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\ExecutionCommandLease;
use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Executions\ProcessExecutionFinalization;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Enums\EventSeverity;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Exceptions\ExecutionCommandLeaseLost;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Conflict;
use App\Models\ExecutionCommand;
use App\Models\User;
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
        if ($claimed->command->command_type === ExecutionCommandType::FINALIZE) {
            app(ProcessExecutionFinalization::class)->process($claimed->command->id, $claimed->owner);

            return;
        }
        $execution = $claimed->command->execution;
        match ($claimed->command->command_type) {
            ExecutionCommandType::START => $execution->status === ExecutionStatus::CANCELLING
                ? $this->workflow->cancel($execution)
                : ($execution->status->isTerminal() ? null : $this->workflow->start($execution)),
            ExecutionCommandType::CANCEL => $this->workflow->cancel($execution),
            ExecutionCommandType::RESOLVE_CONFLICT => $this->resolve($claimed->command),
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

    /** The audited decision on a consolidation intervention continues the Execution. */
    private function resolve(ExecutionCommand $command): void
    {
        if (($command->payload['continues_execution'] ?? false) !== true) {
            return;
        }
        $conflict = Conflict::query()->where('execution_id', $command->execution_id)->where('key', (string) ($command->payload['conflict_key'] ?? ''))->first();
        $actor = User::query()->find($command->created_by);
        if ($conflict === null || $actor === null || ! in_array($conflict->type, RemoteConsolidationInterventions::TYPES, true)) {
            throw new ToolOperationBlocked('La decisión no corresponde a una intervención de la consolidación.');
        }
        try {
            $this->workflow->resolve($command->execution, $conflict, (string) ($command->payload['decision'] ?? ''), $actor);
        } catch (ToolOperationBlocked $exception) {
            // The destination is not ready to continue: the Execution keeps waiting with the reason.
            app(ExecutionEventRecorder::class)->record($command->execution, 'consolidator.continue_blocked', $conflict->step?->step_key,
                EventSeverity::WARNING, message: $exception->getMessage());
        }
    }
}
