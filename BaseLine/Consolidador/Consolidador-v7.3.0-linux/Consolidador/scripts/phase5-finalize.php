<?php
// Fase 5: identifica, marca y registra el curso restaurado.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once('/opt/consolidator/phase5-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'phase4' => '/exports/phase4',
        'phase5' => '/exports/phase5',
        'configsha' => null,
        'targetid' => null,
        'expectlab' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php phase5-finalize.php --phase4=/exports/phase4 --phase5=/exports/phase5 " .
        "--configsha=SHA256 --targetid=target [--expectlab=1]\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $phase4dir = rtrim((string)$options['phase4'], '/\\');
    $phase5dir = rtrim((string)$options['phase5'], '/\\');
    $configsha = p5_require_sha256((string)$options['configsha'], 'configsha');
    $targetid = p5_norm((string)$options['targetid']);
    $expectlab = (bool)(int)$options['expectlab'];
    $bundle = p5_load_plan(
        $phase4dir,
        $phase5dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $statepath = $phase5dir . '/apply_preflight.json';
    $state = p5_read_json($statepath);
    if (($state['plan_summary_sha256'] ?? '') !==
            hash_file('sha256', $phase5dir . '/plan_summary.json') ||
            ($state['normalized_backup_sha256'] ?? '') !==
            $bundle['hashes']['normalized_backup.mbz']) {
        throw new RuntimeException('apply_preflight.json no corresponde al plan confirmado.');
    }
    $mode = (string)($state['mode'] ?? '');
    $courseid = (int)($state['target_course_id'] ?? 0);
    if ($mode === 'restore_completed' &&
            ($state['destination_write_performed'] ?? null) !== true) {
        throw new RuntimeException('El estado de restauración completada no registra escritura.');
    }
    $courses = p5_target_courses();
    if ($mode === 'restore_new') {
        $beforeids = array_map('intval', $state['before_course_ids'] ?? []);
        $newcourses = array_values(array_filter(
            $courses,
            static fn(array $row): bool => !in_array((int)$row['id'], $beforeids, true)
        ));
        $candidates = array_values(array_filter(
            $newcourses,
            static fn(array $row): bool =>
                p5_norm((string)$row['shortname']) ===
                    p5_norm((string)$bundle['summary']['source_shortname']) ||
                (string)$row['idnumber'] ===
                    (string)$bundle['summary']['source_course_idnumber']
        ));
        if (count($candidates) !== 1) {
            throw new RuntimeException(
                'La restauración no produjo exactamente un curso piloto identificable.'
            );
        }
        $courseid = (int)$candidates[0]['id'];
    } else if (in_array(
        $mode,
        ['restore_completed', 'finalize_interrupted', 'already_restored'],
        true
    )) {
        if ($courseid < 1) {
            throw new RuntimeException('El preflight no registró target_course_id.');
        }
    } else {
        throw new RuntimeException('Modo de aplicación desconocido: ' . $mode . '.');
    }

    $course = $DB->get_record(
        'course',
        ['id' => $courseid],
        'id,category,fullname,shortname,idnumber',
        MUST_EXIST
    );
    $marker = (string)$bundle['summary']['target_course_marker'];
    $othermarker = $DB->get_record_select(
        'course',
        'idnumber = :marker AND id <> :courseid',
        ['marker' => $marker, 'courseid' => $courseid],
        'id',
        IGNORE_MISSING
    );
    if ($othermarker) {
        throw new RuntimeException('Otro curso ya posee el marcador del piloto.');
    }
    if (p5_norm((string)$course->shortname) !==
            p5_norm((string)$bundle['summary']['source_shortname'])) {
        throw new RuntimeException('El shortname restaurado no coincide con el plan.');
    }
    if ((int)$course->category !== (int)$bundle['summary']['target_category_id']) {
        throw new RuntimeException('El curso se restauró en una categoría distinta.');
    }
    $inventory = p5_collect_course_inventory($courseid);
    $sourceinventory = $bundle['source_inventory'];
    $comparison = p5_compare_course_inventories($sourceinventory, $inventory);
    if (($comparison['complete'] ?? false) !== true) {
        $firstissue = $comparison['issues'][0] ?? [];
        $detail = json_encode(
            $firstissue,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        throw new RuntimeException(
            'El curso candidato no conserva el inventario académico: ' .
            ($detail ?: 'diferencia no serializable') .
            '; no se aplicó el marcador de migración.'
        );
    }

    $precheckpath = $phase5dir . '/restore_precheck.json';
    if (!is_file($precheckpath)) {
        throw new RuntimeException(
            'No existe la evidencia restore_precheck.json de la restauración.'
        );
    }
    $precheck = p5_read_json($precheckpath);
    if (($precheck['config_sha256'] ?? '') !== $configsha ||
            ($precheck['target_id'] ?? '') !== $targetid ||
            (int)($precheck['course_id_created'] ?? 0) !== $courseid ||
            !in_array(
                (string)($precheck['outcome'] ?? ''),
                ['passed', 'warnings_accepted'],
                true
            ) ||
            (int)($precheck['error_count'] ?? -1) !== 0) {
        throw new RuntimeException(
            'restore_precheck.json no acredita un precheck aplicable para este curso.'
        );
    }
    if ((string)$course->idnumber !== $marker) {
        $course->idnumber = $marker;
        $DB->update_record('course', $course);
        rebuild_course_cache($courseid, true);
    }

    $maprow = [[
        'pilot_id' => (string)$bundle['course_row']['pilot_id'],
        'source' => (string)$bundle['summary']['source_id'],
        'source_course_id' => (int)$bundle['summary']['source_course_id'],
        'source_course_idnumber' => (string)$bundle['summary']['source_course_idnumber'],
        'target_course_id' => $courseid,
        'target_course_marker' => $marker,
        'target_shortname' => (string)$course->shortname,
        'apply_status' => $mode === 'already_restored' ? 'reused' : 'restored',
    ]];
    $mappath = $phase5dir . '/pilot_course_map.csv';
    p5_write_csv($mappath, [
        'pilot_id', 'source', 'source_course_id', 'source_course_idnumber',
        'target_course_id', 'target_course_marker', 'target_shortname',
        'apply_status',
    ], $maprow);
    $summary = [
        'schema_version' => '1.0',
        'phase' => '5-pilot-course-apply',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'plan_summary_sha256' =>
            hash_file('sha256', $phase5dir . '/plan_summary.json'),
        'normalized_backup_sha256' =>
            $bundle['hashes']['normalized_backup.mbz'],
        'restore_precheck_sha256' => hash_file('sha256', $precheckpath),
        'restore_precheck_outcome' => (string)$precheck['outcome'],
        'restore_precheck_warning_count' =>
            (int)($precheck['warning_count'] ?? 0),
        'pilot_course_map_sha256' => hash_file('sha256', $mappath),
        'source_id' => (string)$bundle['summary']['source_id'],
        'target_course_id' => $courseid,
        'target_course_marker' => $marker,
        'apply_status' => $mode === 'already_restored' ? 'reused' : 'restored',
        'enrolments_expected' => count($bundle['user_rows']),
        'roles_expected' => count($bundle['role_rows']),
        'post_restore_counts' => $inventory['counts'],
        'comparable_post_restore_counts' =>
            $comparison['comparable_actual_counts'],
        'compatibility_adjustments' =>
            $comparison['compatibility_adjustments'],
        'apply_performed' => true,
        'roles_applied' => true,
        'enrolments_applied' => true,
        'course_data_applied' => true,
    ];
    if ($expectlab) {
        $summary['lab_validation'] = 'pending_verification';
    }
    p5_write_json($phase5dir . '/apply_summary.json', $summary);
    cli_writeln(
        'FASE5_APPLY_OK course_id=' . $courseid .
        ' action=' . $summary['apply_status'] .
        ' users=' . count($bundle['user_rows']) .
        ' roles=' . count($bundle['role_rows'])
    );
} catch (Throwable $error) {
    cli_error('FASE5_APPLY_ERROR ' . $error->getMessage());
}
