<?php

namespace App\Domain\Remote;

use App\Exceptions\RemoteAgentUnavailable;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lists the source profiles the server's administrator defined. The agent
 * returns only their identifier, validity and runtime; the profiles' paths
 * and settings stay on the server.
 */
class DetectRemoteSources
{
    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
    ) {}

    /** @return array{detected: bool, error_code: string|null, sources: list<array{id: string, valid: bool, runtime: string|null, run_as: string|null, error_code: string|null}>} */
    public function handle(Server $server, ?User $actor): array
    {
        $this->connections->assertEnabled();
        $errorCode = null;
        $sources = [];
        try {
            $response = $this->agent->call($this->connections->target($server), 'source-list', [], 60);
            if ($response->ok) {
                foreach (is_array($response->data['sources'] ?? null) ? $response->data['sources'] : [] as $source) {
                    if (is_array($source) && is_string($source['id'] ?? null) && preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/D', $source['id']) === 1) {
                        $sources[] = ['id' => $source['id'], 'valid' => ($source['valid'] ?? false) === true,
                            'runtime' => in_array($source['runtime'] ?? null, ['native', 'compose'], true) ? $source['runtime'] : null,
                            'run_as' => is_string($source['run_as'] ?? null) && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $source['run_as']) === 1 ? $source['run_as'] : null,
                            'error_code' => is_string($source['error_code'] ?? null) && preg_match('/^[a-z_]{1,64}$/D', $source['error_code']) === 1 ? $source['error_code'] : null];
                    }
                }
            } else {
                $errorCode = $response->errorCode;
            }
        } catch (RemoteAgentUnavailable $exception) {
            $errorCode = $exception->errorCode;
        }

        if ($errorCode === null) {
            DB::transaction(function () use ($server, $actor, $sources): void {
                $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
                $metadata = $locked->metadata ?? [];
                $metadata['remote_sources_detected'] = ['detected_at' => now()->utc()->toIso8601String(), 'sources' => $sources];
                $locked->update(['metadata' => $metadata]);
                AuditLog::query()->create([
                    'actor_id' => $actor?->getKey(), 'project_id' => $locked->project_id, 'action' => 'REMOTE_SOURCES_DETECTED',
                    'auditable_type' => $locked->getMorphClass(), 'auditable_id' => $locked->getKey(),
                    'payload' => ['sources' => $sources],
                ]);
            });
        }

        return ['detected' => $errorCode === null, 'error_code' => $errorCode, 'sources' => $sources];
    }
}
