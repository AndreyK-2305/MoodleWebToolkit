<?php

namespace App\Domain\Processes;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Artifacts\StreamingSensitiveValueRedactor;
use App\Domain\Processes\DTOs\RegisteredProcessResult;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\Execution;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** @phpstan-import-type RegisteredCommandDefinition from RegisteredCommandRegistry */
class RegisteredCommandRunner
{
    private readonly RegisteredCommandRegistry $registry;

    private readonly ExecutionWorkspaceManager $workspaces;

    private readonly SensitiveValueRedactor $redactor;

    public function __construct(
        RegisteredCommandRegistry $registry,
        ExecutionWorkspaceManager $workspaces,
        SensitiveValueRedactor $redactor,
    ) {
        $this->registry = $registry;
        $this->workspaces = $workspaces;
        $this->redactor = $redactor;
    }

    /**
     * Execute a command key from the administrator-owned registry, with argv (never a shell string)
     * and a working directory constrained to one workspace area.
     *
     * @param  array<string, string>  $parameters
     * @param  null|callable(int):void  $onStarted
     * @param  null|callable(int):void  $onHeartbeat
     * @param  RegisteredCommandDefinition|null  $registeredDefinition
     */
    public function run(
        Execution $execution,
        string $commandKey,
        array $parameters = [],
        string $area = 'tools',
        string $workingDirectory = '',
        ?string $operationUuid = null,
        ?callable $onStarted = null,
        ?callable $onHeartbeat = null,
        ?string $commandSha256 = null,
        ?array $registeredDefinition = null,
    ): RegisteredProcessResult {
        if ((bool) config('toolkit.features.local_runner.enabled', false) === false) {
            throw new RuntimeException('El runner local está deshabilitado por feature flag.');
        }
        if (PHP_OS_FAMILY === 'Windows' || function_exists('posix_kill') === false || function_exists('pcntl_exec') === false) {
            throw new RuntimeException('El runner de operaciones requiere Linux con soporte de grupos de procesos.');
        }

        $definition = $registeredDefinition ?? $this->registry->resolve($commandKey, $parameters);
        $operationUuid ??= (string) Str::uuid();
        $durableLimit = $this->workspaces->durableOutputLimit($execution, $definition['durable_log_max_bytes'], $definition['platform_durable_log_max_bytes']);
        $cwd = $this->workspaces->resolve($execution, $area, $workingDirectory);
        $environment = $definition['environment'];
        $workspaceTemp = $this->workspaces->resolve($execution, 'temporary');
        $environment['HOME'] = $workspaceTemp;
        $environment['TMPDIR'] = $workspaceTemp;
        $environment['MOODLE_OPERATION_ID'] = $operationUuid;
        $environment['MOODLE_COMMAND_SHA256'] = $commandSha256 ?? hash('sha256', json_encode([$commandKey, $definition['argv']], JSON_THROW_ON_ERROR));
        $argv = $definition['argv'];
        if ($definition['enforce_os_limits']) {
            $wrapper = (string) config('toolkit.runner.limit_wrapper', '/usr/bin/prlimit');
            if (str_starts_with($wrapper, DIRECTORY_SEPARATOR) === false || is_file($wrapper) === false || is_executable($wrapper) === false) {
                throw new RuntimeException('El runner requiere el limitador de recursos del sistema operativo.');
            }
            $limits = $definition['resource_limits'];
            $argv = [
                $wrapper,
                ...($limits['cpu_seconds'] === null ? [] : ['--cpu='.$limits['cpu_seconds']]),
                '--as='.$limits['memory_bytes'],
                '--nproc='.$limits['processes'],
                '--fsize='.$limits['file_bytes'],
                '--',
                ...$argv,
            ];
        }
        $sessionWrapper = (string) config('toolkit.runner.session_wrapper', '/usr/bin/setsid');
        if (str_starts_with($sessionWrapper, DIRECTORY_SEPARATOR) === false || is_file($sessionWrapper) === false || is_executable($sessionWrapper) === false) {
            throw new RuntimeException('El runner requiere setsid para aislar y cancelar el grupo de procesos.');
        }
        $argv = [$sessionWrapper, PHP_BINARY, base_path('bin/registered-command-entrypoint.php'), ...$argv];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'w']];
        $process = proc_open($argv, $descriptors, $pipes, $cwd, $environment, ['bypass_shell' => true]);

        if (is_resource($process) === false) {
            throw new RuntimeException('No se pudo iniciar el proceso local registrado.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $startedAt = hrtime(true) / 1_000_000_000;
        $lastHeartbeat = $startedAt;
        $lastActivity = $startedAt;
        $exitCode = null;
        $timedOut = false;
        $resourceLimitExceeded = false;
        $files = [];
        $captures = [];
        try {
            $processId = $this->awaitProcessReady($process, $pipes[3], $definition['startup_timeout_seconds']);
            foreach (['stdout', 'stderr'] as $name) {
                $path = $this->workspaces->operationLogPath($execution, $operationUuid, $name);
                $file = @fopen($path, 'xb');
                if ($file === false) {
                    throw new RuntimeException('No se pudo crear el log durable privado de la operación.');
                }
                $files[$name] = $file;
                @chmod($path, 0600);
                $captures[$name] = new SanitizedOutputCapture(
                    new StreamingSensitiveValueRedactor($this->redactor, $definition['stream_pending_max_bytes']),
                    $durableLimit,
                    $definition['max_output_bytes'],
                    fn (string $safe) => $this->workspaces->writeDurableLog($execution, $file, $safe),
                );
            }
            if ($onStarted !== null) {
                $onStarted($processId);
            }
            $status = proc_get_status($process);
            if ($status['running'] && (@fwrite($pipes[0], '1') !== 1 || fflush($pipes[0]) === false)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    throw new RuntimeException('No se pudo liberar el comando después de registrar su identidad.');
                }
            }
            if (! $status['running']) {
                $exitCode = (int) $status['exitcode'];
            }
            fclose($pipes[0]);
            do {
                $status = proc_get_status($process);
                foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $name) {
                    // Bounded reads and a fairness budget prevent a busy stream
                    // from starving the other. Truncated streams still drain.
                    for ($read = 0; $read < 64; $read++) {
                        $chunk = stream_get_contents($pipes[$index], 8192);
                        if ($chunk === false) {
                            throw new RuntimeException('No se pudo leer el pipe de salida de la operación.');
                        }
                        if ($chunk === '') {
                            break;
                        }
                        $captures[$name]->observe($chunk);
                        $lastActivity = hrtime(true) / 1_000_000_000;
                    }
                }
                if (! $status['running']) {
                    $exitCode ??= (int) $status['exitcode'];
                    if (feof($pipes[1]) && feof($pipes[2])) {
                        break;
                    }
                }
                $observedAt = hrtime(true) / 1_000_000_000;
                if (app(RegisteredExecutionPolicy::class)->expired($definition['wall_timeout_seconds'], $startedAt, $observedAt)
                    || app(RegisteredExecutionPolicy::class)->expired($definition['stall_timeout_seconds'], $lastActivity, $observedAt)) {
                    $timedOut = true;
                    $this->terminateProcessGroup($status['pid'], forceAfterGrace: true, graceSeconds: $definition['cancellation_grace_seconds']);
                    $exitCode = 124;
                }
                if ($observedAt - $lastHeartbeat >= $definition['heartbeat_interval_seconds']) {
                    if ($onHeartbeat !== null) {
                        $onHeartbeat($processId);
                    }
                    $lastHeartbeat = $observedAt;
                    try {
                        $this->workspaces->measure($execution);
                    } catch (Throwable) {
                        $resourceLimitExceeded = true;
                        $this->terminateProcessGroup($status['pid'], forceAfterGrace: true);
                        $exitCode = 125;
                    }
                }
                usleep(50_000);
            } while (true);
            foreach ($captures as $capture) {
                $capture->finish();
            }
            foreach ($files as $file) {
                if (! @fflush($file) || (function_exists('fsync') && ! @fsync($file))) {
                    throw new RuntimeException('No se pudo cerrar y sincronizar el log durable de la operación.');
                }
            }
        } catch (Throwable $exception) {
            $status = proc_get_status($process);
            // Also stop descendants when the original parent exited but its
            // inherited pipes remain open. This group was created by this run.
            $this->terminateProcessGroup($status['pid'], forceAfterGrace: true);
            throw $exception;
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            foreach ($files as $file) {
                fclose($file);
            }
            $closedCode = proc_close($process);
            if ($exitCode === null && $closedCode >= 0) {
                $exitCode = $closedCode;
            }
        }
        $stdout = $captures['stdout'];
        $stderr = $captures['stderr'];

        return new RegisteredProcessResult(
            $exitCode,
            $stdout->captured(),
            $stderr->captured(),
            $processId,
            $timedOut,
            $stdout->truncated() || $stderr->truncated() || $stdout->memoryTruncated() || $stderr->memoryTruncated(),
            $resourceLimitExceeded,
            $stdout->persistedBytes(),
            $stderr->persistedBytes(),
            $stdout->sha256(),
            $stderr->sha256(),
            $stdout->observedBytes(),
            $stderr->observedBytes(),
            $stdout->truncated(),
            $stderr->truncated(),
            $durableLimit,
        );
    }

    /**
     * @param  resource  $process
     * @param  resource  $readyPipe
     */
    private function awaitProcessReady(mixed $process, mixed $readyPipe, int $timeoutSeconds): int
    {
        stream_set_blocking($readyPipe, false);
        $deadline = microtime(true) + $timeoutSeconds;
        $ready = '';
        do {
            $chunk = stream_get_contents($readyPipe);
            if (is_string($chunk)) {
                $ready .= $chunk;
            }
            $status = proc_get_status($process);
            if (preg_match('/^([1-9][0-9]*)\n$/D', $ready, $matches) === 1
                && (int) $matches[1] === $status['pid'] && $status['running']
            ) {
                return (int) $matches[1];
            }
            if (! $status['running'] || strlen($ready) > 32) {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('El proceso registrado no confirmó el arranque antes de capturar su identidad.');
    }

    private function terminateProcessGroup(int $pid, bool $forceAfterGrace = false, ?int $graceSeconds = null): void
    {
        if ($pid < 2 || function_exists('posix_kill') === false) {
            return;
        }
        @posix_kill(-$pid, SIGTERM);
        $deadline = microtime(true) + ($graceSeconds ?? (int) config('toolkit.runner.cancel_grace_seconds', 3));
        do {
            if (@posix_kill(-$pid, 0) === false) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
        if ($forceAfterGrace || (bool) config('toolkit.runner.allow_force_kill', false)) {
            @posix_kill(-$pid, SIGKILL);
        }
    }
}
