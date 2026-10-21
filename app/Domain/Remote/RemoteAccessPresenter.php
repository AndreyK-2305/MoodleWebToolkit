<?php

namespace App\Domain\Remote;

use App\Models\Connection;
use App\Models\Project;
use App\Models\RemoteOperation;
use App\Models\Server;

/**
 * Inertia props for the remote access panel. Only non-secret, closed values
 * leave the backend: no secret reference, no full host key, no remote output.
 */
class RemoteAccessPresenter
{
    private const PROBE_FIELDS = ['hostname', 'os_name', 'kernel', 'arch', 'cpus', 'memory_bytes', 'launcher',
        'systemd_version', 'bash_version', 'disk_total_bytes', 'disk_free_bytes', 'php_version', 'docker_version',
        'pwsh_version', 'aws_cli_version', 'observed_at'];

    public function __construct(private readonly RemoteAgent $agent) {}

    /** @return array{enabled: bool, agent_version: string|null, servers: list<array<string, mixed>>} */
    public function present(Project $project): array
    {
        $enabled = (bool) config('toolkit.features.remote_agent.enabled', false);
        if (! $enabled) {
            return ['enabled' => false, 'agent_version' => null, 'servers' => []];
        }
        $servers = $project->servers()->with('connections')->orderBy('role')->orderBy('name')->get();
        $operations = RemoteOperation::query()->where('provider_key', RemoteAgentOperations::PROVIDER)
            ->whereIn('host_id', $servers->pluck('uuid'))->latest('id')->limit(50)->get()->groupBy('host_id');

        return [
            'enabled' => true,
            'agent_version' => $this->agent->distribution()['version'],
            'servers' => array_values($servers->map(fn (Server $server): array => [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'role' => $server->role->value,
                'host' => $server->host,
                'connection' => $this->connection($server->connections->firstWhere('name', RemoteServerConnections::PRIMARY)),
                'evidence' => $this->evidence($server),
                'collector_deployed' => is_string($server->metadata['remote_tools'][RemoteCollectorExport::DISTRIBUTION_KEY]['tree_sha256'] ?? null),
                'sources' => $this->sources($server),
                'operations' => ($operations->get($server->uuid) ?? collect())->take(5)->map(fn (RemoteOperation $operation): array => [
                    'uuid' => $operation->operation_uuid,
                    'command_key' => $operation->command_key,
                    'communication_state' => $operation->communication_state->value,
                    'functional_state' => $operation->functional_state->value,
                    'exit_code' => $operation->exit_code,
                    'manual_intervention_required' => $operation->manual_intervention_required,
                    'last_error' => $operation->last_error,
                    'started_at' => $operation->started_at?->toIso8601String(),
                    'terminated_at' => $operation->terminated_at?->toIso8601String(),
                    'last_observed_at' => $operation->last_observed_at?->toIso8601String(),
                    'collector' => $this->collector($operation),
                ])->values()->all(),
            ])->all()),
        ];
    }

    /** @return list<array{id: string, ready: bool, checked_at: string|null}> */
    private function sources(Server $server): array
    {
        $sources = [];
        foreach (is_array($server->metadata['remote_sources'] ?? null) ? $server->metadata['remote_sources'] : [] as $id => $source) {
            if (is_string($id) && is_array($source)) {
                $sources[] = ['id' => $id, 'ready' => ($source['ready'] ?? false) === true,
                    'checked_at' => is_string($source['checked_at'] ?? null) ? $source['checked_at'] : null];
            }
        }

        return $sources;
    }

    /** @return array<string, mixed>|null */
    private function collector(RemoteOperation $operation): ?array
    {
        $report = $operation->evidence['collector'] ?? null;
        if (! is_array($report)) {
            return null;
        }

        return [
            'stage' => $report['progress']['stage'] ?? $report['status']['stage'] ?? null,
            'completed_courses' => $report['progress']['completed_courses'] ?? null,
            'total_courses' => $report['progress']['total_courses'] ?? null,
            'failed_courses' => $report['progress']['failed_courses'] ?? null,
            'percent' => $report['percent'] ?? null,
            'package_ready' => ($report['package_ready'] ?? false) === true,
            'package_size' => $report['package_size'] ?? null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function connection(?Connection $connection): ?array
    {
        if ($connection === null) {
            return null;
        }
        $configuration = $connection->configuration ?? [];
        $hostKey = is_string($configuration['host_key'] ?? null) ? $configuration['host_key'] : null;

        return [
            'type' => $connection->type,
            'status' => $connection->status->value,
            'instance_id' => $configuration['instance_id'] ?? null,
            'region' => $configuration['region'] ?? null,
            'host' => $configuration['host'] ?? null,
            'port' => $configuration['port'] ?? null,
            'user' => $configuration['user'] ?? null,
            'use_sudo' => ($configuration['use_sudo'] ?? false) === true,
            'secret_version' => $configuration['secret_version'] ?? null,
            'has_secret_reference' => $connection->secret_reference !== null,
            // The key type and a fingerprint identify the pinned key without echoing it.
            'host_key_type' => $hostKey === null ? null : strtok($hostKey, ' '),
            'host_key_fingerprint' => $hostKey === null ? null : $this->fingerprint($hostKey),
        ];
    }

    /** @return array<string, mixed>|null */
    private function evidence(Server $server): ?array
    {
        $evidence = $server->metadata['remote_probe'] ?? null;
        if (! is_array($evidence)) {
            return null;
        }
        $probe = is_array($evidence['probe'] ?? null) ? array_intersect_key($evidence['probe'], array_flip(self::PROBE_FIELDS)) : null;

        return [
            'checked_at' => $evidence['checked_at'] ?? null,
            'status' => $evidence['status'] ?? null,
            'agent_version' => $evidence['agent_version'] ?? null,
            'checks' => is_array($evidence['checks'] ?? null) ? array_values($evidence['checks']) : [],
            'probe' => $probe,
        ];
    }

    private function fingerprint(string $hostKey): ?string
    {
        $parts = explode(' ', $hostKey);
        $blob = base64_decode($parts[1] ?? '', true);

        return $blob === false ? null : 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }
}
