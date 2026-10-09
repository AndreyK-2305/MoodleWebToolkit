<?php

namespace App\Jobs;

use App\Domain\Collector\CollectorRegisteredCommand;
use App\Domain\Collector\CollectorWorkflow;
use App\Domain\Executions\RemoteOperationCoordinator;
use App\Models\RemoteOperation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReconcileRemoteOperation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 90;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $operationId) {}

    public function uniqueId(): string
    {
        return 'remote-operation:'.$this->operationId;
    }

    public function handle(RemoteOperationCoordinator $operations): void
    {
        $operation = RemoteOperation::query()->find($this->operationId);
        if ($operation !== null) {
            if ($operation->command_key === CollectorRegisteredCommand::KEY) {
                if ($operation->host_id !== (gethostname() ?: 'local')) {
                    return;
                }
                try {
                    app(CollectorWorkflow::class)->observe($operation);
                } catch (\Throwable) {
                    app(CollectorWorkflow::class)->deferObservation($operation);
                }
            } else {
                $operations->reconcile($operation);
            }
        }
    }
}
