<?php
// Fase 6: prevalidación de solo lectura antes de aplicar el lote.

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
    cli_writeln(
        "Uso: php phase6-apply-preflight.php --phase4=/exports/phase4 " .
        "--phase6=/exports/phase6 --configsha=SHA256 --targetid=target " .
        "[--expectlab=1]\n"
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
    $bundle = p6_load_reference_manifest(
        $phase4dir,
        $phase6dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $parentid = (int)$bundle['summary']['target_parent_category_id'];
    if (!$DB->record_exists('course_categories', ['id' => $parentid])) {
        throw new RuntimeException('La categoría padre aprobada dejó de existir.');
    }

    $targetinventory = p5_read_json($phase6dir . '/target_inventory.json');
    $pilot = $targetinventory['approved_phase5_pilot'] ?? [];
    $pilotid = (int)($pilot['target_course_id'] ?? 0);
    $pilotcourse = $DB->get_record(
        'course',
        ['id' => $pilotid],
        'id,shortname,idnumber,category',
        MUST_EXIST
    );
    if ((string)$pilotcourse->idnumber !==
            (string)($pilot['target_course_marker'] ?? '')) {
        throw new RuntimeException('El curso piloto perdió su marcador aprobado.');
    }

    foreach ($bundle['phase4']['target_by_canonical'] as $canonicalid => $mapping) {
        $user = $DB->get_record(
            'user',
            ['id' => (int)$mapping['target_user_id'], 'deleted' => 0],
            'id,username,email',
            MUST_EXIST
        );
        if (p5_norm((string)$user->username) !==
                p5_norm((string)$mapping['target_username']) ||
                p5_norm((string)$user->email) !==
                p5_norm((string)$mapping['target_email'])) {
            throw new RuntimeException(
                'El usuario canónico ' . $canonicalid . ' cambió en el destino.'
            );
        }
    }
    foreach (['student', 'editingteacher', 'manager'] as $shortname) {
        if (!$DB->record_exists('role', ['shortname' => $shortname])) {
            throw new RuntimeException('Falta el rol destino ' . $shortname . '.');
        }
    }
    $personalizado = p6_personalizado_role_status();
    if (!$personalizado['safe']) {
        throw new RuntimeException(
            'El rol personalizado existente no es seguro: ' .
            implode(' ', $personalizado['issues'])
        );
    }

    $checkpoints = [];
    foreach (glob($phase6dir . '/apply-checkpoints/checkpoint-*.json') ?: [] as $path) {
        $checkpoint = p5_read_json($path);
        $coursekey = (string)($checkpoint['course_key'] ?? '');
        if ($coursekey === '' || isset($checkpoints[$coursekey])) {
            throw new RuntimeException('Los checkpoints de aplicación son inválidos.');
        }
        $checkpoints[$coursekey] = $checkpoint;
    }
    $targetcoursesbyshortname = [];
    $targetcoursesbyfullname = [];
    foreach (p5_target_courses() as $targetcourse) {
        $normalizedshortname = p5_norm((string)$targetcourse['shortname']);
        $targetcoursesbyshortname[$normalizedshortname][] = $targetcourse;
        $normalizedfullname = p5_norm((string)$targetcourse['fullname']);
        $targetcoursesbyfullname[$normalizedfullname][] = $targetcourse;
    }
    $coursesrestored = 0;
    foreach ($bundle['restore_courses'] as $courseplan) {
        $coursekey = (string)$courseplan['course_key'];
        $marker = (string)$courseplan['target_course_marker'];
        $matches = $DB->get_records('course', ['idnumber' => $marker], 'id ASC');
        if (count($matches) > 1) {
            throw new RuntimeException('El destino repite el marcador de ' . $coursekey . '.');
        }
        if (count($matches) === 1) {
            $checkpoint = $checkpoints[$coursekey] ?? null;
            $course = reset($matches);
            if (p5_norm((string)$course->shortname) !==
                    p5_norm((string)$courseplan['target_shortname']) ||
                    p5_norm((string)$course->fullname) !==
                    p5_norm((string)$courseplan['target_fullname'])) {
                throw new RuntimeException(
                    'El curso marcado de ' . $coursekey .
                    ' no conserva el nombre aprobado.'
                );
            }
            $checkpointok = $checkpoint &&
                ($checkpoint['checkpoint_status'] ?? '') === 'applied' &&
                (int)($checkpoint['target_course_id'] ?? 0) === (int)$course->id &&
                ($checkpoint['manifest_sha256'] ?? '') ===
                    $bundle['manifest_sha256'];
            $statepath = $phase6dir . '/apply-states/state-' .
                p6_backup_basename($coursekey) . '.json';
            $stateok = false;
            if (!$checkpointok && is_readable($statepath)) {
                $state = p5_read_json($statepath);
                $stateok =
                    ($state['course_key'] ?? '') === $coursekey &&
                    ($state['manifest_sha256'] ?? '') ===
                        $bundle['manifest_sha256'] &&
                    ($state['state'] ?? '') === 'restore_completed' &&
                    (int)($state['target_course_id'] ?? 0) === (int)$course->id;
            }
            if (!$checkpointok && !$stateok) {
                throw new RuntimeException(
                    'Existe un curso marcado sin checkpoint ni estado recuperable para ' .
                    $coursekey . '.'
                );
            }
            if ($checkpointok) {
                $coursesrestored++;
            }
            continue;
        }
        $shortname = p5_norm((string)$courseplan['target_shortname']);
        $recoverableid = 0;
        $statepath = $phase6dir . '/apply-states/state-' .
            p6_backup_basename($coursekey) . '.json';
        if (is_readable($statepath)) {
            $state = p5_read_json($statepath);
            if (($state['course_key'] ?? '') === $coursekey &&
                    ($state['manifest_sha256'] ?? '') ===
                        $bundle['manifest_sha256']) {
                $recoverableid = (int)($state['target_course_id'] ?? 0);
            }
        }
        foreach ($targetcoursesbyshortname[$shortname] ?? [] as $targetcourse) {
            if ((int)$targetcourse['id'] !== $recoverableid) {
                throw new RuntimeException(
                    'El shortname de ' . $coursekey . ' ya está ocupado.'
                );
            }
        }
        $fullname = p5_norm((string)$courseplan['target_fullname']);
        foreach ($targetcoursesbyfullname[$fullname] ?? [] as $targetcourse) {
            if ((int)$targetcourse['id'] !== $recoverableid) {
                throw new RuntimeException(
                    'El fullname de ' . $coursekey . ' ya está ocupado.'
                );
            }
        }
    }

    $plannedsiteadmins = p6_planned_site_administrators($bundle['phase4']);

    $record = [
        'schema_version' => '1.0',
        'phase' => '6-batch-apply-preflight',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'batch_id' => (string)$bundle['summary']['batch_id'],
        'manifest_sha256' => $bundle['manifest_sha256'],
        'pilot_course_id' => $pilotid,
        'courses_expected' => count($bundle['restore_courses']),
        'courses_already_applied' => $coursesrestored,
        'courses_pending' => count($bundle['restore_courses']) - $coursesrestored,
        'personalizado_exists' => (bool)$personalizado['exists'],
        'personalizado_safe' => (bool)$personalizado['safe'],
        'site_administrators_existing' =>
            count(p6_current_site_administrator_ids()),
        'site_administrators_planned' => count($plannedsiteadmins),
        'preflight_status' => 'applicable',
        'destination_write_performed' => false,
    ];
    p5_write_json($phase6dir . '/apply_preflight.json', $record);
    cli_writeln(
        'FASE6_APPLY_PREFLIGHT_OK courses=' . count($bundle['restore_courses']) .
        ' reused=' . $coursesrestored .
        ' pending=' . $record['courses_pending'] .
        ' pilot=' . $pilotid .
        ' write=0'
    );
} catch (Throwable $error) {
    cli_error('FASE6_APPLY_PREFLIGHT_ERROR ' . $error->getMessage());
}
