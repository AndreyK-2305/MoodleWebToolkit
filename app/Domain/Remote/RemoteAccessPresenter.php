<?php

namespace App\Domain\Remote;

use App\Enums\ServerRole;
use App\Models\Connection;
use App\Models\Project;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\SourcePackage;
use Illuminate\Support\Facades\Gate;

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

    /** @return array{enabled: bool, agent_version: string|null, available_packages: list<array<string, mixed>>, servers: list<array<string, mixed>>} */
    public function present(Project $project): array
    {
        $enabled = (bool) config('toolkit.features.remote_agent.enabled', false);
        if (! $enabled) {
            return ['enabled' => false, 'agent_version' => null, 'available_packages' => [], 'servers' => []];
        }
        $servers = $project->servers()->with('connections')->orderBy('role')->orderBy('name')->get();
        $operations = RemoteOperation::query()->where('provider_key', RemoteAgentOperations::PROVIDER)
            ->whereIn('host_id', $servers->pluck('uuid'))->latest('id')->limit(50)->get()->groupBy('host_id');

        return [
            'enabled' => true,
            'agent_version' => $this->agent->distribution()['version'],
            'available_packages' => $servers->contains(fn (Server $server): bool => $server->role === ServerRole::DESTINATION) ? $this->availablePackages() : [],
            'servers' => array_values($servers->map(fn (Server $server): array => [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'role' => $server->role->value,
                'host' => $server->host,
                'connection' => $this->connection($server->connections->firstWhere('name', RemoteServerConnections::PRIMARY)),
                'evidence' => $this->evidence($server),
                'collector_deployed' => is_string($server->metadata['remote_tools'][RemoteCollectorExport::DISTRIBUTION_KEY]['tree_sha256'] ?? null),
                'sources' => $this->sources($server),
                'sources_detected_at' => $server->metadata['remote_sources_detected']['detected_at'] ?? null,
                'consolidator' => $this->consolidator($server),
                'destinations' => $this->destinations($server),
                'destinations_detected_at' => $server->metadata['remote_destinations_detected']['detected_at'] ?? null,
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

    /** @return list<array<string, mixed>> */
    private function sources(Server $server): array
    {
        $checked = is_array($server->metadata['remote_sources'] ?? null) ? $server->metadata['remote_sources'] : [];
        $listed = $server->metadata['remote_sources_detected']['sources'] ?? null;
        $detected = collect(is_array($listed) ? $listed : [])->filter(fn ($source): bool => is_array($source)
            && is_string($source['id'] ?? null))->keyBy('id');
        $sources = [];
        foreach (collect(array_keys($checked))->merge($detected->keys())->filter(fn ($id): bool => is_string($id))->unique()->sort()->values() as $id) {
            $check = is_array($checked[$id] ?? null) ? $checked[$id] : null;
            $report = is_array($check['report'] ?? null) ? $check['report'] : [];
            $sources[] = ['id' => $id, 'ready' => ($check['ready'] ?? false) === true,
                'checked_at' => is_string($check['checked_at'] ?? null) ? $check['checked_at'] : null,
                'check_error' => is_string($check['error_code'] ?? null) ? $check['error_code'] : null,
                'detected' => $detected->has($id), 'valid' => $detected->has($id) ? $detected->get($id)['valid'] === true : null,
                'profile_error' => $detected->get($id)['error_code'] ?? null, 'runtime' => $report['runtime'] ?? $detected->get($id)['runtime'] ?? null,
                'moodle_release' => $report['moodle_release'] ?? null, 'php_version' => $report['php_version'] ?? null,
                'dataroot_free_bytes' => $report['dataroot_free_bytes'] ?? null, 'state_free_bytes' => $report['state_free_bytes'] ?? null];
        }

        return $sources;
    }

    /** @return array{deployed: bool, deployment_state: string|null, deployment_error: string|null, updated_at: string|null} */
    private function consolidator(Server $server): array
    {
        $deployment = $server->metadata['remote_tool_deployments'][CheckRemoteDestination::DISTRIBUTION_KEY] ?? null;
        $deployment = is_array($deployment) ? $deployment : [];

        $tree = $server->metadata['remote_tools'][CheckRemoteDestination::DISTRIBUTION_KEY]['tree_sha256'] ?? null;

        return ['deployed' => is_string($tree), 'tree_sha256' => is_string($tree) ? $tree : null,
            'deployment_state' => in_array($deployment['state'] ?? null, ['QUEUED', 'RUNNING', 'DEPLOYED', 'FAILED'], true) ? $deployment['state'] : null,
            'deployment_error' => is_string($deployment['error_code'] ?? null) ? $deployment['error_code'] : null,
            'updated_at' => is_string($deployment['updated_at'] ?? null) ? $deployment['updated_at'] : null];
    }

    /** @return list<array<string, mixed>> */
    private function destinations(Server $server): array
    {
        $checked = is_array($server->metadata['remote_destinations'] ?? null) ? $server->metadata['remote_destinations'] : [];
        $listed = $server->metadata['remote_destinations_detected']['destinations'] ?? null;
        $detected = collect(is_array($listed) ? $listed : [])->filter(fn ($destination): bool => is_array($destination)
            && is_string($destination['id'] ?? null))->keyBy('id');
        $destinations = [];
        foreach (collect(array_keys($checked))->merge($detected->keys())->filter(fn ($id): bool => is_string($id))->unique()->sort()->values() as $id) {
            $check = is_array($checked[$id] ?? null) ? $checked[$id] : null;
            $report = is_array($check['report'] ?? null) ? $check['report'] : [];
            $destinations[] = ['id' => $id, 'ready' => ($check['ready'] ?? false) === true,
                'checked_at' => is_string($check['checked_at'] ?? null) ? $check['checked_at'] : null,
                'check_error' => is_string($check['error_code'] ?? null) ? $check['error_code'] : null,
                'blockers' => array_values(array_filter($check['blockers'] ?? [], 'is_string')),
                'warnings' => array_values(array_filter($check['warnings'] ?? [], 'is_string')),
                'detected' => $detected->has($id), 'valid' => $detected->has($id) ? $detected->get($id)['valid'] === true : null,
                'profile_error' => $detected->get($id)['error_code'] ?? null,
                'public_url' => $report['public_url'] ?? $detected->get($id)['public_url'] ?? null,
                'docker_version' => $report['docker_version'] ?? null, 'memory_bytes' => $report['memory_bytes'] ?? null,
                'runtime_free_bytes' => $report['runtime_free_bytes'] ?? null, 'target_running' => ($report['target_running'] ?? false) === true,
                'destination_written' => ($report['destination_written'] ?? false) === true,
                'in_use' => CheckRemoteDestination::used($report),
                'target_volumes' => is_int($report['target_volumes'] ?? null) ? $report['target_volumes'] : null,
                'copias_packages' => is_int($report['copias_packages'] ?? null) ? $report['copias_packages'] : null,
                'preparation' => $this->preparation($server, $id),
                'copias' => $this->copias($server, $id),
                'fetches' => $this->fetches($server, $id)];
        }

        return $destinations;
    }

    /** @return array{listed_at: string|null, write_locked: bool, packages: list<array<string, mixed>>}|null */
    private function copias(Server $server, string $destination): ?array
    {
        $copias = $server->metadata['remote_destination_copias'][$destination] ?? null;
        if (! is_array($copias)) {
            return null;
        }

        return ['listed_at' => is_string($copias['listed_at'] ?? null) ? $copias['listed_at'] : null,
            'write_locked' => ($copias['write_locked'] ?? false) === true,
            'packages' => array_values(array_map(fn (array $package): array => array_intersect_key($package,
                array_flip(['name', 'size', 'sidecar_sha256', 'source_package_uuid', 'source_id'])),
                array_filter(is_array($copias['packages'] ?? null) ? $copias['packages'] : [], 'is_array')))];
    }

    /** @return list<array<string, mixed>> */
    private function fetches(Server $server, string $destination): array
    {
        $fetches = $server->metadata['remote_destination_fetches'][$destination] ?? [];
        $list = [];
        foreach (is_array($fetches) ? $fetches : [] as $packageUuid => $fetch) {
            if (is_string($packageUuid) && is_array($fetch)) {
                $list[] = ['source_package_uuid' => $packageUuid, ...array_intersect_key($fetch, array_flip(['package_name', 'source_id', 'sha256', 'size',
                    'state', 'error_code', 'error_line', 'name', 'already_present', 'launched_at', 'finished_at', 'observe_error']))];
            }
        }
        usort($list, fn (array $left, array $right): int => strcmp((string) ($right['launched_at'] ?? ''), (string) ($left['launched_at'] ?? '')));

        return array_slice($list, 0, 20);
    }

    /**
     * Sealed packages stored in S3 that the viewer may bring to a destination.
     *
     * @return list<array<string, mixed>>
     */
    private function availablePackages(): array
    {
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        return array_values(SourcePackage::query()->with('project:id,uuid,name')->where('validation_state', 'VALID')->where('availability', 'AVAILABLE')
            ->whereNull('revoked_at')->whereHas('artifact', fn ($artifact) => $artifact->where('storage_mode', 'REFERENCE')->where('disk', 's3'))
            ->latest('id')->limit(50)->get()
            ->filter(fn (SourcePackage $package): bool => $package->project !== null && Gate::forUser($user)->allows('view', $package->project))
            ->map(fn (SourcePackage $package): array => ['uuid' => $package->uuid, 'name' => $package->name, 'source_id' => $package->source_id,
                'sha256' => $package->sha256, 'size' => $package->size_bytes, 'project' => $package->project?->name])
            ->all());
    }

    /** @return array<string, mixed>|null */
    private function preparation(Server $server, string $destination): ?array
    {
        $preparation = $server->metadata['remote_destination_preparations'][$destination] ?? null;
        if (! is_array($preparation)) {
            return null;
        }
        $string = fn (string $key): ?string => is_string($preparation[$key] ?? null) ? $preparation[$key] : null;

        return ['state' => $string('state'), 'launched_at' => $string('launched_at'), 'finished_at' => $string('finished_at'),
            'observed_at' => $string('observed_at'), 'exit_code' => is_int($preparation['exit_code'] ?? null) ? $preparation['exit_code'] : null,
            'error_code' => $string('error_code'), 'observe_error' => $string('observe_error'), 'last_error_line' => $string('last_error_line'),
            'markers' => array_values(array_intersect(PrepareRemoteDestination::MARKERS, (array) ($preparation['markers'] ?? []))),
            // Already redacted when read; only the end is shown.
            'tail' => mb_substr((string) ($preparation['tail'] ?? ''), -4000)];
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
