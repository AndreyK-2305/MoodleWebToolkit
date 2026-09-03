<?php

namespace App\Jobs;

use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\ExecutionFailureCloser;
use App\Domain\Executions\ExecutionQueueConfiguration;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Models\ExecutionCommand;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunExecutionUnit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = ExecutionQueueConfiguration::JOB_TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $commandId) {}

    public function handle(ExecutionProvider $provider, ToolAdapter $adapter): void
    {
        $command = ExecutionCommand::query()->findOrFail($this->commandId);
        $provider->execute($command, $adapter);
    }

    public function failed(?Throwable $exception): void
    {
        try {
            app(ExecutionFailureCloser::class)->closeWorkerFailure($this->commandId, $exception);
        } catch (Throwable $reportable) {
            report($reportable);
        }
    }
}
