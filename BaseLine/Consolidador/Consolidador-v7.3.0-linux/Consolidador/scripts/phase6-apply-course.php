<?php
// Fase 6: normaliza, restaura y verifica un curso del lote con checkpoint.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once('/opt/consolidator/phase5-lib.php');
require_once('/opt/consolidator/phase6-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'phase4' => '/exports/phase4',
        'phase6' => '/exports/phase6',
        'configsha' => null,
        'targetid' => null,
        'targeturl' => null,
        'coursekey' => null,
        'expectlab' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln("Uso: php phase6-apply-course.php --phase4=DIR --phase6=DIR " .
        "--configsha=SHA256 --targetid=target --targeturl=URL " .
        "--coursekey=COURSE-... [--expectlab=1]\n");
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

$phase6dir = rtrim((string)$options['phase6'], '/\\');
$coursekey = trim((string)$options['coursekey']);
$courseid = 0;
$controller = null;
$restorepath = '';
$statepath = '';
$state = null;
$stage = 'initializing';
$identityaudit = null;
$courselock = null;
$olduser = $USER;

try {
    $phase4dir = rtrim((string)$options['phase4'], '/\\');
    $configsha = p5_require_sha256((string)$options['configsha'], 'configsha');
    $targetid = p5_norm((string)$options['targetid']);
    $targeturl = trim((string)$options['targeturl']);
    $expectlab = (bool)(int)$options['expectlab'];
    if (!preg_match('/^COURSE-[A-Z0-9_-]+-[A-F0-9]{12}$/', $coursekey) ||
            !preg_match('/^[a-z][a-z0-9_-]*$/', $targetid) ||
            !preg_match('/^https?:\/\//', $targeturl)) {
        throw new RuntimeException('Los parámetros del curso son inválidos.');
    }
    $lockfactory = \core\lock\lock_config::get_lock_factory('phase6_restore');
    $courselock = $lockfactory->get_lock(
        'course-' . hash('sha256', $coursekey),
        5
    );
    if (!$courselock) {
        throw new RuntimeException(
            'Otro worker continúa procesando este curso; reintente después.'
        );
    }
    $bundle = p6_load_course_job(
        $phase6dir,
        $configsha,
        $targetid,
        $coursekey
    );
    $courseplan = $bundle['courses_by_key'][$coursekey] ?? null;
    $entry = $bundle['manifest_entries_by_course'][$coursekey] ?? null;
    if (!$courseplan ||
            !$entry ||
            ($entry['_artifact_hashes_verified'] ?? false) !== true ||
            ($courseplan['action'] ?? '') !== 'restore_new') {
        throw new RuntimeException('El curso no pertenece al lote preparado.');
    }
    $basename = p6_backup_basename($coursekey);
    $checkpointpath =
        $phase6dir . '/apply-checkpoints/checkpoint-' . $basename . '.json';
    $statepath = $phase6dir . '/apply-states/state-' . $basename . '.json';
    $diagnosticpath =
        $phase6dir . '/restore-diagnostics/diagnostic-' . $basename . '.json';
    $normalizationauditpath =
        $phase6dir . '/normalization-audits/audit-' . $basename . '.json';
    $targetinventorypath =
        $phase6dir . '/target-inventories/inventory-' . $basename . '.json';

    if (is_readable($checkpointpath)) {
        $checkpoint = p5_read_json($checkpointpath);
        $targetcourseid = (int)($checkpoint['target_course_id'] ?? 0);
        $course = $DB->get_record(
            'course',
            ['id' => $targetcourseid],
            'id,category,fullname,shortname,idnumber',
            MUST_EXIST
        );
        if (($checkpoint['checkpoint_status'] ?? '') !== 'applied' ||
                ($checkpoint['course_key'] ?? '') !== $coursekey ||
                ($checkpoint['manifest_sha256'] ?? '') !==
                    $bundle['manifest_sha256'] ||
                ($checkpoint['target_course_marker'] ?? '') !==
                    (string)$courseplan['target_course_marker'] ||
                (string)$course->idnumber !==
                    (string)$courseplan['target_course_marker'] ||
                ($checkpoint['target_shortname'] ?? '') !==
                    (string)$courseplan['target_shortname'] ||
                p5_norm((string)$course->shortname) !==
                    p5_norm((string)$courseplan['target_shortname']) ||
                ($checkpoint['target_fullname'] ?? '') !==
                    (string)$courseplan['target_fullname'] ||
                p5_norm((string)$course->fullname) !==
                    p5_norm((string)$courseplan['target_fullname']) ||
                ($checkpoint['source_backup_sha256'] ?? '') !==
                    (string)$entry['source_backup_sha256'] ||
                !is_readable($normalizationauditpath) ||
                ($checkpoint['normalization_audit_sha256'] ?? '') !==
                    hash_file('sha256', $normalizationauditpath) ||
                ($checkpoint['target_inventory_sha256'] ?? '') !==
                    hash_file('sha256', $targetinventorypath)) {
            throw new RuntimeException('El checkpoint aplicado perdió integridad.');
        }
        cli_writeln(
            'FASE6_COURSE_OK course_key=' . $coursekey .
            ' status=reused target_course_id=' . $targetcourseid
        );
        $courselock->release();
        $courselock = null;
        exit(0);
    }

    $categorysummarypath = $phase6dir . '/category_apply_summary.json';
    $categorymappath = $phase6dir . '/category_map.csv';
    $categorysummary = p5_read_json($categorysummarypath);
    if (($categorysummary['manifest_sha256'] ?? '') !==
            $bundle['manifest_sha256'] ||
            ($categorysummary['category_map_sha256'] ?? '') !==
                hash_file('sha256', $categorymappath) ||
            ($categorysummary['apply_status'] ?? '') !== 'applied') {
        throw new RuntimeException('La jerarquía aplicada no corresponde al manifiesto.');
    }
    $categorymap = [];
    foreach (p5_read_csv($categorymappath) as $row) {
        $categorymap[(string)$row['category_key']] = (int)$row['target_category_id'];
    }
    $targetcategoryid =
        (int)($categorymap[(string)$courseplan['target_category_key']] ?? 0);
    if ($targetcategoryid < 1 ||
            !$DB->record_exists('course_categories', ['id' => $targetcategoryid])) {
        throw new RuntimeException('La categoría destino del curso no existe.');
    }

    $marker = (string)$courseplan['target_course_marker'];
    $marked = $DB->get_records('course', ['idnumber' => $marker], 'id ASC');
    if (count($marked) > 1) {
        throw new RuntimeException('El destino repite el marcador del curso.');
    }
    if (count($marked) === 1) {
        // Una interrupción después del marcador se finaliza mediante las mismas
        // comprobaciones; nunca se restaura un segundo curso.
        $courseid = (int)reset($marked)->id;
        $stage = 'finalizing_interrupted';
    } else {
        $resumecompleted = false;
        if (is_readable($statepath)) {
            $previous = p5_read_json($statepath);
            $residualid = (int)($previous['target_course_id'] ?? 0);
            if (($previous['manifest_sha256'] ?? '') !==
                    $bundle['manifest_sha256'] ||
                    ($previous['course_key'] ?? '') !== $coursekey) {
                throw new RuntimeException('El estado anterior no corresponde al lote.');
            }
            $previousrestore = (string)($previous['restore_directory'] ?? '');
            $restorebase = $CFG->tempdir . DIRECTORY_SEPARATOR . 'backup' .
                DIRECTORY_SEPARATOR;
            if ($previousrestore !== '' &&
                    str_starts_with($previousrestore, $restorebase) &&
                    is_dir($previousrestore)) {
                fulldelete($previousrestore);
                cli_writeln(
                    'FASE6_COURSE_RECOVERY_OK removed_restore_directory=1'
                );
            }
            if ($residualid > 0 && $DB->record_exists('course', ['id' => $residualid])) {
                $residual = $DB->get_record('course', ['id' => $residualid], '*', MUST_EXIST);
                if ((int)$residual->category !== $targetcategoryid ||
                        (string)$residual->idnumber === $marker) {
                    throw new RuntimeException('El curso residual no es recuperable con seguridad.');
                }
                if (($previous['state'] ?? '') === 'restore_completed') {
                    $courseid = $residualid;
                    $stage = 'finalizing_interrupted';
                    $resumecompleted = true;
                } else {
                    if (!delete_course($residual, false)) {
                        throw new RuntimeException('No fue posible retirar el curso residual.');
                    }
                    cli_writeln(
                        'FASE6_COURSE_RECOVERY_OK removed_course_id=' . $residualid
                    );
                }
            }
        }

        if (!$resumecompleted) {
            $expectedtargetids = [];
            foreach ($bundle['phase4']['source_by_key'] as $mapping) {
                if (!$mapping || (int)($mapping['target_user_id'] ?? 0) < 1) {
                    throw new RuntimeException('Falta un destino canónico del curso.');
                }
                $expectedtargetids[(int)$mapping['target_user_id']] = true;
            }
            $targetusersbyid = [];
            $targetrecords = $expectedtargetids
                ? $DB->get_records_list(
                    'user',
                    'id',
                    array_map('intval', array_keys($expectedtargetids)),
                    'id ASC',
                    'id,username,email,auth,firstaccess,deleted'
                )
                : [];
            foreach ($targetrecords as $user) {
                if ((int)$user->deleted !== 0) {
                    throw new RuntimeException(
                        'Un usuario destino auditado fue eliminado.'
                    );
                }
                $targetusersbyid[(int)$user->id] = [
                    'username' => (string)$user->username,
                    'email' => (string)$user->email,
                    'auth' => (string)$user->auth,
                    'firstaccess' => (int)$user->firstaccess,
                ];
            }
            if (count($targetusersbyid) !== count($expectedtargetids)) {
                throw new RuntimeException(
                    'Falta un usuario destino previsto por el plan.'
                );
            }

            $admin = get_admin();
            if (!$admin) {
                throw new RuntimeException('No existe una cuenta administradora.');
            }
            \core\session\manager::set_user($admin);
            $backupdir = 'phase6_' . bin2hex(random_bytes(12));
            $restorepath = $CFG->tempdir . DIRECTORY_SEPARATOR .
                'backup' . DIRECTORY_SEPARATOR . $backupdir;
            $state = [
                'schema_version' => '1.0',
                'phase' => '6-course-apply-state',
                'generated_at_utc' => gmdate('c'),
                'config_sha256' => $configsha,
                'target_id' => $targetid,
                'manifest_sha256' => $bundle['manifest_sha256'],
                'course_key' => $coursekey,
                'target_course_id' => null,
                'target_category_id' => $targetcategoryid,
                'restore_directory' => $restorepath,
                'source_backup_sha256' => (string)$entry['source_backup_sha256'],
                'state' => 'extracting_source_backup',
            ];
            p5_write_json($statepath, $state);
            $stage = 'single_extract';
            $packer = get_file_packer('application/vnd.moodle.backup');
            if (!$packer->extract_to_pathname(
                $entry['_paths']['source_backup'],
                $restorepath
            )) {
                throw new RuntimeException('Moodle no pudo extraer el MBZ de origen.');
            }
            $stage = 'normalize_in_place';
            $normalization = p6_normalize_extracted_backup(
                $restorepath,
                (string)$courseplan['source'],
                $coursekey,
                $targeturl,
                $bundle,
                $targetusersbyid,
                $normalizationauditpath,
                (string)$entry['source_backup_sha256'],
                (int)$entry['source_backup_bytes']
            );
            $state['normalization_audit_sha256'] =
                (string)$normalization['normalization_audit_sha256'];
            $state['state'] = 'restore_starting';
            p5_write_json($statepath, $state);
            // Los nombres de contenedor son deterministas y exclusivos por
            // curso; así varios workers no compiten por el nombre temporal que
            // Moodle calcula de forma global.
            $containertoken = substr(hash('sha256', $coursekey), 0, 16);
            $fullname = 'P6 restore ' . $containertoken;
            $shortname = 'P6-' . $containertoken;
            $courseid = (int)restore_dbops::create_new_course(
                $fullname,
                $shortname,
                $targetcategoryid
            );
            if ($courseid < 1) {
                throw new RuntimeException('Moodle no devolvió el curso contenedor.');
            }
            $state['target_course_id'] = $courseid;
            $state['state'] = 'restore_in_progress';
            p5_write_json($statepath, $state);
            $controller = new restore_controller(
                $backupdir,
                $courseid,
                backup::INTERACTIVE_NO,
                backup::MODE_GENERAL,
                (int)$admin->id,
                backup::TARGET_NEW_COURSE
            );
            $stage = 'restore_precheck';
            $precheckok = $controller->execute_precheck();
            $precheck = $controller->get_precheck_results();
            $errors = is_array($precheck) ? ($precheck['errors'] ?? []) : [];
            if (!$precheckok && $errors) {
                throw new RuntimeException(
                    'El precheck de Moodle rechazó el curso: ' .
                    substr(
                        json_encode($precheck, JSON_UNESCAPED_UNICODE) ?: '',
                        0,
                        1600
                    )
                );
            }
            $stage = 'restore_execute';
            $controller->execute_plan();
            $controller->destroy();
            $controller = null;
            if (is_dir($restorepath)) {
                fulldelete($restorepath);
            }
            $restorepath = '';
            $stage = 'restore_completed';
            $state['state'] = 'restore_completed';
            p5_write_json($statepath, $state);
        }
    }

    $course = $DB->get_record(
        'course',
        ['id' => $courseid],
        'id,category,fullname,shortname,idnumber',
        MUST_EXIST
    );
    $approvedshortname = (string)$courseplan['target_shortname'];
    $approvedfullname = (string)$courseplan['target_fullname'];
    $identityaudit = [
        'approved_shortname' => $approvedshortname,
        'approved_fullname' => $approvedfullname,
        'approved_category_id' => $targetcategoryid,
        'restored_shortname' => (string)$course->shortname,
        'restored_fullname' => (string)$course->fullname,
        'restored_category_id' => (int)$course->category,
        'adjusted' => false,
    ];
    if ((int)$course->category !== $targetcategoryid ||
            p5_norm((string)$course->shortname) !==
                p5_norm($approvedshortname) ||
            p5_norm((string)$course->fullname) !==
                p5_norm($approvedfullname)) {
        // La restauración de Moodle puede conservar temporalmente la identidad
        // del contenedor o una categoría del backup. El manifiesto sellado es
        // la autoridad para ambos campos, pero nunca se pisa otro curso.
        foreach (p5_target_courses() as $candidate) {
            if ((int)$candidate['id'] !== $courseid &&
                    p5_norm((string)$candidate['shortname']) ===
                        p5_norm($approvedshortname)) {
                throw new RuntimeException(
                    'El shortname aprobado quedó ocupado por el curso destino ' .
                    (int)$candidate['id'] . '; no se ajustó el curso restaurado.'
                );
            }
            if ((int)$candidate['id'] !== $courseid &&
                    p5_norm((string)$candidate['fullname']) ===
                        p5_norm($approvedfullname)) {
                throw new RuntimeException(
                    'El fullname aprobado quedó ocupado por el curso destino ' .
                    (int)$candidate['id'] . '; no se ajustó el curso restaurado.'
                );
            }
        }
        if (!$DB->record_exists(
            'course_categories',
            ['id' => $targetcategoryid]
        )) {
            throw new RuntimeException(
                'La categoría aprobada dejó de existir antes de ajustar el curso.'
            );
        }
        $identityupdate = $DB->get_record(
            'course',
            ['id' => $courseid],
            '*',
            MUST_EXIST
        );
        $identityupdate->shortname = $approvedshortname;
        $identityupdate->fullname = $approvedfullname;
        $identityupdate->category = $targetcategoryid;
        update_course($identityupdate);
        rebuild_course_cache($courseid, true);
        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,category,fullname,shortname,idnumber',
            MUST_EXIST
        );
        $identityaudit['adjusted'] = true;
        $identityaudit['final_shortname'] = (string)$course->shortname;
        $identityaudit['final_fullname'] = (string)$course->fullname;
        $identityaudit['final_category_id'] = (int)$course->category;
        if ((int)$course->category !== $targetcategoryid ||
                p5_norm((string)$course->shortname) !==
                    p5_norm($approvedshortname) ||
                p5_norm((string)$course->fullname) !==
                    p5_norm($approvedfullname)) {
            throw new RuntimeException(
                'Moodle no conservó el nombre o la categoría aprobados después del ajuste.'
            );
        }
        cli_writeln(
            'FASE6_COURSE_IDENTITY_OK course_key=' . $coursekey .
            ' shortname=' . preg_replace('/\s+/', '_', $approvedshortname) .
            ' fullname=' . preg_replace('/\s+/', '_', $approvedfullname) .
            ' category=' . $targetcategoryid .
            ' adjusted=1'
        );
    }

    $context = context_course::instance($courseid);
    $personalizadostatus = p6_personalizado_role_status();
    if (!$personalizadostatus['exists'] || !$personalizadostatus['safe']) {
        throw new RuntimeException(
            'El rol personalizado perdió su perfil de mínimo privilegio.'
        );
    }
    $expectedroles = p6_effective_course_roles($bundle, $coursekey);
    $expectedusers = [];
    foreach (array_merge(
        p6_effective_course_enrolments($bundle, $coursekey),
        $expectedroles
    ) as $row) {
        $expectedusers[(int)$row['target_user_id']] = true;
    }
    foreach ($DB->get_records('role_assignments', ['contextid' => (int)$context->id]) as $ra) {
        $expectedusers[(int)$ra->userid] = true;
    }
    foreach (array_keys($expectedusers) as $userid) {
        role_unassign_all([
            'userid' => (int)$userid,
            'contextid' => (int)$context->id,
        ]);
    }
    $rolesbyshortname = [];
    foreach (['student', 'editingteacher', 'manager', 'personalizado'] as $shortname) {
        $role = $DB->get_record('role', ['shortname' => $shortname], 'id,shortname', MUST_EXIST);
        $rolesbyshortname[$shortname] = (int)$role->id;
    }
    foreach ($expectedroles as $role) {
        role_assign(
            $rolesbyshortname[(string)$role['target_role_shortname']],
            (int)$role['target_user_id'],
            (int)$context->id
        );
    }
    $inventory = p5_collect_course_inventory($courseid);
    $expectedens = p6_effective_course_enrolments($bundle, $coursekey);
    $actualens = array_map(
        static fn(array $row): array => [
            'target_user_id' => (int)$row['source_user_id'],
            'target_username' => (string)$row['source_username'],
            'enrol_method' => p5_norm((string)$row['enrol_method']),
            'enrol_status' => (int)$row['enrol_status'],
        ],
        $inventory['enrolments']
    );
    $enrolkey = static fn(array $row): string =>
        sprintf('%012d|%s|%d',
            (int)$row['target_user_id'],
            (string)$row['enrol_method'],
            (int)$row['enrol_status']
        );
    usort($expectedens, static fn(array $a, array $b): int =>
        $enrolkey($a) <=> $enrolkey($b));
    usort($actualens, static fn(array $a, array $b): int =>
        $enrolkey($a) <=> $enrolkey($b));
    if (array_map($enrolkey, $expectedens) !== array_map($enrolkey, $actualens)) {
        throw new RuntimeException('Las matrículas restauradas no coinciden con el plan.');
    }
    $actualroles = array_map(
        static fn(array $row): string =>
            (int)$row['source_user_id'] . '|' .
            p5_norm((string)$row['role_shortname']),
        $inventory['roles']
    );
    $plannedroles = array_map(
        static fn(array $row): string =>
            (int)$row['target_user_id'] . '|' .
            p5_norm((string)$row['target_role_shortname']),
        $expectedroles
    );
    sort($actualroles, SORT_STRING);
    sort($plannedroles, SORT_STRING);
    if ($actualroles !== $plannedroles) {
        throw new RuntimeException('Los roles restaurados no coinciden con el plan.');
    }
    $sourceinventory = p5_read_json($entry['_paths']['source_inventory']);
    $comparison = p6_compare_applied_course(
        $sourceinventory,
        $inventory,
        count($expectedens),
        count($expectedroles),
        (string)$entry['source_state_sha256']
    );
    if (($comparison['complete'] ?? false) !== true) {
        throw new RuntimeException(
            'El inventario académico restaurado difiere: ' .
            substr(json_encode($comparison['issues'][0] ?? [], JSON_UNESCAPED_UNICODE) ?: '', 0, 1200)
        );
    }
    if ((string)$course->idnumber !== $marker) {
        $course->idnumber = $marker;
        $DB->update_record('course', $course);
        rebuild_course_cache($courseid, true);
    }
    $inventory['schema_version'] = '1.0';
    $inventory['phase'] = '6-target-course-inventory';
    $inventory['generated_at_utc'] = gmdate('c');
    $inventory['course_key'] = $coursekey;
    $inventory['target_course_id'] = $courseid;
    p5_write_json($targetinventorypath, $inventory);
    $checkpoint = [
        'schema_version' => '1.0',
        'phase' => '6-course-apply-checkpoint',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'batch_id' => (string)$bundle['summary']['batch_id'],
        'manifest_sha256' => $bundle['manifest_sha256'],
        'course_key' => $coursekey,
        'source' => (string)$courseplan['source'],
        'source_course_id' => (int)$courseplan['source_course_id'],
        'target_course_id' => $courseid,
        'target_category_id' => $targetcategoryid,
        'target_course_marker' => $marker,
        'target_shortname' => (string)$courseplan['target_shortname'],
        'target_fullname' => (string)$courseplan['target_fullname'],
        'source_backup_sha256' => (string)$entry['source_backup_sha256'],
        'source_backup_bytes' => (int)$entry['source_backup_bytes'],
        'single_extraction' => true,
        'normalized_archive_created' => false,
        'normalization_audit_sha256' =>
            hash_file('sha256', $normalizationauditpath),
        'target_inventory_sha256' => hash_file('sha256', $targetinventorypath),
        'course_identity_adjusted' => (bool)$identityaudit['adjusted'],
        'restored_shortname_before' =>
            (string)$identityaudit['restored_shortname'],
        'restored_fullname_before' =>
            (string)$identityaudit['restored_fullname'],
        'restored_category_before' =>
            (int)$identityaudit['restored_category_id'],
        'effective_enrolments' => count($expectedens),
        'effective_roles' => count($expectedroles),
        'checkpoint_status' => 'applied',
    ];
    p5_write_json($checkpointpath, $checkpoint);
    if (is_file($statepath)) {
        unlink($statepath);
    }
    \core\session\manager::set_user($olduser);
    $courselock->release();
    $courselock = null;
    cli_writeln(
        'FASE6_COURSE_OK course_key=' . $coursekey .
        ' status=restored target_course_id=' . $courseid .
        ' users=' . count($expectedens) .
        ' roles=' . count($expectedroles) .
        ' extractions=1 copied_archives=0 normalized_archives=0'
    );
} catch (Throwable $error) {
    if ($controller !== null) {
        try {
            $controller->destroy();
        } catch (Throwable $ignored) {
        }
    }
    if ($restorepath !== '' && is_dir($restorepath)) {
        fulldelete($restorepath);
    }
    $cleanup = 'not_needed';
    if ($courseid > 0 && $DB->record_exists('course', ['id' => $courseid])) {
        try {
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $cleanup = delete_course($course, false) ? 'ok' : 'failed';
        } catch (Throwable $cleanupError) {
            $cleanup = 'failed: ' . $cleanupError->getMessage();
        }
    }
    if ($phase6dir !== '' && $coursekey !== '') {
        $diagnosticdir = $phase6dir . '/restore-diagnostics';
        if (!is_dir($diagnosticdir)) {
            mkdir($diagnosticdir, 0770, true);
        }
        $diagnosticbasename = preg_match(
            '/^COURSE-[A-Z0-9_-]+-[A-F0-9]{12}$/',
            $coursekey
        )
            ? p6_backup_basename($coursekey)
            : 'invalid-' . substr(hash('sha256', $coursekey), 0, 16);
        p5_write_json(
            $diagnosticdir . '/diagnostic-' .
                $diagnosticbasename . '.json',
            [
                'schema_version' => '1.0',
                'phase' => '6-course-restore-diagnostic',
                'generated_at_utc' => gmdate('c'),
                'course_key' => $coursekey,
                'stage' => $stage,
                'error_class' => get_class($error),
                'error' => $error->getMessage(),
                'target_course_id' => $courseid ?: null,
                'course_identity' => $identityaudit,
                'course_cleanup' => $cleanup,
                'safe_to_retry' => $cleanup !== 'failed',
            ]
        );
    }
    if ($courselock !== null) {
        try {
            $courselock->release();
        } catch (Throwable $ignored) {
        }
        $courselock = null;
    }
    \core\session\manager::set_user($olduser);
    cli_error(
        'FASE6_COURSE_ERROR course_key=' . $coursekey .
        ' stage=' . $stage .
        ' cleanup=' . preg_replace('/\s+/', '_', $cleanup) .
        ' message=' . $error->getMessage()
    );
}
