<?php

namespace App\Domain\Processes;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Processes\DTOs\RegisteredProcessResult;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\Execution;
use HashContext;
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
        $cwd = $this->workspaces->resolve($execution, $area, $workingDirectory);
        $environment = $definition['environment'];
        $workspaceTemp = $this->workspaces->resolve($execution, 'temporary');
        $environment['HOME'] = $workspaceTemp;
        $environment['TMPDIR'] = $workspaceTemp;
        $environment['MOODLE_OPERATION_ID'] = $operationUuid ?? (string) ($execution->uuid ?? $execution->getKey());
        $environment['MOODLE_COMMAND_SHA256'] = $commandSha256 ?? hash('sha256', json_encode([$commandKey, $definition['argv']], JSON_THROW_ON_ERROR));
        $argv = $definition['argv'];
        if ((bool) config('toolkit.runner.enforce_os_limits', true)) {
            $wrapper = (string) config('toolkit.runner.limit_wrapper', '/usr/bin/prlimit');
            if (str_starts_with($wrapper, DIRECTORY_SEPARATOR) === false || is_file($wrapper) === false || is_executable($wrapper) === false) {
                throw new RuntimeException('El runner requiere el limitador de recursos del sistema operativo.');
            }
            $limits = config('toolkit.runner.limits', []);
            $argv = [
                $wrapper,
                '--cpu='.max(1, (int) ($limits['cpu_seconds'] ?? 86400)),
                '--as='.max(134_217_728, (int) ($limits['memory_bytes'] ?? 8_589_934_592)),
                '--nproc='.max(1, (int) ($limits['processes'] ?? 128)),
                '--fsize='.max(1_048_576, (int) ($limits['file_bytes'] ?? 1_099_511_627_776)),
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
        $startedAt = microtime(true);
        $lastHeartbeat = $startedAt;
        $processId = null;
        $exitCode = null;
        $timedOut = false;
        $truncated = false;
        $resourceLimitExceeded = false;
        $stdout = '';
        $stderr = '';
        $limit = $definition['max_output_bytes'];
        $stdoutBytes = 0;
        $stderrBytes = 0;
        $stdoutHash = hash_init('sha256');
        $stderrHash = hash_init('sha256');
        $stdoutFile = null;
        $stderrFile = null;

        try {
            $processId = $this->awaitProcessReady($process, $pipes[3]);
            if ($operationUuid !== null) {
                $stdoutFile = fopen($this->workspaces->operationLogPath($execution, $operationUuid, 'stdout'), 'xb');
                $stderrFile = fopen($this->workspaces->operationLogPath($execution, $operationUuid, 'stderr'), 'xb');
                if ($stdoutFile === false || $stderrFile === false) {
                    throw new RuntimeException('No se pudo crear el log durable de la operación.');
                }
                @chmod($this->workspaces->operationLogPath($execution, $operationUuid, 'stdout'), 0600);
                @chmod($this->workspaces->operationLogPath($execution, $operationUuid, 'stderr'), 0600);
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

                foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $streamName) {
                    $chunk = stream_get_contents($pipes[$index]);
                    if (is_string($chunk) === false || $chunk === '') {
                        continue;
                    }
                    $chunk = $this->redactor->redactString($chunk);
                    if ($streamName === 'stdout') {
                        $this->persistOutputChunk($stdoutFile, $chunk, $stdoutHash);
                        $stdoutBytes += strlen($chunk);
                    } else {
                        $this->persistOutputChunk($stderrFile, $chunk, $stderrHash);
                        $stderrBytes += strlen($chunk);
                    }
                    $current = $streamName === 'stdout' ? $stdout : $stderr;
                    $remaining = max(0, $limit - strlen($current));
                    if (strlen($chunk) > $remaining) {
                        $truncated = true;
                    }
                    $current .= substr($chunk, 0, $remaining);
                    if ($streamName === 'stdout') {
                        $stdout = $current;
                    } else {
                        $stderr = $current;
                    }
                }

                if ($status['running'] === false) {
                    $exitCode ??= (int) $status['exitcode'];
                    break;
                }

                if (microtime(true) - $startedAt > $definition['timeout']) {
                    $timedOut = true;
                    $this->terminateProcessGroup($status['pid'], forceAfterGrace: true);
                    $exitCode = 124;
                }

                if (microtime(true) - $lastHeartbeat >= 10) {
                    if ($onHeartbeat !== null) {
                        $onHeartbeat($processId);
                    }
                    $lastHeartbeat = microtime(true);

                    try {
                        $this->workspaces->measure($execution);
                    } catch (Throwable $exception) {
                        $resourceLimitExceeded = true;
                        $this->terminateProcessGroup($status['pid'], forceAfterGrace: true);
                        $exitCode = 125;
                        $stderr .= "\n[Proceso terminado al superar la cuota o fallar la inspección del workspace.]";
                    }
                }

                usleep(50_000);
            } while (true);

            foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $streamName) {
                $chunk = stream_get_contents($pipes[$index]);
                if (is_string($chunk) && $chunk !== '') {
                    $chunk = $this->redactor->redactString($chunk);
                    if ($streamName === 'stdout') {
                        $this->persistOutputChunk($stdoutFile, $chunk, $stdoutHash);
                        $stdoutBytes += strlen($chunk);
                    } else {
                        $this->persistOutputChunk($stderrFile, $chunk, $stderrHash);
                        $stderrBytes += strlen($chunk);
                    }
                    $current = $streamName === 'stdout' ? $stdout : $stderr;
                    $remaining = max(0, $limit - strlen($current));
                    if (strlen($chunk) > $remaining) {
                        $truncated = true;
                    }
                    $current .= substr($chunk, 0, $remaining);
                    if ($streamName === 'stdout') {
                        $stdout = $current;
                    } else {
                        $stderr = $current;
                    }
                }
            }
        } catch (Throwable $exception) {
            $status = proc_get_status($process);
            if ($status['running']) {
                $this->terminateProcessGroup($status['pid'], forceAfterGrace: true);
            }
            throw $exception;
        } finally {
            foreach ([$pipes[0], $pipes[3]] as $gatePipe) {
                if (is_resource($gatePipe)) {
                    fclose($gatePipe);
                }
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            foreach ([$stdoutFile, $stderrFile] as $outputFile) {
                if (is_resource($outputFile)) {
                    fflush($outputFile);
                    if (function_exists('fsync')) {
                        fsync($outputFile);
                    }
                    fclose($outputFile);
                }
            }
            $closedCode = proc_close($process);
            if ($exitCode === null && $closedCode >= 0) {
                $exitCode = $closedCode;
            }
        }

        $stdout = $this->redactor->redactString($stdout);
        $stderr = $this->redactor->redactString($stderr);
        if ($timedOut) {
            $stderr .= "\n[Proceso terminado por exceder el tiempo máximo.]";
        }
        if ($resourceLimitExceeded) {
            $stderr .= "\n[Se excedió un límite de recursos del workspace.]";
        }
        if ($truncated) {
            $stderr .= "\n[Salida truncada por superar el límite configurado.]";
        }

        return new RegisteredProcessResult(
            $exitCode,
            $stdout,
            $stderr,
            $processId,
            $timedOut,
            $truncated,
            $resourceLimitExceeded,
            $stdoutBytes,
            $stderrBytes,
            hash_final($stdoutHash),
            hash_final($stderrHash),
        );
    }

    /**
     * @param  resource  $process
     * @param  resource  $readyPipe
     */
    private function awaitProcessReady(mixed $process, mixed $readyPipe): int
    {
        stream_set_blocking($readyPipe, false);
        $deadline = microtime(true) + min(5, max(1, (int) config('toolkit.runner.supervisor_start_timeout_seconds', 3)));
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

    /**
     * @param  resource|null  $stream
     */
    private function persistOutputChunk(mixed $stream, string $chunk, HashContext $hash): void
    {
        hash_update($hash, $chunk);
        if (is_resource($stream) === false) {
            return;
        }
        $offset = 0;
        while ($offset < strlen($chunk)) {
            $written = fwrite($stream, substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('No se pudo persistir la salida de la operación.');
            }
            $offset += $written;
        }
        if (fflush($stream) === false || (function_exists('fsync') && fsync($stream) === false)) {
            throw new RuntimeException('No se pudo sincronizar la salida de la operación.');
        }
    }

    private function terminateProcessGroup(int $pid, bool $forceAfterGrace = false): void
    {
        if ($pid < 2 || function_exists('posix_kill') === false) {
            return;
        }
        @posix_kill(-$pid, SIGTERM);
        $deadline = microtime(true) + min(5, max(1, (int) config('toolkit.runner.cancel_grace_seconds', 3)));
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
