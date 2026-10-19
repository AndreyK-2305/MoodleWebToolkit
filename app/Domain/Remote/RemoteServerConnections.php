<?php

namespace App\Domain\Remote;

use App\Domain\Remote\DTOs\RemoteTarget;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Exceptions\ToolOperationBlocked;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Each server has one primary remote connection. Changing it invalidates any
 * previous test result, because the evidence no longer describes the target.
 */
class RemoteServerConnections
{
    public const PRIMARY = 'primary';

    public function __construct(private readonly RemoteConnectionConfiguration $configuration) {}

    public function assertEnabled(): void
    {
        if (! (bool) config('toolkit.features.remote_agent.enabled', false)) {
            throw new ToolOperationBlocked('Las conexiones remotas están deshabilitadas por feature flag.');
        }
    }

    /** @param array<string, mixed> $input */
    public function configure(Server $server, ConnectionType $type, array $input, ?string $secretReference, ?User $actor): Connection
    {
        $this->assertEnabled();
        $normalized = $this->configuration->normalize($type, $input);
        $this->configuration->assertSecretReference($type, $secretReference);

        return DB::transaction(function () use ($server, $type, $normalized, $secretReference, $actor): Connection {
            $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            $connection = $locked->connections()->where('name', self::PRIMARY)->lockForUpdate()->first();
            $previous = $connection === null ? null : ['type' => $connection->type, 'configuration' => $connection->configuration,
                'status' => $connection->status->value, 'has_secret_reference' => $connection->secret_reference !== null];
            $attributes = [
                'type' => $type->value,
                'status' => ConnectionStatus::UNTESTED,
                'configuration' => $normalized,
                'secret_reference' => $secretReference,
            ];
            if ($connection === null) {
                $connection = $locked->connections()->create(['name' => self::PRIMARY, ...$attributes]);
            } else {
                $connection->update($attributes);
            }
            $metadata = $locked->metadata ?? [];
            unset($metadata['remote_probe']);
            $locked->update(['metadata' => $metadata === [] ? null : $metadata]);

            AuditLog::query()->create([
                'actor_id' => $actor?->getKey(), 'project_id' => $locked->project_id, 'action' => 'REMOTE_CONNECTION_CONFIGURED',
                'auditable_type' => $locked->getMorphClass(), 'auditable_id' => $locked->getKey(),
                'old_values' => $previous,
                'new_values' => ['type' => $type->value, 'configuration' => $normalized, 'has_secret_reference' => $secretReference !== null],
            ]);

            return $connection->refresh();
        }, attempts: 3);
    }

    public function primary(Server $server): ?Connection
    {
        return $server->connections()->where('name', self::PRIMARY)->first();
    }

    public function target(Server $server): RemoteTarget
    {
        $connection = $this->primary($server);
        if ($connection === null) {
            throw new ToolOperationBlocked('El servidor no tiene una conexión remota configurada.');
        }

        return RemoteTarget::fromConnection($server, $connection);
    }
}
