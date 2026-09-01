<?php
// Fase 6: sella referencias inmutables a los MBZ importados.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once('/opt/consolidator/phase5-lib.php');
require_once('/opt/consolidator/phase6-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'phase4' => '/exports/phase4',
        'phase6' => '/exports/phase6',
        'configsha' => null,
        'targetid' => null,
        'expectlab' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php phase6-seal-backups.php --phase4=/exports/phase4 " .
        "--phase6=/exports/phase6 --configsha=SHA256 --targetid=target\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $phase4dir = rtrim((string)$options['phase4'], '/\\');
    $phase6dir = rtrim((string)$options['phase6'], '/\\');
    $configsha = p5_require_sha256((string)$options['configsha'], 'configsha');
    $targetid = p5_norm((string)$options['targetid']);
    $expectlab = (bool)(int)$options['expectlab'];
    if (!preg_match('/^[a-z][a-z0-9_-]*$/', $targetid)) {
        throw new RuntimeException('targetid inválido.');
    }
    $bundle = p6_load_inventory_plan(
        $phase4dir,
        $phase6dir,
        $configsha,
        $targetid,
        $expectlab
    );

    $entries = [];
    $expectedcheckpointnames = [];
    $totalbytes = 0;
    $totalplannedusers = 0;
    $totalmerges = 0;
    $zeroparticipantcourses = 0;
    foreach ($bundle['restore_courses'] as $courseplan) {
        $coursekey = (string)$courseplan['course_key'];
        $basename = p6_backup_basename($coursekey);
        $inventorypath = $phase6dir . '/course-inventories/inventory-' . $basename . '.json';
        $jobpath = $phase6dir . '/course-jobs/job-' . $basename . '.json';
        $checkpointpath = $phase6dir . '/backup-checkpoints/checkpoint-' . $basename . '.json';
        $expectedcheckpointnames[] = basename($checkpointpath);
        $checkpoint = p5_read_json($checkpointpath);
        $smallfiles = [
            'source_inventory' => $inventorypath,
            'course_job' => $jobpath,
        ];
        $actualhashes = p5_hash_files($smallfiles);
        $checkpointfiles = $checkpoint['files_sha256'] ?? [];
        if (!is_array($checkpointfiles)) {
            throw new RuntimeException('El checkpoint de ' . $coursekey . ' no contiene hashes.');
        }
        ksort($checkpointfiles, SORT_STRING);
        $backuprelative = ltrim((string)($checkpoint['source_backup_file'] ?? ''), '/\\');
        $backuppath = dirname($phase6dir) . '/' . $backuprelative;
        $backupbytes = is_file($backuppath) ? filesize($backuppath) : false;
        if (($checkpoint['schema_version'] ?? '') !== '1.0' ||
                ($checkpoint['phase'] ?? '') !== '6-course-reference-checkpoint' ||
                ($checkpoint['config_sha256'] ?? '') !== $configsha ||
                ($checkpoint['plan_summary_sha256'] ?? '') !== $bundle['summary_sha256'] ||
                ($checkpoint['batch_id'] ?? '') !== (string)$bundle['summary']['batch_id'] ||
                ($checkpoint['course_key'] ?? '') !== $coursekey ||
                ($checkpoint['source'] ?? '') !== (string)$courseplan['source'] ||
                (int)($checkpoint['source_course_id'] ?? 0) !==
                    (int)$courseplan['source_course_id'] ||
                ($checkpoint['target_shortname'] ?? '') !==
                    (string)$courseplan['target_shortname'] ||
                ($checkpoint['target_fullname'] ?? '') !==
                    (string)$courseplan['target_fullname'] ||
                ($checkpoint['target_category_key'] ?? '') !==
                    (string)$courseplan['target_category_key'] ||
                ($checkpoint['target_course_marker'] ?? '') !==
                    (string)$courseplan['target_course_marker'] ||
                ($checkpoint['checkpoint_status'] ?? '') !== 'referenced' ||
                ($checkpoint['source_archive_hashed_again'] ?? null) !== false ||
                ($checkpoint['source_archive_copied'] ?? null) !== false ||
                ($checkpoint['destination_write_performed'] ?? null) !== false ||
                $checkpointfiles !== $actualhashes ||
                !is_readable($backuppath) ||
                $backupbytes === false ||
                (int)$backupbytes !== (int)($checkpoint['source_backup_bytes'] ?? 0)) {
            throw new RuntimeException('El checkpoint de ' . $coursekey . ' no coincide con el plan.');
        }
        $backupsha = p5_require_sha256(
            (string)($checkpoint['source_backup_sha256'] ?? ''),
            'source_backup_sha256'
        );
        $job = p5_read_json($jobpath);
        if (($job['phase'] ?? '') !== '6-course-restore-job' ||
                ($job['course_key'] ?? '') !== $coursekey ||
                ($job['source_backup_sha256'] ?? '') !== $backupsha ||
                (int)($job['source_backup_bytes'] ?? 0) !== (int)$backupbytes) {
            throw new RuntimeException('El trabajo liviano de ' . $coursekey . ' perdió integridad.');
        }
        $plannedusers = (int)($checkpoint['planned_source_users'] ?? 0);
        $merges = (int)($checkpoint['approved_identity_merges'] ?? 0);
        $entry = [
            'course_key' => $coursekey,
            'source' => (string)$courseplan['source'],
            'source_course_id' => (int)$courseplan['source_course_id'],
            'source_course_idnumber' => (string)$courseplan['source_course_idnumber'],
            'source_shortname' => (string)$courseplan['source_shortname'],
            'target_shortname' => (string)$courseplan['target_shortname'],
            'target_fullname' => (string)$courseplan['target_fullname'],
            'target_category_key' => (string)$courseplan['target_category_key'],
            'target_course_marker' => (string)$courseplan['target_course_marker'],
            'source_state_sha256' => (string)$checkpoint['source_state_sha256'],
            'source_backup_file' => $backuprelative,
            'source_backup_sha256' => $backupsha,
            'source_backup_bytes' => (int)$backupbytes,
            // Alias de lectura para reportes antiguos; no existe una copia raw.
            'raw_backup_file' => $backuprelative,
            'raw_backup_sha256' => $backupsha,
            'raw_backup_bytes' => (int)$backupbytes,
            'source_inventory_file' => (string)$checkpoint['source_inventory_file'],
            'source_inventory_sha256' => $actualhashes['source_inventory'],
            'course_job_file' => (string)$checkpoint['course_job_file'],
            'course_job_sha256' => $actualhashes['course_job'],
            'checkpoint_file' => 'backup-checkpoints/' . basename($checkpointpath),
            'checkpoint_sha256' => hash_file('sha256', $checkpointpath),
            'planned_source_users' => $plannedusers,
            'zero_participant_course' => $plannedusers === 0 ? 1 : 0,
            'approved_identity_merges' => $merges,
            'estimated_weight' => (int)$checkpoint['estimated_weight'],
            'preparation_status' => 'referenced',
            'source_archive_hashed_again' => false,
            'source_archive_copied' => false,
        ];
        $entries[] = $entry;
        $totalbytes += (int)$backupbytes;
        $totalplannedusers += $plannedusers;
        $totalmerges += $merges;
        $zeroparticipantcourses += $plannedusers === 0 ? 1 : 0;
    }

    sort($expectedcheckpointnames, SORT_STRING);
    $actualcheckpointnames = array_values(array_map(
        'basename',
        glob($phase6dir . '/backup-checkpoints/checkpoint-*.json') ?: []
    ));
    sort($actualcheckpointnames, SORT_STRING);
    if ($actualcheckpointnames !== $expectedcheckpointnames) {
        throw new RuntimeException('Los checkpoints contienen faltantes o cursos ajenos.');
    }
    if (count($entries) !== (int)$bundle['summary']['courses_to_restore'] ||
            $totalmerges !== (int)$bundle['summary']['approved_identity_convergences']) {
        throw new RuntimeException('Los totales referenciados no coinciden con el plan.');
    }
    usort($entries, static fn(array $left, array $right): int =>
        [$left['source'], $left['source_course_id']] <=>
        [$right['source'], $right['source_course_id']]
    );
    $progresspath = $phase6dir . '/backup_progress.csv';
    p5_write_csv($progresspath, [
        'course_key', 'source', 'source_course_id', 'source_shortname',
        'target_shortname', 'source_backup_file', 'source_backup_sha256',
        'source_backup_bytes', 'source_inventory_file', 'source_inventory_sha256',
        'course_job_file', 'course_job_sha256', 'checkpoint_file',
        'checkpoint_sha256', 'planned_source_users', 'zero_participant_course',
        'approved_identity_merges', 'estimated_weight', 'preparation_status',
        'source_archive_hashed_again', 'source_archive_copied',
    ], $entries);

    $manifest = [
        'schema_version' => '1.0',
        'phase' => '6-multi-course-reference-manifest',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'batch_id' => (string)$bundle['summary']['batch_id'],
        'plan_summary_sha256' => $bundle['summary_sha256'],
        'plan_artifacts_sha256' => $bundle['hashes'],
        'courses_expected' => count($entries),
        'courses_prepared' => count($entries),
        'courses_pending' => 0,
        'source_backups_referenced' => count($entries),
        'source_backup_bytes' => $totalbytes,
        'raw_backup_bytes' => $totalbytes,
        'raw_backups_created' => 0,
        'normalized_backups_created' => 0,
        'duplicate_backup_bytes' => 0,
        'planned_source_user_rows' => $totalplannedusers,
        'zero_participant_courses' => $zeroparticipantcourses,
        'approved_identity_convergences' => $totalmerges,
        'backup_progress_sha256' => hash_file('sha256', $progresspath),
        'entries_sha256' => p6_value_sha256($entries),
        'entries' => $entries,
        'manifest_status' => 'prepared',
        'single_extraction_pipeline' => true,
        'source_archives_hashed_again' => false,
        'normalization_performed' => false,
        'destination_write_performed' => false,
        'categories_created' => false,
        'courses_restored' => false,
    ];
    if ($expectlab) {
        $manifest['lab_validation'] = 'passed';
    }
    p5_write_json($phase6dir . '/batch_manifest.json', $manifest);
    cli_writeln(
        'FASE6_REFERENCE_MANIFEST_OK courses=' . count($entries) .
        ' bytes=' . $totalbytes . ' copied=0 extracted=0 hashed_again=0 write=0'
    );
} catch (Throwable $error) {
    cli_error('FASE6_REFERENCE_MANIFEST_ERROR ' . $error->getMessage());
}
