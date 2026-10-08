<?php

namespace App\Domain\Executions;

use App\Models\RemoteOperation;
use RuntimeException;

class LocalProcessInspector
{
    /** @return array{process_group_id: string, process_start_identity: string}|null */
    public function identity(int $pid, RemoteOperation $operation): ?array
    {
        if ($pid < 2 || PHP_OS_FAMILY === 'Windows' || ! is_dir('/proc/'.$pid)) {
            return null;
        }
        $environment = @file_get_contents('/proc/'.$pid.'/environ');
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        $command = @file_get_contents('/proc/'.$pid.'/cmdline');
        if (! is_string($environment) || ! is_string($stat) || ! is_string($command) || $command === '') {
            return null;
        }
        if (! str_contains($environment, 'MOODLE_OPERATION_ID='.$operation->operation_uuid."\0")
            || ! str_contains($environment, 'MOODLE_COMMAND_SHA256='.$operation->command_sha256."\0")
        ) {
            return null;
        }
        $closingParen = strrpos($stat, ')');
        if ($closingParen === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $closingParen + 1))) ?: [];
        // /proc/PID/stat starts at field 3 after the command name: pgrp is 5 and starttime is 22.
        $processGroup = $fields[2] ?? null;
        $startTicks = $fields[19] ?? null;
        if (! is_string($processGroup) || ! ctype_digit($processGroup) || (int) $processGroup < 2
            || ! is_string($startTicks) || ! ctype_digit($startTicks)
        ) {
            return null;
        }

        return ['process_group_id' => $processGroup, 'process_start_identity' => $startTicks];
    }

    public function isRunning(RemoteOperation $operation): bool
    {
        $pid = (int) $operation->process_id;
        if ($pid < 2 || PHP_OS_FAMILY === 'Windows'
            || $operation->host_id !== (gethostname() ?: 'local')
            || $operation->runtime_key !== 'workspace-process-v2'
            || $operation->process_group_id === null
            || $operation->process_start_identity === null
        ) {
            return false;
        }
        $identity = $this->identity($pid, $operation);

        return $identity !== null
            && hash_equals((string) $operation->process_group_id, $identity['process_group_id'])
            && hash_equals((string) $operation->process_start_identity, $identity['process_start_identity']);
    }

    public function terminate(RemoteOperation $operation): bool
    {
        if (! $this->isRunning($operation)) {
            return false;
        }

        $processGroupId = (int) $operation->process_group_id;
        if ($processGroupId < 2 || ! function_exists('posix_kill') || ! posix_kill(-$processGroupId, SIGTERM)) {
            throw new RuntimeException('El runtime no pudo enviar una señal segura al proceso registrado.');
        }

        $grace = (int) ($operation->evidence['execution_policy']['cancellation_grace_seconds'] ?? config('toolkit.runner.cancel_grace_seconds', 3));
        $deadline = microtime(true) + $grace;
        do {
            usleep(100_000);
            if (! $this->processGroupExists($processGroupId)) {
                return true;
            }
        } while (microtime(true) < $deadline);

        if ((bool) ($operation->evidence['execution_policy']['allow_force_kill'] ?? config('toolkit.runner.allow_force_kill', false))) {
            if (! $this->processGroupExists($processGroupId) || ! posix_kill(-$processGroupId, SIGKILL)) {
                return false;
            }
            $deadline = microtime(true) + $grace;
            do {
                usleep(100_000);
                if (! $this->processGroupExists($processGroupId)) {
                    return true;
                }
            } while (microtime(true) < $deadline);
        }

        return ! $this->processGroupExists($processGroupId);
    }

    public function hasActiveProcessGroup(RemoteOperation $operation): bool
    {
        if (PHP_OS_FAMILY !== 'Linux' || $operation->host_id !== (gethostname() ?: 'local')
            || $operation->runtime_key !== 'workspace-process-v2' || (int) $operation->process_group_id < 2) {
            return true;
        }
        $paths = glob('/proc/[0-9]*/stat');
        if ($paths === false) {
            return true;
        }
        foreach ($paths as $path) {
            $stat = @file_get_contents($path);
            if (! is_string($stat)) {
                continue; // A process may exit between enumeration and inspection.
            }
            $end = strrpos($stat, ')');
            $fields = $end === false ? [] : (preg_split('/\s+/', trim(substr($stat, $end + 1))) ?: []);
            if (($fields[2] ?? null) === $operation->process_group_id && ! in_array($fields[0] ?? null, ['Z', 'X'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @phpstan-impure */
    private function processGroupExists(int $processGroupId): bool
    {
        return $processGroupId > 1 && function_exists('posix_kill') && @posix_kill(-$processGroupId, 0);
    }
}
