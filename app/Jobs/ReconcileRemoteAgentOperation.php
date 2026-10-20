<?php

namespace App\Jobs;

use App\Domain\Remote\RemoteAgentOperations;
use App\Domain\Remote\RemoteCollectorExport;
use App\Domain\Remote\RemoteSourcePackagePublisher;
use App\Models\RemoteOperation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One bounded observation of a remote operation. It holds no connection
 * between runs: the scheduler dispatches it again while the operation is due.
 */
final class ReconcileRemoteAgentOperation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 150;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $operationId) {}

    public function uniqueId(): string
    {
        return 'remote-agent-operation:'.$this->operationId;
    }

    public function handle(RemoteAgentOperations $operations, RemoteCollectorExport $collector, RemoteSourcePackagePublisher $publisher): void
    {
        $operation = RemoteOperation::query()->where('provider_key', RemoteAgentOperations::PROVIDER)->find($this->operationId);
        if ($operation === null) {
            return;
        }
        // A Collector export is reconciled together with its structured report, so the
        // final report is captured in the same pass that observes the termination.
        if ($operation->command_key === RemoteCollectorExport::COMMAND) {
            $collector->observe($operation);
        } elseif ($operation->command_key === RemoteSourcePackagePublisher::COMMAND) {
            // Registers the SourcePackage in the same pass that observes a successful upload.
            $publisher->observe($operation);
        } else {
            $operations->reconcile($operation);
        }
    }
}
