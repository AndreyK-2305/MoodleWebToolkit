<?php
// Fase 6: referencia un MBZ sellado sin copiarlo ni extraerlo.

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
        'package' => null,
        'configsha' => null,
        'targetid' => null,
        'sourceid' => null,
        'coursekey' => null,
        'expectlab' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php phase6-prepare-package-course.php --phase4=DIR " .
        "--phase6=DIR --package=/exports/packages/virtual " .
        "--configsha=SHA256 --targetid=target --sourceid=virtual " .
        "--coursekey=COURSE-VIRTUAL-...\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $phase4dir = rtrim((string)$options['phase4'], '/\\');
    $phase6dir = rtrim((string)$options['phase6'], '/\\');
    $packagedir = rtrim((string)$options['package'], '/\\');
    $configsha = p5_require_sha256((string)$options['configsha'], 'configsha');
    $targetid = p5_norm((string)$options['targetid']);
    $sourceid = p5_norm((string)$options['sourceid']);
    $coursekey = strtoupper(trim((string)$options['coursekey']));
    $expectlab = (bool)(int)$options['expectlab'];
    if (!is_dir($packagedir) ||
            !preg_match('/^[a-z][a-z0-9_-]*$/', $targetid) ||
            !preg_match('/^[a-z][a-z0-9_-]*$/', $sourceid) ||
            !preg_match('/^COURSE-[A-Z0-9_-]+-[A-F0-9]{12}$/', $coursekey)) {
        throw new RuntimeException('Los parámetros del curso empaquetado son inválidos.');
    }

    $bundle = p6_load_inventory_plan(
        $phase4dir,
        $phase6dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $courseplan = $bundle['courses_by_key'][$coursekey] ?? null;
    if (!$courseplan ||
            ($courseplan['action'] ?? '') !== 'restore_new' ||
            p5_norm((string)$courseplan['source']) !== $sourceid) {
        throw new RuntimeException('El curso no pertenece al lote restaurable.');
    }

    $manifestpath = $packagedir . '/manifest.json';
    $manifest = p5_read_json($manifestpath);
    if (($manifest['schema_version'] ?? '') !== '1.0' ||
            ($manifest['package_type'] ?? '') !== 'moodle-consolidation-source' ||
            ($manifest['source_id'] ?? '') !== $sourceid ||
            ($manifest['package_status'] ?? '') !== 'sealed') {
        throw new RuntimeException('El manifiesto del paquete de origen no es válido.');
    }
    $packageentry = null;
    foreach ($manifest['entries'] ?? [] as $candidate) {
        if (($candidate['course_key'] ?? '') === $coursekey) {
            $packageentry = $candidate;
            break;
        }
    }
    if (!is_array($packageentry) ||
            (int)($packageentry['source_course_id'] ?? 0) !==
                (int)$courseplan['source_course_id']) {
        throw new RuntimeException('El paquete no contiene el curso aprobado.');
    }
    $backuprelative = ltrim((string)($packageentry['backup_file'] ?? ''), '/\\');
    $inventoryrelative = ltrim((string)($packageentry['inventory_file'] ?? ''), '/\\');
    if ($backuprelative === '' || $inventoryrelative === '' ||
            str_contains($backuprelative, '..') ||
            str_contains($inventoryrelative, '..')) {
        throw new RuntimeException('El manifiesto contiene una ruta insegura.');
    }
    $packagebackuppath = $packagedir . '/' . $backuprelative;
    $packageinventorypath = $packagedir . '/' . $inventoryrelative;
    $backupbytes = is_file($packagebackuppath) ? filesize($packagebackuppath) : false;
    if (!is_readable($packagebackuppath) ||
            !is_readable($packageinventorypath) ||
            $backupbytes === false || $backupbytes < 1) {
        throw new RuntimeException('El backup o inventario sellado no está disponible.');
    }
    $packagebackupsha = p5_require_sha256(
        (string)($packageentry['backup_sha256'] ?? ''),
        'backup_sha256 del paquete'
    );
    $packagemanifestsha = hash_file('sha256', $manifestpath);

    $packagedocument = p5_read_json($packageinventorypath);
    $inventory = $packagedocument['inventory'] ?? null;
    if (($packagedocument['schema_version'] ?? '') !== '1.0' ||
            ($packagedocument['package_type'] ?? '') !==
                'moodle-consolidation-course-inventory' ||
            ($packagedocument['source_id'] ?? '') !== $sourceid ||
            ($packagedocument['course_key'] ?? '') !== $coursekey ||
            ($packagedocument['write_performed'] ?? null) !== false ||
            !is_array($inventory)) {
        throw new RuntimeException('El inventario detallado perdió su contrato.');
    }
    $sourcestatehash = p5_require_sha256(
        (string)($packagedocument['source_state_sha256'] ?? ''),
        'source_state_sha256'
    );
    $expectedcourse = null;
    foreach ($bundle['source_inventories'][$sourceid]['courses'] ?? [] as $candidate) {
        if ((int)($candidate['source_course_id'] ?? 0) ===
                (int)$courseplan['source_course_id']) {
            $expectedcourse = $candidate;
            break;
        }
    }
    $detailcourse = $inventory['course'] ?? [];
    $expectedmodules = $expectedcourse['modules_by_type'] ?? [];
    $actualmodules = $inventory['modules_by_type'] ?? [];
    ksort($expectedmodules, SORT_STRING);
    ksort($actualmodules, SORT_STRING);
    if (!is_array($expectedcourse) ||
            (int)($detailcourse['source_course_id'] ?? 0) !==
                (int)$expectedcourse['source_course_id'] ||
            (int)($detailcourse['category_id'] ?? 0) !==
                (int)$expectedcourse['source_category_id'] ||
            (string)($detailcourse['fullname'] ?? '') !==
                (string)$expectedcourse['fullname'] ||
            (string)($detailcourse['shortname'] ?? '') !==
                (string)$expectedcourse['shortname'] ||
            $expectedmodules !== $actualmodules ||
            count($expectedcourse['enrolments'] ?? []) !==
                count($inventory['enrolments'] ?? []) ||
            count($expectedcourse['roles'] ?? []) !==
                count($inventory['roles'] ?? [])) {
        throw new RuntimeException('El detalle académico no coincide con el inventario global.');
    }

    $basename = p6_backup_basename($coursekey);
    $inventorypath = $phase6dir . '/course-inventories/inventory-' . $basename . '.json';
    $jobpath = $phase6dir . '/course-jobs/job-' . $basename . '.json';
    $checkpointpath = $phase6dir . '/backup-checkpoints/checkpoint-' . $basename . '.json';
    foreach ([dirname($inventorypath), dirname($jobpath), dirname($checkpointpath)] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible crear ' . $directory . '.');
        }
    }
    $sourcebackupfile = 'packages/' . $sourceid . '/' . $backuprelative;
    if (is_readable($checkpointpath)) {
        $existing = p5_read_json($checkpointpath);
        $oldjob = p5_read_json($jobpath);
        $existingfiles = [
            'source_inventory' => $inventorypath,
            'course_job' => $jobpath,
        ];
        $existinghashes = $existing['files_sha256'] ?? [];
        ksort($existinghashes, SORT_STRING);
        $actualhashes = p5_hash_files($existingfiles);
        $basecheckpointvalid =
            ($existing['phase'] ?? '') === '6-course-reference-checkpoint' &&
            ($existing['config_sha256'] ?? '') === $configsha &&
            ($existing['plan_summary_sha256'] ?? '') === $bundle['summary_sha256'] &&
            ($existing['source_backup_sha256'] ?? '') === $packagebackupsha &&
            (int)($existing['source_backup_bytes'] ?? 0) === (int)$backupbytes &&
            ($existing['checkpoint_status'] ?? '') === 'referenced' &&
            is_array($oldjob['backup_user_classification'] ?? null) &&
            is_array($oldjob['phase4_input_sha256'] ?? null) &&
            $existinghashes === $actualhashes;
        if (!$basecheckpointvalid) {
            throw new RuntimeException(
                'El checkpoint existente pertenece a otro plan o perdió integridad.'
            );
        }
        $oldclassification = $oldjob['backup_user_classification'];
        $currentclassificationcontract =
            (int)($existing['backup_user_contract_version'] ?? 0) === 3 &&
            (int)($oldjob['backup_user_contract_version'] ?? 0) === 3 &&
            ($oldclassification['schema_version'] ?? '') === '3.0' &&
            ($oldclassification['classification_contract'] ?? '') ===
                p6_backup_user_classification_contract();
        if ($currentclassificationcontract) {
            p6_assert_referenced_checkpoint_classification(
                $existing,
                $oldjob,
                'El checkpoint existente de ' . $coursekey
            );
            cli_writeln(
                'FASE6_PACKAGE_REFERENCE_OK course_key=' . $coursekey .
                ' source=' . $sourceid . ' status=reused bytes=' . (int)$backupbytes
            );
            exit(0);
        }
        cli_writeln(
            'FASE6_PACKAGE_REFERENCE_REGENERATE course_key=' . $coursekey .
            ' source=' . $sourceid . ' reason=obsolete_user_classification_contract'
        );
        // Es un artefacto derivado y reproducible. Se retira antes de volver a
        // clasificar para que un fallo real nunca deje visible la combinación
        // referenced + blocked heredada de contratos anteriores.
        if (!unlink($checkpointpath)) {
            throw new RuntimeException(
                'No fue posible retirar el checkpoint obsoleto de ' . $coursekey . '.'
            );
        }
    }
    $backupdeclaredreferences =
        p6_backup_declared_user_references($packagebackuppath);
    $userreferences = p6_course_user_reference_sets(
        $bundle,
        $coursekey,
        $inventory,
        $backupdeclaredreferences['source_user_ids']
    );
    $plannedsourceids = $userreferences['planned_participant_source_user_ids'];
    $requiredbackupuserids = $userreferences['required_backup_user_ids'];
    $referencedsourceids = $userreferences['referenced_source_user_ids'];
    $usermappings = [];
    foreach ($referencedsourceids as $sourceuserid) {
        $mapping = $bundle['phase4']['source_by_key'][$sourceid . ':' . $sourceuserid] ?? null;
        if (!$mapping) {
            // No se fabrica un mapa para autoría técnica o seguimiento. Si el
            // usuario está realmente en users.xml, la clasificación conserva
            // el control active_unmapped/historical_deleted existente.
            continue;
        }
        $usermappings[] = [
            'source_user_id' => $sourceuserid,
            'mapping' => $mapping,
        ];
    }
    $backupusers = p6_classify_backup_users(
        p6_backup_users_xml($packagebackuppath),
        $sourceid,
        $requiredbackupuserids,
        $bundle['phase4']['source_by_key'],
        $plannedsourceids,
        $userreferences['backup_declared_user_reference_ids']
    );
    if (($backupusers['blocked'] ?? true) !== false) {
        throw new RuntimeException(
            'La clasificación de users.xml bloquea la referencia: ' .
            'unresolved=' . json_encode(array_values(array_filter(
                $backupusers['users'] ?? [],
                static fn(array $row): bool => in_array(
                    $row['classification'] ?? '',
                    ['academic_reference_unmapped', 'active_unmapped'],
                    true
                )
            )), JSON_UNESCAPED_UNICODE) .
            ' historical_map_conflicts=' .
            implode('|', $backupusers['historical_map_conflicts'] ?? []) .
            ' missing=' .
            implode('|', $backupusers['missing_academic_user_ids'] ?? [])
        );
    }
    $weightcounts = $inventory['counts'] ?? [];
    $estimatedweight = (int)$backupbytes +
        1048576 * (
            1000 * (int)($weightcounts['activities'] ?? 0) +
            100 * (int)($weightcounts['enrolments'] ?? 0) +
            25 * (
                (int)($weightcounts['assignment_submissions'] ?? 0) +
                (int)($weightcounts['forum_posts'] ?? 0) +
                (int)($weightcounts['quiz_attempts'] ?? 0)
            )
        );
    $inventorydocument = [
        'schema_version' => '1.0',
        'phase' => '6-source-course-reference-inventory',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'plan_summary_sha256' => $bundle['summary_sha256'],
        'batch_id' => (string)$bundle['summary']['batch_id'],
        'course_key' => $coursekey,
        'source_id' => $sourceid,
        'source_state_sha256' => $sourcestatehash,
        'source_package_manifest_sha256' => $packagemanifestsha,
        'source_package_backup_sha256' => $packagebackupsha,
        'inventory' => $inventory,
        'write_performed' => false,
    ];
    $job = [
        'schema_version' => '1.0',
        'phase' => '6-course-restore-job',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'plan_summary_sha256' => $bundle['summary_sha256'],
        'batch_id' => (string)$bundle['summary']['batch_id'],
        'course_key' => $coursekey,
        'course_plan' => $courseplan,
        'batch_config' => $bundle['batch_config'],
        'source_backup_file' => $sourcebackupfile,
        'source_backup_sha256' => $packagebackupsha,
        'source_backup_bytes' => (int)$backupbytes,
        'source_state_sha256' => $sourcestatehash,
        'source_inventory_file' => 'course-inventories/' . basename($inventorypath),
        'backup_user_contract_version' => 3,
        'planned_source_user_ids' => $plannedsourceids,
        'backup_declared_user_references' => $backupdeclaredreferences,
        'backup_declared_user_reference_ids' =>
            $userreferences['backup_declared_user_reference_ids'],
        'strict_relation_source_user_ids' =>
            $userreferences['strict_relation_source_user_ids'],
        'reference_only_source_user_ids' =>
            $userreferences['reference_only_source_user_ids'],
        'required_backup_user_ids' => $requiredbackupuserids,
        'referenced_source_user_ids' => $referencedsourceids,
        'phase4_input_sha256' => $bundle['phase4']['hashes'],
        'backup_user_classification' => $backupusers,
        'user_mappings' => $usermappings,
        'course_user_plan_rows' =>
            array_values($bundle['user_rows_by_course'][$coursekey] ?? []),
        'course_role_plan_rows' =>
            array_values($bundle['role_rows_by_course'][$coursekey] ?? []),
        'effective_enrolments' => p6_effective_course_enrolments($bundle, $coursekey),
        'effective_roles' => p6_effective_course_roles($bundle, $coursekey),
        'identity_convergences' => array_values(
            $bundle['convergence_by_course_target'][$coursekey] ?? []
        ),
        'identity_convergences_by_target' =>
            $bundle['convergence_by_course_target'][$coursekey] ?? [],
        'estimated_weight' => $estimatedweight,
        'source_archive_hashed_again' => false,
        'source_archive_copied' => false,
    ];
    p5_write_json($inventorypath, $inventorydocument);
    p5_write_json($jobpath, $job);
    $smallfiles = [
        'source_inventory' => $inventorypath,
        'course_job' => $jobpath,
    ];
    $checkpoint = [
        'schema_version' => '1.0',
        'phase' => '6-course-reference-checkpoint',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'plan_summary_sha256' => $bundle['summary_sha256'],
        'batch_id' => (string)$bundle['summary']['batch_id'],
        'course_key' => $coursekey,
        'source' => $sourceid,
        'source_course_id' => (int)$courseplan['source_course_id'],
        'source_shortname' => (string)$courseplan['source_shortname'],
        'target_shortname' => (string)$courseplan['target_shortname'],
        'target_fullname' => (string)$courseplan['target_fullname'],
        'target_category_key' => (string)$courseplan['target_category_key'],
        'target_course_marker' => (string)$courseplan['target_course_marker'],
        'source_state_sha256' => $sourcestatehash,
        'source_package_manifest_sha256' => $packagemanifestsha,
        'source_backup_file' => $sourcebackupfile,
        'source_backup_sha256' => $packagebackupsha,
        'source_backup_bytes' => (int)$backupbytes,
        'source_inventory_file' => 'course-inventories/' . basename($inventorypath),
        'course_job_file' => 'course-jobs/' . basename($jobpath),
        'files_sha256' => p5_hash_files($smallfiles),
        'backup_user_contract_version' => 3,
        'backup_user_classification_sha256' => p6_value_sha256($backupusers),
        'backup_declared_user_references_sha256' =>
            p6_value_sha256($backupdeclaredreferences),
        'planned_source_users' => count($plannedsourceids),
        'approved_identity_merges' => count(
            $bundle['convergence_by_course_target'][$coursekey] ?? []
        ),
        'estimated_weight' => $estimatedweight,
        'checkpoint_status' => 'referenced',
        'source_archive_hashed_again' => false,
        'source_archive_copied' => false,
        'destination_write_performed' => false,
    ];

    p6_assert_referenced_checkpoint_classification(
        $checkpoint,
        $job,
        'El checkpoint nuevo de ' . $coursekey
    );
    p5_write_json($checkpointpath, $checkpoint);
    cli_writeln(
        'FASE6_PACKAGE_REFERENCE_OK course_key=' . $coursekey .
        ' source=' . $sourceid . ' status=created bytes=' . (int)$backupbytes .
        ' copied=0 extracted=0 hashed_again=0'
    );
} catch (Throwable $error) {
    cli_error('FASE6_PACKAGE_REFERENCE_ERROR ' . $error->getMessage());
}
