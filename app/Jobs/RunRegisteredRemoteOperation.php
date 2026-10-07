<?php

namespace App\Jobs;

use App\Domain\Executions\RemoteOperationCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunRegisteredRemoteOperation implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    /** @param array<string, string> $parameters */
    public function __construct(
        public readonly int $operationId,
        public readonly string $commandKey,
        public readonly array $parameters,
        public readonly string $workingDirectory,
    ) {}

    public function handle(RemoteOperationCoordinator $operations): void
    {
        $operations->runScheduled($this->operationId, $this->commandKey, $this->parameters, $this->workingDirectory);
    }
}
