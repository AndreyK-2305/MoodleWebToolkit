<?php

namespace App\Domain\Remote;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Enums\ConnectionStatus;
use App\Enums\LogStream;
use App\Enums\RemoteCommunicationState;
use App\Enums\RemoteFunctionalState;
use App\Exceptions\RemoteAgentUnavailable;
use App\Exceptions\ToolOperationBlocked;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\ExecutionLog;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Durable operations launched on a remote server through mwt-agent.
 *
 * The process lives on the server under systemd. The platform only keeps
 * short calls: launch once, then observe, reconcile and cancel by UUID.
 * Losing contact never means failure; success is only accepted from the
 * agent's exit evidence.
 */
class RemoteAgentOperations
{
    public const PROVIDER = 'remote-agent';

    public const RUNTIME = 'mwt-agent-v1';

    private const POLL_SECONDS = 15;

    private const CANCEL_POLL_SECONDS = 5;

    private const STARTUP_GRACE_SECONDS = 60;

    private const HEARTBEAT_STALE_SECONDS = 90;

    private const MANUAL_AFTER_ATTEMPTS = 8;

    private const MAX_BACKOFF_SECONDS = 900;

    private const OBSERVATION_CALL_SECONDS = 20;

    private const LOG_CHUNK_BYTES = 12288;

    private const LOG_CHUNKS_PER_STREAM = 4;

    /** Definitive launch rejections: retrying cannot change the answer. */
    private const REJECTED_LAUNCH = ['launcher_unavailable', 'unregistered_command', 'invalid_parameter',
        'duplicate_parameter', 'invalid_operation_uuid', 'invalid_arguments'];

    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
        private readonly RemoteCommandRegistry $registry,
        private readonly SensitiveValueRedactor $redactor,
    ) {}

    /** @param array<string, string> $parameters */
    public function launch(Execution $execution, Server $server, string $idempotencyKey, string $commandKey, array $parameters, ?User $actor): RemoteOperation
    {
        $this->connections->assertEnabled();
        if ((int) $server->project_id !== (int) $execution->project_id) {
            throw new ToolOperationBlocked('El servidor no pertenece al proyecto de la ejecución.');
        }
        $connection = $this->connections->primary($server);
        if ($connection === null || $connection->status !== ConnectionStatus::VALID) {
            throw new ToolOperationBlocked('El servidor debe superar la prueba de conexión antes de lanzar operaciones.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $idempotencyKey) !== 1) {
            throw new RuntimeException('La clave de idempotencia de la operación no es válida.');
        }
        $command = $this->registry->resolve($commandKey, $parameters);
        $distribution = $this->agent->distribution();
        $commandHash = hash('sha256', (string) json_encode([
            'provider' => self::PROVIDER,
            'host' => $server->uuid,
            'agent_version' => $distribution['version'],
            'agent_sha256' => $distribution['sha256'],
            'command_key' => $command['key'],
            'parameters' => $command['parameters'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        [$operation, $created] = DB::transaction(function () use ($execution, $server, $idempotencyKey, $command, $commandHash, $distribution, $connection, $actor): array {
            $existing = RemoteOperation::query()->where('execution_id', $execution->getKey())
                ->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->command_key !== $command['key'] || ! hash_equals($existing->command_sha256, $commandHash)) {
                    throw new RuntimeException('La clave de idempotencia ya está asociada a otra operación.');
                }

                return [$existing, false];
            }
            $operation = RemoteOperation::query()->create([
                'execution_id' => $execution->getKey(),
                'operation_uuid' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'provider_key' => self::PROVIDER,
                'host_id' => $server->uuid,
                'runtime_key' => self::RUNTIME,
                'command_key' => $command['key'],
                'command_sha256' => $commandHash,
                'communication_state' => RemoteCommunicationState::RECONCILING,
                'functional_state' => RemoteFunctionalState::STARTING,
                'launch_claimed_at' => now()->utc(),
                'next_poll_at' => now()->utc()->addSeconds(self::POLL_SECONDS),
                'evidence' => [
                    'server_uuid' => $server->uuid,
                    'connection_type' => $connection->type,
                    'agent_version' => $distribution['version'],
                    'agent_sha256' => $distribution['sha256'],
                    'parameters' => $command['parameters'],
                    'cancellable' => $command['cancellable'],
                    'log_offsets' => ['stdout' => 0, 'stderr' => 0],
                ],
            ]);
            $this->audit($operation, $actor, 'REMOTE_OPERATION_LAUNCH_REQUESTED', ['command_key' => $command['key'], 'parameters' => $command['parameters']]);

            return [$operation, true];
        }, attempts: 3);

        if (! $created) {
            return $operation;
        }

        return $this->sendLaunch($operation, $server);
    }

    public function reconcile(RemoteOperation $operation): RemoteOperation
    {
        $operation->refresh();
        if ($operation->provider_key !== self::PROVIDER) {
            throw new RuntimeException('La operación no pertenece al proveedor del agente remoto.');
        }
        if ($operation->communication_state === RemoteCommunicationState::TERMINATED) {
            return $operation;
        }
        $server = $this->server($operation);
        $target = $this->connections->target($server);
        try {
            $status = $this->agent->call($target, 'status', [$operation->operation_uuid], self::OBSERVATION_CALL_SECONDS);
        } catch (RemoteAgentUnavailable $exception) {
            return $this->markUnreachable($operation, $exception->errorCode);
        }
        if (! $status->ok) {
            if ($status->errorCode === 'operation_not_found') {
                // A launch without a confirmed identity is safely repeated: the agent is idempotent by UUID.
                return $operation->process_id === null
                    ? $this->sendLaunch($operation, $server)
                    : $this->lost($operation, 'remote_state_missing');
            }

            return $this->markUnreachable($operation, 'agent_'.$status->errorCode);
        }
        $data = $status->data;
        if ($operation->process_id === null) {
            if (! is_array($data['launch'] ?? null)) {
                return $this->lost($operation, 'launch_evidence_missing');
            }
            $operation = $this->applyLaunch($operation, $data['launch']);
            if ($operation->communication_state === RemoteCommunicationState::TERMINATED) {
                return $operation;
            }
        }

        $alive = ($data['process_alive'] ?? null) === true;
        $exit = is_array($data['exit'] ?? null) ? $data['exit'] : null;
        $this->collectLogs($operation, $target, $data, $exit !== null && ! $alive);

        if ($exit !== null && ! $alive) {
            return $this->terminate($operation, $exit);
        }
        if ($alive) {
            if (is_string(($operation->fresh()->evidence ?? [])['cancel_requested_at'] ?? null) && ($data['cancel_requested'] ?? false) !== true) {
                $this->sendCancel($operation, $target);
            }

            return $this->running($operation, $data);
        }
        if (($data['run'] ?? null) === null && $operation->started_at !== null
            && $operation->started_at->diffInSeconds(now()->utc(), true) < self::STARTUP_GRACE_SECONDS
        ) {
            return $this->persist($operation, [
                'communication_state' => RemoteCommunicationState::CONNECTED,
                'functional_state' => RemoteFunctionalState::STARTING,
                'last_observed_at' => now()->utc(),
                'next_poll_at' => now()->utc()->addSeconds(self::CANCEL_POLL_SECONDS),
            ]);
        }

        return $this->lost($operation, 'process_vanished_without_exit');
    }

    public function cancel(RemoteOperation $operation, ?User $actor): RemoteOperation
    {
        $operation->refresh();
        if ($operation->provider_key !== self::PROVIDER) {
            throw new RuntimeException('La operación no pertenece al proveedor del agente remoto.');
        }
        if ($operation->communication_state === RemoteCommunicationState::TERMINATED) {
            return $operation;
        }
        if (($operation->evidence['cancellable'] ?? false) !== true) {
            throw new ToolOperationBlocked('La operación registrada no admite cancelación.');
        }
        if ($operation->process_id === null) {
            throw new ToolOperationBlocked('El lanzamiento todavía no está confirmado; reconcilie la operación antes de cancelarla.');
        }

        $operation = DB::transaction(function () use ($operation, $actor): RemoteOperation {
            $locked = RemoteOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            $evidence = $locked->evidence ?? [];
            if (! isset($evidence['cancel_requested_at'])) {
                // The intent is durable before any call: reconciliation repeats it if the call is lost.
                $evidence['cancel_requested_at'] = now()->utc()->toIso8601String();
                $locked->forceFill(['evidence' => $evidence, 'next_poll_at' => now()->utc()->addSeconds(self::CANCEL_POLL_SECONDS)])->save();
                $this->audit($locked, $actor, 'REMOTE_OPERATION_CANCEL_REQUESTED', []);
            }

            // Reread: in memory, UTC timestamps would be reinterpreted in the application time zone.
            return $locked->refresh();
        }, attempts: 3);

        $this->sendCancel($operation, $this->connections->target($this->server($operation)));

        return $operation->refresh();
    }

    private function sendLaunch(RemoteOperation $operation, Server $server): RemoteOperation
    {
        $command = $this->registry->resolve($operation->command_key, $this->parameters($operation));
        try {
            $response = $this->agent->call($this->connections->target($server), 'launch',
                [$operation->operation_uuid, $command['key'], ...$command['arguments']]);
        } catch (RemoteAgentUnavailable $exception) {
            return $this->markUnreachable($operation, $exception->errorCode);
        }
        if (! $response->ok) {
            return match (true) {
                $response->errorCode === 'launch_in_progress' => $this->persist($operation, [
                    'last_observed_at' => now()->utc(), 'next_poll_at' => now()->utc()->addSeconds(self::CANCEL_POLL_SECONDS),
                ]),
                in_array($response->errorCode, self::REJECTED_LAUNCH, true) => $this->rejected($operation, (string) $response->errorCode),
                $response->errorCode === 'operation_conflict' => $this->lost($operation, 'operation_conflict'),
                default => $this->markUnreachable($operation, 'agent_'.$response->errorCode),
            };
        }
        $launch = $response->data['launch'] ?? null;
        if (! is_array($launch)) {
            return $this->lost($operation, 'launch_evidence_missing');
        }

        return $this->applyLaunch($operation, $launch);
    }

    /** @param array<string, mixed> $launch */
    private function applyLaunch(RemoteOperation $operation, array $launch): RemoteOperation
    {
        if (($launch['operation_uuid'] ?? null) !== $operation->operation_uuid || ($launch['command_key'] ?? null) !== $operation->command_key
            || ($launch['agent_sha256'] ?? null) !== ($operation->evidence['agent_sha256'] ?? null)
            || ! in_array($launch['launcher'] ?? null, ['systemd', 'nohup'], true) || ! is_string($launch['launched_at'] ?? null)
        ) {
            return $this->lost($operation, 'launch_evidence_mismatch');
        }
        $unit = $launch['launcher'] === 'systemd' && is_string($launch['unit'] ?? null) ? $launch['unit'] : null;

        return DB::transaction(function () use ($operation, $launch, $unit): RemoteOperation {
            $locked = RemoteOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->process_id !== null || $locked->communication_state === RemoteCommunicationState::TERMINATED) {
                return $locked;
            }
            $evidence = $locked->evidence ?? [];
            $evidence['launch'] = $launch;
            $locked->forceFill([
                'process_id' => $unit ?? 'nohup:'.$locked->operation_uuid,
                'process_start_identity' => (string) $launch['launched_at'],
                'communication_state' => RemoteCommunicationState::CONNECTED,
                'functional_state' => RemoteFunctionalState::RUNNING,
                'started_at' => $this->time($launch['launched_at']) ?? now()->utc(),
                'last_observed_at' => now()->utc(),
                'next_poll_at' => now()->utc()->addSeconds(self::POLL_SECONDS),
                'reconcile_attempts' => 0,
                'last_reconcile_error' => null,
                'evidence' => $evidence,
            ])->save();
            $this->audit($locked, null, 'REMOTE_OPERATION_LAUNCHED', ['process_id' => $locked->process_id, 'launcher' => $launch['launcher']]);

            // Reread: in memory, UTC timestamps would be reinterpreted in the application time zone.
            return $locked->refresh();
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    private function running(RemoteOperation $operation, array $data): RemoteOperation
    {
        $heartbeat = is_string($data['heartbeat_at'] ?? null) ? $this->time($data['heartbeat_at']) : null;
        $observed = is_string($data['observed_at'] ?? null) ? $this->time($data['observed_at']) : null;
        // Compare against the server's own clock so clock skew does not mark a healthy process stale.
        $stale = $heartbeat === null || $observed === null || $heartbeat->diffInSeconds($observed, true) > self::HEARTBEAT_STALE_SECONDS;

        return $this->persist($operation, [
            'communication_state' => $stale ? RemoteCommunicationState::DEGRADED : RemoteCommunicationState::CONNECTED,
            'functional_state' => RemoteFunctionalState::RUNNING,
            'last_heartbeat_at' => $heartbeat,
            'last_observed_at' => now()->utc(),
            'next_poll_at' => now()->utc()->addSeconds(isset($operation->evidence['cancel_requested_at']) ? self::CANCEL_POLL_SECONDS : self::POLL_SECONDS),
            'reconcile_attempts' => 0,
            'last_reconcile_error' => $stale ? 'heartbeat_stale' : null,
            'manual_intervention_required' => false,
        ]);
    }

    /** @param array<string, mixed> $exit */
    private function terminate(RemoteOperation $operation, array $exit): RemoteOperation
    {
        $code = $exit['exit_code'] ?? null;
        if (! is_int($code)) {
            return $this->lost($operation, 'exit_evidence_invalid');
        }

        return DB::transaction(function () use ($operation, $exit, $code): RemoteOperation {
            $locked = RemoteOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->communication_state === RemoteCommunicationState::TERMINATED) {
                return $locked;
            }
            $functional = match (true) {
                ($exit['cancel_requested'] ?? false) === true => RemoteFunctionalState::CANCELLED,
                $code === 0 => RemoteFunctionalState::SUCCEEDED,
                default => RemoteFunctionalState::FAILED,
            };
            $evidence = $locked->evidence ?? [];
            $evidence['exit'] = $exit;
            $locked->forceFill([
                'communication_state' => RemoteCommunicationState::TERMINATED,
                'functional_state' => $functional,
                'exit_code' => $code,
                'terminated_at' => is_string($exit['finished_at'] ?? null) ? ($this->time($exit['finished_at']) ?? now()->utc()) : now()->utc(),
                'last_observed_at' => now()->utc(),
                'next_poll_at' => null,
                'reconcile_attempts' => 0,
                'last_reconcile_error' => null,
                'manual_intervention_required' => false,
                'evidence' => $evidence,
            ])->save();
            $this->audit($locked, null, 'REMOTE_OPERATION_TERMINATED', ['functional_state' => $functional->value, 'exit_code' => $code]);

            // Reread: in memory, UTC timestamps would be reinterpreted in the application time zone.
            return $locked->refresh();
        }, attempts: 3);
    }

    /** The process is gone without trustworthy exit evidence: the result stays unknown. */
    private function lost(RemoteOperation $operation, string $reason): RemoteOperation
    {
        return DB::transaction(function () use ($operation, $reason): RemoteOperation {
            $locked = RemoteOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->communication_state === RemoteCommunicationState::TERMINATED) {
                return $locked;
            }
            $locked->forceFill([
                'communication_state' => RemoteCommunicationState::TERMINATED,
                'functional_state' => RemoteFunctionalState::UNKNOWN,
                'terminated_at' => now()->utc(),
                'last_observed_at' => now()->utc(),
                'next_poll_at' => null,
                'manual_intervention_required' => true,
                'last_reconcile_error' => $reason,
                'last_error' => $reason,
            ])->save();
            $this->audit($locked, null, 'REMOTE_OPERATION_RESULT_UNKNOWN', ['reason' => $reason]);

            // Reread: in memory, UTC timestamps would be reinterpreted in the application time zone.
            return $locked->refresh();
        }, attempts: 3);
    }

    private function rejected(RemoteOperation $operation, string $code): RemoteOperation
    {
        return DB::transaction(function () use ($operation, $code): RemoteOperation {
            $locked = RemoteOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->communication_state === RemoteCommunicationState::TERMINATED) {
                return $locked;
            }
            $locked->forceFill([
                'communication_state' => RemoteCommunicationState::TERMINATED,
                'functional_state' => RemoteFunctionalState::FAILED,
                'terminated_at' => now()->utc(),
                'last_observed_at' => now()->utc(),
                'next_poll_at' => null,
                'last_error' => 'launch_rejected:'.$code,
            ])->save();
            $this->audit($locked, null, 'REMOTE_OPERATION_LAUNCH_REJECTED', ['error_code' => $code]);

            // Reread: in memory, UTC timestamps would be reinterpreted in the application time zone.
            return $locked->refresh();
        }, attempts: 3);
    }

    /** Contact lost: keep polling with backoff and never infer a result. */
    private function markUnreachable(RemoteOperation $operation, string $code): RemoteOperation
    {
        return DB::transaction(function () use ($operation, $code): RemoteOperation {
            $locked = RemoteOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->communication_state === RemoteCommunicationState::TERMINATED) {
                return $locked;
            }
            $attempts = min(65_535, $locked->reconcile_attempts + 1);
            $manual = $attempts >= self::MANUAL_AFTER_ATTEMPTS;
            if ($manual && ! $locked->manual_intervention_required) {
                $this->audit($locked, null, 'REMOTE_OPERATION_MANUAL_INTERVENTION_REQUIRED', ['reason' => $code, 'attempts' => $attempts]);
            }
            $locked->forceFill([
                'communication_state' => RemoteCommunicationState::UNREACHABLE,
                'functional_state' => RemoteFunctionalState::UNKNOWN,
                'last_observed_at' => now()->utc(),
                'next_poll_at' => now()->utc()->addSeconds(min(self::MAX_BACKOFF_SECONDS, self::POLL_SECONDS * (2 ** min(6, $attempts - 1)))),
                'reconcile_attempts' => $attempts,
                'last_reconcile_error' => $code,
                'manual_intervention_required' => $manual,
            ])->save();

            // Reread: in memory, UTC timestamps would be reinterpreted in the application time zone.
            return $locked->refresh();
        }, attempts: 3);
    }

    private function sendCancel(RemoteOperation $operation, RemoteTarget $target): void
    {
        try {
            $this->agent->call($target, 'cancel', [$operation->operation_uuid], self::OBSERVATION_CALL_SECONDS);
        } catch (RemoteAgentUnavailable) {
            // The durable intent is repeated on the next reconciliation.
        }
    }

    /**
     * Reads new complete lines of the remote logs, bounded per call. The
     * offset and the stored lines are committed together, so a repeated
     * observation never duplicates a line.
     *
     * @param  array<string, mixed>  $data
     */
    private function collectLogs(RemoteOperation $operation, RemoteTarget $target, array $data, bool $final): void
    {
        foreach (['stdout' => LogStream::STDOUT, 'stderr' => LogStream::STDERR] as $stream => $logStream) {
            $size = $data[$stream.'_bytes'] ?? null;
            if (! is_int($size)) {
                continue;
            }
            for ($chunk = 0; $chunk < self::LOG_CHUNKS_PER_STREAM; $chunk++) {
                $offset = (int) (($operation->fresh()->evidence['log_offsets'][$stream] ?? 0));
                if ($size <= $offset) {
                    break;
                }
                try {
                    $tail = $this->agent->call($target, 'tail', [$operation->operation_uuid, $stream, (string) $offset, (string) self::LOG_CHUNK_BYTES], self::OBSERVATION_CALL_SECONDS);
                } catch (RemoteAgentUnavailable) {
                    return;
                }
                $bytes = $tail->ok ? base64_decode((string) ($tail->data['data_base64'] ?? ''), true) : false;
                if (! is_string($bytes) || ($tail->data['offset'] ?? null) !== $offset) {
                    return;
                }
                $complete = strrpos($bytes, "\n");
                $full = strlen($bytes) >= self::LOG_CHUNK_BYTES;
                $take = match (true) {
                    $complete !== false => $complete + 1,
                    $full || $final => strlen($bytes),
                    default => 0,
                };
                if ($take === 0) {
                    break;
                }
                $this->storeLines($operation, $stream, $logStream, $offset, substr($bytes, 0, $take));
            }
        }
    }

    private function storeLines(RemoteOperation $operation, string $stream, LogStream $logStream, int $offset, string $bytes): void
    {
        DB::transaction(function () use ($operation, $stream, $logStream, $offset, $bytes): void {
            $locked = RemoteOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            $evidence = $locked->evidence ?? [];
            if ((int) ($evidence['log_offsets'][$stream] ?? 0) !== $offset) {
                return;
            }
            $position = $offset;
            foreach (explode("\n", rtrim($bytes, "\n")) as $line) {
                $clean = mb_convert_encoding($line, 'UTF-8', 'UTF-8');
                ExecutionLog::query()->create([
                    'execution_id' => $locked->execution_id,
                    'remote_operation_id' => $locked->getKey(),
                    'stream' => $logStream,
                    'level' => $logStream === LogStream::STDERR ? 'WARNING' : 'INFO',
                    'message' => $this->redactor->redactString(mb_substr($clean, 0, 4000)),
                    'context' => ['remote_operation_uuid' => $locked->operation_uuid, 'offset' => $position],
                    'logged_at' => now()->utc(),
                ]);
                $position += strlen($line) + 1;
            }
            $evidence['log_offsets'][$stream] = $offset + strlen($bytes);
            $locked->forceFill(['evidence' => $evidence])->save();
        }, attempts: 3);
    }

    /** @param array<string, mixed> $attributes */
    private function persist(RemoteOperation $operation, array $attributes): RemoteOperation
    {
        return DB::transaction(function () use ($operation, $attributes): RemoteOperation {
            $locked = RemoteOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->communication_state === RemoteCommunicationState::TERMINATED) {
                return $locked;
            }
            $locked->forceFill($attributes)->save();

            // Reread: in memory, UTC timestamps would be reinterpreted in the application time zone.
            return $locked->refresh();
        }, attempts: 3);
    }

    private function server(RemoteOperation $operation): Server
    {
        return Server::query()->where('uuid', (string) $operation->host_id)->firstOrFail();
    }

    /** @return array<string, string> */
    private function parameters(RemoteOperation $operation): array
    {
        $parameters = $operation->evidence['parameters'] ?? [];

        return is_array($parameters) ? array_map('strval', $parameters) : [];
    }

    private function time(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $payload */
    private function audit(RemoteOperation $operation, ?User $actor, string $action, array $payload): void
    {
        $execution = Execution::query()->whereKey($operation->execution_id)->first(['id', 'project_id']);
        AuditLog::query()->create([
            'actor_id' => $actor?->getKey(),
            'project_id' => $execution?->project_id,
            'execution_id' => $operation->execution_id,
            'action' => $action,
            'auditable_type' => $operation->getMorphClass(),
            'auditable_id' => $operation->getKey(),
            'payload' => ['operation_uuid' => $operation->operation_uuid, 'server_uuid' => $operation->host_id, ...$payload],
        ]);
    }
}
