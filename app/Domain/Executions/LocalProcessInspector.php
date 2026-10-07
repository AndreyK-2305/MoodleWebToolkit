<?php

namespace App\Domain\Executions;

use App\Models\RemoteOperation;
use RuntimeException;

class LocalProcessInspector
{
    public function isRunning(RemoteOperation $operation): bool
    {
        $pid = (int) $operation->process_id;
        if ($pid < 1 || PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $procRoot = '/proc/'.$pid;
        $environmentPath = $procRoot.'/environ';
        $commandPath = $procRoot.'/cmdline';

        if (! is_file($environmentPath) || ! is_file($commandPath)) {
            return false;
        }

        $environment = @file_get_contents($environmentPath);
        $command = @file_get_contents($commandPath);
        if (! is_string($environment) || ! is_string($command)) {
            return false;
        }

        return str_contains($environment, 'MOODLE_OPERATION_ID='.$operation->operation_uuid."\0")
            && $command !== '';
    }

    public function terminate(RemoteOperation $operation): bool
    {
        if (! $this->isRunning($operation)) {
            return false;
        }

        if (! function_exists('posix_kill') || ! posix_kill((int) $operation->process_id, SIGTERM)) {
            throw new RuntimeException('El runtime no pudo enviar una señal segura al proceso registrado.');
        }

        return true;
    }
}
