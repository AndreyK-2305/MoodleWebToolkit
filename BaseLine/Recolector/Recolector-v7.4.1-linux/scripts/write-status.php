<?php
// Escritor atómico del estado agregado del recolector.

declare(strict_types=1);

function status_arg(string $name, string $default = ''): string {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? [] as $argument) {
        if (str_starts_with((string)$argument, $prefix)) {
            return substr((string)$argument, strlen($prefix));
        }
    }
    return $default;
}

function status_read_progress(string $path): array {
    if ($path === '' || !is_readable($path)) {
        return [];
    }
    try {
        $value = json_decode(
            (string)file_get_contents($path),
            true,
            128,
            JSON_THROW_ON_ERROR
        );
        return is_array($value) ? $value : [];
    } catch (Throwable) {
        return [];
    }
}

try {
    $statusfile = trim(status_arg('statusfile'));
    $progressfile = trim(status_arg('progressfile'));
    $startedepoch = max(0, (int)status_arg('startedepoch', (string)time()));
    if ($statusfile === '') {
        throw new RuntimeException('Falta --statusfile.');
    }

    $progress = status_read_progress($progressfile);
    $now = time();
    $document = [
        'schema_version' => '1.0',
        'collector_version' => status_arg('collectorversion'),
        'source_id' => status_arg('sourceid'),
        'state' => status_arg('state'),
        'stage' => status_arg('stage'),
        'execution_mode' => status_arg('executionmode'),
        'exit_code' => (int)status_arg('exitcode', '0'),
        'duration_seconds' => max(0, $now - $startedepoch),
        'workers_requested' => status_arg('workersrequested', 'auto'),
        'workers_effective' => (int)status_arg('workerseffective', '1'),
        'cpu_threads_available' => (int)status_arg('cputhreads', '1'),
        'auto_workers_cap' => (int)status_arg('autoworkerscap', '4'),
        'notify_every_minutes' => (int)status_arg('notifyevery', '10'),
        'output_directory' => status_arg('outputdir'),
        'output_zip' => status_arg('outputzip'),
        'work_directory' => status_arg('workdir'),
        'temporary_directory' => status_arg('tempdir'),
        'existing_backups_directory' => status_arg('reusebackups'),
        'reuse_only' => status_arg('reuseonly', '0') === '1',
        'log_file' => status_arg('logfile'),
        'updated_at_utc' => gmdate('c', $now),
        'ended_at_utc' => status_arg('endedat'),
    ];

    foreach ([
        'total_courses',
        'completed_courses',
        'created_courses',
        'reused_courses',
        'resumed_courses',
        'adopted_courses',
        'failed_courses',
        'scheduled_courses',
        'pending_courses',
        'active_workers',
        'percent_complete',
        'estimated_remaining_seconds',
        'last_completed_course',
        'workers',
        'failures',
    ] as $key) {
        if (array_key_exists($key, $progress)) {
            $document[$key] = $progress[$key];
        }
    }

    $directory = dirname($statusfile);
    if (!is_dir($directory) &&
            !mkdir($directory, 0770, true) &&
            !is_dir($directory)) {
        throw new RuntimeException('No fue posible crear el directorio de estado.');
    }
    $temporary = $statusfile . '.tmp.' . getmypid();
    $encoded = json_encode(
        $document,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRESERVE_ZERO_FRACTION |
        JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    if (file_put_contents($temporary, $encoded, LOCK_EX) === false ||
            !rename($temporary, $statusfile)) {
        @unlink($temporary);
        throw new RuntimeException('No fue posible actualizar el estado.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'STATUS_WARNING ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
