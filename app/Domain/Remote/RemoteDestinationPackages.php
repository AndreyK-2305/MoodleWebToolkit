<?php

namespace App\Domain\Remote;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Enums\ConnectionStatus;
use App\Enums\ServerRole;
use App\Exceptions\RemoteAgentUnavailable;
use App\Exceptions\ToolOperationBlocked;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\SourcePackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sealed source packages on their way from S3 into a destination runtime's
 * copias/, where the Consolidator imports them. The destination downloads
 * with its own instance role; the platform first verifies the object with its
 * identity and afterwards accepts only the hash the agent reports back.
 *
 * Each transfer is a durable agent operation kept on the server's metadata
 * under `remote_destination_fetches.<profile>.<package uuid>`.
 */
class RemoteDestinationPackages
{
    public const COMMAND = 'package.fetch';

    public const ACTIVE = ['STARTING', 'RUNNING', 'CANCELLING'];

    private const HEARTBEAT_STALE_SECONDS = 90;

    /** Closed failure markers the agent prints on stderr. */
    private const FAILURES = ['DESTINATION_ALREADY_WRITTEN', 'SIDECAR_DOWNLOAD_FAILED', 'SIDECAR_INVALID', 'DESTINATION_NAME_CONFLICT',
        'PACKAGE_NOT_FOUND', 'INSUFFICIENT_DISK', 'PACKAGE_DOWNLOAD_FAILED', 'PACKAGE_HASH_MISMATCH'];

    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
        private readonly RemoteCommandRegistry $registry,
        private readonly RemotePackageObjects $objects,
        private readonly SensitiveValueRedactor $redactor,
    ) {}

    /** @return array<string, mixed> */
    public function fetch(Server $server, string $destination, SourcePackage $package, ?User $actor): array
    {
        $this->assertWritable($server, $destination);
        $package->loadMissing(['artifact', 'project']);
        if ($package->validation_state !== 'VALID' || $package->availability !== 'AVAILABLE' || $package->revoked_at !== null
            || $package->artifact === null || $package->artifact->storage_mode !== 'REFERENCE') {
            throw new ToolOperationBlocked('Solo se transfieren paquetes vigentes guardados en S3.');
        }
        $location = $this->objects->location($package->artifact);
        $prefix = 'mwt/'.$package->project->uuid.'/'.$package->source_id.'/'.$package->sha256;
        if ($location['key'] !== $prefix.'.zip' || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $package->source_id) !== 1) {
            throw new ToolOperationBlocked('La ubicación del paquete en S3 no sigue el contrato de publicación.');
        }
        // The object is checked with the platform's identity before the destination trusts it.
        $this->objects->verifyPackage($location['region'], $location['bucket'], $location['key'], $package->sha256, $package->size_bytes);
        $command = $this->registry->resolve(self::COMMAND, ['destination' => $destination, 'project' => $package->project->uuid,
            'source' => $package->source_id, 'sha' => $package->sha256, 'bucket' => $location['bucket'], 'region' => $location['region']]);
        $uuid = (string) Str::uuid();

        DB::transaction(function () use ($server, $destination, $package, $uuid): void {
            $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            $fetches = $locked->metadata['remote_destination_fetches'][$destination] ?? [];
            foreach (is_array($fetches) ? $fetches : [] as $fetch) {
                if (is_array($fetch) && in_array($fetch['state'] ?? null, self::ACTIVE, true)) {
                    throw new ToolOperationBlocked('Ya hay una transferencia en curso hacia este destino; espere a que termine.');
                }
            }
            $this->write($locked, $destination, $package->uuid, ['operation_uuid' => $uuid, 'state' => 'STARTING',
                'package_name' => $package->name, 'source_id' => $package->source_id, 'sha256' => $package->sha256, 'size' => $package->size_bytes,
                'launched_at' => now()->utc()->toIso8601String(), 'finished_at' => null, 'exit_code' => null, 'error_code' => null,
                'error_line' => null, 'name' => null, 'already_present' => null, 'observed_at' => null]);
        });

        $state = 'RUNNING';
        $errorCode = null;
        try {
            $response = $this->agent->call($this->connections->target($server), 'launch', [$uuid, self::COMMAND, ...$command['arguments']], 120);
            if (! $response->ok) {
                [$state, $errorCode] = ['FAILED', $response->errorCode];
            }
        } catch (RemoteAgentUnavailable $exception) {
            // It may have reached the server: the observer settles it from the agent's own state.
            $state = 'STARTING';
            $this->update($server, $destination, $package->uuid, ['launch_error' => $exception->errorCode]);
        }
        $this->update($server, $destination, $package->uuid, ['state' => $state, 'error_code' => $errorCode,
            'finished_at' => $state === 'FAILED' ? now()->utc()->toIso8601String() : null]);
        $this->audit($server, $actor, 'REMOTE_DESTINATION_PACKAGE_FETCH_STARTED', ['destination' => $destination,
            'source_package_uuid' => $package->uuid, 'sha256' => $package->sha256, 'operation_uuid' => $uuid, 'error_code' => $errorCode]);

        return $this->current($server->refresh(), $destination, $package->uuid);
    }

    /**
     * One bounded observation of a transfer; once it succeeds the copias/ listing is refreshed.
     *
     * @return array<string, mixed>
     */
    public function observe(Server $server, string $destination, string $packageUuid): array
    {
        $current = $this->current($server, $destination, $packageUuid);
        if (! in_array($current['state'] ?? null, self::ACTIVE, true)) {
            return $current;
        }
        $uuid = (string) $current['operation_uuid'];
        $target = $this->connections->target($server);
        $now = now()->utc()->toIso8601String();
        try {
            $status = $this->agent->call($target, 'status', [$uuid], 30);
            if (! $status->ok) {
                $lost = $status->errorCode === 'operation_not_found' && $current['state'] === 'STARTING' && $this->stale(null, (string) $current['launched_at']);
                $this->update($server, $destination, $packageUuid, ['observed_at' => $now, 'observe_error' => $status->errorCode,
                    ...($lost ? ['state' => 'FAILED', 'error_code' => 'launch_not_received', 'finished_at' => $now] : [])]);

                return $this->current($server->refresh(), $destination, $packageUuid);
            }
            $data = $status->data;
            $changes = ['state' => 'RUNNING', 'observed_at' => $now, 'observe_error' => null];
            $exit = is_array($data['exit'] ?? null) ? $data['exit'] : null;
            if ($exit !== null) {
                $changes = [...$changes, ...$this->settle($target, $uuid, $exit, (int) ($data['stderr_bytes'] ?? 0), (string) $current['sha256'])];
                $changes['finished_at'] = $now;
            } elseif (($data['process_alive'] ?? false) !== true
                && $this->stale(is_string($data['heartbeat_at'] ?? null) ? $data['heartbeat_at'] : null, (string) $current['launched_at'])) {
                $changes = [...$changes, 'state' => 'UNKNOWN', 'error_code' => 'process_lost', 'finished_at' => $now];
            }
        } catch (RemoteAgentUnavailable $exception) {
            $this->update($server, $destination, $packageUuid, ['observed_at' => $now, 'observe_error' => $exception->errorCode]);

            return $this->current($server->refresh(), $destination, $packageUuid);
        }
        $this->update($server, $destination, $packageUuid, $changes);
        if (! in_array($changes['state'], self::ACTIVE, true)) {
            $this->audit($server, null, 'REMOTE_DESTINATION_PACKAGE_FETCH_FINISHED', ['destination' => $destination, 'source_package_uuid' => $packageUuid,
                'operation_uuid' => $uuid, 'state' => $changes['state'], 'error_code' => $changes['error_code'] ?? null, 'name' => $changes['name'] ?? null]);
            if ($changes['state'] === 'SUCCEEDED') {
                try {
                    $this->list($server->refresh(), $destination);
                } catch (ToolOperationBlocked) {
                    // The listing is refreshed again from the panel.
                }
            }
        }

        return $this->current($server->refresh(), $destination, $packageUuid);
    }

    /**
     * The packages in copias/ as the agent lists them, matched to known source packages by hash.
     *
     * @return array{listed: bool, error_code: string|null, write_locked: bool, packages: list<array<string, mixed>>}
     */
    public function list(Server $server, string $destination): array
    {
        $this->assertDestination($server);
        $errorCode = null;
        $packages = [];
        $locked = false;
        try {
            $response = $this->agent->call($this->connections->target($server), 'copias-list', [$destination], 60);
            if ($response->ok) {
                $locked = ($response->data['write_locked'] ?? false) === true;
                foreach (is_array($response->data['packages'] ?? null) ? $response->data['packages'] : [] as $entry) {
                    if (is_array($entry) && is_string($entry['name'] ?? null) && preg_match('/^[a-z][a-z0-9_-]{0,63}\.zip$/D', $entry['name']) === 1) {
                        $sha = is_string($entry['sidecar_sha256'] ?? null) && preg_match('/^[0-9a-f]{64}$/D', $entry['sidecar_sha256']) === 1 ? $entry['sidecar_sha256'] : null;
                        $known = $sha === null ? null : SourcePackage::query()->where('sha256', $sha)->first(['uuid', 'name', 'source_id']);
                        $packages[] = ['name' => $entry['name'], 'size' => is_int($entry['size'] ?? null) ? $entry['size'] : null, 'sidecar_sha256' => $sha,
                            'source_package_uuid' => $known?->uuid, 'source_id' => $known?->source_id];
                    }
                }
            } else {
                $errorCode = $response->errorCode;
            }
        } catch (RemoteAgentUnavailable $exception) {
            $errorCode = $exception->errorCode;
        }
        if ($errorCode === null) {
            DB::transaction(function () use ($server, $destination, $packages, $locked): void {
                $row = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
                $metadata = $row->metadata ?? [];
                $metadata['remote_destination_copias'][$destination] = ['listed_at' => now()->utc()->toIso8601String(),
                    'write_locked' => $locked, 'packages' => $packages];
                $row->update(['metadata' => $metadata]);
            });
        }

        return ['listed' => $errorCode === null, 'error_code' => $errorCode, 'write_locked' => $locked, 'packages' => $packages];
    }

    /** Takes one package out of copias/; refused by the agent once the destination was written. */
    public function remove(Server $server, string $destination, string $name, ?User $actor): void
    {
        $this->assertWritable($server, $destination);
        $response = $this->agent->call($this->connections->target($server), 'copias-remove', [$destination, $name], 60);
        if (! $response->ok) {
            throw new ToolOperationBlocked('El agente no retiró el paquete ('.$response->errorCode.').');
        }
        $this->audit($server, $actor, 'REMOTE_DESTINATION_PACKAGE_REMOVED', ['destination' => $destination, 'name' => $name]);
        $this->list($server->refresh(), $destination);
    }

    /** @return array<string, mixed> */
    public function current(Server $server, string $destination, string $packageUuid): array
    {
        $current = $server->metadata['remote_destination_fetches'][$destination][$packageUuid] ?? null;

        return is_array($current) ? $current : [];
    }

    /**
     * @param  array<string, mixed>  $exit
     * @return array<string, mixed>
     */
    private function settle(RemoteTarget $target, string $uuid, array $exit, int $stderrBytes, string $sha): array
    {
        if ((int) ($exit['exit_code'] ?? -1) === 0) {
            $report = $this->agent->call($target, 'fetch-report', [$uuid], 30);
            $result = $report->ok && is_string($report->data['result_base64'] ?? null)
                ? json_decode((string) base64_decode($report->data['result_base64'], true), true) : null;
            // Success is the hash the agent computed on the server, equal to the one the platform verified in S3.
            if (is_array($result) && ($result['sha256'] ?? null) === $sha && is_string($result['name'] ?? null)
                && preg_match('/^[a-z][a-z0-9_-]{0,63}\.zip$/D', $result['name']) === 1) {
                return ['state' => 'SUCCEEDED', 'exit_code' => 0, 'error_code' => null, 'name' => $result['name'],
                    'already_present' => ($result['already_present'] ?? false) === true];
            }

            return ['state' => 'FAILED', 'exit_code' => 0, 'error_code' => 'fetch_report_invalid'];
        }
        $cancelled = ($exit['cancel_requested'] ?? false) === true;
        $line = null;
        try {
            $tail = $this->agent->call($target, 'tail', [$uuid, 'stderr', (string) max(0, $stderrBytes - 2048), '2048'], 30);
            $text = $tail->ok ? $this->redactor->redactString(mb_scrub((string) base64_decode((string) ($tail->data['data_base64'] ?? ''), true), 'UTF-8')) : '';
            $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $value): bool => $value !== ''));
            $line = $lines === [] ? null : mb_substr((string) end($lines), 0, 300);
        } catch (RemoteAgentUnavailable) {
            // The exit code alone still settles the transfer.
        }
        $marker = $line !== null && in_array($line, self::FAILURES, true) ? strtolower($line) : null;

        return ['state' => $cancelled ? 'CANCELLED' : 'FAILED', 'exit_code' => (int) ($exit['exit_code'] ?? -1),
            'error_code' => $cancelled ? 'cancelled' : ($marker ?? 'fetch_failed'), 'error_line' => $line];
    }

    private function assertDestination(Server $server): void
    {
        $this->connections->assertEnabled();
        if ($server->role !== ServerRole::DESTINATION) {
            throw new ToolOperationBlocked('Solo un servidor de destino recibe paquetes para consolidar.');
        }
        if ($this->connections->primary($server)?->status !== ConnectionStatus::VALID) {
            throw new ToolOperationBlocked('El servidor debe superar la prueba de conexión.');
        }
    }

    private function assertWritable(Server $server, string $destination): void
    {
        $this->assertDestination($server);
        $check = $server->metadata['remote_destinations'][$destination] ?? null;
        if (! is_array($check) || ($check['ready'] ?? false) !== true) {
            throw new ToolOperationBlocked('El perfil de destino debe superar su comprobación.');
        }
        if (($check['report']['destination_written'] ?? false) === true
            || ($server->metadata['remote_destination_copias'][$destination]['write_locked'] ?? false) === true) {
            throw new ToolOperationBlocked('El destino ya recibió escrituras del Consolidador; sus paquetes no cambian.');
        }
    }

    private function stale(?string $heartbeat, string $launchedAt): bool
    {
        $reference = strtotime($heartbeat ?? $launchedAt);

        return $reference === false || now()->getTimestamp() - $reference > self::HEARTBEAT_STALE_SECONDS;
    }

    /** @param array<string, mixed> $changes */
    private function update(Server $server, string $destination, string $packageUuid, array $changes): void
    {
        DB::transaction(function () use ($server, $destination, $packageUuid, $changes): void {
            $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            $this->write($locked, $destination, $packageUuid, [...$this->current($locked, $destination, $packageUuid), ...$changes]);
        });
    }

    /** @param array<string, mixed> $fetch */
    private function write(Server $locked, string $destination, string $packageUuid, array $fetch): void
    {
        $metadata = $locked->metadata ?? [];
        $metadata['remote_destination_fetches'][$destination][$packageUuid] = $fetch;
        $locked->update(['metadata' => $metadata]);
    }

    /** @param array<string, mixed> $payload */
    private function audit(Server $server, ?User $actor, string $action, array $payload): void
    {
        AuditLog::query()->create(['actor_id' => $actor?->getKey(), 'project_id' => $server->project_id, 'action' => $action,
            'auditable_type' => $server->getMorphClass(), 'auditable_id' => $server->getKey(), 'payload' => $payload]);
    }
}
