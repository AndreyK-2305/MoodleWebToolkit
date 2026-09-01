<?php
// Worker reanudable: inventaría y respalda un curso con progreso por fases.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/bootstrap.php');
require(collector_moodle_config_path());
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/phase5-lib.php');
require_once(__DIR__ . '/backup-reuse-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'config' => null,
        'outputdir' => null,
        'sourceid' => null,
        'courseid' => null,
        'worker' => 1,
        'position' => 1,
        'total' => 1,
        'progressfile' => null,
        'resultfile' => null,
        'inventoryseed' => null,
        'reusecandidate' => null,
        'reusecandidates' => null,
        'reuseonly' => 0,
        'tempdir' => null,
        'runfingerprint' => null,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php source-backup-course.php --outputdir=RUTA " .
        "--sourceid=virtual --courseid=2 --config=/ruta/config.php " .
        "[--worker=1 --position=1 --total=1 --progressfile=RUTA --resultfile=RUTA " .
        "--inventoryseed=RUTA --reusecandidate=MBZ --reusecandidates=JSON " .
        "--reuseonly=0|1 --tempdir=RUTA --runfingerprint=SHA256]\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

function collector_course_key(string $sourceid, int $courseid): string {
    $source = strtoupper((string)preg_replace('/[^a-z0-9_-]+/i', '-', $sourceid));
    $token = strtoupper(substr(hash('sha256', $sourceid . '|course|' . $courseid), 0, 12));
    return 'COURSE-' . $source . '-' . $token;
}

function collector_value_sha256(mixed $value): string {
    if (is_array($value)) {
        if (array_is_list($value)) {
            $value = array_map('collector_value_sha256_value', $value);
        } else {
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = collector_value_sha256_value($item);
            }
        }
    }
    return hash('sha256', json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRESERVE_ZERO_FRACTION |
        JSON_THROW_ON_ERROR
    ));
}

function collector_value_sha256_value(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('collector_value_sha256_value', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = collector_value_sha256_value($item);
    }
    return $value;
}

/** @return array{bytes:int,mtime:int} */
function collector_artifact_metadata(string $path, string $label): array {
    clearstatcache(true, $path);
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        throw new RuntimeException($label . ' no existe o no es un archivo regular.');
    }
    $bytes = filesize($path);
    $mtime = filemtime($path);
    if ($bytes === false || $bytes < 1 || $mtime === false) {
        throw new RuntimeException($label . ' no conserva metadatos válidos.');
    }
    return ['bytes' => (int)$bytes, 'mtime' => (int)$mtime];
}

function collector_is_sha256(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
}

function collector_atomic_json(string $path, array $document): void {
    if ($path === '') {
        return;
    }
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

function collector_log(string $event, array $fields): void {
    $parts = [gmdate('Y-m-d\TH:i:s\Z'), $event];
    foreach ($fields as $key => $value) {
        $text = preg_replace('/[\r\n\t ]+/', '_', trim((string)$value));
        $parts[] = $key . '=' . $text;
    }
    cli_writeln(implode(' ', $parts));
}

$progressfile = trim((string)$options['progressfile']);
$resultfile = trim((string)$options['resultfile']);
$worker = max(1, (int)$options['worker']);
$position = max(1, (int)$options['position']);
$total = max(1, (int)$options['total']);
$courseid = (int)$options['courseid'];
$shortname = (string)$courseid;
$courseStartedEpoch = time();
$courseStarted = microtime(true);
$currentStage = 'initializing';
$stageStarted = microtime(true);

$setPhase = static function(string $stage, array $extra = []) use (
    &$currentStage,
    &$stageStarted,
    &$shortname,
    $progressfile,
    $worker,
    $position,
    $total,
    $courseid,
    $courseStartedEpoch,
    $courseStarted
): void {
    $now = microtime(true);
    if ($currentStage !== 'initializing') {
        collector_log('COURSE_PHASE_OK', [
            'worker' => $worker,
            'position' => $position . '/' . $total,
            'course_id' => $courseid,
            'stage' => $currentStage,
            'duration_seconds' => round($now - $stageStarted, 2),
        ]);
    }
    $currentStage = $stage;
    $stageStarted = $now;
    $document = [
        'schema_version' => '1.0',
        'worker' => $worker,
        'position' => $position,
        'total' => $total,
        'course_id' => $courseid,
        'shortname' => $shortname,
        'stage' => $stage,
        'course_started_epoch' => $courseStartedEpoch,
        'stage_started_epoch' => time(),
        'course_elapsed_seconds' => (int)round($now - $courseStarted),
        'updated_at_utc' => gmdate('c'),
    ] + $extra;
    collector_atomic_json($progressfile, $document);
    collector_log('COURSE_PHASE_START', [
        'worker' => $worker,
        'position' => $position . '/' . $total,
        'course_id' => $courseid,
        'shortname' => $shortname,
        'stage' => $stage,
    ] + $extra);
};

$writeResult = static function(string $status, array $extra = []) use (
    $resultfile,
    $worker,
    $position,
    $total,
    $courseid,
    &$shortname,
    $courseStarted
): void {
    collector_atomic_json($resultfile, [
        'schema_version' => '1.0',
        'worker' => $worker,
        'position' => $position,
        'total' => $total,
        'course_id' => $courseid,
        'shortname' => $shortname,
        'status' => $status,
        'duration_seconds' => (int)round(microtime(true) - $courseStarted),
        'ended_at_utc' => gmdate('c'),
    ] + $extra);
};

try {
    $outputdir = rtrim(trim((string)$options['outputdir']), '/\\');
    $sourceid = core_text::strtolower(trim((string)$options['sourceid']));
    $inventoryseedpath = trim((string)$options['inventoryseed']);
    $reusecandidate = trim((string)$options['reusecandidate']);
    $reusecandidatespath = trim((string)$options['reusecandidates']);
    $reuseonly = (int)$options['reuseonly'];
    $tempdir = rtrim(trim((string)$options['tempdir']), '/\\');
    $runfingerprint = strtolower(trim((string)$options['runfingerprint']));
    if ($outputdir === '' ||
            !preg_match('/^[a-z][a-z0-9_-]*$/', $sourceid) ||
            $courseid < 1 || $courseid === SITEID ||
            $worker > 4 || $position > $total ||
            !in_array($reuseonly, [0, 1], true) ||
            ($runfingerprint !== '' && !collector_is_sha256($runfingerprint))) {
        throw new RuntimeException('outputdir, sourceid o parámetros del worker inválidos.');
    }
    if ($tempdir !== '') {
        $resolvedtempdir = realpath($tempdir);
        if ($resolvedtempdir === false || !is_dir($resolvedtempdir) ||
                !is_writable($resolvedtempdir)) {
            throw new RuntimeException('El almacenamiento temporal opcional no es escribible.');
        }
        $CFG->backuptempdir = $resolvedtempdir;
        $tempdir = $resolvedtempdir;
    }
    $setPhase('course-load');
    $course = $DB->get_record(
        'course',
        ['id' => $courseid],
        'id,category,fullname,shortname,idnumber',
        MUST_EXIST
    );
    $shortname = (string)$course->shortname;
    $expectedbackupsettings = p5_expected_backup_settings();
    $backupprofilesha = collector_value_sha256($expectedbackupsettings);
    $reusecandidates = [];
    if ($reusecandidatespath !== '') {
        $candidatepayload = p5_read_json($reusecandidatespath);
        $candidatevalues = $candidatepayload['candidates'] ?? [];
        if (!is_array($candidatevalues)) {
            throw new RuntimeException('La lista de respaldos candidatos no es válida.');
        }
        foreach ($candidatevalues as $candidatevalue) {
            $candidatepath = is_array($candidatevalue)
                ? trim((string)($candidatevalue['path'] ?? ''))
                : trim((string)$candidatevalue);
            if ($candidatepath !== '') {
                $reusecandidates[] = is_array($candidatevalue)
                    ? $candidatevalue + ['path' => $candidatepath]
                    : ['path' => $candidatepath];
            }
        }
    }
    if ($reusecandidate !== '') {
        $reusecandidates[] = ['path' => $reusecandidate];
    }
    $uniquecandidates = [];
    foreach ($reusecandidates as $candidateinspection) {
        $candidatepath = trim((string)($candidateinspection['path'] ?? ''));
        if ($candidatepath !== '' && !isset($uniquecandidates[$candidatepath])) {
            $uniquecandidates[$candidatepath] = $candidateinspection;
        }
    }
    $reusecandidates = array_values($uniquecandidates);
    $coursekey = collector_course_key($sourceid, $courseid);
    $basename = core_text::strtolower($coursekey);
    $directories = [
        'courses' => $outputdir . '/cursos',
        'inventories' => $outputdir . '/inventarios',
        'checkpoints' => $outputdir . '/checkpoints',
    ];
    foreach ($directories as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible crear ' . $directory . '.');
        }
    }

    $backuppath = $directories['courses'] . '/' . $basename . '.mbz';
    $inventorypath = $directories['inventories'] . '/inventory-' . $basename . '.json';
    $checkpointpath = $directories['checkpoints'] . '/checkpoint-' . $basename . '.json';

    $inventory = null;
    $statehash = '';
    $loadinventory = static function() use (
        &$inventory,
        &$statehash,
        $inventoryseedpath,
        $courseid,
        $setPhase
    ): void {
        if ($inventory !== null) {
            return;
        }
        $setPhase('course-inventory');
        $seed = null;
        if ($inventoryseedpath !== '') {
            $seed = p5_read_json($inventoryseedpath);
        }
        $inventory = p5_collect_course_inventory($courseid, $seed);
        $statehash = collector_value_sha256($inventory);
    };

    if (is_readable($checkpointpath)) {
        $setPhase('checkpoint-validation');
        $checkpoint = p5_read_json($checkpointpath);
        $backupmeta = collector_artifact_metadata($backuppath, 'El backup');
        $inventorymeta = collector_artifact_metadata($inventorypath, 'El inventario del curso');
        $basevalid = ($checkpoint['schema_version'] ?? '') === '1.0' &&
                ($checkpoint['package_type'] ?? '') === 'moodle-consolidation-source-course' &&
                ($checkpoint['source_id'] ?? '') === $sourceid &&
                ($checkpoint['course_key'] ?? '') === $coursekey &&
                (int)($checkpoint['source_course_id'] ?? 0) === $courseid &&
                collector_is_sha256($checkpoint['backup_sha256'] ?? null) &&
                collector_is_sha256($checkpoint['inventory_sha256'] ?? null) &&
                (int)($checkpoint['backup_bytes'] ?? -1) === $backupmeta['bytes'] &&
                ($checkpoint['status'] ?? '') === 'prepared';
        if (!$basevalid) {
            throw new RuntimeException('El checkpoint existente no corresponde al curso.');
        }
        $hasfastmetadata = array_key_exists('backup_mtime', $checkpoint) &&
            array_key_exists('inventory_bytes', $checkpoint) &&
            array_key_exists('inventory_mtime', $checkpoint);
        $metadataunchanged = $hasfastmetadata &&
            (int)$checkpoint['backup_mtime'] === $backupmeta['mtime'] &&
            (int)$checkpoint['inventory_bytes'] === $inventorymeta['bytes'] &&
            (int)$checkpoint['inventory_mtime'] === $inventorymeta['mtime'];
        $fastresume = $runfingerprint !== '' &&
            ($checkpoint['run_fingerprint'] ?? '') === $runfingerprint &&
            $metadataunchanged;

        if (!$fastresume) {
            $loadinventory();
        }
        $statecompatible = $fastresume ||
            ($checkpoint['source_state_sha256'] ?? '') === $statehash;
        $legacyresume = false;
        if (!$statecompatible &&
                str_starts_with((string)($checkpoint['collector_version'] ?? ''), '7.3.0-')) {
            $legacyinventory = $inventory;
            unset($legacyinventory['source_change_epoch']);
            $statecompatible = ($checkpoint['source_state_sha256'] ?? '') ===
                collector_value_sha256($legacyinventory);
            $legacyresume = $statecompatible;
        }
        if (!$fastresume && (
                ($checkpoint['schema_version'] ?? '') !== '1.0' ||
                ($checkpoint['package_type'] ?? '') !== 'moodle-consolidation-source-course' ||
                ($checkpoint['source_id'] ?? '') !== $sourceid ||
                ($checkpoint['course_key'] ?? '') !== $coursekey ||
                (int)($checkpoint['source_course_id'] ?? 0) !== $courseid ||
                !$statecompatible ||
                !collector_is_sha256($checkpoint['backup_sha256'] ?? null) ||
                !collector_is_sha256($checkpoint['inventory_sha256'] ?? null) ||
                (int)($checkpoint['backup_bytes'] ?? -1) !== $backupmeta['bytes'] ||
                ($checkpoint['status'] ?? '') !== 'prepared')) {
            throw new RuntimeException('El checkpoint existente no coincide con el curso actual.');
        }

        if ($hasfastmetadata) {
            if (!$metadataunchanged) {
                throw new RuntimeException('Los artefactos cambiaron después de crear el checkpoint.');
            }
        } else {
            $setPhase('checkpoint-migration-hash');
            $backupsha = hash_file('sha256', $backuppath);
            $inventorysha = hash_file('sha256', $inventorypath);
            if ($backupsha === false || $inventorysha === false ||
                    !hash_equals($checkpoint['backup_sha256'], $backupsha) ||
                    !hash_equals($checkpoint['inventory_sha256'], $inventorysha)) {
                throw new RuntimeException('El checkpoint anterior perdió integridad durante la migración.');
            }
            $checkpoint['collector_version'] = '7.4.1-linux';
            $checkpoint['checkpoint_updated_at_utc'] = gmdate('c');
            $checkpoint['backup_bytes'] = $backupmeta['bytes'];
            $checkpoint['backup_mtime'] = $backupmeta['mtime'];
            $checkpoint['inventory_bytes'] = $inventorymeta['bytes'];
            $checkpoint['inventory_mtime'] = $inventorymeta['mtime'];
            $checkpoint['hash_strategy'] = 'single-pass-v1';
            if ($runfingerprint !== '') {
                $checkpoint['run_fingerprint'] = $runfingerprint;
            }
            p5_write_json($checkpointpath, $checkpoint);
        }
        if ($runfingerprint !== '' &&
                ($checkpoint['run_fingerprint'] ?? '') !== $runfingerprint) {
            $checkpoint['collector_version'] = '7.4.1-linux';
            $checkpoint['checkpoint_updated_at_utc'] = gmdate('c');
            $checkpoint['run_fingerprint'] = $runfingerprint;
            p5_write_json($checkpointpath, $checkpoint);
        }
        $setPhase('completed', ['status' => 'reused']);
        $writeResult('reused', [
            'bytes' => $backupmeta['bytes'],
            'reuse_kind' => 'checkpoint',
            'fast_resume' => $fastresume,
            'legacy_resume' => $legacyresume,
        ]);
        cli_writeln(
            'SOURCE_COURSE_BACKUP_OK source=' . $sourceid .
            ' course_key=' . $coursekey . ' course_id=' . $courseid .
            ' status=reused reuse_kind=checkpoint fast_resume=' . ($fastresume ? '1' : '0') .
            ' legacy_resume=' . ($legacyresume ? '1' : '0')
        );
        exit(0);
    }

    $loadinventory();

    $partials = array_values(array_filter(
        [$backuppath, $inventorypath],
        static fn(string $path): bool => is_file($path)
    ));
    if ($partials !== []) {
        $setPhase('partial-cleanup', ['files' => count($partials)]);
        foreach ($partials as $partial) {
            if (!unlink($partial) && is_file($partial)) {
                throw new RuntimeException('No fue posible retirar el artefacto parcial ' . $partial . '.');
            }
        }
    }

    $setPhase('inventory-write');
    $document = [
        'schema_version' => '1.0',
        'package_type' => 'moodle-consolidation-course-inventory',
        'generated_at_utc' => gmdate('c'),
        'source_id' => $sourceid,
        'course_key' => $coursekey,
        'source_state_sha256' => $statehash,
        'inventory' => $inventory,
        'write_performed' => false,
    ];
    p5_write_json($inventorypath, $document);

    try {
        $inventorymeta = collector_artifact_metadata($inventorypath, 'El inventario del curso');
        $inventorysha = hash_file('sha256', $inventorypath);
        if ($inventorysha === false) {
            throw new RuntimeException('No fue posible calcular SHA-256 del inventario del curso.');
        }
        $backupmeta = null;
        $backuporigin = 'generated';
        $existingbackupname = '';
        $existingbackupdate = 0;
        $existingbackupmode = '';
        $existingbackuptype = '';
        $existingbackupsettingssha = '';
        $existingbackupprofilewarnings = [];
        $rejectionreasons = [];
        $sourcechangeepoch = max(0, (int)($inventory['source_change_epoch'] ?? 0));
        foreach ($reusecandidates as $candidateindex => $candidateinspection) {
            $candidate = trim((string)($candidateinspection['path'] ?? ''));
            $setPhase('existing-backup-validation', [
                'candidate' => $candidateindex + 1,
                'candidates' => count($reusecandidates),
                'file' => basename($candidate),
            ]);
            if (array_key_exists('valid', $candidateinspection)) {
                $inspection = $candidateinspection;
                if (!collector_reuse_inspection_matches_file(
                    $inspection,
                    $candidate
                )) {
                    $inspection['valid'] = false;
                    $inspection['reason'] = 'candidate_changed_after_index';
                }
            } else {
                $inspection = collector_inspect_existing_backup($candidate);
            }
            $rejection = collector_reuse_rejection_reason(
                $inspection,
                $candidate,
                $courseid,
                (string)$course->shortname,
                $sourcechangeepoch,
                $outputdir,
                $expectedbackupsettings
            );
            if ($rejection !== '') {
                $rejectionreasons[] = $rejection;
                collector_log('EXISTING_BACKUP_REJECTED', [
                    'worker' => $worker,
                    'course_id' => $courseid,
                    'candidate' => ($candidateindex + 1) . '/' . count($reusecandidates),
                    'file' => basename($candidate),
                    'reason' => $rejection,
                    'backup_date' => (int)($inspection['backup_date'] ?? 0),
                    'source_change_epoch' => $sourcechangeepoch,
                    'expected_profile_sha256' => $backupprofilesha,
                ]);
                continue;
            }

            $candidateprofilewarnings =
                collector_reuse_advisory_profile_differences(
                    $inspection,
                    $expectedbackupsettings
                );

            $setPhase('existing-backup-copy-sha', [
                'candidate' => $candidateindex + 1,
                'candidates' => count($reusecandidates),
                'file' => basename($candidate),
                'bytes' => (int)$inspection['bytes'],
            ]);
            try {
                $input = @fopen($candidate, 'rb');
                if ($input === false) {
                    throw new RuntimeException('candidate_open_failed');
                }
                try {
                    $backupmeta = p5_copy_stream_with_sha256($input, $backuppath);
                } finally {
                    fclose($input);
                }
                if (!collector_reuse_inspection_matches_file(
                    $inspection,
                    $candidate
                )) {
                    throw new RuntimeException('candidate_changed_during_copy');
                }
                $backuporigin = 'existing';
                $existingbackupname = basename($candidate);
                $existingbackupdate = (int)$inspection['backup_date'];
                $existingbackupmode = (string)($inspection['backup_mode'] ?? '');
                $existingbackuptype = (string)($inspection['backup_type'] ?? '');
                $existingbackupsettingssha =
                    (string)($inspection['settings_sha256'] ?? '');
                $existingbackupprofilewarnings = $candidateprofilewarnings;
                foreach ($candidateprofilewarnings as $warning) {
                    collector_log('EXISTING_BACKUP_PROFILE_WARNING', [
                        'worker' => $worker,
                        'course_id' => $courseid,
                        'file' => $existingbackupname,
                        'setting' => $warning['setting'],
                        'expected' => $warning['expected'],
                        'actual' => $warning['actual'],
                        'impact' => $warning['impact'],
                    ]);
                }
                collector_log('EXISTING_BACKUP_ADOPTED', [
                    'worker' => $worker,
                    'course_id' => $courseid,
                    'candidate' => ($candidateindex + 1) . '/' . count($reusecandidates),
                    'file' => $existingbackupname,
                    'backup_date' => $existingbackupdate,
                    'backup_mode' => $existingbackupmode,
                    'archive_format' =>
                        (string)($inspection['archive_format'] ?? ''),
                    'profile_warnings' => count($candidateprofilewarnings),
                    'bytes' => $backupmeta['bytes'],
                ]);
                break;
            } catch (Throwable $reuseerror) {
                if (is_file($backuppath)) {
                    @unlink($backuppath);
                }
                $backupmeta = null;
                $reason = 'copy_failed_' . $reuseerror->getMessage();
                $rejectionreasons[] = $reason;
                collector_log('EXISTING_BACKUP_REJECTED', [
                    'worker' => $worker,
                    'course_id' => $courseid,
                    'candidate' => ($candidateindex + 1) . '/' . count($reusecandidates),
                    'file' => basename($candidate),
                    'reason' => $reason,
                ]);
            }
        }
        if ($backupmeta === null) {
            if ($reuseonly === 1) {
                $reasons = $rejectionreasons === []
                    ? 'no_candidate_for_course'
                    : implode(',', array_values(array_unique($rejectionreasons)));
                throw new RuntimeException(
                    'reuse_required_no_compatible_backup course_id=' . $courseid .
                    ' candidates=' . count($reusecandidates) .
                    ' reasons=' . $reasons
                );
            }
            if ($reusecandidates !== []) {
                $setPhase('existing-backup-fallback', [
                    'candidates_checked' => count($reusecandidates),
                ]);
                collector_log('EXISTING_BACKUP_FALLBACK_GENERATE', [
                    'worker' => $worker,
                    'course_id' => $courseid,
                    'candidates_checked' => count($reusecandidates),
                    'reasons' => implode(
                        ',',
                        array_values(array_unique($rejectionreasons))
                    ),
                ]);
            }
            $backupmeta = p5_create_course_backup(
                $courseid,
                $backuppath,
                static function(string $stage, array $details = []) use ($setPhase): void {
                    $setPhase($stage, $details);
                }
            );
        }
        $setPhase('checkpoint-write');
        $checkpoint = [
            'schema_version' => '1.0',
            'package_type' => 'moodle-consolidation-source-course',
            'collector_version' => '7.4.1-linux',
            'generated_at_utc' => gmdate('c'),
            'source_id' => $sourceid,
            'course_key' => $coursekey,
            'source_course_id' => $courseid,
            'source_course_idnumber' => (string)$course->idnumber,
            'source_shortname' => (string)$course->shortname,
            'source_state_sha256' => $statehash,
            'backup_file' => 'cursos/' . basename($backuppath),
            'backup_sha256' => $backupmeta['sha256'],
            'backup_bytes' => $backupmeta['bytes'],
            'backup_mtime' => $backupmeta['mtime'],
            'inventory_file' => 'inventarios/' . basename($inventorypath),
            'inventory_sha256' => $inventorysha,
            'inventory_bytes' => $inventorymeta['bytes'],
            'inventory_mtime' => $inventorymeta['mtime'],
            'hash_strategy' => 'single-pass-v1',
            'run_fingerprint' => $runfingerprint,
            'backup_profile_sha256' => $backupprofilesha,
            'backup_origin' => $backuporigin,
            'reuse_policy' => $reuseonly === 1 ? 'required' : 'prefer',
            'existing_backup_name' => $existingbackupname,
            'existing_backup_date' => $existingbackupdate,
            'existing_backup_mode' => $existingbackupmode,
            'existing_backup_type' => $existingbackuptype,
            'existing_backup_settings_sha256' => $existingbackupsettingssha,
            'existing_backup_profile_warnings' =>
                $existingbackupprofilewarnings,
            'status' => 'prepared',
            'destination_write_performed' => false,
        ];
        p5_write_json($checkpointpath, $checkpoint);
    } catch (Throwable $error) {
        foreach ([$backuppath, $inventorypath] as $partial) {
            if (is_file($partial)) {
                @unlink($partial);
            }
        }
        throw $error;
    }

    $resultstatus = $backuporigin === 'existing' ? 'reused' : 'created';
    $reusekind = $backuporigin === 'existing' ? 'existing-backup' : '';
    $setPhase('completed', [
        'status' => $resultstatus,
        'reuse_kind' => $reusekind,
        'bytes' => $backupmeta['bytes'],
    ]);
    $writeResult($resultstatus, [
        'bytes' => $backupmeta['bytes'],
        'reuse_kind' => $reusekind,
    ]);
    cli_writeln(
        'SOURCE_COURSE_BACKUP_OK source=' . $sourceid .
        ' course_key=' . $coursekey . ' course_id=' . $courseid .
        ' status=' . $resultstatus .
        ($reusekind !== '' ? ' reuse_kind=' . $reusekind : '') .
        ' bytes=' . $backupmeta['bytes']
    );
} catch (Throwable $error) {
    try {
        $failedStage = $currentStage;
        $setPhase('error', ['failed_stage' => $failedStage, 'error' => $error->getMessage()]);
        $writeResult('error', ['error' => $error->getMessage(), 'stage' => $failedStage]);
    } catch (Throwable $statusError) {
        fwrite(STDERR, 'COURSE_STATUS_WARNING ' . $statusError->getMessage() . PHP_EOL);
    }
    cli_error('SOURCE_COURSE_BACKUP_ERROR ' . $error->getMessage());
}
