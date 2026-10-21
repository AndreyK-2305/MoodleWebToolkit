<?php

namespace App\Domain\Remote;

use App\Enums\RemoteFunctionalState;
use App\Exceptions\RemoteAgentUnavailable;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Execution;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\ToolDistribution;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Runs Collector 7.4.2 on a remote source through collector.742 and reads its
 * structured state. Only whitelisted fields of the Collector's own status and
 * progress documents are kept; paths and console text are never stored.
 */
class RemoteCollectorExport
{
    public const COMMAND = 'collector.742';

    public const DISTRIBUTION_KEY = 'moodle-recolector-7.4.2-linux-tree';

    private const STATUS_FIELDS = ['schema_version', 'collector_version', 'source_id', 'state', 'stage', 'exit_code',
        'duration_seconds', 'workers_requested', 'workers_effective', 'updated_at_utc', 'ended_at_utc'];

    private const PROGRESS_FIELDS = ['stage', 'total_courses', 'completed_courses', 'failed_courses', 'pending_courses',
        'created_courses', 'reused_courses', 'resumed_courses', 'adopted_courses', 'active_workers', 'percent_complete',
        'estimated_remaining_seconds'];

    public function __construct(
        private readonly RemoteAgentOperations $operations,
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
    ) {}

    public function start(Execution $execution, Server $server, string $source, string $package, string $workers, ?User $actor): RemoteOperation
    {
        $distribution = $this->assertLaunchable($server, $source);

        return $this->operations->launch($execution, $server, 'collector-'.$package, self::COMMAND, [
            'distribution' => $distribution->distribution_sha256,
            'source' => $source,
            'package' => $package,
            'workers' => $workers,
        ], $actor);
    }

    /** The checks of a launch, usable before launching several sources so none starts alone. */
    public function assertLaunchable(Server $server, string $source): ToolDistribution
    {
        $this->connections->assertEnabled();
        $distribution = ToolDistribution::query()->where('key', self::DISTRIBUTION_KEY)->where('verification_state', 'VERIFIED')->first();
        $deployed = $server->metadata['remote_tools'][self::DISTRIBUTION_KEY]['tree_sha256'] ?? null;
        if ($distribution === null || ! is_string($deployed) || ! hash_equals($distribution->distribution_sha256, $deployed)) {
            throw new ToolOperationBlocked('El Recolector verificado del catálogo no está desplegado en '.$server->name.'.');
        }
        if (($server->metadata['remote_sources'][$source]['ready'] ?? false) !== true) {
            throw new ToolOperationBlocked('El perfil '.$source.' no superó la comprobación en '.$server->name.'.');
        }

        return $distribution;
    }

    /**
     * Reconciles the operation and stores the Collector's structured state.
     * A transport failure leaves the previous report untouched.
     */
    public function observe(RemoteOperation $operation): RemoteOperation
    {
        if ($operation->command_key !== self::COMMAND) {
            throw new ToolOperationBlocked('La operación no es una exportación del Recolector.');
        }
        $operation = $this->operations->reconcile($operation);
        if ($operation->process_id === null) {
            return $operation;
        }
        $server = Server::query()->where('uuid', (string) $operation->host_id)->firstOrFail();
        try {
            $response = $this->agent->call($this->connections->target($server), 'collector-report', [$operation->operation_uuid], 30);
        } catch (RemoteAgentUnavailable) {
            return $operation;
        }
        if (! $response->ok) {
            return $operation;
        }

        return $this->store($operation, $this->report($operation, $response->data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function report(RemoteOperation $operation, array $data): array
    {
        $package = (string) ($data['package'] ?? '');
        $status = $this->document($data['status_base64'] ?? null, self::STATUS_FIELDS);
        $progress = $this->document($data['progress_base64'] ?? null, self::PROGRESS_FIELDS);
        $outputs = [];
        foreach (is_array($data['outputs'] ?? null) ? $data['outputs'] : [] as $output) {
            if (is_array($output) && is_string($output['name'] ?? null) && is_int($output['size'] ?? null)) {
                $outputs[] = ['name' => $output['name'], 'size' => $output['size']];
            }
        }
        $sidecar = is_string($data['sidecar'] ?? null) ? $data['sidecar'] : null;
        $sha = null;
        if ($sidecar !== null && preg_match('/^([0-9a-f]{64})  ([a-z][a-z0-9_-]*\.zip)$/D', $sidecar, $match) === 1 && $match[2] === $package.'.zip') {
            $sha = $match[1];
        }
        $zip = collect($outputs)->firstWhere('name', $package.'.zip');
        // Ready only when every independent signal agrees; never inferred from one of them.
        $ready = $operation->functional_state === RemoteFunctionalState::SUCCEEDED && $operation->exit_code === 0
            && ($status['state'] ?? null) === 'success' && ($status['stage'] ?? null) === 'completed'
            && ($status['exit_code'] ?? null) === 0 && $sha !== null && is_array($zip) && $zip['size'] > 0;
        $total = $progress['total_courses'] ?? null;

        return [
            'package' => $package,
            'status' => $status,
            'progress' => $progress,
            // Real progress only: completed over known total, otherwise unknown.
            'percent' => is_int($total) && $total > 0 && is_int($progress['completed_courses'] ?? null)
                ? round($progress['completed_courses'] / $total * 100, 1) : null,
            'outputs' => $outputs,
            'package_sha256' => $sha,
            'package_size' => is_array($zip) ? $zip['size'] : null,
            'package_ready' => $ready,
            'observed_at' => is_string($data['observed_at'] ?? null) ? $data['observed_at'] : null,
        ];
    }

    /** @param array<string, mixed> $report */
    private function store(RemoteOperation $operation, array $report): RemoteOperation
    {
        return DB::transaction(function () use ($operation, $report): RemoteOperation {
            $locked = RemoteOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            $evidence = $locked->evidence ?? [];
            $evidence['collector'] = $report;
            $locked->forceFill(['evidence' => $evidence])->save();

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>|null
     */
    private function document(mixed $encoded, array $fields): ?array
    {
        if (! is_string($encoded) || $encoded === 'too_large') {
            return null;
        }
        $decoded = json_decode((string) base64_decode($encoded, true), true);
        if (! is_array($decoded)) {
            return null;
        }
        $kept = [];
        foreach ($fields as $field) {
            $value = $decoded[$field] ?? null;
            if (is_int($value) || is_float($value) || is_bool($value) || (is_string($value) && preg_match('/^[A-Za-z0-9 ._:+-]{0,64}$/D', $value) === 1)) {
                $kept[$field] = $value;
            }
        }

        return $kept;
    }
}
