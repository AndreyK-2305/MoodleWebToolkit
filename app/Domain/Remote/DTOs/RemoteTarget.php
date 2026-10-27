<?php

namespace App\Domain\Remote\DTOs;

use App\Domain\Remote\RemoteConnectionConfiguration;
use App\Enums\ConnectionType;
use App\Models\Connection;
use App\Models\Server;

/**
 * Normalized address of a remote server. It never contains secret values:
 * SSH keys are referenced and only materialized by the transport.
 */
final readonly class RemoteTarget
{
    public function __construct(
        public ConnectionType $type,
        public string $serverUuid,
        public ?string $instanceId = null,
        public ?string $region = null,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $user = null,
        public ?string $hostKey = null,
        public ?string $secretReference = null,
        public ?string $secretVersion = null,
        public bool $useSudo = false,
        public ?string $awsAccount = null,
    ) {}

    public static function fromConnection(Server $server, Connection $connection): self
    {
        $type = ConnectionType::from($connection->type);
        // Stored values are revalidated on every use, not trusted from the database.
        $configuration = app(RemoteConnectionConfiguration::class)->normalize($type, $connection->configuration ?? []);
        $string = fn (string $key): ?string => is_string($configuration[$key] ?? null) ? $configuration[$key] : null;

        return new self(
            type: $type,
            serverUuid: $server->uuid,
            instanceId: $string('instance_id'),
            region: $string('region'),
            host: $string('host'),
            port: is_int($configuration['port'] ?? null) ? $configuration['port'] : null,
            user: $string('user'),
            hostKey: $string('host_key'),
            secretReference: $connection->secret_reference,
            secretVersion: $string('secret_version'),
            useSudo: ($configuration['use_sudo'] ?? false) === true,
            awsAccount: $string('aws_account'),
        );
    }

    /** @return array<string, string|int|bool|null> */
    public function describe(): array
    {
        return [
            'type' => $this->type->value,
            'server_uuid' => $this->serverUuid,
            'instance_id' => $this->instanceId,
            'region' => $this->region,
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->user,
            'use_sudo' => $this->useSudo,
        ];
    }
}
