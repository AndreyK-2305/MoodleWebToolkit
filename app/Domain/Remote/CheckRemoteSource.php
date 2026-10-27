<?php

namespace App\Domain\Remote;

use App\Exceptions\RemoteAgentUnavailable;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Asks the agent whether a source profile on the server can run the
 * Collector. The platform names the profile and records the closed result;
 * the agent never returns the profile's paths.
 */
class CheckRemoteSource
{
    public const FIELDS = ['source', 'runtime', 'run_as', 'php_version', 'zip', 'dom', 'config_readable', 'moodle_release', 'moodle_version',
        'dataroot_free_bytes', 'dataroot_writable', 'state_free_bytes', 'cpus', 'aws_cli_version', 'aws_cli_v2'];

    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
    ) {}

    /** @return array{ready: bool, source: string, error_code: string|null, report: array<string, mixed>|null} */
    public function handle(Server $server, string $source, ?User $actor): array
    {
        $this->connections->assertEnabled();
        $data = null;
        $errorCode = null;
        try {
            $response = $this->agent->call($this->connections->target($server), 'source-check', [$source], 120);
            if ($response->ok) {
                $data = $response->data;
            } else {
                $errorCode = $response->errorCode;
            }
        } catch (RemoteAgentUnavailable $exception) {
            $errorCode = $exception->errorCode;
        }

        return $this->record($server, $source, $actor, $data, $errorCode);
    }

    /**
     * Keeps the closed result of a source-check, run on its own or right after the
     * profile was created from the platform.
     *
     * @param  array<string, mixed>|null  $data
     * @return array{ready: bool, source: string, error_code: string|null, report: array<string, mixed>|null}
     */
    public function record(Server $server, string $source, ?User $actor, ?array $data, ?string $errorCode): array
    {
        // Closed fields only: the agent never returns paths, and nothing else is kept.
        $report = $data === null ? null : array_intersect_key($data, array_flip(self::FIELDS));
        $ready = $report !== null && ($report['zip'] ?? false) === true && ($report['dom'] ?? false) === true
            && ($report['config_readable'] ?? false) === true && preg_match('/^8\.[1-3]\./', (string) ($report['php_version'] ?? '')) === 1
            // Moodle must load its configuration and be able to write its backups to moodledata.
            && is_string($report['moodle_release'] ?? null) && ($report['dataroot_writable'] ?? false) === true;

        DB::transaction(function () use ($server, $source, $actor, $report, $errorCode, $ready): void {
            $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            $metadata = $locked->metadata ?? [];
            $metadata['remote_sources'][$source] = ['ready' => $ready, 'checked_at' => now()->utc()->toIso8601String(),
                'error_code' => $errorCode, 'report' => $report];
            $locked->update(['metadata' => $metadata]);
            AuditLog::query()->create([
                'actor_id' => $actor?->getKey(), 'project_id' => $locked->project_id, 'action' => 'REMOTE_SOURCE_CHECKED',
                'auditable_type' => $locked->getMorphClass(), 'auditable_id' => $locked->getKey(),
                'payload' => ['source' => $source, 'ready' => $ready, 'error_code' => $errorCode, 'report' => $report],
            ]);
        });

        return ['ready' => $ready, 'source' => $source, 'error_code' => $errorCode, 'report' => $report];
    }

    /**
     * The workers the Recolector may use on a checked profile: «auto» resolves to CPU - 1
     * (at least 1), and an explicit value goes from 1 to CPU - 1. The Recolector 7.4.2
     * accepts at most 4, so both are capped there.
     */
    public static function maxWorkers(?int $cpus): int
    {
        return $cpus === null ? 1 : max(1, min(4, $cpus - 1));
    }
}
