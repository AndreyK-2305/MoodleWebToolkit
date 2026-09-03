<?php

namespace App\Console\Commands;

use App\Domain\Executions\ExecutionCommandDispatcher;
use App\Enums\ExecutionCommandType;
use App\Models\ExecutionCommand;
use Illuminate\Console\Command;
use Throwable;

class RecoverExecutionDispatches extends Command
{
    protected $signature = 'executions:recover-dispatches {--stale=5 : Minutos antes de volver a despachar}';

    protected $description = 'Vuelve a despachar comandos START persistidos que no llegaron al worker';

    public function handle(ExecutionCommandDispatcher $dispatcher): int
    {
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

        if ($failed > 0) {
            $this->error("{$failed} comando(s) continúan pendientes.");

            return self::FAILURE;
        }

        $this->info('Los comandos pendientes fueron revisados.');

        return self::SUCCESS;
    }
}
