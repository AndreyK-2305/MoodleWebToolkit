<?php

namespace App\Jobs;

use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Executions\ExecutionLifecycle;
use App\Domain\Executions\ExecutionQueueConfiguration;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\EventSeverity;
use App\Enums\ExecutionStatus;
use App\Models\ExecutionCommand;
use App\Models\ExecutionLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
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
            DB::transaction(function () use ($exception): void {
                $command = ExecutionCommand::query()
                    ->with('execution')
                    ->lockForUpdate()
                    ->find($this->commandId);

                if ($command === null || $command->processed_at !== null) {
                    return;
                }

                $execution = $command->execution;
                $command->processed_at = now();
                $command->save();

                ExecutionLog::query()->create([
                    'execution_id' => $execution->getKey(),
                    'stream' => 'SYSTEM',
                    'level' => 'ERROR',
                    'message' => 'La unidad asíncrona falló. Consulte el log técnico del worker.',
                    'context' => ['exception_type' => $exception?->getMessage() === null ? null : $exception::class],
                ]);

                if (in_array($execution->status, [ExecutionStatus::QUEUED, ExecutionStatus::RUNNING], true)) {
                    $failed = app(ExecutionLifecycle::class)
                        ->transitionForWorker($execution, ExecutionStatus::FAILED);
                    app(ExecutionEventRecorder::class)->recordNormalized($failed, new NormalizedToolEvent(
                        'tool.failed',
                        severity: EventSeverity::ERROR,
                        progress: $failed->progress,
                        message: 'La unidad asíncrona no pudo completarse.',
                    ));
                }
            });
        } catch (Throwable $reportable) {
            report($reportable);
        }
    }
}
