<?php

namespace App\Console\Commands;

use App\Domain\Executions\RemoteOperationCoordinator;
use App\Jobs\ReconcileRemoteOperation;
use App\Models\RemoteOperation;
use Illuminate\Console\Command;
use Throwable;

class ReconcileRemoteOperations extends Command
{
    protected $signature = 'executions:reconcile-remote-operations {--limit=100}';

    protected $description = 'Reconcilia procesos registrados sin volver a lanzar operaciones existentes';

    public function handle(RemoteOperationCoordinator $operations): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $failed = 0;

        RemoteOperation::query()
            ->whereIn('communication_state', ['CONNECTED', 'DEGRADED', 'UNREACHABLE', 'RECONCILING'])
            ->whereNotNull('next_poll_at')
            ->where('next_poll_at', '<=', now()->utc())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (RemoteOperation $operation) use ($operations, &$failed): void {
                try {
                    if (config('queue.default') === 'sync') {
                        $operations->reconcile($operation);
                    } else {
                        dispatch((new ReconcileRemoteOperation((int) $operation->getKey()))
                            ->onConnection('redis-tool-runs')->onQueue('tool-runs'));
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                }
            });

        if ($failed > 0) {
            $this->error("{$failed} operación(es) no pudieron reconciliarse.");

            return self::FAILURE;
        }

        $this->info('Las operaciones locales registradas se reconciliaron sin despachos duplicados.');

        return self::SUCCESS;
    }
}
