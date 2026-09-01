<?php
// Fase 5: prepara y firma el piloto sin modificar el Moodle destino.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once('/opt/consolidator/phase5-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'phase4' => '/exports/phase4',
        'output' => '/exports/phase5',
        'configsha' => null,
        'targetid' => null,
        'sourceid' => null,
        'courseidnumber' => null,
        'targeturl' => null,
        'categoryid' => null,
        'expectlab' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php phase5-prepare.php --phase4=/exports/phase4 --output=/exports/phase5 " .
        "--configsha=SHA256 --targetid=target --sourceid=virtual " .
        "--courseidnumber=CURSO --targeturl=URL --categoryid=1 [--expectlab=1]\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $phase4dir = rtrim((string)$options['phase4'], '/\\');
    $outputdir = rtrim((string)$options['output'], '/\\');
    $configsha = p5_require_sha256((string)$options['configsha'], 'configsha');
    $targetid = p5_norm((string)$options['targetid']);
    $sourceid = p5_norm((string)$options['sourceid']);
    $courseidnumber = trim((string)$options['courseidnumber']);
    $targeturl = trim((string)$options['targeturl']);
    $categoryid = (int)$options['categoryid'];
    $expectlab = (bool)(int)$options['expectlab'];
    if (!preg_match('/^[a-z][a-z0-9_-]*$/', $targetid) ||
            !preg_match('/^[a-z][a-z0-9_-]*$/', $sourceid) ||
            $courseidnumber === '' || !preg_match('/^https?:\/\//', $targeturl) ||
            $categoryid < 1) {
        throw new RuntimeException('Los parámetros del piloto son inválidos.');
    }
    if (!is_dir($outputdir) && !mkdir($outputdir, 0770, true) && !is_dir($outputdir)) {
        throw new RuntimeException('No fue posible crear el directorio de fase 5.');
    }
    $backupdir = $outputdir . '/backups';
    if (!is_dir($backupdir) && !mkdir($backupdir, 0770, true) && !is_dir($backupdir)) {
        throw new RuntimeException('No fue posible crear el directorio de backups.');
    }

    $contract = p5_load_phase4_contract(
        $phase4dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $preflightpath = $outputdir . '/target_preflight.json';
    $preflight = p5_read_json($preflightpath);
    if (($preflight['config_sha256'] ?? '') !== $configsha ||
            ($preflight['target_id'] ?? '') !== $targetid ||
            (int)($preflight['pilot_category']['id'] ?? 0) !== $categoryid ||
            ($preflight['write_performed'] ?? null) !== false) {
        throw new RuntimeException('target_preflight.json no corresponde al destino confirmado.');
    }
    $targetusersbyid = [];
    foreach ($preflight['verified_target_users'] ?? [] as $targetuser) {
        $targetuserid = (int)($targetuser['target_user_id'] ?? 0);
        if ($targetuserid < 1 || isset($targetusersbyid[$targetuserid])) {
            throw new RuntimeException(
                'target_preflight.json contiene un usuario destino inválido o repetido.'
            );
        }
        $targetusersbyid[$targetuserid] = $targetuser;
    }

    $course = $DB->get_record(
        'course',
        ['idnumber' => $courseidnumber],
        'id,category,fullname,shortname,idnumber',
        MUST_EXIST
    );
    $inventory = p5_collect_course_inventory((int)$course->id);
    $inventory['schema_version'] = '1.0';
    $inventory['phase'] = '5-source-course-inventory';
    $inventory['generated_at_utc'] = gmdate('c');
    $inventory['config_sha256'] = $configsha;
    $inventory['source_id'] = $sourceid;
    $inventory['target_id'] = $targetid;
    $inventory['write_performed'] = false;
    $inventorypath = $outputdir . '/source_course_inventory.json';
    p5_write_json($inventorypath, $inventory);

    $marker = p5_course_marker($sourceid, (string)$course->idnumber);
    $markermatches = [];
    $shortnamematches = [];
    $idnumbermatches = [];
    foreach ($preflight['courses'] ?? [] as $targetcourse) {
        if ((string)($targetcourse['idnumber'] ?? '') === $marker) {
            $markermatches[] = $targetcourse;
        }
        if (p5_norm((string)($targetcourse['shortname'] ?? '')) ===
                p5_norm((string)$course->shortname)) {
            $shortnamematches[] = $targetcourse;
        }
        if ((string)($targetcourse['idnumber'] ?? '') === (string)$course->idnumber) {
            $idnumbermatches[] = $targetcourse;
        }
    }
    $blocking = [];
    $modulekeys = array_column($inventory['modules'], 'module_key');
    if (count($modulekeys) !== count(array_unique($modulekeys))) {
        $blocking[] =
            'El curso repite una llave semántica de actividad; asigne idnumber únicos.';
    }
    $action = 'restore_new';
    $existingtargetcourseid = '';
    if (count($markermatches) > 1) {
        $blocking[] = 'El destino repite el marcador de migración del curso piloto.';
    } else if (count($markermatches) === 1) {
        $action = 'reuse_restored';
        $existingtargetcourseid = (int)$markermatches[0]['id'];
        if (p5_norm((string)$markermatches[0]['shortname']) !==
                p5_norm((string)$course->shortname)) {
            $blocking[] = 'El curso marcado en el destino usa un shortname diferente.';
        }
    } else {
        if ($shortnamematches) {
            $blocking[] = 'El shortname del piloto ya pertenece a otro curso destino.';
        }
        if ($idnumbermatches) {
            $blocking[] = 'El idnumber original del piloto ya pertenece a otro curso destino.';
        }
    }

    $availablemodules = array_map('p5_norm', $preflight['available_modules'] ?? []);
    $missingmodules = array_values(array_diff(
        array_keys($inventory['modules_by_type']),
        $availablemodules
    ));
    if ($missingmodules) {
        $blocking[] = 'Faltan módulos en el destino: ' . implode('|', $missingmodules) . '.';
    }

    $userplan = [];
    $seenparticipants = [];
    $seentargetparticipants = [];
    foreach ($inventory['enrolments'] as $enrolment) {
        $sourceuserid = (int)$enrolment['source_user_id'];
        $key = $sourceid . ':' . $sourceuserid;
        $mapping = $contract['source_by_key'][$key] ?? null;
        if (!$mapping) {
            $blocking[] = 'La matrícula ' . $key . ' no tiene target_user_id.';
            continue;
        }
        $participantkey = $sourceuserid . '|' . (string)$enrolment['enrol_method'];
        if (isset($seenparticipants[$participantkey])) {
            $blocking[] = 'El inventario repite la matrícula ' . $participantkey . '.';
            continue;
        }
        $seenparticipants[$participantkey] = true;
        $targetuserid = (int)$mapping['target_user_id'];
        if (isset($seentargetparticipants[$targetuserid]) &&
                $seentargetparticipants[$targetuserid] !== $sourceuserid) {
            $blocking[] = 'Dos cuentas matriculadas convergen en target_user_id=' .
                $targetuserid . '.';
        }
        $seentargetparticipants[$targetuserid] = $sourceuserid;
        $userplan[] = [
            'source' => $sourceid,
            'source_user_id' => $sourceuserid,
            'source_username' => (string)$enrolment['source_username'],
            'canonical_id' => (string)$mapping['canonical_id'],
            'target_user_id' => $targetuserid,
            'target_username' => (string)$mapping['target_username'],
            'enrol_method' => (string)$enrolment['enrol_method'],
            'enrol_status' => (int)$enrolment['enrol_status'],
            'mapping_status' => 'mapped',
            'planned_action' => 'restore_enrolment',
        ];
    }

    $availabletargetroles = array_map('p5_norm', $preflight['available_roles'] ?? []);
    $roleplan = [];
    foreach ($inventory['roles'] as $role) {
        $sourceuserid = (int)$role['source_user_id'];
        $key = $sourceid . ':' . $sourceuserid;
        $mapping = $contract['source_by_key'][$key] ?? null;
        [$normalizedrole, $targetrole, $approved, $reason] =
            p5_role_policy((string)$role['role_shortname']);
        if (!$mapping) {
            $approved = false;
            $reason = 'La asignación no tiene un usuario destino verificado.';
        }
        if ($approved && p5_norm($targetrole) !== 'personalizado' &&
                !in_array(p5_norm($targetrole), $availabletargetroles, true)) {
            $approved = false;
            $reason = 'El rol objetivo no existe en el Moodle destino.';
        }
        if (!$approved) {
            $blocking[] = 'Rol pendiente para ' . $key . ': ' .
                (string)$role['role_shortname'] . '.';
        }
        $roleplan[] = [
            'source' => $sourceid,
            'source_user_id' => $sourceuserid,
            'canonical_id' => $mapping ? (string)$mapping['canonical_id'] : '',
            'target_user_id' => $mapping ? (int)$mapping['target_user_id'] : '',
            'source_role_shortname' => (string)$role['role_shortname'],
            'normalized_role' => $normalizedrole,
            'target_role_shortname' => $targetrole,
            'approval_status' => $approved
                ? (p5_norm($targetrole) === 'personalizado'
                    ? 'approved_default_fallback'
                    : 'approved_standard')
                : 'blocked_review',
            'planned_action' => $approved ? 'restore_course_role' : 'skip_role',
            'reason' => $reason,
        ];
    }

    if ($expectlab) {
        $counts = $inventory['counts'];
        $labminimums = [
            'enrolments' => 3,
            'assignment_submissions' => 2,
            'assignment_grades' => 1,
            'forum_discussions' => 1,
            'forum_posts' => 2,
            'quiz_attempts' => 1,
            'activity_completions' => 3,
            'course_completions' => 1,
            'module_files' => 2,
        ];
        foreach ($labminimums as $name => $minimum) {
            if ((int)($counts[$name] ?? 0) < $minimum) {
                $blocking[] = 'La evidencia LAB es insuficiente para ' . $name .
                    '; esperado al menos ' . $minimum . '.';
            }
        }
    }
    $blocking = array_values(array_unique($blocking));
    if ($blocking) {
        throw new RuntimeException(
            'El piloto tiene ' . count($blocking) . ' bloqueo(s): ' . implode(' ', $blocking)
        );
    }

    $token = substr(hash('sha256', $sourceid . '|' . $courseidnumber), 0, 12);
    $rawfile = 'phase5-raw-' . $sourceid . '-' . $token . '.mbz';
    $normalizedfile = 'phase5-normalized-' . $sourceid . '-' . $token . '.mbz';
    $rawpath = $backupdir . '/' . $rawfile;
    $normalizedpath = $backupdir . '/' . $normalizedfile;
    if (is_file($rawpath) && !unlink($rawpath)) {
        throw new RuntimeException('No fue posible reemplazar el backup crudo anterior.');
    }
    p5_create_course_backup((int)$course->id, $rawpath);
    $auditpath = $outputdir . '/backup_user_rewrite.csv';
    $normalization = p5_normalize_backup(
        $rawpath,
        $normalizedpath,
        $sourceid,
        $targeturl,
        $contract,
        $targetusersbyid,
        $auditpath
    );

    $courseplan = [[
        'pilot_id' => 'P5-' . strtoupper($sourceid) . '-' . strtoupper($token),
        'source' => $sourceid,
        'source_course_id' => (int)$course->id,
        'source_course_idnumber' => (string)$course->idnumber,
        'source_shortname' => (string)$course->shortname,
        'target_id' => $targetid,
        'target_category_id' => $categoryid,
        'target_course_marker' => $marker,
        'matched_target_course_id' => $existingtargetcourseid,
        'action' => $action,
        'blocking_reason' => '',
    ]];
    $courseplanpath = $outputdir . '/pilot_course_plan.csv';
    $userplanpath = $outputdir . '/pilot_user_plan.csv';
    $roleplanpath = $outputdir . '/pilot_role_plan.csv';
    p5_write_csv($courseplanpath, [
        'pilot_id', 'source', 'source_course_id', 'source_course_idnumber',
        'source_shortname', 'target_id', 'target_category_id',
        'target_course_marker', 'matched_target_course_id', 'action',
        'blocking_reason',
    ], $courseplan);
    p5_write_csv($userplanpath, [
        'source', 'source_user_id', 'source_username', 'canonical_id',
        'target_user_id', 'target_username', 'enrol_method', 'enrol_status',
        'mapping_status', 'planned_action',
    ], $userplan);
    p5_write_csv($roleplanpath, [
        'source', 'source_user_id', 'canonical_id', 'target_user_id',
        'source_role_shortname', 'normalized_role', 'target_role_shortname',
        'approval_status', 'planned_action', 'reason',
    ], $roleplan);

    $artifactpaths = [
        'pilot_config.json' => $outputdir . '/pilot_config.json',
        'pilot_course_plan.csv' => $courseplanpath,
        'pilot_user_plan.csv' => $userplanpath,
        'pilot_role_plan.csv' => $roleplanpath,
        'backup_user_rewrite.csv' => $auditpath,
        'source_course_inventory.json' => $inventorypath,
        'target_preflight.json' => $preflightpath,
        'raw_backup.mbz' => $rawpath,
        'normalized_backup.mbz' => $normalizedpath,
    ];
    $summary = [
        'schema_version' => '1.0',
        'phase' => '5-pilot-course-plan',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'source_id' => $sourceid,
        'target_id' => $targetid,
        'source_course_id' => (int)$course->id,
        'source_course_idnumber' => (string)$course->idnumber,
        'source_shortname' => (string)$course->shortname,
        'target_category_id' => $categoryid,
        'target_course_marker' => $marker,
        'course_action' => $action,
        'matched_target_course_id' => $existingtargetcourseid,
        'enrolments_planned' => count($userplan),
        'roles_planned' => count($roleplan),
        'backup_users' => (int)$normalization['backup_users'],
        'backup_users_mapped' => (int)$normalization['mapped_users'],
        'backup_reserved_users' => (int)$normalization['reserved_users'],
        'backup_question_categories_checked' =>
            (int)$normalization['question_categories_checked'],
        'backup_question_categories_with_questions' =>
            (int)$normalization['question_categories_with_questions'],
        'required_modules' => array_keys($inventory['modules_by_type']),
        'blocking_conflicts' => 0,
        'raw_backup_file' => $rawfile,
        'normalized_backup_file' => $normalizedfile,
        'phase4_input_sha256' => $contract['hashes'],
        'artifacts_sha256' => p5_hash_files($artifactpaths),
        'destination_write_performed' => false,
        'roles_applied' => false,
        'enrolments_applied' => false,
        'course_data_applied' => false,
    ];
    if ($expectlab) {
        $summary['lab_validation'] = 'passed';
    }
    p5_write_json($outputdir . '/plan_summary.json', $summary);
    cli_writeln(
        'FASE5_PLAN_OK source=' . $sourceid .
        ' course=' . $courseidnumber .
        ' users=' . count($userplan) .
        ' roles=' . count($roleplan) .
        ' backup_users=' . (int)$normalization['backup_users'] .
        ' blocked=0 action=' . $action
    );
} catch (Throwable $error) {
    cli_error('FASE5_PLAN_ERROR ' . $error->getMessage());
}
