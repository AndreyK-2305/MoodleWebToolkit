<?php

namespace App\Jobs;

use App\Domain\Remote\RemoteAgentOperations;
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

    public function handle(RemoteAgentOperations $operations): void
    {
        $operation = RemoteOperation::query()->where('provider_key', RemoteAgentOperations::PROVIDER)->find($this->operationId);
        if ($operation !== null) {
            $operations->reconcile($operation);
        }
    }
}
