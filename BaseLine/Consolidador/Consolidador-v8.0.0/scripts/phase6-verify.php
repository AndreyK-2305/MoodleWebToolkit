<?php
// Fase 6: verificación consolidada del lote aplicado.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');
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
    cli_writeln("Uso: php phase6-verify.php --phase4=DIR --phase6=DIR " .
        "--configsha=SHA256 --targetid=target [--expectlab=1]\n");
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
    $bundle = p6_load_reference_manifest(
        $phase4dir,
        $phase6dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $applypath = $phase6dir . '/batch_apply_summary.json';
    $coursemapfile = $phase6dir . '/course_map.csv';
    $apply = p5_read_json($applypath);
    if (($apply['manifest_sha256'] ?? '') !== $bundle['manifest_sha256'] ||
            ($apply['course_map_sha256'] ?? '') !==
                hash_file('sha256', $coursemapfile) ||
            ($apply['apply_status'] ?? '') !==
                'applied_pending_batch_verification' ||
            (int)($apply['courses_pending'] ?? -1) !== 0) {
        throw new RuntimeException('La aplicación del lote no está lista para verificar.');
    }
    $categorysummarypath = $phase6dir . '/category_apply_summary.json';
    $categorymappath = $phase6dir . '/category_map.csv';
    $categorysummary = p5_read_json($categorysummarypath);
    if (($apply['category_apply_summary_sha256'] ?? '') !==
            hash_file('sha256', $categorysummarypath) ||
            ($apply['category_map_sha256'] ?? '') !==
                hash_file('sha256', $categorymappath) ||
            ($categorysummary['manifest_sha256'] ?? '') !==
                $bundle['manifest_sha256']) {
        throw new RuntimeException('La evidencia de categorías perdió integridad.');
    }
    foreach (p5_read_csv($categorymappath) as $categoryrow) {
        $category = $DB->get_record(
            'course_categories',
            ['id' => (int)$categoryrow['target_category_id']],
            'id,parent,name,idnumber',
            MUST_EXIST
        );
        if ((int)$category->parent !==
                (int)$categoryrow['target_parent_category_id'] ||
                p5_norm((string)$category->name) !==
                    p5_norm((string)$categoryrow['category_name']) ||
                (string)$category->idnumber !==
                    (string)$categoryrow['target_category_marker']) {
            throw new RuntimeException(
                'La jerarquía cambió en ' . (string)$categoryrow['category_key'] . '.'
            );
        }
    }
    $personalizado = p6_personalizado_role_status();
    if (!$personalizado['exists'] || !$personalizado['safe']) {
        throw new RuntimeException('El rol personalizado perdió su perfil seguro.');
    }
    $targetinventory = p5_read_json($phase6dir . '/target_inventory.json');
    $plannedsiteadminids = array_values(array_map(
        static fn(array $row): int => (int)$row['target_user_id'],
        p6_planned_site_administrators($bundle['phase4'])
    ));
    $baselineadminids = array_values(array_map(
        static fn(array $row): int => (int)$row['target_user_id'],
        $targetinventory['site_administrators'] ?? []
    ));
    $requiredsiteadminids = array_values(array_unique(array_merge(
        $baselineadminids,
        $plannedsiteadminids
    )));
    sort($requiredsiteadminids, SORT_NUMERIC);
    $currentsiteadminids = p6_current_site_administrator_ids();
    if (array_diff($requiredsiteadminids, $currentsiteadminids)) {
        throw new RuntimeException(
            'No se conservaron todos los administradores aprobados del sitio.'
        );
    }
    $pilot = $targetinventory['approved_phase5_pilot'] ?? [];
    $pilotcourse = $DB->get_record(
        'course',
        ['id' => (int)($pilot['target_course_id'] ?? 0)],
        'id,idnumber',
        MUST_EXIST
    );
    if ((string)$pilotcourse->idnumber !==
            (string)($pilot['target_course_marker'] ?? '')) {
        throw new RuntimeException('El curso piloto cambió durante la aplicación.');
    }

    $mapbykey = [];
    foreach (p5_read_csv($coursemapfile) as $row) {
        $key = (string)$row['course_key'];
        if ($key === '' || isset($mapbykey[$key])) {
            throw new RuntimeException('course_map.csv contiene filas inválidas.');
        }
        $mapbykey[$key] = $row;
    }
    $rows = [];
    $failed = 0;
    foreach ($bundle['restore_courses'] as $courseplan) {
        $coursekey = (string)$courseplan['course_key'];
        $map = $mapbykey[$coursekey] ?? null;
        $issues = [];
        if (!$map) {
            $issues[] = 'Falta el mapa del curso.';
            $courseid = 0;
        } else {
            $courseid = (int)$map['target_course_id'];
        }
        $inventory = null;
        if ($courseid > 0) {
            try {
                $course = $DB->get_record(
                    'course',
                    ['id' => $courseid],
                    'id,category,fullname,shortname,idnumber',
                    MUST_EXIST
                );
                if ((string)$course->idnumber !==
                        (string)$courseplan['target_course_marker']) {
                    $issues[] = 'Marcador diferente.';
                }
                if ((int)$course->category !== (int)$map['target_category_id']) {
                    $issues[] = 'Categoría diferente.';
                }
                if (p5_norm((string)$course->shortname) !==
                        p5_norm((string)$courseplan['target_shortname'])) {
                    $issues[] = 'Shortname diferente.';
                }
                if (p5_norm((string)$course->fullname) !==
                        p5_norm((string)$courseplan['target_fullname'])) {
                    $issues[] = 'Fullname diferente.';
                }
                $basename = p6_backup_basename($coursekey);
                $checkpointpath = $phase6dir .
                    '/apply-checkpoints/checkpoint-' . $basename . '.json';
                $targetinventorypath = $phase6dir .
                    '/target-inventories/inventory-' . $basename . '.json';
                $effectiveinventorypath = $phase6dir .
                    '/effective-source-inventories/inventory-' .
                    $basename . '.json';
                $checkpoint = p5_read_json($checkpointpath);
                if (($checkpoint['checkpoint_status'] ?? '') !== 'applied' ||
                        ($checkpoint['manifest_sha256'] ?? '') !==
                            $bundle['manifest_sha256'] ||
                        (int)($checkpoint['target_course_id'] ?? 0) !== $courseid ||
                        ($checkpoint['target_inventory_sha256'] ?? '') !==
                            hash_file('sha256', $targetinventorypath) ||
                        !is_readable($effectiveinventorypath) ||
                        ($checkpoint['effective_source_inventory_sha256'] ?? '') !==
                            hash_file('sha256', $effectiveinventorypath)) {
                    throw new RuntimeException(
                        'La evidencia incremental del curso perdió integridad.'
                    );
                }
                $inventory = p5_read_json($targetinventorypath);
                $expectedens = p6_effective_course_enrolments($bundle, $coursekey);
                $expectedroles = p6_effective_course_roles($bundle, $coursekey);
                $actualens = [];
                foreach ($inventory['enrolments'] as $row) {
                    $actualens[] = (int)$row['source_user_id'] . '|' .
                        p5_norm((string)$row['enrol_method']) . '|' .
                        (int)$row['enrol_status'];
                }
                $plannedens = [];
                foreach ($expectedens as $row) {
                    $plannedens[] = (int)$row['target_user_id'] . '|' .
                        p5_norm((string)$row['enrol_method']) . '|' .
                        (int)$row['enrol_status'];
                }
                sort($actualens, SORT_STRING);
                sort($plannedens, SORT_STRING);
                if ($actualens !== $plannedens) {
                    $issues[] = 'Matrículas diferentes.';
                }
                $actualroles = [];
                foreach ($inventory['roles'] as $row) {
                    $actualroles[] = (int)$row['source_user_id'] . '|' .
                        p5_norm((string)$row['role_shortname']);
                }
                $plannedroles = [];
                foreach ($expectedroles as $row) {
                    $plannedroles[] = (int)$row['target_user_id'] . '|' .
                        p5_norm((string)$row['target_role_shortname']);
                }
                sort($actualroles, SORT_STRING);
                sort($plannedroles, SORT_STRING);
                if ($actualroles !== $plannedroles) {
                    $issues[] = 'Roles diferentes.';
                }
                $entry = $bundle['manifest_entries_by_course'][$coursekey];
                $sourceinventory = p5_read_json($effectiveinventorypath);
                $job = p5_read_json($entry['_paths']['course_job']);
                $historicalids = p6_historical_source_ids(
                    $job['backup_user_classification'] ?? [],
                    $bundle['phase4']['source_by_key']
                );
                $historicalmap = [];
                $historicalidsbytarget = [];
                if ($historicalids) {
                    $historicalauditpath = $phase6dir . '/historical-audits/audit-' .
                        $basename . '.json';
                    if (!is_file($historicalauditpath) ||
                            ($checkpoint['historical_audit_sha256'] ?? '') !==
                                hash_file('sha256', $historicalauditpath)) {
                        throw new RuntimeException(
                            'historical_history_lost: auditoría histórica sin sello.'
                        );
                    }
                    $audit = p5_read_json($historicalauditpath);
                    if (($audit['course_key'] ?? '') !== $coursekey ||
                            ($audit['resolution_state'] ?? '') !== 'validated' ||
                            count($audit['resolutions'] ?? []) !== count($historicalids)) {
                        throw new RuntimeException('historical_history_lost: auditoría incompleta.');
                    }
                    foreach ($audit['resolutions'] as $resolution) {
                        $sourceuserid = (int)($resolution['source_user_id'] ?? 0);
                        $targetuserid = (int)($resolution['target_user_id'] ?? 0);
                        if (!in_array($sourceuserid, $historicalids, true) ||
                                isset($historicalidsbytarget[$sourceuserid])) {
                            throw new RuntimeException('historical_user_duplicated: mapa ambiguo.');
                        }
                        $registry = p5_read_json(p6_historical_registry_path(
                            $phase6dir, (string)$courseplan['source'], $sourceuserid
                        ));
                        p6_historical_registry_update(
                            $registry, (string)$courseplan['source'],
                            $sourceuserid, $targetuserid, $coursekey
                        );
                        p6_historical_assert_target($DB, $targetuserid);
                        $historicalidsbytarget[$sourceuserid] = $targetuserid;
                        $historicalmap[(string)$courseplan['source'] . ':' .
                            $sourceuserid] = ['target_user_id' => $targetuserid,
                                'canonical_id' => ''];
                    }
                    p6_historical_assert_disjoint(
                        $historicalidsbytarget, $bundle['phase4']['source_by_key']
                    );
                    $historyissues = p6_historical_relation_issues(
                        $sourceinventory['inventory'], $inventory,
                        $historicalidsbytarget
                    );
                    if ($historyissues) {
                        $issues[] = 'historical_history_lost: ' .
                            json_encode($historyissues[0], JSON_UNESCAPED_UNICODE);
                    }
                }
                $comparison = p6_compare_applied_course(
                    $sourceinventory,
                    $inventory,
                    count($expectedens),
                    count($expectedroles),
                    (string)($job['source_state_sha256'] ?? ''),
                    $bundle['phase4']['source_by_key'],
                    (string)$courseplan['source'],
                    $historicalmap,
                    (int)$courseplan['source_course_id'],
                    $courseid
                );
                $quizreportsha = p5_write_quiz_attempt_report(
                    $phase6dir . '/restore-diagnostics/quiz_attempt_differences-' .
                        $basename . '.json',
                    $comparison['quiz_attempt_diagnostic'],
                    [
                        'course_key' => $coursekey,
                        'source_id' => (string)$courseplan['source'],
                        'source_course_id' => (int)$courseplan['source_course_id'],
                        'target_course_id' => $courseid,
                    ]
                );
                if ($quizreportsha !== null) {
                    $issues[] = 'quiz_attempt_differences.json sha256=' . $quizreportsha;
                }
                if (($comparison['complete'] ?? false) !== true) {
                    $issues[] = 'Inventario académico diferente: ' .
                        json_encode(
                            $comparison['issues'][0] ?? [],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        );
                }
            } catch (Throwable $courseerror) {
                $issues[] = $courseerror->getMessage();
            }
        }
        if ($issues) {
            $failed++;
        }
        $rows[] = [
            'course_key' => $coursekey,
            'source' => (string)$courseplan['source'],
            'source_course_id' => (int)$courseplan['source_course_id'],
            'target_course_id' => $courseid ?: '',
            'validation' => $issues ? 'failed' : 'passed',
            'issues' => implode(' ', $issues),
        ];
    }
    $csvpath = $phase6dir . '/batch_verification.csv';
    p5_write_csv($csvpath, [
        'course_key',
        'source',
        'source_course_id',
        'target_course_id',
        'validation',
        'issues',
    ], $rows);
    $summary = [
        'schema_version' => '1.0',
        'phase' => '6-multi-course-verification',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'batch_id' => (string)$bundle['summary']['batch_id'],
        'manifest_sha256' => $bundle['manifest_sha256'],
        'batch_apply_summary_sha256' => hash_file('sha256', $applypath),
        'course_map_sha256' => hash_file('sha256', $coursemapfile),
        'verification_csv_sha256' => hash_file('sha256', $csvpath),
        'courses_expected' => count($bundle['restore_courses']),
        'courses_verified' => count($rows) - $failed,
        'failed_courses' => $failed,
        'pilot_course_id' => (int)$pilotcourse->id,
        'pilot_preserved' => true,
        'personalizado_profile' => 'student_readonly',
        'site_administrator_ids_required' => $requiredsiteadminids,
        'site_administrator_ids_verified' => $currentsiteadminids,
        'verification_mode' => 'incremental_checkpoint_evidence',
        'full_course_inventories_rebuilt' => false,
        'validation' => $failed === 0 ? 'passed' : 'failed',
    ];
    if ($expectlab) {
        $summary['lab_validation'] =
            count($rows) === count($bundle['restore_courses']) &&
            $failed === 0
                ? 'passed'
                : 'failed';
    }
    p5_write_json($phase6dir . '/batch_verification.json', $summary);
    if ($failed > 0) {
        throw new RuntimeException(
            'La verificación encontró ' . $failed . ' curso(s) con diferencias.'
        );
    }
    cli_writeln(
        'FASE6_VERIFY_OK courses=' . count($rows) .
        ' failed=0 pilot=' . (int)$pilotcourse->id .
        ' personalizado=student_readonly'
    );
} catch (Throwable $error) {
    cli_error('FASE6_VERIFY_ERROR ' . $error->getMessage());
}
