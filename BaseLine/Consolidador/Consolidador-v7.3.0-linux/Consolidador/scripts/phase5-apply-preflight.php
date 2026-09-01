<?php
// Fase 5: prevalidación idempotente antes de restaurar el curso piloto.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
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
        "Uso: php phase5-apply-preflight.php --phase4=/exports/phase4 " .
        "--phase5=/exports/phase5 --configsha=SHA256 --targetid=target [--expectlab=1]\n"
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
    if (!preg_match('/^[a-z][a-z0-9_-]*$/', $targetid)) {
        throw new RuntimeException('targetid inválido.');
    }
    $bundle = p5_load_plan(
        $phase4dir,
        $phase5dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $summary = $bundle['summary'];
    $courseplan = $bundle['course_row'];
    $categoryid = (int)$summary['target_category_id'];
    if (!$DB->record_exists('course_categories', ['id' => $categoryid])) {
        throw new RuntimeException('La categoría destino dejó de existir.');
    }
    foreach ($bundle['user_rows'] as $row) {
        $user = $DB->get_record(
            'user',
            ['id' => (int)$row['target_user_id'], 'deleted' => 0],
            'id,username',
            MUST_EXIST
        );
        if (p5_norm((string)$user->username) !== p5_norm((string)$row['target_username'])) {
            throw new RuntimeException(
                'El target_user_id=' . (int)$user->id . ' cambió de username.'
            );
        }
    }
    foreach ($bundle['role_rows'] as $row) {
        if (!in_array((string)($row['approval_status'] ?? ''), [
                'approved_standard',
                'approved_default_fallback',
            ], true) ||
                ($row['planned_action'] ?? '') !== 'restore_course_role') {
            throw new RuntimeException('El plan contiene un rol sin aprobación.');
        }
        if ((string)$row['target_role_shortname'] !== 'personalizado' &&
                !$DB->record_exists('role', [
                    'shortname' => (string)$row['target_role_shortname'],
                ])) {
            throw new RuntimeException(
                'El rol destino ' . (string)$row['target_role_shortname'] . ' dejó de existir.'
            );
        }
    }
    $personalizado = p5_personalizado_role_status();
    if (!$personalizado['safe']) {
        throw new RuntimeException(
            'El rol personalizado existente no es seguro: ' .
            implode(' ', $personalizado['issues'])
        );
    }

    $courses = p5_target_courses();
    $marker = (string)$summary['target_course_marker'];
    $markermatches = array_values(array_filter(
        $courses,
        static fn(array $row): bool => (string)$row['idnumber'] === $marker
    ));
    if (count($markermatches) > 1) {
        throw new RuntimeException('El destino repite el marcador del curso piloto.');
    }
    $mode = '';
    $targetcourseid = 0;
    $statepath = $phase5dir . '/apply_preflight.json';
    $plansummarysha = hash_file('sha256', $phase5dir . '/plan_summary.json');
    if (count($markermatches) === 1) {
        $mode = 'already_restored';
        $targetcourseid = (int)$markermatches[0]['id'];
    } else {
        $previous = null;
        if (is_readable($statepath)) {
            try {
                $candidate = p5_read_json($statepath);
                if (($candidate['plan_summary_sha256'] ?? '') === $plansummarysha &&
                        in_array(
                            (string)($candidate['mode'] ?? ''),
                            ['restore_new', 'restore_in_progress', 'restore_completed'],
                            true
                        )) {
                    $previous = $candidate;
                }
            } catch (Throwable $ignored) {
                $previous = null;
            }
        }
        $beforeidsforstate = array_values(array_map(
            static fn(array $row): int => (int)$row['id'],
            $courses
        ));
        if ($previous !== null) {
            $beforeids = array_map('intval', $previous['before_course_ids'] ?? []);
            $newcourses = array_values(array_filter(
                $courses,
                static fn(array $row): bool => !in_array((int)$row['id'], $beforeids, true)
            ));
            $candidates = array_values(array_filter(
                $newcourses,
                static fn(array $row): bool =>
                    p5_norm((string)$row['shortname']) ===
                        p5_norm((string)$summary['source_shortname']) ||
                    (string)$row['idnumber'] ===
                        (string)$summary['source_course_idnumber']
            ));
            if (count($candidates) === 1) {
                $candidateid = (int)$candidates[0]['id'];
                $inventory = p5_collect_course_inventory($candidateid);
                $comparison = p5_compare_course_inventories(
                    $bundle['source_inventory'],
                    $inventory
                );
                // Si Moodle terminó execute_plan(), el curso restaurado se
                // conserva incluso ante una diferencia de inventario. La
                // finalización emitirá el detalle y no aplicará el marcador.
                // Solo un intento realmente interrumpido puede recuperarse
                // mediante eliminación controlada del contenedor.
                $mode = (string)$previous['mode'] === 'restore_completed' ||
                        ($comparison['complete'] ?? false)
                    ? 'finalize_interrupted'
                    : 'recover_failed_restore';
                $targetcourseid = $candidateid;
                $beforeidsforstate = array_values($beforeids);
            } else if (count($candidates) > 1) {
                throw new RuntimeException(
                    'Una aplicación interrumpida dejó varios cursos candidatos.'
                );
            } else if (count($newcourses) === 1) {
                // La CLI estándar crea el curso contenedor antes de comprobar el
                // precheck. Si falla, puede dejar exactamente un curso sin el
                // shortname definitivo. El ID se vincula al inventario firmado
                // anterior al intento y se elimina solo desde phase5-restore.php.
                $mode = 'recover_failed_restore';
                $targetcourseid = (int)$newcourses[0]['id'];
                $beforeidsforstate = array_values($beforeids);
            } else if (count($newcourses) > 1) {
                throw new RuntimeException(
                    'La aplicación anterior dejó varios cursos nuevos no identificables.'
                );
            }
        }
        if ($mode === '') {
            if ((string)$courseplan['action'] === 'reuse_restored') {
                throw new RuntimeException(
                    'El curso previamente restaurado desapareció del destino.'
                );
            }
            $shortnamecollision = array_filter(
                $courses,
                static fn(array $row): bool =>
                    p5_norm((string)$row['shortname']) ===
                    p5_norm((string)$summary['source_shortname'])
            );
            $idnumbercollision = array_filter(
                $courses,
                static fn(array $row): bool =>
                    (string)$row['idnumber'] ===
                    (string)$summary['source_course_idnumber']
            );
            if ($shortnamecollision || $idnumbercollision) {
                throw new RuntimeException(
                    'Apareció una colisión de shortname o idnumber después de simular.'
                );
            }
            $mode = 'restore_new';
        }
    }
    $record = [
        'schema_version' => '1.0',
        'phase' => '5-apply-preflight',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'plan_summary_sha256' => $plansummarysha,
        'normalized_backup_sha256' =>
            $bundle['hashes']['normalized_backup.mbz'],
        'mode' => $mode,
        'target_course_id' => $targetcourseid ?: null,
        'before_course_ids' => $beforeidsforstate ?? array_values(array_map(
            static fn(array $row): int => (int)$row['id'],
            $courses
        )),
        'destination_write_performed' => false,
    ];
    p5_write_json($statepath, $record);
    cli_writeln(
        'FASE5_APPLY_PREFLIGHT_OK mode=' . $mode .
        ' courses_before=' . count($courses) .
        ' target_course_id=' . ($targetcourseid ?: 0)
    );
} catch (Throwable $error) {
    cli_error('FASE5_APPLY_PREFLIGHT_ERROR ' . $error->getMessage());
}
