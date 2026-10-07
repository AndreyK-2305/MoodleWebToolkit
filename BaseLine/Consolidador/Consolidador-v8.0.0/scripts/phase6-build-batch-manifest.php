<?php
// Fase 12: manifiesto ligero. No abre el contenido de ningún MBZ.

declare(strict_types=1);

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once('/opt/consolidator/phase5-lib.php');
require_once('/opt/consolidator/phase6-lib.php');

[$options, $unrecognized] = cli_get_params([
    'phase4' => '/exports/phase4',
    'phase6' => '/exports/phase6',
    'configsha' => null,
    'targetid' => null,
    'source-integrity' => '/exports/phase6/source-integrity-policies.csv',
    'resolutions' => '/exports/phase6/degradation-resolutions.csv',
    'expectlab' => 0,
    'help' => false,
], ['h' => 'help']);
if ($options['help']) {
    cli_writeln('Uso: php phase6-build-batch-manifest.php --phase6=/exports/phase6');
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
    $integritypath = (string)$options['source-integrity'];
    $resolutionpath = (string)$options['resolutions'];
    if (!is_readable($integritypath) || !is_readable($resolutionpath)) {
        throw new RuntimeException('Faltan políticas o resoluciones de fuente.');
    }
    $policies = p6_degradation_source_integrity_map(p5_read_csv($integritypath));
    p6_degradation_resolution_map(p5_read_csv($resolutionpath));
    $bundle = p6_load_inventory_plan(
        $phase4dir,
        $phase6dir,
        $configsha,
        $targetid,
        $expectlab
    );

    $entries = [];
    $totalbytes = 0;
    $sources = [];
    foreach ($bundle['restore_courses'] as $courseplan) {
        $coursekey = (string)$courseplan['course_key'];
        $sourceid = p5_norm((string)$courseplan['source']);
        $sources[$sourceid] = p6_degradation_source_integrity_policy(
            $policies,
            $sourceid
        );
        $packagedir = dirname($phase6dir) . '/packages/' . $sourceid;
        $manifestpath = $packagedir . '/manifest.json';
        $packagemanifest = p5_read_json($manifestpath);
        if (($packagemanifest['schema_version'] ?? '') !== '1.0' ||
                ($packagemanifest['package_type'] ?? '') !==
                    'moodle-consolidation-source' ||
                ($packagemanifest['package_status'] ?? '') !== 'sealed' ||
                ($packagemanifest['source_id'] ?? '') !== $sourceid) {
            throw new RuntimeException('Paquete fuente inválido: ' . $sourceid);
        }
        $packageentry = null;
        foreach ($packagemanifest['entries'] ?? [] as $candidate) {
            if (($candidate['course_key'] ?? '') === $coursekey) {
                $packageentry = $candidate;
                break;
            }
        }
        if (!is_array($packageentry) ||
                (int)($packageentry['source_course_id'] ?? 0) !==
                    (int)$courseplan['source_course_id']) {
            throw new RuntimeException('El paquete no contiene ' . $coursekey . '.');
        }
        $backuprelative = ltrim((string)($packageentry['backup_file'] ?? ''), '/\\');
        $inventoryrelative = ltrim(
            (string)($packageentry['inventory_file'] ?? ''),
            '/\\'
        );
        if ($backuprelative === '' || $inventoryrelative === '' ||
                str_contains($backuprelative, '..') ||
                str_contains($inventoryrelative, '..')) {
            throw new RuntimeException('Ruta insegura en ' . $coursekey . '.');
        }
        $backuppath = $packagedir . '/' . $backuprelative;
        $inventorypath = $packagedir . '/' . $inventoryrelative;
        $bytes = is_file($backuppath) ? filesize($backuppath) : false;
        if (!is_readable($backuppath) || !is_readable($inventorypath) ||
                $bytes === false || $bytes < 1) {
            throw new RuntimeException('Referencia ausente en ' . $coursekey . '.');
        }
        $backupsha = p5_require_sha256(
            (string)($packageentry['backup_sha256'] ?? ''),
            'backup_sha256'
        );
        $inventorysha = p5_require_sha256(
            (string)($packageentry['inventory_sha256'] ?? ''),
            'inventory_sha256'
        );
        if (hash_file('sha256', $inventorypath) !== $inventorysha) {
            throw new RuntimeException('El inventario ligero cambió: ' . $coursekey . '.');
        }
        $entries[] = [
            'course_key' => $coursekey,
            'source' => $sourceid,
            'source_course_id' => (int)$courseplan['source_course_id'],
            'source_course_idnumber' => (string)$courseplan['source_course_idnumber'],
            'source_shortname' => (string)$courseplan['source_shortname'],
            'target_shortname' => (string)$courseplan['target_shortname'],
            'target_fullname' => (string)$courseplan['target_fullname'],
            'target_category_key' => (string)$courseplan['target_category_key'],
            'target_course_marker' => (string)$courseplan['target_course_marker'],
            'source_package_manifest_sha256' => hash_file('sha256', $manifestpath),
            'source_backup_file' =>
                'packages/' . $sourceid . '/' . $backuprelative,
            'source_backup_sha256' => $backupsha,
            'source_backup_bytes' => (int)$bytes,
            'package_inventory_file' =>
                'packages/' . $sourceid . '/' . $inventoryrelative,
            'package_inventory_sha256' => $inventorysha,
            'estimated_weight' => (int)$bytes,
            'preparation_status' => 'referenced_pending_worker_precheck',
            'worker_precheck_deferred' => true,
            'source_archive_hashed_again' => false,
            'source_archive_copied' => false,
        ];
        $totalbytes += (int)$bytes;
    }
    usort($entries, static fn(array $a, array $b): int =>
        [$a['source'], $a['source_course_id']] <=>
        [$b['source'], $b['source_course_id']]
    );
    ksort($sources, SORT_STRING);
    $progresspath = $phase6dir . '/backup_progress.csv';
    p5_write_csv($progresspath, [
        'course_key', 'source', 'source_course_id', 'source_shortname',
        'target_shortname', 'source_backup_file', 'source_backup_sha256',
        'source_backup_bytes', 'package_inventory_file',
        'package_inventory_sha256', 'estimated_weight', 'preparation_status',
        'worker_precheck_deferred', 'source_archive_hashed_again',
        'source_archive_copied',
    ], $entries);
    $manifest = [
        'schema_version' => '1.1',
        'phase' => '6-lightweight-batch-manifest',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'batch_id' => (string)$bundle['summary']['batch_id'],
        'plan_summary_sha256' => $bundle['summary_sha256'],
        'plan_artifacts_sha256' => $bundle['hashes'],
        'source_integrity_policies_sha256' => hash_file('sha256', $integritypath),
        'degradation_resolutions_sha256' => hash_file('sha256', $resolutionpath),
        'source_integrity_policies' => array_values($sources),
        'courses_expected' => count($entries),
        'courses_prepared' => 0,
        'courses_pending' => count($entries),
        'source_backups_referenced' => count($entries),
        'source_backup_bytes' => $totalbytes,
        'raw_backups_created' => 0,
        'normalized_backups_created' => 0,
        'duplicate_backup_bytes' => 0,
        'backup_progress_sha256' => hash_file('sha256', $progresspath),
        'entries_sha256' => p6_value_sha256($entries),
        'entries' => $entries,
        'manifest_status' => 'BATCH_READY',
        'single_extraction_pipeline' => true,
        'worker_course_precheck' => true,
        'mbz_deep_open_count' => 0,
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
        'BATCH_READY courses=' . count($entries) .
        ' bytes=' . $totalbytes .
        ' MBZ_DEEP_OPEN_COUNT=0 copied=0 extracted=0 hashed_again=0 write=0'
    );
} catch (Throwable $error) {
    cli_error('PHASE12_MANIFEST_ERROR ' . $error->getMessage());
}
