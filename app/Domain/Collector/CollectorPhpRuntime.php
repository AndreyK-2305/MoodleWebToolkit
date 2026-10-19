<?php

namespace App\Domain\Collector;

final class CollectorPhpRuntime
{
    public function available(): bool
    {
        $binary = (string) config('collector.php_binary');
        $ini = (string) config('collector.php_ini');
        $scan = (string) config('collector.php_scan_dir');
        if (! str_starts_with($binary, '/') || ! is_file($binary) || ! is_executable($binary)
            || ! is_file($ini) || ! is_dir($scan) || ! is_executable('/usr/bin/setsid') || ! is_executable('/usr/bin/prlimit') || ! is_executable('/bin/bash')) {
            return false;
        }
        $process = proc_open([$binary, '-c', $ini, base_path('bin/collector-runtime-probe.php')],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes,
            base_path(), ['LANG' => 'C.UTF-8', 'PHP_INI_SCAN_DIR' => $scan], ['bypass_shell' => true]);
        if (! is_resource($process)) {
            return false;
        }
        $output = '';
        $exit = -1;
        stream_set_blocking($pipes[1], false);
        $deadline = hrtime(true) + 3_000_000_000;
        try {
            do {
                $chunk = fread($pipes[1], 2048);
                if (is_string($chunk)) {
                    $output .= $chunk;
                }
                if (strlen($output) > 4096) {
                    return false;
                }
                $status = proc_get_status($process);
                if (! $status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                usleep(10_000);
            } while (hrtime(true) < $deadline);
            $output .= (string) stream_get_contents($pipes[1], 4097 - strlen($output));
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            proc_close($process);
        }
        $document = json_decode($output, true);
        if ($exit !== 0 || ! is_array($document) || ($document['schema_version'] ?? null) !== 'collector-php-runtime.v1'
            || ($document['os_family'] ?? null) !== 'Linux' || ! is_int($document['php_version_id'] ?? null)
            || $document['php_version_id'] < 80300 || $document['php_version_id'] >= 80400 || ! is_array($document['extensions'] ?? null)) {
            return false;
        }

        return array_diff(['dom', 'zip', 'pgsql', 'gd', 'intl', 'mbstring', 'pcntl', 'posix', 'curl', 'xml', 'xmlreader', 'xmlwriter'], array_keys($document['extensions'])) === [];
    }
}
