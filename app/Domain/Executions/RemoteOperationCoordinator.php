<?php

namespace App\Domain\Executions;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Processes\RegisteredCommandRunner;
use App\Domain\Processes\RegisteredCommandRegistry;
use App\Enums\LogStream;
use App\Enums\RemoteCommunicationState;
use App\Enums\RemoteFunctionalState;
use App\Models\Execution;
use App\Models\ExecutionLog;
use App\Models\AuditLog;
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
        private readonly RegisteredCommandRegistry $registry,
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
                'next_poll_at' => now()->utc()->addSeconds(15),
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

        try {
            $registeredDefinition = $this->registry->resolve($commandKey, $parameters);
            if (! hash_equals($operation->command_sha256, $this->commandHash(
                $commandKey,
                $parameters,
                $workingDirectory,
                $registeredDefinition['artifact_descriptors'],
                $registeredDefinition,
            ))) {
                throw new RuntimeException('La definición registrada cambió después de fijar el binding de la operación.');
            }
            $request = [
                'schema_version' => 'remote-operation-request.v1',
                'operation_id' => $operation->getKey(),
                'operation_uuid' => $operation->operation_uuid,
                'command_sha256' => $operation->command_sha256,
                'command_key' => $commandKey,
                'parameters' => $parameters,
                'working_directory' => $workingDirectory,
                'registered_definition' => $registeredDefinition,
            ];
            $requestPath = $this->workspaces->operationEvidencePath($operation->execution, $operation->operation_uuid, 'request.json');
            $this->workspaces->writeAtomic($operation->execution, 'state', 'remote-operations/'.$operation->operation_uuid.'/request.json', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill')) {
                throw new RuntimeException('El runtime durable requiere Linux y grupos de procesos POSIX.');
            }
            $setsid = (string) config('toolkit.runner.session_wrapper', '/usr/bin/setsid');
            if (! is_file($setsid) || ! is_executable($setsid)) {
                throw new RuntimeException('No se encontró setsid para separar el supervisor del worker Laravel.');
            }
            $launcherEnvironment = getenv();
            $launcherEnvironment = is_array($launcherEnvironment) ? $launcherEnvironment : [];
            $launcherEnvironment['PATH'] = (string) ($launcherEnvironment['PATH'] ?? '/usr/bin:/bin');
            $launcherEnvironment['LANG'] = (string) ($launcherEnvironment['LANG'] ?? 'C.UTF-8');
            $launcherEnvironment['TOOL_LOCAL_RUNNER_ENABLED'] = config('toolkit.features.local_runner.enabled') ? 'true' : 'false';
            $launcherEnvironment['TOOL_WORKSPACES_ROOT'] = (string) config('toolkit.workspaces.root');
            $launcherEnvironment['TOOL_WORKSPACE_QUOTA_BYTES'] = (string) config('toolkit.workspaces.quota_bytes');
            $launcherEnvironment['TOOL_RUNNER_MAX_OUTPUT_BYTES'] = (string) config('toolkit.runner.max_output_bytes');
            $launcherEnvironment['TOOL_RUNNER_ENFORCE_OS_LIMITS'] = config('toolkit.runner.enforce_os_limits') ? 'true' : 'false';
            $launcherEnvironment['TOOL_RUNNER_LIMIT_WRAPPER'] = (string) config('toolkit.runner.limit_wrapper');
            $launcherEnvironment['TOOL_RUNNER_SESSION_WRAPPER'] = (string) config('toolkit.runner.session_wrapper');
            $limits = config('toolkit.runner.limits', []);
            $launcherEnvironment['TOOL_RUNNER_CPU_SECONDS'] = (string) ($limits['cpu_seconds'] ?? 86400);
            $launcherEnvironment['TOOL_RUNNER_MEMORY_BYTES'] = (string) ($limits['memory_bytes'] ?? 8_589_934_592);
            $launcherEnvironment['TOOL_RUNNER_MAX_PROCESSES'] = (string) ($limits['processes'] ?? 128);
            $launcherEnvironment['TOOL_RUNNER_MAX_FILE_BYTES'] = (string) ($limits['file_bytes'] ?? 1_099_511_627_776);
            $launcherEnvironment['TOOL_RUNNER_CANCEL_GRACE_SECONDS'] = (string) config('toolkit.runner.cancel_grace_seconds');
            $launcherEnvironment['TOOL_RUNNER_ALLOW_FORCE_KILL'] = config('toolkit.runner.allow_force_kill') ? 'true' : 'false';
            $launcher = proc_open(
                [$setsid, '-f', PHP_BINARY, base_path('artisan'), 'toolkit:run-detached-remote-operation', $requestPath],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
                $pipes,
                base_path(),
                $launcherEnvironment,
                ['bypass_shell' => true],
            );
            if (! is_resource($launcher)) {
                throw new RuntimeException('No se pudo separar el supervisor de la operación.');
            }
            $launcherStatus = proc_get_status($launcher);
            if ($launcherStatus['running']) {
                usleep(100_000);
            }
            proc_close($launcher);

            $operation->forceFill([
                'last_observed_at' => now()->utc(),
                'next_poll_at' => now()->utc()->addSeconds(5),
                'evidence' => [...($operation->evidence ?? []), 'supervisor_dispatched_at' => now()->utc()->toIso8601String()],
            ])->save();
            $this->persistState($operation);
            $deadline = microtime(true) + min(5, max(1, (int) config('toolkit.runner.supervisor_start_timeout_seconds', 3)));
            $launchPath = $this->workspaces->operationEvidencePath($operation->execution, $operation->operation_uuid, 'launch.json');
            $exitPath = $this->workspaces->operationEvidencePath($operation->execution, $operation->operation_uuid, 'exit.json');
            do {
                if ((is_file($launchPath) && ! is_link($launchPath)) || (is_file($exitPath) && ! is_link($exitPath))) {
                    return $this->reconcile($operation);
                }
                usleep(50_000);
            } while (microtime(true) < $deadline);
        } catch (\Throwable $exception) {
            $operation->forceFill([
                'communication_state' => RemoteCommunicationState::UNREACHABLE,
                'functional_state' => RemoteFunctionalState::UNKNOWN,
                'last_observed_at' => now()->utc(),
                'next_poll_at' => now()->utc()->addSeconds(15),
                'last_error' => $this->redactor->redactString($exception->getMessage()),
            ])->save();
            $this->persistState($operation);
        }

        return $operation->refresh();
    }

    /** @param array<string, string> $parameters */
    public function runDetached(int $operationId, string $commandKey, array $parameters, string $workingDirectory, array $registeredDefinition): RemoteOperation
    {
        $operation = RemoteOperation::query()->with('execution')->findOrFail($operationId);
        if ($operation->provider_key !== 'local-registered-process'
            || $operation->host_id !== (gethostname() ?: 'local')
            || $operation->runtime_key !== 'workspace-process-v2'
            || $operation->command_key !== $commandKey
            || $operation->communication_state === RemoteCommunicationState::TERMINATED
            || ! hash_equals($operation->command_sha256, $this->commandHash(
                $commandKey,
                $parameters,
                $workingDirectory,
                $registeredDefinition['artifact_descriptors'] ?? [],
                $registeredDefinition,
            ))
        ) {
            throw new RuntimeException('El supervisor rechazó una identidad o un hash de comando diferente al binding.');
        }
        if (isset(($operation->evidence ?? [])['cancel_requested_at']) && $operation->process_id === null) {
            $operation->forceFill([
                'communication_state' => RemoteCommunicationState::TERMINATED,
                'functional_state' => RemoteFunctionalState::CANCELLED,
                'terminated_at' => now()->utc(),
                'next_poll_at' => null,
                'last_observed_at' => now()->utc(),
                'evidence' => [...($operation->evidence ?? []), 'cancelled_before_process_start' => true],
            ])->save();
            $this->persistState($operation);

            return $operation->refresh();
        }

        return $this->runProcess($operation, $parameters, $workingDirectory, $registeredDefinition);
    }

    /** @param array<string, string> $parameters @return array{RemoteOperation, bool} */
    private function createOperation(Execution $execution, string $idempotencyKey, string $commandKey, array $parameters, string $workingDirectory): array
    {
        if (! (bool) config('toolkit.features.local_runner.enabled', false)) {
            throw new RuntimeException('El runtime local está deshabilitado por feature flag.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $idempotencyKey) !== 1) {
            throw new RuntimeException('La clave de idempotencia de la operación no es válida.');
        }
        if ($this->redactor->redactString($workingDirectory) !== $workingDirectory) {
            throw new RuntimeException('La ruta de trabajo contiene material sensible y no se persistirá en la operación.');
        }

        $definition = $this->registry->resolve($commandKey, $parameters);
        $commandHash = $this->commandHash($commandKey, $parameters, $workingDirectory, $definition['artifact_descriptors'], $definition);

        return DB::transaction(function () use ($execution, $idempotencyKey, $commandKey, $commandHash, $definition): array {
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
                'runtime_key' => 'workspace-process-v2',
                'command_key' => $commandKey,
                'command_sha256' => $commandHash,
                'communication_state' => RemoteCommunicationState::RECONCILING,
                'functional_state' => RemoteFunctionalState::STARTING,
                'next_poll_at' => now()->utc(),
                'evidence' => [
                    'artifact_descriptors' => $definition['artifact_descriptors'],
                    'cancellable' => $definition['cancellable'],
                ],
            ]), true];
        });
    }

    /** @param array<string, string> $parameters @param list<array<string, mixed>> $descriptors */
    private function commandHash(string $commandKey, array $parameters, string $workingDirectory, array $descriptors = [], array $definition = []): string
    {
        ksort($parameters, SORT_STRING);

        return hash('sha256', json_encode([
            'command_key' => $commandKey,
            'parameters' => $parameters,
            'working_directory' => $workingDirectory,
            'artifact_descriptors' => $descriptors,
            'registered_definition' => [
                'argv' => $definition['argv'] ?? [],
                'environment' => $definition['environment'] ?? [],
                'timeout' => $definition['timeout'] ?? null,
                'max_output_bytes' => $definition['max_output_bytes'] ?? null,
                'cancellable' => $definition['cancellable'] ?? false,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, string> $parameters */
    private function runProcess(RemoteOperation $operation, array $parameters, string $workingDirectory, array $registeredDefinition): RemoteOperation
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
                    $identity = $this->inspector->identity($pid, $operation);
                    if ($identity === null) {
                        throw new RuntimeException('El proceso iniciado no acreditó UUID, hash y grupo de procesos esperados.');
                    }
                    $startedAt = now()->utc();
                    $this->workspaces->writeOperationEvidence($operation->execution, $operation->operation_uuid, 'launch.json', [
                        'schema_version' => 'remote-operation-launch.v1',
                        'operation_uuid' => $operation->operation_uuid,
                        'command_sha256' => $operation->command_sha256,
                        'pid' => $pid,
                        'pgid' => $identity['process_group_id'],
                        'process_start_identity' => $identity['process_start_identity'],
                        'host_id' => $operation->host_id,
                        'runtime_key' => $operation->runtime_key,
                        'started_at' => $startedAt->toIso8601String(),
                    ]);
                    $operation->forceFill([
                        'process_id' => (string) $pid,
                        'process_group_id' => $identity['process_group_id'],
                        'process_start_identity' => $identity['process_start_identity'],
                        'communication_state' => RemoteCommunicationState::CONNECTED,
                        'functional_state' => RemoteFunctionalState::RUNNING,
                        'started_at' => $startedAt,
                        'last_heartbeat_at' => $startedAt,
                        'last_observed_at' => $startedAt,
                        'next_poll_at' => $startedAt->addSeconds(15),
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
                    $this->workspaces->writeOperationEvidence($operation->execution, $operation->operation_uuid, 'heartbeat.json', [
                        'schema_version' => 'remote-operation-heartbeat.v1',
                        'operation_uuid' => $operation->operation_uuid,
                        'command_sha256' => $operation->command_sha256,
                        'pid' => $pid,
                        'pgid' => $operation->process_group_id,
                        'process_start_identity' => $operation->process_start_identity,
                        'observed_at' => now()->utc()->toIso8601String(),
                    ]);
                },
                $operation->command_sha256,
                $registeredDefinition,
            );

            $stdout = $this->redactor->redactString($result->stdout);
            $stderr = $this->redactor->redactString($result->stderr);
            $this->storeLog($execution, LogStream::STDOUT, $stdout, $operation);
            $this->storeLog($execution, LogStream::STDERR, $stderr, $operation);
            $now = now()->utc();
            $operation->refresh();
            $cancelRequested = isset(($operation->evidence ?? [])['cancel_requested_at']);
            $exitEvidence = [
                'schema_version' => 'remote-operation-exit.v1',
                'operation_uuid' => $operation->operation_uuid,
                'command_sha256' => $operation->command_sha256,
                'pid' => $operation->process_id === null ? $result->processId : (int) $operation->process_id,
                'pgid' => $operation->process_group_id,
                'process_start_identity' => $operation->process_start_identity,
                'host_id' => $operation->host_id,
                'runtime_key' => $operation->runtime_key,
                'started_at' => $operation->started_at?->toIso8601String(),
                'finished_at' => $now->toIso8601String(),
                'exit_code' => $result->exitCode,
                'timed_out' => $result->timedOut,
                'resource_limit_exceeded' => $result->resourceLimitExceeded,
                'cancelled' => $cancelRequested,
                'stdout_size_bytes' => $result->stdoutBytes,
                'stderr_size_bytes' => $result->stderrBytes,
                'stdout_sha256' => $result->stdoutSha256,
                'stderr_sha256' => $result->stderrSha256,
                'output_limit_bytes' => (int) config('toolkit.runner.max_output_bytes', 1_048_576),
                'output_truncated' => $result->outputTruncated,
            ];
            $this->workspaces->writeOperationEvidence($execution, $operation->operation_uuid, 'exit.json', $exitEvidence);
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
                    'stdout_sha256' => $result->stdoutSha256,
                    'stderr_sha256' => $result->stderrSha256,
                    'stdout_size_bytes' => $result->stdoutBytes,
                    'stderr_size_bytes' => $result->stderrBytes,
                    'exit_evidence' => $exitEvidence,
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
        return DB::transaction(function () use ($operation): RemoteOperation {
            $locked = RemoteOperation::query()->with('execution')->lockForUpdate()->findOrFail($operation->getKey());

            return $this->reconcileLocked($locked);
        }, attempts: 3);
    }

    private function reconcileLocked(RemoteOperation $operation): RemoteOperation
    {
        $operation->refresh();
        if ($operation->communication_state === RemoteCommunicationState::TERMINATED) {
            return $operation;
        }

        $launch = $this->readOperationEvidence($operation, 'launch.json');
        if ($launch !== null) {
            if (! $this->validLaunchEvidence($operation, $launch)) {
                return $this->markUnreachable($operation, 'La evidencia launch.json no coincide con la identidad durable de la operación.');
            }
            if ($operation->process_id === null) {
                $operation->forceFill([
                    'process_id' => (string) $launch['pid'],
                    'process_group_id' => (string) $launch['pgid'],
                    'process_start_identity' => (string) $launch['process_start_identity'],
                    'started_at' => $launch['started_at'],
                    'host_id' => $operation->host_id,
                    'last_observed_at' => now()->utc(),
                ])->save();
                $operation->refresh();
            } elseif ((int) $operation->process_id !== (int) $launch['pid']
                || (string) $operation->process_group_id !== (string) $launch['pgid']
                || (string) $operation->process_start_identity !== (string) $launch['process_start_identity']
            ) {
                return $this->markUnreachable($operation, 'La identidad del proceso no coincide con launch.json; se rechazó una posible reutilización de PID.');
            }
        }

        $exit = $this->readOperationEvidence($operation, 'exit.json');
        if ($exit !== null) {
            if (! $this->validExitEvidence($operation, $exit, $launch)) {
                return $this->markUnreachable($operation, 'La evidencia exit.json no pasó validación de identidad, hash o integridad.');
            }

            $finishedAt = now()->utc();
            $cancelled = $exit['cancelled'] === true;
            $successful = $exit['exit_code'] === 0 && $exit['timed_out'] === false && $exit['resource_limit_exceeded'] === false;
            $operation->forceFill([
                'communication_state' => RemoteCommunicationState::TERMINATED,
                'functional_state' => $cancelled ? RemoteFunctionalState::CANCELLED : ($successful ? RemoteFunctionalState::SUCCEEDED : RemoteFunctionalState::FAILED),
                'last_observed_at' => $finishedAt,
                'next_poll_at' => null,
                'terminated_at' => $exit['finished_at'],
                'exit_code' => $exit['exit_code'],
                'reconcile_attempts' => 0,
                'last_reconcile_error' => null,
                'manual_intervention_required' => false,
                'evidence' => [...($operation->evidence ?? []), 'exit_evidence' => $exit],
                'last_error' => $cancelled || $successful ? null : 'La operación terminó sin éxito; consulte stderr.log y exit.json.',
            ])->save();
            $this->persistState($operation);

            return $operation->refresh();
        }

        if ($this->inspector->isRunning($operation)) {
            $operation->forceFill([
                'communication_state' => RemoteCommunicationState::CONNECTED,
                'functional_state' => RemoteFunctionalState::RUNNING,
                'last_heartbeat_at' => now()->utc(),
                'last_observed_at' => now()->utc(),
                'next_poll_at' => now()->utc()->addSeconds(15),
                'reconcile_attempts' => 0,
                'last_reconcile_error' => null,
                'manual_intervention_required' => false,
                'last_error' => null,
            ])->save();
            $this->persistState($operation);

            return $operation->refresh();
        }

        $heartbeat = $this->readOperationEvidence($operation, 'heartbeat.json');
        if ($heartbeat !== null && $this->validHeartbeatEvidence($operation, $heartbeat)
            && strtotime((string) $heartbeat['observed_at']) >= now()->utc()->subSeconds(45)->timestamp
        ) {
            $operation->forceFill([
                'communication_state' => RemoteCommunicationState::DEGRADED,
                'functional_state' => RemoteFunctionalState::RUNNING,
                'last_heartbeat_at' => $heartbeat['observed_at'],
                'last_observed_at' => now()->utc(),
                'next_poll_at' => now()->utc()->addSeconds(15),
                'last_error' => 'El último heartbeat es reciente, pero no se confirmó el proceso en /proc.',
            ])->save();
            $this->persistState($operation);

            return $operation->refresh();
        }

        if ($operation->process_id === null && $operation->launch_claimed_at !== null
            && $operation->launch_claimed_at->greaterThan(now()->utc()->subMinute())
        ) {
            $operation->forceFill([
                'communication_state' => RemoteCommunicationState::RECONCILING,
                'functional_state' => RemoteFunctionalState::STARTING,
                'last_observed_at' => now()->utc(),
                'next_poll_at' => now()->utc()->addSeconds(15),
            ])->save();
            $this->persistState($operation);

            return $operation->refresh();
        }

        return $this->markUnreachable($operation, $operation->process_id === null
            ? 'No existe launch ni exit válido; el estado queda desconocido y no se volverá a lanzar automáticamente.'
            : 'El PID ya no acredita la identidad registrada y no existe exit.json; nunca se infiere éxito por ausencia del proceso.');
    }

    /** @return array<string, mixed>|null */
    private function readOperationEvidence(RemoteOperation $operation, string $name): ?array
    {
        try {
            $path = $this->workspaces->operationEvidencePath($operation->execution, $operation->operation_uuid, $name);
            if (is_link($path) || ! is_file($path)) {
                return null;
            }
            $decoded = json_decode((string) file_get_contents($path), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $evidence */
    private function validLaunchEvidence(RemoteOperation $operation, array $evidence): bool
    {
        return ($evidence['schema_version'] ?? null) === 'remote-operation-launch.v1'
            && ($evidence['operation_uuid'] ?? null) === $operation->operation_uuid
            && is_string($evidence['command_sha256'] ?? null) && hash_equals($operation->command_sha256, $evidence['command_sha256'])
            && is_int($evidence['pid'] ?? null) && $evidence['pid'] > 1
            && (string) ($evidence['pgid'] ?? '') === (string) $evidence['pid']
            && is_string($evidence['process_start_identity'] ?? null) && ctype_digit($evidence['process_start_identity'])
            && ($evidence['host_id'] ?? null) === $operation->host_id
            && ($evidence['runtime_key'] ?? null) === $operation->runtime_key
            && is_string($evidence['started_at'] ?? null);
    }

    /** @param array<string, mixed> $evidence @param array<string, mixed>|null $launch */
    private function validExitEvidence(RemoteOperation $operation, array $evidence, ?array $launch): bool
    {
        $identityMatches = $operation->process_id === null
            || ((int) $operation->process_id === ($evidence['pid'] ?? 0)
                && (string) $operation->process_group_id === (string) ($evidence['pgid'] ?? '')
                && (string) $operation->process_start_identity === (string) ($evidence['process_start_identity'] ?? ''));

        return ($evidence['schema_version'] ?? null) === 'remote-operation-exit.v1'
            && ($evidence['operation_uuid'] ?? null) === $operation->operation_uuid
            && is_string($evidence['command_sha256'] ?? null) && hash_equals($operation->command_sha256, $evidence['command_sha256'])
            && is_int($evidence['pid'] ?? null) && $evidence['pid'] > 1
            && is_string($evidence['pgid'] ?? null) && $evidence['pgid'] === (string) $evidence['pid']
            && is_string($evidence['process_start_identity'] ?? null) && ctype_digit($evidence['process_start_identity'])
            && ($launch === null || ((int) $launch['pid'] === $evidence['pid']
                && $launch['process_start_identity'] === $evidence['process_start_identity']))
            && ($evidence['host_id'] ?? null) === $operation->host_id
            && ($evidence['runtime_key'] ?? null) === $operation->runtime_key
            && $identityMatches
            && is_int($evidence['exit_code'] ?? null)
            && is_bool($evidence['timed_out'] ?? null)
            && is_bool($evidence['resource_limit_exceeded'] ?? null)
            && is_bool($evidence['cancelled'] ?? null)
            && is_int($evidence['stdout_size_bytes'] ?? null) && $evidence['stdout_size_bytes'] >= 0
            && is_int($evidence['stderr_size_bytes'] ?? null) && $evidence['stderr_size_bytes'] >= 0
            && is_string($evidence['stdout_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $evidence['stdout_sha256']) === 1
            && is_string($evidence['stderr_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $evidence['stderr_sha256']) === 1
            && is_string($evidence['started_at'] ?? null) && strtotime($evidence['started_at']) !== false
            && is_string($evidence['finished_at'] ?? null) && strtotime($evidence['finished_at']) !== false
            && strtotime($evidence['finished_at']) >= strtotime($evidence['started_at'])
            && $this->operationLogsMatchEvidence($operation, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    private function operationLogsMatchEvidence(RemoteOperation $operation, array $evidence): bool
    {
        foreach (['stdout', 'stderr'] as $stream) {
            try {
                $path = $this->workspaces->operationLogPath($operation->execution, $operation->operation_uuid, $stream);
            } catch (\Throwable) {
                return false;
            }
            $size = is_link($path) || ! is_file($path) ? false : filesize($path);
            $hash = is_link($path) || ! is_file($path) ? false : hash_file('sha256', $path);
            if (! is_int($size) || $size !== ($evidence[$stream.'_size_bytes'] ?? null)
                || ! is_string($hash) || ! is_string($evidence[$stream.'_sha256'] ?? null)
                || ! hash_equals($evidence[$stream.'_sha256'], $hash)
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $evidence */
    private function validHeartbeatEvidence(RemoteOperation $operation, array $evidence): bool
    {
        return ($evidence['schema_version'] ?? null) === 'remote-operation-heartbeat.v1'
            && ($evidence['operation_uuid'] ?? null) === $operation->operation_uuid
            && is_string($evidence['command_sha256'] ?? null) && hash_equals($operation->command_sha256, $evidence['command_sha256'])
            && (int) ($evidence['pid'] ?? 0) === (int) $operation->process_id
            && (string) ($evidence['pgid'] ?? '') === (string) $operation->process_group_id
            && (string) ($evidence['process_start_identity'] ?? '') === (string) $operation->process_start_identity
            && is_string($evidence['observed_at'] ?? null) && strtotime($evidence['observed_at']) !== false;
    }

    private function markUnreachable(RemoteOperation $operation, string $reason): RemoteOperation
    {
        $operation = DB::transaction(function () use ($operation, $reason): RemoteOperation {
            $locked = RemoteOperation::query()->lockForUpdate()->findOrFail($operation->getKey());
            $attempts = min(65_535, (int) $locked->reconcile_attempts + 1);
            $manualRequired = $attempts >= 8;
            $delaySeconds = min(900, 15 * (2 ** min(6, max(0, $attempts - 1))));
            $evidence = $locked->evidence ?? [];
            if ($manualRequired && ! $locked->manual_intervention_required) {
                $evidence['manual_intervention_required_at'] = now()->utc()->toIso8601String();
                AuditLog::query()->create([
                    'project_id' => $locked->execution->project_id,
                    'execution_id' => $locked->execution_id,
                    'action' => 'remote_operation.manual_intervention_required',
                    'payload' => ['operation_uuid' => $locked->operation_uuid, 'reason' => $reason, 'attempts' => $attempts],
                ]);
            }
            $locked->forceFill([
                'communication_state' => RemoteCommunicationState::UNREACHABLE,
                'functional_state' => RemoteFunctionalState::UNKNOWN,
                'last_observed_at' => now()->utc(),
                'next_poll_at' => $manualRequired ? null : now()->utc()->addSeconds($delaySeconds),
                'reconcile_attempts' => $attempts,
                'last_reconcile_error' => $this->redactor->redactString($reason),
                'manual_intervention_required' => $manualRequired,
                'evidence' => $evidence,
                'last_error' => $this->redactor->redactString($reason),
            ])->save();

            return $locked;
        }, attempts: 3);
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
        $this->workspaces->writeOperationEvidence($operation->execution, $operation->operation_uuid, 'heartbeat.json', [
            'schema_version' => 'remote-operation-heartbeat.v1',
            'operation_uuid' => $operation->operation_uuid,
            'command_sha256' => $operation->command_sha256,
            'pid' => (int) $operation->process_id,
            'pgid' => $operation->process_group_id,
            'process_start_identity' => $operation->process_start_identity,
            'observed_at' => $now->toIso8601String(),
        ]);
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
            if (($locked->evidence['cancellable'] ?? false) !== true) {
                throw new RuntimeException('La operación registrada no es cancelable; detener el coordinador o el runtime es una acción distinta.');
            }

            $now = now()->utc();
            $cancelAt = $locked->evidence['cancel_requested_at'] ?? $now->toIso8601String();
            $fields = [
                'last_observed_at' => $now,
                'evidence' => [...($locked->evidence ?? []), 'cancel_requested_at' => $cancelAt],
            ];
            if ($locked->process_id === null && $locked->launch_claimed_at === null) {
                $fields['evidence'] = [...($locked->evidence ?? []), 'cancel_requested_at' => $cancelAt, 'cancelled_before_launch' => true];
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

        if ($operation->communication_state === RemoteCommunicationState::TERMINATED) {
            return $operation->refresh();
        }

        $cancelPath = $this->workspaces->operationEvidencePath($operation->execution, $operation->operation_uuid, 'cancel.json');
        if (! is_file($cancelPath)) {
            $this->workspaces->writeOperationEvidence($operation->execution, $operation->operation_uuid, 'cancel.json', [
                'schema_version' => 'remote-operation-cancel.v1',
                'operation_uuid' => $operation->operation_uuid,
                'command_sha256' => $operation->command_sha256,
                'requested_at' => ($operation->evidence['cancel_requested_at'] ?? now()->utc()->toIso8601String()),
                'pid' => $operation->process_id === null ? null : (int) $operation->process_id,
                'pgid' => $operation->process_group_id,
                'process_start_identity' => $operation->process_start_identity,
            ]);
        }

        if ($operation->process_id === null) {
            return $operation->refresh();
        }

        if (! $this->inspector->terminate($operation)) {
            return $this->markUnreachable($operation, 'La cancelación no encontró la identidad exacta del grupo de procesos; no se envió una señal.');
        }

        return $operation->refresh();
    }

    public function requestManualReconciliation(RemoteOperation $operation, \App\Models\User $actor, string $reason): RemoteOperation
    {
        if (trim($reason) === '') {
            throw new RuntimeException('La reconciliación manual requiere un motivo auditable.');
        }
        $operation = DB::transaction(function () use ($operation, $actor, $reason): RemoteOperation {
            $locked = RemoteOperation::query()->lockForUpdate()->findOrFail($operation->getKey());
            $now = now()->utc();
            $locked->forceFill([
                'next_poll_at' => $now,
                'reconcile_attempts' => 0,
                'manual_intervention_required' => false,
                'last_reconcile_error' => null,
                'evidence' => [...($locked->evidence ?? []), 'manual_reconciliation' => [
                    'actor_id' => $actor->getKey(),
                    'reason' => $this->redactor->redactString($reason),
                    'requested_at' => $now->toIso8601String(),
                ]],
            ])->save();
            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'project_id' => $locked->execution->project_id,
                'execution_id' => $locked->execution_id,
                'action' => 'remote_operation.manual_reconciliation_requested',
                'payload' => ['operation_uuid' => $locked->operation_uuid, 'reason' => $this->redactor->redactString($reason)],
            ]);

            return $locked;
        });
        $this->persistState($operation);

        return $this->reconcile($operation);
    }

    private function storeLog(Execution $execution, LogStream $stream, string $message, RemoteOperation $operation): void
    {
        if ($message === '') {
            return;
        }

        ExecutionLog::query()->create([
            'execution_id' => $execution->getKey(),
            'remote_operation_id' => $operation->getKey(),
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
