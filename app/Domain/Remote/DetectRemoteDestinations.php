<?php

namespace App\Domain\Remote;

use App\Exceptions\RemoteAgentUnavailable;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lists the destination profiles the server's administrator defined: their
 * identifier, validity and public URL. Runtime paths and secrets stay on the
 * server.
 */
class DetectRemoteDestinations
{
    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
    ) {}

    /** @return array{detected: bool, error_code: string|null, destinations: list<array{id: string, valid: bool, public_url: string|null, error_code: string|null}>} */
    public function handle(Server $server, ?User $actor): array
    {
        $this->connections->assertEnabled();
        $errorCode = null;
        $destinations = [];
        try {
            $response = $this->agent->call($this->connections->target($server), 'destination-list', [], 60);
            if ($response->ok) {
                foreach (is_array($response->data['destinations'] ?? null) ? $response->data['destinations'] : [] as $destination) {
                    if (is_array($destination) && is_string($destination['id'] ?? null) && preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/D', $destination['id']) === 1) {
                        $url = $destination['public_url'] ?? null;
                        $destinations[] = ['id' => $destination['id'], 'valid' => ($destination['valid'] ?? false) === true,
                            'public_url' => is_string($url) && preg_match('#^https://[A-Za-z0-9._:-]+(/[A-Za-z0-9._~/-]*)?$#D', $url) === 1 ? $url : null,
                            'error_code' => is_string($destination['error_code'] ?? null) && preg_match('/^[a-z_]{1,64}$/D', $destination['error_code']) === 1
                                ? $destination['error_code'] : null];
                    }
                }
            } else {
                $errorCode = $response->errorCode;
            }
        } catch (RemoteAgentUnavailable $exception) {
            $errorCode = $exception->errorCode;
        }

        if ($errorCode === null) {
            DB::transaction(function () use ($server, $actor, $destinations): void {
                $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
                $metadata = $locked->metadata ?? [];
                $metadata['remote_destinations_detected'] = ['detected_at' => now()->utc()->toIso8601String(), 'destinations' => $destinations];
                $locked->update(['metadata' => $metadata]);
                AuditLog::query()->create([
                    'actor_id' => $actor?->getKey(), 'project_id' => $locked->project_id, 'action' => 'REMOTE_DESTINATIONS_DETECTED',
                    'auditable_type' => $locked->getMorphClass(), 'auditable_id' => $locked->getKey(),
                    'payload' => ['destinations' => $destinations],
                ]);
            });
        }

        return ['detected' => $errorCode === null, 'error_code' => $errorCode, 'destinations' => $destinations];
    }
}
