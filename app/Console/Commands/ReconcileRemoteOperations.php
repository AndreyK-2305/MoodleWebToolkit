<?php

namespace App\Console\Commands;

use App\Domain\Collector\CollectorRegisteredCommand;
use App\Domain\Executions\RemoteOperationCoordinator;
use App\Domain\Remote\RemoteAgentOperations;
use App\Domain\Remote\RemoteCollectionPreparation;
use App\Domain\Remote\RemoteCollectorExport;
use App\Domain\Remote\RemoteConsolidationBackup;
use App\Domain\Remote\RemoteConsolidationPreparation;
use App\Domain\Remote\RemoteConsolidationWorkflow;
use App\Domain\Remote\RemoteSourceCleanup;
use App\Domain\Remote\RemoteSourcePackagePublisher;
use App\Jobs\ReconcileRemoteAgentOperation;
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
            ->where(function ($query): void {
                $query->where(function ($active): void {
                    $active->whereIn('communication_state', ['CONNECTED', 'DEGRADED', 'UNREACHABLE', 'RECONCILING'])
                        ->whereNotNull('next_poll_at')->where('next_poll_at', '<=', now()->utc());
                })->orWhere(function ($collector): void {
                    $collector->where('command_key', CollectorRegisteredCommand::KEY)->where('communication_state', 'TERMINATED')
                        ->where('manual_intervention_required', false)
                        ->whereHas('execution', fn ($execution) => $execution->whereIn('status', ['QUEUED', 'RUNNING', 'CANCELLING', 'VERIFYING']))
                        ->where(fn ($due) => $due->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()->utc()));
                })->orWhere(function ($remote): void {
                    // A remote collection keeps moving after its export or upload terminates.
                    $remote->where('provider_key', RemoteAgentOperations::PROVIDER)->where('communication_state', 'TERMINATED')
                        ->whereIn('command_key', [RemoteCollectorExport::COMMAND, RemoteSourcePackagePublisher::COMMAND])
                        ->where('manual_intervention_required', false)
                        ->whereHas('execution', fn ($execution) => $execution->whereIn('status', ['RUNNING', 'CANCELLING', 'VERIFYING'])
                            ->whereHas('toolBinding', fn ($binding) => $binding->where('adapter_key', RemoteCollectionPreparation::ADAPTER_KEY)))
                        ->where(fn ($due) => $due->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()->utc()));
                })->orWhere(function ($consolidation): void {
                    // A consolidation run that ended still needs its final report read once.
                    $consolidation->where('provider_key', RemoteAgentOperations::PROVIDER)->where('communication_state', 'TERMINATED')
                        ->where('command_key', RemoteConsolidationWorkflow::COMMAND)->where('manual_intervention_required', false)
                        ->whereRaw("coalesce((evidence->'consolidator'->>'final')::boolean, false) = false")
                        ->whereHas('execution', fn ($execution) => $execution->whereIn('status', ['RUNNING', 'CANCELLING'])
                            ->whereHas('toolBinding', fn ($binding) => $binding->where('adapter_key', RemoteConsolidationPreparation::ADAPTER_KEY)))
                        ->where(fn ($due) => $due->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()->utc()));
                })->orWhere(function ($inspection): void {
                    // A finished plugin inspection fills the intervention the Execution waits on.
                    $inspection->where('provider_key', RemoteAgentOperations::PROVIDER)->where('communication_state', 'TERMINATED')
                        ->where('command_key', RemoteConsolidationWorkflow::INSPECT_COMMAND)->where('manual_intervention_required', false)
                        ->whereNull('evidence->inspection')
                        ->whereHas('execution', fn ($execution) => $execution->where('status', 'WAITING_USER_ACTION'))
                        ->where(fn ($due) => $due->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()->utc()));
                })->orWhere(function ($backup): void {
                    // A finished backup upload is verified and registered while the Execution is in REVIEW.
                    $backup->where('provider_key', RemoteAgentOperations::PROVIDER)->where('communication_state', 'TERMINATED')
                        ->where('command_key', RemoteConsolidationBackup::COMMAND)->where('manual_intervention_required', false)
                        ->whereNull('evidence->backup')
                        ->whereHas('execution', fn ($execution) => $execution->where('status', 'REVIEW'))
                        ->where(fn ($due) => $due->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()->utc()));
                })->orWhere(function ($cleanup): void {
                    // After REVIEW or closing, the source disk is freed; the service applies the backoff.
                    $cleanup->where('provider_key', RemoteAgentOperations::PROVIDER)->where('communication_state', 'TERMINATED')
                        ->whereIn('command_key', [RemoteCollectorExport::COMMAND, RemoteSourcePackagePublisher::COMMAND])
                        ->whereNull('evidence->cleanup')
                        ->whereRaw("coalesce((evidence->'cleanup_pending'->>'manual')::boolean, false) = false")
                        ->whereHas('execution', fn ($execution) => $execution->whereIn('status', RemoteSourceCleanup::STATUSES)
                            ->whereHas('toolBinding', fn ($binding) => $binding->where('adapter_key', RemoteCollectionPreparation::ADAPTER_KEY)));
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (RemoteOperation $operation) use ($operations, &$failed): void {
                try {
                    // Remote operations are observed through their agent, never by the local process inspector.
                    if ($operation->provider_key === RemoteAgentOperations::PROVIDER && config('queue.default') === 'sync') {
                        // Same job synchronously, so Collector exports also refresh their report.
                        dispatch_sync(new ReconcileRemoteAgentOperation((int) $operation->getKey()));
                    } elseif ($operation->provider_key === RemoteAgentOperations::PROVIDER) {
                        dispatch((new ReconcileRemoteAgentOperation((int) $operation->getKey()))
                            ->onConnection('redis-tool-runs')->onQueue('tool-runs'));
                    } elseif (config('queue.default') === 'sync' && $operation->command_key !== CollectorRegisteredCommand::KEY) {
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
