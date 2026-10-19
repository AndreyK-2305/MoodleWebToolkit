<?php

namespace App\Domain\Collector;

use App\Domain\Executions\ExecutionCommandLease;
use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Executions\ExecutionLifecycle;
use App\Domain\Executions\LocalToolExecutionProvider;
use App\Domain\Executions\ProcessExecutionFinalization;
use App\Domain\Tools\CollectorAdapter;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Exceptions\ExecutionCommandLeaseLost;
use App\Exceptions\ToolOperationBlocked;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use Illuminate\Support\Facades\DB;

final class CollectorExecutionProvider
{
    public function __construct(private readonly ExecutionCommandLease $leases, private readonly CollectorWorkflow $workflow,
        private readonly LocalToolExecutionProvider $local, private readonly ExecutionLifecycle $lifecycle,
        private readonly ExecutionEventRecorder $events) {}

    public function execute(ExecutionCommand $command): void
    {
        $claimed = $this->leases->claim($command->id);
        if ($claimed === null) {
            return;
        }
        if ($claimed->command->command_type === ExecutionCommandType::FINALIZE) {
            app(ProcessExecutionFinalization::class)->process($claimed->command->id, $claimed->owner);

            return;
        }
        if ($claimed->command->command_type === ExecutionCommandType::CANCEL) {
            $this->cancel($claimed->command, $claimed->owner);
        } elseif ($claimed->command->command_type === ExecutionCommandType::START) {
            if ($claimed->command->execution->status === ExecutionStatus::CANCELLING) {
                $this->cancel($claimed->command, $claimed->owner);
            } elseif (! $claimed->command->execution->status->isTerminal()) {
                $execution = $claimed->command->execution;
                $step = $execution->steps()->where('step_key', 'collection')->firstOrFail();
                foreach (app(CollectorAdapter::class)->executeUnit($execution, $step) as $event) {
                    DB::transaction(function () use ($execution, $event): void {
                        $locked = Execution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
                        $operation = $locked->remoteOperations()->where('command_key', CollectorRegisteredCommand::KEY)->sole();
                        if (! $locked->events()->where('remote_operation_id', $operation->id)->where('type', $event->type)->exists()) {
                            $this->events->recordNormalized($locked, $event, operation: $operation);
                        }
                    }, attempts: 3);
                }
            }
        } else {
            throw new ToolOperationBlocked('El comando no está habilitado para el Recolector real.');
        }
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

    private function cancel(ExecutionCommand $command, string $owner): void
    {
        $operation = DB::transaction(function () use ($command, $owner) {
            $locked = $this->leases->lockCommand($command->id);
            if ($locked === null || ! $this->leases->isOwnedAndActive($locked, $owner)) {
                throw new ExecutionCommandLeaseLost;
            }
            $execution = $locked->execution;
            $operation = $execution->remoteOperations()->where('command_key', CollectorRegisteredCommand::KEY)->first();
            if ($operation !== null || $execution->status !== ExecutionStatus::CANCELLING) {
                return $operation;
            }
            foreach ($execution->steps()->whereIn('status', ['PENDING', 'RUNNING'])->get() as $step) {
                $step->forceFill(['status' => ExecutionStepStatus::CANCELLED, 'finished_at' => now()->utc()])->save();
            }
            $closed = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::CANCELLED);
            foreach ($closed->commands()->where('command_type', ExecutionCommandType::START)->whereNull('processed_at')->get() as $start) {
                $this->leases->finish($start);
            }
            $this->events->record($closed, 'collector.cancelled_before_launch', message: 'La ejecución se canceló antes de crear un proceso real.');
            AuditLog::query()->create(['project_id' => $closed->project_id, 'execution_id' => $closed->id,
                'action' => 'COLLECTOR_CANCELLED_BEFORE_LAUNCH', 'payload' => ['command_id' => $locked->id]]);

            return null;
        }, attempts: 3);
        if ($operation !== null) {
            if ($operation->host_id !== (gethostname() ?: 'local')) {
                throw new ToolOperationBlocked('La cancelación se procesa en el runner de esta operación.');
            }
            $this->local->cancel($operation);
            $this->workflow->observe($operation->fresh());
        }
    }
}
