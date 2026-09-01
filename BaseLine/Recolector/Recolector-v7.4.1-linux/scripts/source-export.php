<?php
// Orquestador concurrente y observable del recolector de origen.

declare(strict_types=1);

function export_arg(string $name, ?string $default = null): ?string {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? [] as $argument) {
        if (str_starts_with((string)$argument, $prefix)) {
            return substr((string)$argument, strlen($prefix));
        }
    }
    return $default;
}

function export_log(string $event, array $fields = []): void {
    $parts = [gmdate('Y-m-d\TH:i:s\Z'), $event];
    foreach ($fields as $key => $value) {
        $text = preg_replace('/[\r\n\t ]+/', '_', trim((string)$value));
        $parts[] = $key . '=' . $text;
    }
    fwrite(STDOUT, implode(' ', $parts) . PHP_EOL);
}

function export_atomic_json(string $path, array $document): void {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('No fue posible crear ' . $directory . '.');
    }
    $temporary = $path . '.tmp.' . getmypid();
    $json = json_encode(
        $document,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRESERVE_ZERO_FRACTION |
        JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    if (file_put_contents($temporary, $json, LOCK_EX) === false ||
            !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('No fue posible actualizar ' . $path . '.');
    }
}

function export_read_json(string $path): array {
    if (!is_readable($path)) {
        return [];
    }
    try {
        $value = json_decode((string)file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : [];
    } catch (Throwable) {
        return [];
    }
}

function export_remove_tree(string $path): void {
    if (is_link($path) || is_file($path)) {
        if (!unlink($path) && file_exists($path)) {
            throw new RuntimeException('No fue posible retirar ' . $path . '.');
        }
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $itempath = $item->getPathname();
        if ($item->isLink() || $item->isFile()) {
            if (!unlink($itempath) && file_exists($itempath)) {
                throw new RuntimeException('No fue posible retirar ' . $itempath . '.');
            }
        } else if (!rmdir($itempath) && is_dir($itempath)) {
            throw new RuntimeException('No fue posible retirar ' . $itempath . '.');
        }
    }
    if (!rmdir($path) && is_dir($path)) {
        throw new RuntimeException('No fue posible retirar ' . $path . '.');
    }
}

function export_reset_work_artifacts(string $outputdir, string $sourceid): void {
    foreach ([
        'identidades.json',
        'inventario-origen.json',
        'plugins.json',
        'manifest.json',
        'checksums.sha256',
        'run-manifest.json',
        'existing-backups-index.json',
        'cursos',
        'inventarios',
        'checkpoints',
        '.runtime-' . $sourceid,
    ] as $relative) {
        export_remove_tree($outputdir . '/' . $relative);
    }
}

function export_work_directory_is_owned(string $outputdir, string $sourceid): bool {
    $manifest = export_read_json($outputdir . '/run-manifest.json');
    if (($manifest['package_type'] ?? '') === 'moodle-collector-run' &&
            ($manifest['source_id'] ?? '') === $sourceid) {
        return true;
    }
    return basename(rtrim($outputdir, '/\\')) ===
        '.moodle-collector-work-' . $sourceid;
}

function export_course_key(string $sourceid, int $courseid): string {
    $source = strtoupper((string)preg_replace('/[^a-z0-9_-]+/i', '-', $sourceid));
    $token = strtoupper(substr(hash('sha256', $sourceid . '|course|' . $courseid), 0, 12));
    return 'COURSE-' . $source . '-' . $token;
}

function export_is_sha256(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
}

function export_run_manifest(
    string $path,
    string $sourceid,
    string $collectorversion,
    string $config,
    array $artifacts
): array {
    $hashes = [];
    foreach ($artifacts as $name => $artifact) {
        $sha = hash_file('sha256', $artifact);
        if (!export_is_sha256($sha)) {
            throw new RuntimeException('No fue posible firmar ' . $name . ' para la reanudación.');
        }
        $hashes[$name] = $sha;
    }
    $manifest = [
        'schema_version' => '1.0',
        'package_type' => 'moodle-collector-run',
        'collector_version' => $collectorversion,
        'source_id' => $sourceid,
        'config_path_sha256' => hash('sha256', (string)realpath($config)),
        'run_fingerprint' => hash('sha256', random_bytes(32) . '|' . microtime(true)),
        'created_at_utc' => gmdate('c'),
        'artifact_hashes' => $hashes,
        'backup_profile' => 'effective-general-profile-v1',
    ];
    export_atomic_json($path, $manifest);
    return $manifest;
}

function export_validate_run_manifest(
    string $path,
    string $sourceid,
    string $collectorversion,
    string $config,
    array $artifacts
): array {
    $manifest = export_read_json($path);
    $manifestversion = (string)($manifest['collector_version'] ?? '');
    $compatibleversions = array_values(array_unique([
        $collectorversion,
        '7.4.1-linux-rc3',
        '7.4.1-linux-rc2',
        '7.4.1-linux-rc1',
        '7.4.0-linux-rc2',
    ]));
    if (($manifest['schema_version'] ?? '') !== '1.0' ||
            ($manifest['package_type'] ?? '') !== 'moodle-collector-run' ||
            !in_array($manifestversion, $compatibleversions, true) ||
            ($manifest['source_id'] ?? '') !== $sourceid ||
            ($manifest['backup_profile'] ?? '') !== 'effective-general-profile-v1' ||
            ($manifest['config_path_sha256'] ?? '') !== hash('sha256', (string)realpath($config)) ||
            !export_is_sha256($manifest['run_fingerprint'] ?? null)) {
        throw new RuntimeException(
            'La ejecución anterior no es compatible con esta versión. Use --restart para iniciar una nueva.'
        );
    }
    foreach ($artifacts as $name => $artifact) {
        $expected = $manifest['artifact_hashes'][$name] ?? '';
        $actual = is_readable($artifact) ? hash_file('sha256', $artifact) : false;
        if (!export_is_sha256($expected) || !is_string($actual) ||
                !hash_equals($expected, $actual)) {
            throw new RuntimeException(
                'El artefacto base ' . $name . ' cambió. Use --restart para iniciar una nueva ejecución.'
            );
        }
    }
    if ($manifestversion !== $collectorversion) {
        $manifest['collector_version'] = $collectorversion;
        $manifest['migrated_from_collector_version'] = $manifestversion;
        $manifest['migrated_at_utc'] = gmdate('c');
        export_atomic_json($path, $manifest);
        export_log('RESUME_MANIFEST_MIGRATED', [
            'from' => $manifestversion,
            'to' => $collectorversion,
        ]);
    }
    return $manifest;
}

/** @return array{bytes:int,reuse_kind:string}|null */
function export_fast_checkpoint(
    string $outputdir,
    string $sourceid,
    int $courseid,
    string $runfingerprint
): ?array {
    $coursekey = export_course_key($sourceid, $courseid);
    $basename = strtolower($coursekey);
    $backuprelative = 'cursos/' . $basename . '.mbz';
    $inventoryrelative = 'inventarios/inventory-' . $basename . '.json';
    $checkpointpath = $outputdir . '/checkpoints/checkpoint-' . $basename . '.json';
    $checkpoint = export_read_json($checkpointpath);
    if (($checkpoint['schema_version'] ?? '') !== '1.0' ||
            ($checkpoint['package_type'] ?? '') !== 'moodle-consolidation-source-course' ||
            ($checkpoint['source_id'] ?? '') !== $sourceid ||
            ($checkpoint['course_key'] ?? '') !== $coursekey ||
            (int)($checkpoint['source_course_id'] ?? 0) !== $courseid ||
            ($checkpoint['run_fingerprint'] ?? '') !== $runfingerprint ||
            ($checkpoint['backup_file'] ?? '') !== $backuprelative ||
            ($checkpoint['inventory_file'] ?? '') !== $inventoryrelative ||
            ($checkpoint['status'] ?? '') !== 'prepared' ||
            !export_is_sha256($checkpoint['backup_sha256'] ?? null) ||
            !export_is_sha256($checkpoint['inventory_sha256'] ?? null)) {
        return null;
    }
    $backuppath = $outputdir . '/' . $backuprelative;
    $inventorypath = $outputdir . '/' . $inventoryrelative;
    foreach ([
        [$backuppath, 'backup_bytes', 'backup_mtime'],
        [$inventorypath, 'inventory_bytes', 'inventory_mtime'],
    ] as [$artifact, $byteskey, $mtimekey]) {
        clearstatcache(true, $artifact);
        if (!is_file($artifact) || is_link($artifact) || !is_readable($artifact)) {
            return null;
        }
        $bytes = filesize($artifact);
        $mtime = filemtime($artifact);
        if ($bytes === false || $mtime === false ||
                (int)($checkpoint[$byteskey] ?? -1) !== (int)$bytes ||
                (int)($checkpoint[$mtimekey] ?? -1) !== (int)$mtime) {
            return null;
        }
    }
    return [
        'bytes' => (int)$checkpoint['backup_bytes'],
        'reuse_kind' => 'checkpoint',
    ];
}

/** @return array{process:resource,pipes:array<int,resource>} */
function export_start_process(array $arguments): array {
    $command = array_merge([PHP_BINARY], array_map('strval', $arguments));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('No fue posible iniciar un subproceso del recolector.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return ['process' => $process, 'pipes' => $pipes];
}

function export_drain_process(array &$child, bool $final = false): void {
    foreach ([1 => STDOUT, 2 => STDERR] as $index => $destination) {
        if (!isset($child['pipes'][$index]) || !is_resource($child['pipes'][$index])) {
            continue;
        }
        do {
            $chunk = fread($child['pipes'][$index], 65536);
            if ($chunk !== false && $chunk !== '') {
                fwrite($destination, $chunk);
            }
        } while ($final && !feof($child['pipes'][$index]));
    }
}

function export_finish_process(array &$child, int $knownExitCode = -1): int {
    foreach ([1, 2] as $index) {
        if (isset($child['pipes'][$index]) && is_resource($child['pipes'][$index])) {
            stream_set_blocking($child['pipes'][$index], true);
        }
    }
    export_drain_process($child, true);
    foreach ([1, 2] as $index) {
        if (isset($child['pipes'][$index]) && is_resource($child['pipes'][$index])) {
            fclose($child['pipes'][$index]);
        }
    }
    $closed = proc_close($child['process']);
    return $knownExitCode >= 0 ? $knownExitCode : $closed;
}

function export_run(array $arguments, string $stage): void {
    $started = microtime(true);
    export_log('EXPORT_STAGE_START', ['stage' => $stage]);
    $child = export_start_process($arguments);
    do {
        export_drain_process($child);
        $status = proc_get_status($child['process']);
        if (!$status['running']) {
            break;
        }
        usleep(100000);
    } while (true);
    $exitcode = export_finish_process($child, (int)$status['exitcode']);
    $duration = (int)round(microtime(true) - $started);
    if ($exitcode !== 0) {
        throw new RuntimeException(
            'La etapa ' . $stage . ' terminó con código ' . $exitcode . '.'
        );
    }
    export_log('EXPORT_STAGE_OK', ['stage' => $stage, 'duration_seconds' => $duration]);
}

function export_worker_details(array $active): array {
    $workers = [];
    foreach ($active as $slot => $entry) {
        $progress = export_read_json($entry['progress_file']);
        $workers[] = [
            'worker' => (int)$slot,
            'position' => (int)$entry['position'],
            'course_id' => (int)$entry['course_id'],
            'shortname' => (string)$entry['shortname'],
            'stage' => (string)($progress['stage'] ?? 'starting'),
            'course_elapsed_seconds' => max(
                0,
                time() - (int)($progress['course_started_epoch'] ?? $entry['started_epoch'])
            ),
            'stage_elapsed_seconds' => max(
                0,
                time() - (int)($progress['stage_started_epoch'] ?? $entry['started_epoch'])
            ),
        ];
    }
    usort($workers, static fn(array $a, array $b): int => $a['worker'] <=> $b['worker']);
    return $workers;
}

function export_progress_document(
    string $stage,
    int $total,
    int $completed,
    int $created,
    int $reused,
    int $resumed,
    int $adopted,
    int $failed,
    int $queueIndex,
    array $active,
    array $durations,
    string $lastCompleted
): array {
    $pending = max(0, $total - $completed - $failed - count($active));
    $estimated = null;
    if ($durations !== [] && $pending + count($active) > 0) {
        $average = array_sum($durations) / count($durations);
        $estimated = (int)ceil(($pending + count($active)) * $average / max(1, count($active)));
    }
    return [
        'schema_version' => '1.0',
        'stage' => $stage,
        'total_courses' => $total,
        'completed_courses' => $completed,
        'created_courses' => $created,
        'reused_courses' => $reused,
        'resumed_courses' => $resumed,
        'adopted_courses' => $adopted,
        'failed_courses' => $failed,
        'pending_courses' => $pending,
        'scheduled_courses' => min($total, $queueIndex),
        'active_workers' => count($active),
        'percent_complete' => $total > 0 ? round(($completed / $total) * 100, 2) : 100.0,
        'estimated_remaining_seconds' => $estimated,
        'last_completed_course' => $lastCompleted,
        'workers' => export_worker_details($active),
        'updated_at_utc' => gmdate('c'),
    ];
}

function export_update_status(
    string $progressfile,
    string $statusfile,
    array $progress,
    array $metadata
): void {
    export_atomic_json($progressfile, $progress);
    if ($statusfile === '') {
        return;
    }
    $arguments = [__DIR__ . '/write-status.php'];
    foreach ($metadata as $key => $value) {
        $arguments[] = '--' . $key . '=' . (string)$value;
    }
    $arguments[] = '--statusfile=' . $statusfile;
    $arguments[] = '--progressfile=' . $progressfile;
    $child = export_start_process($arguments);
    do {
        export_drain_process($child);
        $state = proc_get_status($child['process']);
        if (!$state['running']) {
            break;
        }
        usleep(10000);
    } while (true);
    export_finish_process($child, (int)$state['exitcode']);
}

function export_poll_notification(?array &$notification, bool $terminate = false): void {
    if ($notification === null) {
        return;
    }
    export_drain_process($notification);
    $status = proc_get_status($notification['process']);
    if ($terminate && $status['running']) {
        proc_terminate($notification['process'], 15);
        $deadline = microtime(true) + 1.0;
        do {
            usleep(100000);
            $status = proc_get_status($notification['process']);
        } while ($status['running'] && microtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($notification['process'], 9);
            usleep(100000);
            $status = proc_get_status($notification['process']);
        }
    }
    if (!$status['running']) {
        export_finish_process($notification, (int)$status['exitcode']);
        $notification = null;
    }
}

function export_start_progress_notification(
    ?array &$notification,
    array $settings,
    array $progress
): void {
    export_poll_notification($notification);
    if ($notification !== null) {
        export_log('SMTP_SKIPPED', ['reason' => 'previous_notification_running']);
        return;
    }
    $arguments = [
        __DIR__ . '/notify-smtp.php',
        '--moodleconfig=' . $settings['config'],
        '--smtpconfig=' . $settings['smtpconfig'],
        '--sourceid=' . $settings['sourceid'],
        '--operation=export',
        '--result=progress',
        '--exitcode=0',
        '--stage=' . $progress['stage'],
        '--duration=' . max(0, time() - $settings['startedepoch']),
        '--outputzip=' . $settings['outputzip'],
        '--logfile=' . $settings['logfile'],
        '--completed=' . $progress['completed_courses'],
        '--total=' . $progress['total_courses'],
        '--created=' . $progress['created_courses'],
        '--reused=' . $progress['reused_courses'],
        '--resumed=' . $progress['resumed_courses'],
        '--adopted=' . $progress['adopted_courses'],
        '--failed=' . $progress['failed_courses'],
        '--pending=' . $progress['pending_courses'],
        '--active=' . $progress['active_workers'],
        '--workers=' . $settings['workers'],
        '--eta=' . (string)($progress['estimated_remaining_seconds'] ?? ''),
        '--details=' . json_encode($progress['workers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $notification = export_start_process($arguments);
    export_log('SMTP_PROGRESS_STARTED', [
        'completed' => $progress['completed_courses'],
        'total' => $progress['total_courses'],
    ]);
}

try {
    $config = trim((string)export_arg(
        'config',
        (string)(getenv('MOODLE_CONFIG_PATH') ?: '/var/www/html/config.php')
    ));
    $sourceid = strtolower(trim((string)export_arg('sourceid', '')));
    $sourcename = trim((string)export_arg('sourcename', ''));
    $outputdir = rtrim(trim((string)export_arg('outputdir', '')), '/\\');
    $outputzip = trim((string)export_arg('outputzip', ''));
    $scope = strtolower(trim((string)export_arg('scope', 'all')));
    $workers = (int)export_arg('workers', '1');
    $workersrequested = trim((string)export_arg('workersrequested', (string)$workers));
    $cputhreads = max(1, (int)export_arg('cputhreads', (string)$workers));
    $autoworkerscap = max(1, (int)export_arg('autoworkerscap', '4'));
    $notifyevery = (int)export_arg('notifyevery', '10');
    $smtpconfig = trim((string)export_arg('smtpconfig', ''));
    $statusfile = trim((string)export_arg('statusfile', ''));
    $progressfile = trim((string)export_arg('progressfile', $outputdir . '/export-progress.json'));
    $executionmode = trim((string)export_arg('executionmode', 'foreground'));
    $logfile = trim((string)export_arg('logfile', ''));
    $startedepoch = max(0, (int)export_arg('startedepoch', (string)time()));
    $collectorversion = trim((string)export_arg('collectorversion', '7.4.1-linux'));
    $tempdir = rtrim(trim((string)export_arg('tempdir', '')), '/\\');
    $reusebackups = rtrim(trim((string)export_arg('reusebackups', '')), '/\\');
    $reuseonly = (int)export_arg('reuseonly', '0');
    $restart = (int)export_arg('restart', '0');
    if (!is_readable($config) ||
            !preg_match('/^[a-z][a-z0-9_-]*$/', $sourceid) ||
            $sourcename === '' || $outputdir === '' || $outputzip === '' ||
            !in_array($scope, ['lab', 'all'], true) ||
            $workers < 1 || $workers > 4 ||
            $notifyevery < 0 || $notifyevery > 1440 ||
            !in_array($reuseonly, [0, 1], true) ||
            !in_array($restart, [0, 1], true)) {
        throw new RuntimeException('Los parámetros del orquestador no son válidos.');
    }
    if ($reuseonly === 1 && $reusebackups === '') {
        throw new RuntimeException('--reuse-only requiere --reuse-backups.');
    }
    if (!is_dir($outputdir) && !mkdir($outputdir, 0770, true) && !is_dir($outputdir)) {
        throw new RuntimeException('No fue posible crear ' . $outputdir . '.');
    }
    if ($tempdir !== '') {
        $resolved = realpath($tempdir);
        if ($resolved === false || !is_dir($resolved) || !is_writable($resolved)) {
            throw new RuntimeException('El almacenamiento temporal opcional no es escribible.');
        }
        $tempdir = $resolved;
    }
    if ($reusebackups !== '') {
        $resolved = realpath($reusebackups);
        if ($resolved === false || !is_dir($resolved) ||
                !is_readable($resolved) || !is_executable($resolved)) {
            throw new RuntimeException('El directorio de respaldos existentes no es legible.');
        }
        $reusebackups = $resolved;
    }

    $scripts = __DIR__;
    if ($restart === 1) {
        if (!export_work_directory_is_owned($outputdir, $sourceid)) {
            throw new RuntimeException(
                '--restart se negó a limpiar un directorio de trabajo sin marca de este origen.'
            );
        }
        export_log('RESTART_REQUESTED', ['source' => $sourceid]);
        export_reset_work_artifacts($outputdir, $sourceid);
    }
    $contractsha = hash('sha256', 'moodle-consolidation-source|1.0|' . $sourceid . '|' . $sourcename);
    $statusmetadata = [
        'collectorversion' => $collectorversion,
        'sourceid' => $sourceid,
        'state' => 'running',
        'stage' => 'inventory',
        'executionmode' => $executionmode,
        'exitcode' => '0',
        'startedepoch' => (string)$startedepoch,
        'workersrequested' => $workersrequested,
        'workerseffective' => (string)$workers,
        'cputhreads' => (string)$cputhreads,
        'autoworkerscap' => (string)$autoworkerscap,
        'notifyevery' => (string)$notifyevery,
        'outputdir' => dirname($outputzip),
        'outputzip' => $outputzip,
        'workdir' => $outputdir,
        'tempdir' => $tempdir,
        'reusebackups' => $reusebackups,
        'reuseonly' => (string)$reuseonly,
        'logfile' => $logfile,
        'endedat' => '',
    ];
    $progress = export_progress_document('identities', 0, 0, 0, 0, 0, 0, 0, 0, [], [], '');
    export_update_status($progressfile, $statusfile, $progress, $statusmetadata);

    $baseartifacts = [
        'identidades.json' => $outputdir . '/identidades.json',
        'inventario-origen.json' => $outputdir . '/inventario-origen.json',
        'plugins.json' => $outputdir . '/plugins.json',
    ];
    $runmanifestpath = $outputdir . '/run-manifest.json';
    $basereused = is_readable($runmanifestpath);
    if ($basereused) {
        $runmanifest = export_validate_run_manifest(
            $runmanifestpath,
            $sourceid,
            $collectorversion,
            $config,
            $baseartifacts
        );
        export_log('RESUME_BASE_REUSED', ['source' => $sourceid]);
    } else {
        export_run([
            $scripts . '/extract-identities.php', '--config=' . $config,
            '--source=' . $sourceid, '--scope=' . $scope,
            '--output=' . $baseartifacts['identidades.json'],
        ], 'identities');
        $progress['stage'] = 'source-inventory';
        $statusmetadata['stage'] = 'source-inventory';
        export_update_status($progressfile, $statusfile, $progress, $statusmetadata);
        export_run([
            $scripts . '/phase6-inventory.php', '--config=' . $config,
            '--output=' . $baseartifacts['inventario-origen.json'],
            '--configsha=' . $contractsha, '--sourceid=' . $sourceid,
            '--sourcename=' . $sourcename,
        ], 'source-inventory');
        $progress['stage'] = 'plugins';
        $statusmetadata['stage'] = 'plugins';
        export_update_status($progressfile, $statusfile, $progress, $statusmetadata);
        export_run([
            $scripts . '/source-plugins.php', '--config=' . $config,
            '--output=' . $baseartifacts['plugins.json'], '--sourceid=' . $sourceid,
        ], 'plugins');
        $runmanifest = export_run_manifest(
            $runmanifestpath,
            $sourceid,
            $collectorversion,
            $config,
            $baseartifacts
        );
    }
    $runfingerprint = (string)$runmanifest['run_fingerprint'];

    $inventory = json_decode(
        (string)file_get_contents($baseartifacts['inventario-origen.json']),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $courses = array_values($inventory['courses'] ?? []);
    $total = count($courses);
    $reusecandidates = [];
    $reuseindexcounts = [];
    $runtime = $outputdir . '/.runtime-' . $sourceid;
    if (!is_dir($runtime) && !mkdir($runtime, 0770, true) && !is_dir($runtime)) {
        throw new RuntimeException('No fue posible crear el directorio temporal de workers.');
    }
    foreach (glob($runtime . '/*') ?: [] as $stale) {
        if (is_file($stale) && preg_match(
            '/\/(?:worker|result)-[a-z0-9_.-]+\.json(?:\.tmp\.[0-9]+)?$/',
            $stale
        )) {
            @unlink($stale);
        }
    }
    $seeddir = $runtime . '/seeds';
    if (!is_dir($seeddir) && !mkdir($seeddir, 0770, true) && !is_dir($seeddir)) {
        throw new RuntimeException('No fue posible crear el caché de inventario por curso.');
    }
    $reusecandidatesdir = $runtime . '/reuse-candidates';
    if (!is_dir($reusecandidatesdir) &&
            !mkdir($reusecandidatesdir, 0770, true) &&
            !is_dir($reusecandidatesdir)) {
        throw new RuntimeException('No fue posible crear el caché de respaldos candidatos.');
    }

    $queueIndex = 0;
    $completed = 0;
    $created = 0;
    $reused = 0;
    $resumed = 0;
    $adopted = 0;
    $failed = 0;
    $active = [];
    $durations = [];
    $lastCompleted = '';
    $failureMessages = [];
    $notification = null;
    $lastHeartbeat = 0;
    $nextNotification = $notifyevery > 0 ? time() + ($notifyevery * 60) : PHP_INT_MAX;
    $pendingcourses = [];
    foreach ($courses as $index => $course) {
        $courseid = (int)($course['source_course_id'] ?? 0);
        if ($courseid < 1) {
            throw new RuntimeException('El inventario contiene un curso sin ID válido.');
        }
        $fastcheckpoint = export_fast_checkpoint(
            $outputdir,
            $sourceid,
            $courseid,
            $runfingerprint
        );
        if ($fastcheckpoint !== null) {
            $completed++;
            $reused++;
            $resumed++;
            $lastCompleted = (string)($course['shortname'] ?? $courseid);
            export_log('COURSE_RESUMED_FAST', [
                'position' => ($index + 1) . '/' . $total,
                'course_id' => $courseid,
                'shortname' => $lastCompleted,
                'bytes' => $fastcheckpoint['bytes'],
            ]);
            continue;
        }
        $pendingcourses[] = [
            'position' => $index + 1,
            'course' => $course,
        ];
    }
    $initialresumed = $resumed;
    $progress = export_progress_document(
        'course-backups', $total, $completed, $created, $reused,
        $resumed, $adopted, 0, $completed, [], [], $lastCompleted
    );
    if ($reusebackups !== '' && $pendingcourses !== []) {
        $progress['stage'] = 'existing-backups-index';
        $statusmetadata['stage'] = 'existing-backups-index';
        export_update_status($progressfile, $statusfile, $progress, $statusmetadata);
        $reuseindexpath = $outputdir . '/existing-backups-index.json';
        export_run([
            $scripts . '/source-index-backups.php',
            '--config=' . $config,
            '--directory=' . $reusebackups,
            '--output=' . $reuseindexpath,
            '--exclude=' . $outputdir,
        ], 'existing-backups-index');
        $reuseindex = export_read_json($reuseindexpath);
        $reuseindexcounts = is_array($reuseindex['counts'] ?? null)
            ? $reuseindex['counts']
            : [];
        if (is_array($reuseindex['candidate_groups'] ?? null)) {
            $reusecandidates = $reuseindex['candidate_groups'];
        } else if (is_array($reuseindex['candidates'] ?? null)) {
            // Compatibilidad con índices generados por la RC2.
            foreach ($reuseindex['candidates'] as $courseid => $candidate) {
                if (is_array($candidate)) {
                    $reusecandidates[(string)$courseid] = [$candidate];
                }
            }
        }
        $missingcandidateids = [];
        foreach ($pendingcourses as $pendingentry) {
            $pendingid = (int)($pendingentry['course']['source_course_id'] ?? 0);
            if (!is_array($reusecandidates[(string)$pendingid] ?? null) ||
                    $reusecandidates[(string)$pendingid] === []) {
                $missingcandidateids[] = $pendingid;
            }
        }
        export_log('EXISTING_BACKUPS_PLAN', [
            'scanned' => (int)($reuseindexcounts['scanned'] ?? 0),
            'accepted_files' => (int)($reuseindexcounts['accepted'] ?? 0),
            'rejected_files' => (int)($reuseindexcounts['rejected'] ?? 0),
            'candidate_courses' => count($reusecandidates),
            'pending_courses' => count($pendingcourses),
            'missing_candidate_courses' => count($missingcandidateids),
            'reuse_only' => $reuseonly,
        ]);
        if ($reuseonly === 1 && $missingcandidateids !== []) {
            $sample = array_slice($missingcandidateids, 0, 20);
            throw new RuntimeException(
                '--reuse-only no encontró un MBZ candidato para ' .
                count($missingcandidateids) . ' curso(s). course_ids=' .
                implode(',', $sample) .
                (count($missingcandidateids) > count($sample) ? ',...' : '')
            );
        }
    }
    $progress['stage'] = 'course-backups';
    $statusmetadata['stage'] = 'course-backups';
    export_update_status($progressfile, $statusfile, $progress, $statusmetadata);
    export_log('COURSE_QUEUE_START', [
        'total' => $total,
        'resumed' => $resumed,
        'pending' => count($pendingcourses),
        'workers' => $workers,
        'strategy' => 'dynamic',
    ]);

    while ($queueIndex < count($pendingcourses) || $active !== []) {
        while ($failureMessages === [] &&
                $queueIndex < count($pendingcourses) &&
                count($active) < $workers) {
            $slot = 1;
            while (isset($active[$slot])) {
                $slot++;
            }
            $queueentry = $pendingcourses[$queueIndex];
            $course = $queueentry['course'];
            $position = (int)$queueentry['position'];
            $courseid = (int)($course['source_course_id'] ?? 0);
            $shortname = trim((string)($course['shortname'] ?? $courseid));
            if ($courseid < 1) {
                throw new RuntimeException('El inventario contiene un curso sin ID válido.');
            }
            $progresspath = $runtime . '/worker-' . $slot . '.json';
            $resultpath = $runtime . '/result-' . $position . '-' . $courseid . '.json';
            $seedpath = $seeddir . '/course-' . $courseid . '.json';
            export_atomic_json($seedpath, $course);
            @unlink($progresspath);
            @unlink($resultpath);
            $workerarguments = [
                $scripts . '/source-backup-course.php', '--config=' . $config,
                '--outputdir=' . $outputdir, '--sourceid=' . $sourceid,
                '--courseid=' . $courseid, '--worker=' . $slot,
                '--position=' . $position, '--total=' . $total,
                '--progressfile=' . $progresspath, '--resultfile=' . $resultpath,
                '--inventoryseed=' . $seedpath,
                '--runfingerprint=' . $runfingerprint,
                '--reuseonly=' . $reuseonly,
            ];
            if ($tempdir !== '') {
                $workerarguments[] = '--tempdir=' . $tempdir;
            }
            $coursecandidates = $reusecandidates[(string)$courseid] ?? [];
            if (is_array($coursecandidates) && $coursecandidates !== []) {
                $reusecandidatepath =
                    $reusecandidatesdir . '/course-' . $courseid . '.json';
                export_atomic_json($reusecandidatepath, [
                    'schema_version' => '1.0',
                    'course_id' => $courseid,
                    'candidates' => array_values($coursecandidates),
                ]);
                $workerarguments[] = '--reusecandidates=' . $reusecandidatepath;
            }
            $child = export_start_process($workerarguments);
            $active[$slot] = $child + [
                'position' => $position,
                'course_id' => $courseid,
                'shortname' => $shortname,
                'progress_file' => $progresspath,
                'result_file' => $resultpath,
                'started_epoch' => time(),
            ];
            $queueIndex++;
            export_log('WORKER_ASSIGNED', [
                'worker' => $slot, 'position' => $position . '/' . $total,
                'course_id' => $courseid, 'shortname' => $shortname,
                'reuse_candidates' => is_array($coursecandidates)
                    ? count($coursecandidates)
                    : 0,
                'reuse_only' => $reuseonly,
            ]);
        }

        $completionDetected = false;
        foreach (array_keys($active) as $slot) {
            export_drain_process($active[$slot]);
            $state = proc_get_status($active[$slot]['process']);
            if ($state['running']) {
                continue;
            }
            $completionDetected = true;
            $entry = $active[$slot];
            $exitcode = export_finish_process($active[$slot], (int)$state['exitcode']);
            unset($active[$slot]);
            $result = export_read_json($entry['result_file']);
            if ($exitcode === 0 && in_array(($result['status'] ?? ''), ['created', 'reused'], true)) {
                $completed++;
                $duration = max(0, (int)($result['duration_seconds'] ?? (time() - $entry['started_epoch'])));
                if ($result['status'] === 'created') {
                    $created++;
                    $durations[] = $duration;
                } else {
                    $reused++;
                    if (($result['reuse_kind'] ?? '') === 'existing-backup') {
                        $adopted++;
                    } else {
                        $resumed++;
                    }
                }
                $lastCompleted = (string)$entry['shortname'];
                export_log('WORKER_COMPLETED', [
                    'worker' => $slot, 'position' => $entry['position'] . '/' . $total,
                    'course_id' => $entry['course_id'], 'status' => $result['status'],
                    'duration_seconds' => $duration,
                ]);
            } else {
                $failed++;
                $message = trim((string)($result['error'] ?? 'subprocess_exit_' . $exitcode));
                $failureMessages[] = 'course_id=' . $entry['course_id'] . ' error=' . $message;
                export_log('WORKER_FAILED', [
                    'worker' => $slot, 'position' => $entry['position'] . '/' . $total,
                    'course_id' => $entry['course_id'], 'exit_code' => $exitcode,
                    'error' => $message,
                ]);
            }
        }

        $now = time();
        if ($completionDetected || $lastHeartbeat === 0 || $now - $lastHeartbeat >= 60) {
            $progress = export_progress_document(
                'course-backups', $total, $completed, $created, $reused,
                $resumed, $adopted, $failed, $initialresumed + $queueIndex,
                $active, $durations, $lastCompleted
            );
            if ($failureMessages !== []) {
                $progress['failures'] = $failureMessages;
            }
            export_update_status($progressfile, $statusfile, $progress, $statusmetadata);
            export_log('EXPORT_HEARTBEAT', [
                'completed' => $completed . '/' . $total,
                'created' => $created, 'reused' => $reused,
                'resumed' => $resumed, 'adopted' => $adopted,
                'failed' => $failed,
                'active' => count($active), 'pending' => $progress['pending_courses'],
                'percent' => $progress['percent_complete'],
                'eta_seconds' => $progress['estimated_remaining_seconds'] ?? 'unknown',
            ]);
            foreach ($progress['workers'] as $workerstate) {
                export_log('WORKER_STATUS', [
                    'worker' => $workerstate['worker'],
                    'position' => $workerstate['position'] . '/' . $total,
                    'course_id' => $workerstate['course_id'],
                    'stage' => $workerstate['stage'],
                    'course_elapsed_seconds' => $workerstate['course_elapsed_seconds'],
                    'stage_elapsed_seconds' => $workerstate['stage_elapsed_seconds'],
                ]);
            }
            $lastHeartbeat = $now;
        }
        export_poll_notification($notification);
        if ($notifyevery > 0 && $now >= $nextNotification) {
            export_start_progress_notification($notification, [
                'config' => $config, 'smtpconfig' => $smtpconfig,
                'sourceid' => $sourceid, 'startedepoch' => $startedepoch,
                'outputzip' => $outputzip, 'logfile' => $logfile,
                'workers' => $workers,
            ], $progress);
            $nextNotification = $now + ($notifyevery * 60);
        }
        if ($failureMessages !== [] && $active === []) {
            break;
        }
        if (!$completionDetected) {
            usleep(200000);
        }
    }
    export_poll_notification($notification, true);
    if ($failureMessages !== []) {
        throw new RuntimeException(
            'Fallaron uno o más cursos; se preservaron los checkpoints válidos. ' .
            implode(' | ', $failureMessages)
        );
    }
    if ($completed !== $total) {
        throw new RuntimeException('No se completaron todos los cursos antes del sellado.');
    }

    $progress = export_progress_document(
        'sealing', $total, $completed, $created, $reused,
        $resumed, $adopted, 0, $total, [], $durations, $lastCompleted
    );
    $statusmetadata['stage'] = 'sealing';
    export_update_status($progressfile, $statusfile, $progress, $statusmetadata);
    export_run([
        $scripts . '/source-seal.php', '--inputdir=' . $outputdir,
        '--outputzip=' . $outputzip, '--sourceid=' . $sourceid,
    ], 'sealing');
    $progress['stage'] = 'sealed';
    $statusmetadata['stage'] = 'sealed';
    export_update_status($progressfile, $statusfile, $progress, $statusmetadata);
} catch (Throwable $error) {
    fwrite(STDERR, 'SOURCE_EXPORT_ERROR ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
