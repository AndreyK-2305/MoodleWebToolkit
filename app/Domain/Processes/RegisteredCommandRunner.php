<?php

namespace App\Domain\Processes;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Processes\DTOs\RegisteredProcessResult;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\Execution;
use RuntimeException;
use Throwable;

class RegisteredCommandRunner
{
    public function __construct(
        private readonly RegisteredCommandRegistry $registry,
        private readonly ExecutionWorkspaceManager $workspaces,
        private readonly SensitiveValueRedactor $redactor,
    ) {}

    /**
     * Execute a command key from the administrator-owned registry, with argv (never a shell string)
     * and a working directory constrained to one workspace area.
     *
     * @param array<string, string> $parameters
     * @param null|callable(int):void $onStarted
     * @param null|callable(int):void $onHeartbeat
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
    ): RegisteredProcessResult {
        if (! (bool) config('toolkit.features.local_runner.enabled', false)) {
            throw new RuntimeException('El runner local está deshabilitado por feature flag.');
        }

        $definition = $this->registry->resolve($commandKey, $parameters);
        $cwd = $this->workspaces->resolve($execution, $area, $workingDirectory);
        $environment = $definition['environment'];
        $workspaceTemp = $this->workspaces->resolve($execution, 'temporary');
        $environment['HOME'] = $workspaceTemp;
        $environment['TMPDIR'] = $workspaceTemp;
        $environment['MOODLE_OPERATION_ID'] = $operationUuid ?? (string) ($execution->uuid ?? $execution->getKey());
        $argv = $definition['argv'];
        if ((bool) config('toolkit.runner.enforce_os_limits', true)) {
            $wrapper = (string) config('toolkit.runner.limit_wrapper', '/usr/bin/prlimit');
            if (! str_starts_with($wrapper, DIRECTORY_SEPARATOR) || ! is_file($wrapper) || ! is_executable($wrapper)) {
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
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($argv, $descriptors, $pipes, $cwd, $environment, ['bypass_shell' => true]);

        if (! is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el proceso local registrado.');
        }

        fclose($pipes[0]);
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

        try {
            do {
                $status = proc_get_status($process);
                if ($processId === null && isset($status['pid']) && (int) $status['pid'] > 0) {
                    $processId = (int) $status['pid'];
                    if ($onStarted !== null) {
                        $onStarted($processId);
                    }
                }

                foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $streamName) {
                    $chunk = stream_get_contents($pipes[$index]);
                    if (! is_string($chunk) || $chunk === '') {
                        continue;
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

                if (! $status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }

                if (microtime(true) - $startedAt > $definition['timeout']) {
                    $timedOut = true;
                    proc_terminate($process, 15);
                    usleep(100_000);
                    $status = proc_get_status($process);
                    if ($status['running']) {
                        proc_terminate($process, 9);
                    }
                    $exitCode = 124;
                    break;
                }

                if (microtime(true) - $lastHeartbeat >= 10) {
                    if ($processId !== null) {
                        if ($onHeartbeat !== null) {
                            $onHeartbeat($processId);
                        }
                    }
                    $lastHeartbeat = microtime(true);

                    try {
                        $this->workspaces->measure($execution);
                    } catch (Throwable $exception) {
                        $resourceLimitExceeded = true;
                        proc_terminate($process, 15);
                        usleep(100_000);
                        $status = proc_get_status($process);
                        if ($status['running']) {
                            proc_terminate($process, 9);
                        }
                        $exitCode = 125;
                        $stderr .= "\n[Proceso terminado al superar la cuota o fallar la inspección del workspace.]";
                        break;
                    }
                }

                usleep(50_000);
            } while (true);

            foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $streamName) {
                $chunk = stream_get_contents($pipes[$index]);
                if (is_string($chunk) && $chunk !== '') {
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
                proc_terminate($process, 15);
                usleep(100_000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
            }
            throw $exception;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
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

        return new RegisteredProcessResult($exitCode ?? 255, $stdout, $stderr, $processId, $timedOut, $truncated, $resourceLimitExceeded);
    }
}
