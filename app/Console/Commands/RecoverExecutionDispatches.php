<?php

namespace App\Console\Commands;

use App\Domain\Executions\ExecutionCommandDispatcher;
use App\Domain\Executions\ExecutionCommandLease;
use App\Domain\Executions\ExecutionFailureCloser;
use App\Enums\ExecutionCommandType;
use App\Models\ExecutionCommand;
use Illuminate\Console\Command;
use Throwable;

class RecoverExecutionDispatches extends Command
{
    protected $signature = 'executions:recover-dispatches {--stale=5 : Minutos antes de volver a despachar}';

    protected $description = 'Recupera despachos pendientes y cierra comandos abandonados';

    public function handle(
        ExecutionCommandDispatcher $dispatcher,
        ExecutionCommandLease $leases,
        ExecutionFailureCloser $failureCloser,
    ): int {
        $staleBefore = now()->subMinutes(max(1, (int) $this->option('stale')));
        $failed = 0;

        ExecutionCommand::query()
            ->where('command_type', ExecutionCommandType::START->value)
            ->whereNull('processed_at')
            ->whereNull('processing_started_at')
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', $staleBefore);
            })
            ->orderBy('id')
            ->eachById(function (ExecutionCommand $command) use ($dispatcher, &$failed): void {
                try {
                    $dispatcher->dispatch($command);
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                }
            });

        ExecutionCommand::query()
            ->where('command_type', ExecutionCommandType::START->value)
            ->whereNull('processed_at')
            ->whereNotNull('processing_started_at')
            ->where(function ($query) use ($leases): void {
                $query->where('lease_expires_at', '<=', now()->utc())
                    ->orWhere(function ($legacy) use ($leases): void {
                        $legacy->whereNull('lease_expires_at')
                            ->where('processing_started_at', '<=', $leases->legacyAbandonedBefore());
                    });
            })
            ->orderBy('id')
            ->eachById(function (ExecutionCommand $command) use ($failureCloser, &$failed): void {
                try {
                    $failureCloser->closeAbandoned((int) $command->getKey());
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                }
            });

        if ($failed > 0) {
            $this->error("{$failed} comando(s) continúan pendientes.");

            return self::FAILURE;
        }

        $this->info('Los despachos pendientes y comandos abandonados fueron revisados.');

        return self::SUCCESS;
    }
}
