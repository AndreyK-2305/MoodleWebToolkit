<?php

namespace App\Domain\Remote;

use App\Exceptions\RemoteAgentUnavailable;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Asks the agent whether a destination profile on the server can run the
 * Consolidator. The runtime and its .env are the server administrator's;
 * the platform names the profile and keeps closed facts only, never a value
 * of the .env beyond the public URL the profile declares.
 */
class CheckRemoteDestination
{
    public const DISTRIBUTION_KEY = 'moodle-consolidador-8.1.0-linux-tree';

    /** Below this the Moodle 5.2.1 target, MariaDB and the restore workers compete for memory. */
    public const MIN_MEMORY_BYTES = 3_500_000_000;

    /** The Consolidator's own preflight warns under 20 GiB free. */
    public const MIN_FREE_BYTES = 20 * 1024 * 1024 * 1024;

    private const FIELDS = ['destination', 'public_url', 'env_present', 'env_protected', 'env_matches_url', 'env_complete', 'tool_tree',
        'tool_present', 'engine_intact', 'docker_version', 'compose_version', 'flock', 'python3', 'cpus', 'memory_bytes', 'runtime_free_bytes',
        'target_running', 'destination_written', 'run_as', 'run_as_docker', 'runtime_owned', 'target_volumes', 'assistant_started',
        'copias_packages', 'server_profiles'];

    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
    ) {}

    /** @return array{ready: bool, destination: string, error_code: string|null, blockers: list<string>, warnings: list<string>, report: array<string, mixed>|null} */
    public function handle(Server $server, string $destination, ?User $actor): array
    {
        $this->connections->assertEnabled();
        $report = null;
        $errorCode = null;
        try {
            $response = $this->agent->call($this->connections->target($server), 'destination-check', [$destination], 180);
            if ($response->ok) {
                $report = array_intersect_key($response->data, array_flip(self::FIELDS));
            } else {
                $errorCode = $response->errorCode;
            }
        } catch (RemoteAgentUnavailable $exception) {
            $errorCode = $exception->errorCode;
        }
        $deployed = $server->metadata['remote_tools'][self::DISTRIBUTION_KEY]['tree_sha256'] ?? null;
        [$blockers, $warnings] = $report === null ? [[], []] : self::assess($report, is_string($deployed) ? $deployed : null);
        $ready = $report !== null && $blockers === [];

        DB::transaction(function () use ($server, $destination, $actor, $report, $errorCode, $ready, $blockers, $warnings): void {
            $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            $metadata = $locked->metadata ?? [];
            $metadata['remote_destinations'][$destination] = ['ready' => $ready, 'checked_at' => now()->utc()->toIso8601String(),
                'error_code' => $errorCode, 'blockers' => $blockers, 'warnings' => $warnings, 'report' => $report];
            $locked->update(['metadata' => $metadata]);
            AuditLog::query()->create([
                'actor_id' => $actor?->getKey(), 'project_id' => $locked->project_id, 'action' => 'REMOTE_DESTINATION_CHECKED',
                'auditable_type' => $locked->getMorphClass(), 'auditable_id' => $locked->getKey(),
                'payload' => ['destination' => $destination, 'ready' => $ready, 'error_code' => $errorCode, 'blockers' => $blockers, 'report' => $report],
            ]);
        });

        return ['ready' => $ready, 'destination' => $destination, 'error_code' => $errorCode, 'blockers' => $blockers, 'warnings' => $warnings, 'report' => $report];
    }

    /**
     * A destination that already holds a site: services or volumes of a previous Moodle,
     * an assistant that started, packages in copias/ or writes of the Consolidator.
     *
     * @param  array<string, mixed>  $report
     */
    public static function used(array $report): bool
    {
        return ($report['target_running'] ?? false) === true || ($report['destination_written'] ?? false) === true
            || ($report['assistant_started'] ?? false) === true
            || (is_int($report['target_volumes'] ?? null) && $report['target_volumes'] > 0)
            || (is_int($report['copias_packages'] ?? null) && $report['copias_packages'] > 0);
    }

    /**
     * Closed blocker and warning codes for a destination-check report.
     *
     * @param  array<string, mixed>  $report
     * @return array{list<string>, list<string>}
     */
    public static function assess(array $report, ?string $deployedTree): array
    {
        $blockers = [];
        $required = ['env_present' => 'env_missing', 'env_protected' => 'env_unprotected', 'env_complete' => 'env_incomplete',
            'env_matches_url' => 'env_url_mismatch', 'tool_present' => 'tool_missing', 'flock' => 'flock_missing', 'python3' => 'python3_missing',
            // The Consolidator runs as the runtime's unprivileged owner, which reaches Docker through its group.
            'runtime_owned' => 'runtime_not_owned', 'run_as_docker' => 'run_as_not_docker'];
        foreach ($required as $field => $code) {
            if (($report[$field] ?? false) !== true) {
                $blockers[] = $code;
            }
        }
        if (! is_string($report['public_url'] ?? null) || ! str_starts_with($report['public_url'], 'https://')) {
            $blockers[] = 'public_url_not_https';
        }
        if ($deployedTree === null || ($report['tool_tree'] ?? null) !== $deployedTree) {
            $blockers[] = 'tool_tree_mismatch';
        }
        // Once the Consolidator wrote to the destination its resolutions may legitimately differ from the shipped ones.
        if (($report['engine_intact'] ?? false) !== true && ($report['destination_written'] ?? false) !== true) {
            $blockers[] = 'engine_modified';
        }
        if (! is_string($report['docker_version'] ?? null) || $report['docker_version'] === '') {
            $blockers[] = 'docker_unavailable';
        }
        if (! is_string($report['compose_version'] ?? null) || preg_match('/^v?2\./', $report['compose_version']) !== 1) {
            $blockers[] = 'compose_v2_missing';
        }

        $warnings = [];
        if (is_int($report['memory_bytes'] ?? null) && $report['memory_bytes'] < self::MIN_MEMORY_BYTES) {
            $warnings[] = 'low_memory';
        }
        if (is_int($report['runtime_free_bytes'] ?? null) && $report['runtime_free_bytes'] < self::MIN_FREE_BYTES) {
            $warnings[] = 'low_disk';
        }
        // A site already on the destination is shown when preparing it, where the administrator decides.
        if (($report['destination_written'] ?? false) === true) {
            $warnings[] = 'destination_written';
        }
        // The Consolidator's Compose project name is fixed: two runtimes on one server share it.
        if (is_int($report['server_profiles'] ?? null) && $report['server_profiles'] > 1) {
            $warnings[] = 'shared_server';
        }

        return [$blockers, $warnings];
    }
}
