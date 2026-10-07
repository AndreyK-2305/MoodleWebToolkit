<?php

namespace App\Domain\Executions;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Processes\RegisteredCommandRunner;
use App\Enums\LogStream;
use App\Enums\RemoteCommunicationState;
use App\Enums\RemoteFunctionalState;
use App\Models\Execution;
use App\Models\ExecutionLog;
use App\Models\RemoteOperation;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Jobs\RunRegisteredRemoteOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RemoteOperationCoordinator
{
    public function __construct(
        private readonly RegisteredCommandRunner $runner,
        private readonly LocalProcessInspector $inspector,
        private readonly SensitiveValueRedactor $redactor,
        private readonly ExecutionWorkspaceManager $workspaces,
    ) {}

    /** @param array<string, string> $parameters */
    public function execute(Execution $execution, string $idempotencyKey, string $commandKey, array $parameters = [], string $workingDirectory = ''): RemoteOperation
    {
        [$operation, $created] = $this->createOperation($execution, $idempotencyKey, $commandKey, $parameters, $workingDirectory);

        return $created
            ? $this->runScheduled($operation, $parameters, $workingDirectory)
            : $this->reconcile($operation);
    }

    /** @param array<string, string> $parameters */
    public function schedule(Execution $execution, string $idempotencyKey, string $commandKey, array $parameters = [], string $workingDirectory = ''): RemoteOperation
    {
        [$operation, $created] = $this->createOperation($execution, $idempotencyKey, $commandKey, $parameters, $workingDirectory);
        if (! $created) {
            return $operation;
        }

        try {
            $connection = config('queue.default') === 'sync' ? 'sync' : 'redis-tool-runs';
            dispatch((new RunRegisteredRemoteOperation(
                (int) $operation->getKey(),
                $commandKey,
                $parameters,
                $workingDirectory,
            ))->onConnection($connection)->onQueue('tool-runs'));
        } catch (\Throwable $exception) {
            $operation->forceFill([
                'communication_state' => RemoteCommunicationState::UNREACHABLE,
                'functional_state' => RemoteFunctionalState::UNKNOWN,
                'last_observed_at' => now()->utc(),
                'next_poll_at' => null,
                'last_error' => $this->redactor->redactString($exception->getMessage()),
            ])->save();
            $this->persistState($operation);
            throw $exception;
        }

        return $operation->refresh();
    }

    /** @param array<string, string> $parameters */
    public function runScheduled(int $operationId, string $commandKey, array $parameters, string $workingDirectory): RemoteOperation
    {
        $operation = DB::transaction(function () use ($operationId, $commandKey): ?RemoteOperation {
            $locked = RemoteOperation::query()->lockForUpdate()->findOrFail($operationId);
            if ($locked->command_key !== $commandKey) {
                throw new RuntimeException('La clave del comando no coincide con la operación durable.');
            }
            if ($locked->communication_state === RemoteCommunicationState::TERMINATED || $locked->process_id !== null) {
                return null;
            }
            if ($locked->launch_claimed_at !== null) {
                return null;
            }

            $locked->forceFill([
                'launch_claimed_at' => now()->utc(),
                'communication_state' => RemoteCommunicationState::RECONCILING,
                'functional_state' => RemoteFunctionalState::STARTING,
                'last_observed_at' => now()->utc(),
                'next_poll_at' => now()->utc()->addSeconds(15),
            ])->save();

            return $locked;
        });

        if ($operation === null) {
            $existing = RemoteOperation::query()->findOrFail($operationId);

            return $existing->process_id !== null ? $this->reconcile($existing) : $existing;
        }

        $this->persistState($operation);

        return $this->runProcess($operation, $parameters, $workingDirectory);
    }

    /** @param array<string, string> $parameters @return array{RemoteOperation, bool} */
    private function createOperation(Execution $execution, string $idempotencyKey, string $commandKey, array $parameters, string $workingDirectory): array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $idempotencyKey) !== 1) {
            throw new RuntimeException('La clave de idempotencia de la operación no es válida.');
        }

        $canonicalParameters = $parameters;
        ksort($canonicalParameters, SORT_STRING);
        $commandHash = hash('sha256', json_encode([
            'command_key' => $commandKey,
            'parameters' => $canonicalParameters,
            'working_directory' => $workingDirectory,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($execution, $idempotencyKey, $commandKey, $commandHash): array {
            $existing = RemoteOperation::query()
                ->where('execution_id', $execution->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals($existing->command_sha256, $commandHash) || $existing->command_key !== $commandKey) {
                    throw new RuntimeException('La clave de idempotencia ya está asociada a otro comando.');
                }

                return [$existing, false];
            }

            return [RemoteOperation::query()->create([
                'execution_id' => $execution->getKey(),
                'operation_uuid' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'provider_key' => 'local-registered-process',
                'host_id' => gethostname() ?: 'local',
                'runtime_key' => 'workspace-process-v1',
                'command_key' => $commandKey,
                'command_sha256' => $commandHash,
                'communication_state' => RemoteCommunicationState::RECONCILING,
                'functional_state' => RemoteFunctionalState::STARTING,
                'next_poll_at' => now()->utc(),
            ]), true];
        });
    }

    /** @param array<string, string> $parameters */
    private function runProcess(RemoteOperation $operation, array $parameters, string $workingDirectory): RemoteOperation
    {
        $execution = $operation->execution;
        try {
            $result = $this->runner->run(
                $execution,
                $operation->command_key,
                $parameters,
                'tools',
                $workingDirectory,
                $operation->operation_uuid,
                function (int $pid) use ($operation): void {
                    $operation->forceFill([
                        'process_id' => (string) $pid,
                        'communication_state' => RemoteCommunicationState::CONNECTED,
                        'functional_state' => RemoteFunctionalState::RUNNING,
                        'started_at' => now()->utc(),
                        'last_heartbeat_at' => now()->utc(),
                        'last_observed_at' => now()->utc(),
                        'next_poll_at' => now()->utc()->addSeconds(15),
                    ])->save();
                    $this->persistState($operation);
                    $operation->refresh();
                    if (isset(($operation->evidence ?? [])['cancel_requested_at'])) {
                        $this->inspector->terminate($operation);
                    }
                },
                function (int $pid) use ($operation): void {
                    $operation->forceFill([
                        'communication_state' => RemoteCommunicationState::CONNECTED,
                        'functional_state' => RemoteFunctionalState::RUNNING,
                        'last_heartbeat_at' => now()->utc(),
                        'last_observed_at' => now()->utc(),
                        'next_poll_at' => now()->utc()->addSeconds(15),
                    ])->save();
                },
            );

            $stdout = $this->redactor->redactString($result->stdout);
            $stderr = $this->redactor->redactString($result->stderr);
            $this->storeLog($execution, LogStream::STDOUT, $stdout, $operation);
            $this->storeLog($execution, LogStream::STDERR, $stderr, $operation);
            $now = now()->utc();
            $operation->refresh();
            $cancelRequested = isset(($operation->evidence ?? [])['cancel_requested_at']);
            $operation->forceFill([
                'communication_state' => RemoteCommunicationState::TERMINATED,
                'functional_state' => $cancelRequested
                    ? RemoteFunctionalState::CANCELLED
                    : ($result->successful() ? RemoteFunctionalState::SUCCEEDED : RemoteFunctionalState::FAILED),
                'last_observed_at' => $now,
                'next_poll_at' => null,
                'terminated_at' => $now,
                'exit_code' => $result->exitCode,
                'evidence' => [
                    ...($operation->evidence ?? []),
                    'process_id' => $result->processId,
                    'timed_out' => $result->timedOut,
                    'output_truncated' => $result->outputTruncated,
                    'resource_limit_exceeded' => $result->resourceLimitExceeded,
                    'stdout_sha256' => hash('sha256', $stdout),
                    'stderr_sha256' => hash('sha256', $stderr),
                ],
                'last_error' => $cancelRequested || $result->successful() ? null : mb_substr($stderr, 0, 2000),
            ])->save();
            $this->persistState($operation);

            return $operation->refresh();
        } catch (\Throwable $exception) {
            $operation->refresh()->forceFill([
                'communication_state' => RemoteCommunicationState::UNREACHABLE,
                'functional_state' => RemoteFunctionalState::UNKNOWN,
                'last_observed_at' => now()->utc(),
                'next_poll_at' => now()->utc()->addSeconds(30),
                'last_error' => $this->redactor->redactString($exception->getMessage()),
            ])->save();
            $this->persistState($operation);
            throw $exception;
        }
    }

    public function reconcile(RemoteOperation $operation): RemoteOperation
    {
        $operation->refresh();
        if ($operation->communication_state === RemoteCommunicationState::TERMINATED) {
            return $operation;
        }

        if ($this->inspector->isRunning($operation)) {
            $operation->forceFill([
                'communication_state' => RemoteCommunicationState::CONNECTED,
                'functional_state' => RemoteFunctionalState::RUNNING,
                'last_heartbeat_at' => now()->utc(),
                'last_observed_at' => now()->utc(),
                'next_poll_at' => now()->utc()->addSeconds(15),
                'last_error' => null,
            ])->save();
            $this->persistState($operation);

            return $operation->refresh();
        }

        if ($operation->process_id === null) {
            $claimIsRecent = $operation->launch_claimed_at !== null
                && $operation->launch_claimed_at->greaterThan(now()->utc()->subMinute());

            if ($operation->launch_claimed_at === null || $claimIsRecent) {
                $operation->forceFill([
                    'communication_state' => RemoteCommunicationState::RECONCILING,
                    'functional_state' => RemoteFunctionalState::STARTING,
                    'last_observed_at' => now()->utc(),
                    'next_poll_at' => now()->utc()->addSeconds(15),
                    'last_error' => null,
                ])->save();
                $this->persistState($operation);

                return $operation->refresh();
            }
        }

        $cancelRequested = isset(($operation->evidence ?? [])['cancel_requested_at']);

        if ($cancelRequested) {
            $operation->forceFill([
                'communication_state' => RemoteCommunicationState::TERMINATED,
                'functional_state' => RemoteFunctionalState::CANCELLED,
                'last_observed_at' => now()->utc(),
                'next_poll_at' => null,
                'terminated_at' => now()->utc(),
                'last_error' => null,
            ])->save();
            $this->persistState($operation);

            return $operation->refresh();
        }

        $operation->forceFill([
            'communication_state' => RemoteCommunicationState::UNREACHABLE,
            'functional_state' => RemoteFunctionalState::UNKNOWN,
            'last_observed_at' => now()->utc(),
            'next_poll_at' => null,
            'last_error' => 'No se confirmó un estado terminal ni se encontró el proceso registrado; no se volverá a lanzar automáticamente.',
        ])->save();
        $this->persistState($operation);

        return $operation->refresh();
    }

    public function heartbeat(RemoteOperation $operation): RemoteOperation
    {
        if (! $this->inspector->isRunning($operation)) {
            return $this->reconcile($operation);
        }

        $now = now()->utc();
        $operation->forceFill([
            'communication_state' => RemoteCommunicationState::CONNECTED,
            'functional_state' => RemoteFunctionalState::RUNNING,
            'last_heartbeat_at' => $now,
            'last_observed_at' => $now,
            'next_poll_at' => $now->addSeconds(15),
        ])->save();
        $this->persistState($operation);

        return $operation->refresh();
    }

    public function cancel(RemoteOperation $operation): RemoteOperation
    {
        $operation = DB::transaction(function () use ($operation): RemoteOperation {
            $locked = RemoteOperation::query()->lockForUpdate()->findOrFail($operation->getKey());
            if ($locked->communication_state === RemoteCommunicationState::TERMINATED) {
                return $locked;
            }

            $now = now()->utc();
            $fields = [
                'last_observed_at' => $now,
                'evidence' => [...($locked->evidence ?? []), 'cancel_requested_at' => $now->toIso8601String()],
            ];
            if ($locked->process_id === null && $locked->launch_claimed_at === null) {
                $fields += [
                    'communication_state' => RemoteCommunicationState::TERMINATED,
                    'functional_state' => RemoteFunctionalState::CANCELLED,
                    'terminated_at' => $now,
                    'next_poll_at' => null,
                ];
            } else {
                $fields += [
                    'communication_state' => RemoteCommunicationState::DEGRADED,
                    'next_poll_at' => $now->addSeconds(2),
                ];
            }

            $locked->forceFill($fields)->save();

            return $locked;
        });
        $this->persistState($operation);

        if ($operation->communication_state === RemoteCommunicationState::TERMINATED || $operation->process_id === null) {
            return $operation->refresh();
        }

        if (! $this->inspector->terminate($operation)) {
            return $this->reconcile($operation);
        }

        return $operation->refresh();
    }

    private function storeLog(Execution $execution, LogStream $stream, string $message, RemoteOperation $operation): void
    {
        if ($message === '') {
            return;
        }

        ExecutionLog::query()->create([
            'execution_id' => $execution->getKey(),
            'stream' => $stream,
            'level' => $stream === LogStream::STDERR ? 'WARNING' : 'INFO',
            'message' => mb_substr($message, 0, 1_048_576),
            'context' => ['operation_uuid' => $operation->operation_uuid, 'command_key' => $operation->command_key],
            'logged_at' => now()->utc(),
        ]);
    }

    private function persistState(RemoteOperation $operation): void
    {
        $operation->refresh();
        $this->workspaces->writeState($operation->execution, 'remote-op-'.$operation->operation_uuid.'.json', [
            'operation_uuid' => $operation->operation_uuid,
            'idempotency_key' => $operation->idempotency_key,
            'provider_key' => $operation->provider_key,
            'host_id' => $operation->host_id,
            'runtime_key' => $operation->runtime_key,
            'process_id' => $operation->process_id,
            'command_key' => $operation->command_key,
            'command_sha256' => $operation->command_sha256,
            'communication_state' => $operation->communication_state->value,
            'functional_state' => $operation->functional_state->value,
            'launch_claimed_at' => $operation->launch_claimed_at?->toIso8601String(),
            'last_heartbeat_at' => $operation->last_heartbeat_at?->toIso8601String(),
            'last_observed_at' => $operation->last_observed_at?->toIso8601String(),
            'terminated_at' => $operation->terminated_at?->toIso8601String(),
            'exit_code' => $operation->exit_code,
            'evidence' => $operation->evidence,
        ]);
    }
}
