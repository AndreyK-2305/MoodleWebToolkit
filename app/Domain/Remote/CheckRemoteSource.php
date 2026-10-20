<?php

namespace App\Domain\Remote;

use App\Exceptions\RemoteAgentUnavailable;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Asks the agent whether a source profile on the server can run the
 * Collector. The profile itself is the server administrator's; the platform
 * only names it and records the closed result.
 */
class CheckRemoteSource
{
    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
    ) {}

    /** @return array{ready: bool, source: string, error_code: string|null, report: array<string, mixed>|null} */
    public function handle(Server $server, string $source, ?User $actor): array
    {
        $this->connections->assertEnabled();
        $report = null;
        $errorCode = null;
        try {
            $response = $this->agent->call($this->connections->target($server), 'source-check', [$source], 120);
            if ($response->ok) {
                $report = $response->data;
            } else {
                $errorCode = $response->errorCode;
            }
        } catch (RemoteAgentUnavailable $exception) {
            $errorCode = $exception->errorCode;
        }
        $ready = $report !== null && ($report['zip'] ?? false) === true && ($report['dom'] ?? false) === true
            && ($report['config_readable'] ?? false) === true && preg_match('/^8\.[1-3]\./', (string) ($report['php_version'] ?? '')) === 1;

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
}
