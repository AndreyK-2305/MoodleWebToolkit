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
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prepares a destination: the Consolidator's own `preparar` builds the Moodle
 * 5.2.1 target and its assistant runtime and installs an empty site. It runs
 * on the server as a durable agent operation; the platform launches it once
 * and then only observes, from the scheduler, until its exit evidence.
 *
 * A clean destination is preferred. On one already in use the administrator
 * types CONFIRMAR either to prepare over it or to clean it first: the reset
 * removes the previous site's services, volumes and runtime state in the same
 * operation, keeping only the administrator's .env.
 *
 * The preparation belongs to the server, not to an Execution: its state is
 * kept on the server's metadata under `remote_destination_preparations`.
 */
class PrepareRemoteDestination
{
    public const COMMAND = 'consolidator.prepare';

    public const ACTIVE = ['STARTING', 'RUNNING', 'CANCELLING'];

    /** What the administrator types to act on a destination already in use. */
    public const CONFIRMATION = 'CONFIRMAR';

    /** The Consolidator prints these markers, in this order, on a successful preparation. */
    public const MARKERS = ['PREFLIGHT_OK', 'DESTINO_LISTO', 'EXPORT_TRANSPORT_OK', 'TARGET_SHARED_EMAIL_POLICY_OK'];

    private const HEARTBEAT_STALE_SECONDS = 90;

    private const TAIL_KEEP_BYTES = 8192;

    private const TAIL_CHUNK_BYTES = 16384;

    private const TAIL_CHUNKS = 4;

    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
        private readonly RemoteCommandRegistry $registry,
        private readonly SensitiveValueRedactor $redactor,
    ) {}

    /** @return array<string, mixed> */
    public function start(Server $server, string $destination, ?User $actor, bool $reset = false, ?string $confirmation = null): array
    {
        $this->connections->assertEnabled();
        if ($server->role !== ServerRole::DESTINATION) {
            throw new ToolOperationBlocked('Solo un servidor de destino se prepara para consolidar.');
        }
        if ($this->connections->primary($server)?->status !== ConnectionStatus::VALID) {
            throw new ToolOperationBlocked('El servidor debe superar la prueba de conexión antes de prepararse.');
        }
        $check = $server->metadata['remote_destinations'][$destination] ?? null;
        $tree = $server->metadata['remote_tools'][CheckRemoteDestination::DISTRIBUTION_KEY]['tree_sha256'] ?? null;
        if (! is_array($check) || ! is_string($tree)) {
            throw new ToolOperationBlocked('El perfil de destino debe superar su comprobación antes de prepararse.');
        }
        // A profile left on an earlier Consolidator moves to the deployed one only by cleaning it:
        // the agent re-points it before restoring the runtime, keeping the .env.
        $upgrade = ($check['ready'] ?? false) !== true && CheckRemoteDestination::upgradable($check);
        if (($check['ready'] ?? false) !== true && ! $upgrade) {
            throw new ToolOperationBlocked('El perfil de destino debe superar su comprobación antes de prepararse.');
        }
        if ($upgrade && ! $reset) {
            throw new ToolOperationBlocked('El perfil usa una versión anterior del Consolidador: limpie el destino y prepárelo para pasar a la desplegada.');
        }
        $report = is_array($check['report'] ?? null) ? $check['report'] : [];
        if (! $reset && ($report['destination_written'] ?? false) === true) {
            throw new ToolOperationBlocked('El destino ya recibió escrituras del Consolidador; límpielo antes de prepararlo de nuevo.');
        }
        if ($reset || CheckRemoteDestination::used($report)) {
            if ($actor === null || ! $actor->isAdmin()) {
                throw new ToolOperationBlocked('Solo un administrador puede preparar o limpiar un destino que ya está en uso.');
            }
            if ($confirmation !== self::CONFIRMATION) {
                throw new ToolOperationBlocked('Escriba CONFIRMAR para actuar sobre un destino que ya está en uso.');
            }
        }
        $command = $this->registry->resolve(self::COMMAND, ['destination' => $destination, 'distribution' => $tree, 'reset' => $reset ? '1' : '0']);
        $uuid = (string) Str::uuid();

        // The claim is taken under lock so two requests cannot launch two preparations.
        DB::transaction(function () use ($server, $destination, $uuid, $tree, $reset): void {
            $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->metadata['remote_destination_preparations'][$destination] ?? null;
            if (is_array($current) && in_array($current['state'] ?? null, self::ACTIVE, true)) {
                throw new ToolOperationBlocked('Ya hay una preparación en curso para este perfil de destino.');
            }
            if ($reset) {
                // What the platform knew of copias/ and its transfers no longer holds; the
                // profile is checked again once the preparation ends.
                $metadata = $locked->metadata ?? [];
                unset($metadata['remote_destination_copias'][$destination], $metadata['remote_destination_fetches'][$destination]);
                $metadata['remote_destinations'][$destination]['ready'] = false;
                $locked->metadata = $metadata;
            }
            $this->write($locked, $destination, ['operation_uuid' => $uuid, 'state' => 'STARTING', 'tool_tree' => $tree, 'reset' => $reset,
                'launched_at' => now()->utc()->toIso8601String(), 'finished_at' => null, 'exit_code' => null, 'error_code' => null,
                'markers' => [], 'last_error_line' => null, 'stdout_offset' => 0, 'tail' => '', 'heartbeat_at' => null, 'observed_at' => null]);
        });

        $errorCode = null;
        $state = 'RUNNING';
        try {
            $response = $this->agent->call($this->connections->target($server), 'launch', [$uuid, self::COMMAND, ...$command['arguments']], 300);
            $errorCode = $response->ok ? null : $response->errorCode;
        } catch (RemoteAgentUnavailable $exception) {
            // The launch may have reached the server: it stays STARTING and the observer settles it.
            $state = 'STARTING';
            $this->update($server, $destination, ['launch_error' => $exception->errorCode]);
        }
        $state = $errorCode === null ? $state : 'FAILED';
        $this->update($server, $destination, ['state' => $state, 'error_code' => $errorCode,
            'finished_at' => $errorCode === null ? null : now()->utc()->toIso8601String()]);
        $this->audit($server, $actor, $reset ? 'REMOTE_DESTINATION_RESET_STARTED' : 'REMOTE_DESTINATION_PREPARATION_STARTED',
            ['destination' => $destination, 'operation_uuid' => $uuid, 'tool_tree' => $tree, 'error_code' => $errorCode,
                'reset' => $reset, 'in_use' => CheckRemoteDestination::used($report), 'upgrade_from' => $upgrade ? ($report['tool_tree'] ?? null) : null]);

        return $this->current($server->refresh(), $destination);
    }

    /**
     * One bounded observation: status, new output and, once finished, the closed result.
     *
     * @return array<string, mixed>
     */
    public function observe(Server $server, string $destination): array
    {
        $current = $this->current($server, $destination);
        if (! in_array($current['state'] ?? null, self::ACTIVE, true)) {
            return $current;
        }
        $uuid = (string) $current['operation_uuid'];
        $target = $this->connections->target($server);
        try {
            $status = $this->agent->call($target, 'status', [$uuid], 30);
            if (! $status->ok) {
                // A launch lost in transit never created its operation; give it time to appear first.
                $final = $status->errorCode === 'operation_not_found' && $current['state'] === 'STARTING'
                    && $this->stale(null, (string) $current['launched_at']);
                $this->update($server, $destination, ['observed_at' => now()->utc()->toIso8601String(), 'observe_error' => $status->errorCode,
                    ...($final ? ['state' => 'FAILED', 'error_code' => 'launch_not_received', 'finished_at' => now()->utc()->toIso8601String()] : [])]);

                return $this->current($server->refresh(), $destination);
            }
            [$offset, $tail, $markers, $errorLine] = $this->readOutput($target, $uuid, (int) $current['stdout_offset'],
                (string) $current['tail'], array_values(array_filter((array) $current['markers'], 'is_string')), $current['last_error_line']);
        } catch (RemoteAgentUnavailable $exception) {
            $this->update($server, $destination, ['observed_at' => now()->utc()->toIso8601String(), 'observe_error' => $exception->errorCode]);

            return $this->current($server->refresh(), $destination);
        }

        $data = $status->data;
        $changes = ['state' => $current['state'] === 'STARTING' ? 'RUNNING' : $current['state'], 'stdout_offset' => $offset, 'tail' => $tail,
            'markers' => $markers, 'last_error_line' => $errorLine, 'observe_error' => null,
            'heartbeat_at' => is_string($data['heartbeat_at'] ?? null) ? $data['heartbeat_at'] : null, 'observed_at' => now()->utc()->toIso8601String()];
        $exit = is_array($data['exit'] ?? null) ? $data['exit'] : null;
        if ($exit !== null) {
            $code = (int) ($exit['exit_code'] ?? -1);
            $cancelled = ($exit['cancel_requested'] ?? false) === true;
            // The incremental reading may still be behind a long build log: the closing markers are
            // also looked for at the end of the output before the result is settled.
            if ($offset < (int) ($data['stdout_bytes'] ?? 0)) {
                try {
                    [, $end, $markers, $endError] = $this->readOutput($target, $uuid, max($offset, (int) $data['stdout_bytes'] - self::TAIL_CHUNK_BYTES),
                        '', $markers, null, chunks: 1);
                    $changes['tail'] = substr($tail.$end, -self::TAIL_KEEP_BYTES);
                    $changes['markers'] = $markers;
                    $changes['last_error_line'] = $endError ?? $changes['last_error_line'];
                } catch (RemoteAgentUnavailable $exception) {
                    // Without the end of the log the result cannot be settled yet; the next pass retries.
                    $this->update($server, $destination, [...$changes, 'state' => $current['state'], 'observe_error' => $exception->errorCode]);

                    return $this->current($server->refresh(), $destination);
                }
            }
            // Success needs both the exit code and the Consolidator's last marker.
            $succeeded = $code === 0 && in_array('TARGET_SHARED_EMAIL_POLICY_OK', $markers, true);
            if (! $succeeded) {
                // The Consolidator reports its failures on stderr: keep the end of it, redacted.
                [$stderr, $stderrError] = $this->stderrTail($target, $uuid, (int) ($data['stderr_bytes'] ?? 0));
                $changes['stderr_tail'] = $stderr;
                $changes['last_error_line'] = $stderrError ?? $changes['last_error_line'];
            }
            $changes += ['exit_code' => $code, 'finished_at' => now()->utc()->toIso8601String(),
                'error_code' => $succeeded ? null : ($cancelled ? 'cancelled' : ($code === 0 ? 'markers_missing' : 'prepare_failed'))];
            $changes['state'] = $succeeded ? 'SUCCEEDED' : ($cancelled ? 'CANCELLED' : 'FAILED');
        } elseif (($data['process_alive'] ?? false) !== true && $this->stale($changes['heartbeat_at'], (string) $current['launched_at'])) {
            $changes += ['error_code' => 'process_lost', 'finished_at' => now()->utc()->toIso8601String()];
            $changes['state'] = 'UNKNOWN';
        }
        $this->update($server, $destination, $changes);
        if (! in_array($changes['state'], self::ACTIVE, true)) {
            $this->audit($server, null, 'REMOTE_DESTINATION_PREPARATION_FINISHED', ['destination' => $destination, 'operation_uuid' => $uuid,
                'state' => $changes['state'], 'exit_code' => $changes['exit_code'] ?? null, 'error_code' => $changes['error_code'] ?? null, 'markers' => $markers]);
            if (($current['reset'] ?? false) === true) {
                // The cleaned destination is checked again, so the consolidation sees its new facts.
                try {
                    app(CheckRemoteDestination::class)->handle($server->refresh(), $destination, null);
                } catch (RemoteAgentUnavailable|ToolOperationBlocked) {
                    // The administrator can run the check from the panel.
                }
            }
        }

        return $this->current($server->refresh(), $destination);
    }

    /** @return array<string, mixed> */
    public function cancel(Server $server, string $destination, ?User $actor): array
    {
        $current = $this->current($server, $destination);
        if (! in_array($current['state'] ?? null, self::ACTIVE, true)) {
            throw new ToolOperationBlocked('No hay una preparación en curso para este perfil de destino.');
        }
        $response = $this->agent->call($this->connections->target($server), 'cancel', [(string) $current['operation_uuid']], 60);
        if (! $response->ok) {
            throw new RemoteAgentUnavailable((string) $response->errorCode, 'El agente no pudo cancelar la preparación.');
        }
        $this->update($server, $destination, ['state' => 'CANCELLING']);
        $this->audit($server, $actor, 'REMOTE_DESTINATION_PREPARATION_CANCEL_REQUESTED',
            ['destination' => $destination, 'operation_uuid' => $current['operation_uuid']]);

        return $this->current($server->refresh(), $destination);
    }

    /** @return array<string, mixed> */
    public function current(Server $server, string $destination): array
    {
        $current = $server->metadata['remote_destination_preparations'][$destination] ?? null;

        return is_array($current) ? $current : [];
    }

    /**
     * @param  list<string>  $markers
     * @return array{int, string, list<string>, string|null}
     */
    private function readOutput(RemoteTarget $target, string $uuid, int $offset, string $tail, array $markers, mixed $errorLine, int $chunks = self::TAIL_CHUNKS): array
    {
        $errorLine = is_string($errorLine) ? $errorLine : null;
        for ($chunk = 0; $chunk < $chunks; $chunk++) {
            $response = $this->agent->call($target, 'tail', [$uuid, 'stdout', (string) $offset, (string) self::TAIL_CHUNK_BYTES], 30);
            if (! $response->ok) {
                break;
            }
            $text = (string) base64_decode((string) ($response->data['data_base64'] ?? ''), true);
            $next = (int) ($response->data['next_offset'] ?? $offset);
            if ($text === '' || $next <= $offset) {
                break;
            }
            $offset = $next;
            $text = $this->redactor->redactString(mb_scrub($text, 'UTF-8'));
            foreach (self::MARKERS as $marker) {
                if (! in_array($marker, $markers, true) && preg_match('/^'.$marker.'\b/m', $text) === 1) {
                    $markers[] = $marker;
                }
            }
            if (preg_match_all('/^(?:ERROR|ADVERTENCIA|[A-Z_]+_FAILED)\b.*$/m', $text, $lines) > 0) {
                $errorLine = mb_substr((string) end($lines[0]), 0, 300);
            }
            $tail = substr($tail.$text, -self::TAIL_KEEP_BYTES);
            if ($next >= (int) ($response->data['size'] ?? 0)) {
                break;
            }
        }

        return [$offset, mb_scrub($tail, 'UTF-8'), array_values(array_intersect(self::MARKERS, $markers)), $errorLine];
    }

    /** @return array{string, string|null} */
    private function stderrTail(RemoteTarget $target, string $uuid, int $size): array
    {
        try {
            $response = $this->agent->call($target, 'tail', [$uuid, 'stderr', (string) max(0, $size - 4096), '4096'], 30);
        } catch (RemoteAgentUnavailable) {
            return ['', null];
        }
        $text = $response->ok ? $this->redactor->redactString(mb_scrub((string) base64_decode((string) ($response->data['data_base64'] ?? ''), true), 'UTF-8')) : '';
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $line): bool => $line !== ''));
        $errors = array_values(array_filter($lines, fn (string $line): bool => preg_match('/^(?:ERROR|[A-Z_]+_FAILED)\b/', $line) === 1));
        $last = $errors !== [] ? end($errors) : (end($lines) ?: null);

        return [$text, $last === null ? null : mb_substr($last, 0, 300)];
    }

    private function stale(?string $heartbeat, string $launchedAt): bool
    {
        $reference = $heartbeat !== null ? strtotime($heartbeat) : strtotime($launchedAt);

        return $reference === false || now()->getTimestamp() - $reference > self::HEARTBEAT_STALE_SECONDS;
    }

    /** @param array<string, mixed> $changes */
    private function update(Server $server, string $destination, array $changes): void
    {
        DB::transaction(function () use ($server, $destination, $changes): void {
            $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            $this->write($locked, $destination, [...$this->current($locked, $destination), ...$changes]);
        });
    }

    /** @param array<string, mixed> $preparation */
    private function write(Server $locked, string $destination, array $preparation): void
    {
        $metadata = $locked->metadata ?? [];
        $metadata['remote_destination_preparations'][$destination] = $preparation;
        $locked->update(['metadata' => $metadata]);
    }

    /** @param array<string, mixed> $payload */
    private function audit(Server $server, ?User $actor, string $action, array $payload): void
    {
        AuditLog::query()->create(['actor_id' => $actor?->getKey(), 'project_id' => $server->project_id, 'action' => $action,
            'auditable_type' => $server->getMorphClass(), 'auditable_id' => $server->getKey(), 'payload' => $payload]);
    }
}
