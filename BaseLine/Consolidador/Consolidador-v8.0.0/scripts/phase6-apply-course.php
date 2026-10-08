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
        'degradationplansha' => null,
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
$comparison = null;
$courselock = null;
$historicallock = null;
$historicalids = [];
$historicalregistries = [];
$historicalaudit = null;
$historicalauditpath = '';
$historicalplannedtargets = [];
$rehydratedrestoreid = null;
$olduser = $USER;

try {
    $phase4dir = rtrim((string)$options['phase4'], '/\\');
    $configsha = p5_require_sha256((string)$options['configsha'], 'configsha');
    $targetid = p5_norm((string)$options['targetid']);
    $targeturl = trim((string)$options['targeturl']);
    $expectlab = (bool)(int)$options['expectlab'];
    $degradationplansha = p5_require_sha256(
        (string)$options['degradationplansha'],
        'degradationplansha'
    );
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
        $phase4dir,
        $phase6dir,
        $configsha,
        $targetid,
        $coursekey,
        $expectlab
    );
    $courseplan = $bundle['courses_by_key'][$coursekey] ?? null;
    $entry = $bundle['manifest_entries_by_course'][$coursekey] ?? null;
    if (!$courseplan ||
            !$entry ||
            ($entry['_artifact_hashes_verified'] ?? false) !== true ||
            ($courseplan['action'] ?? '') !== 'restore_new') {
        throw new RuntimeException('El curso no pertenece al lote preparado.');
    }
    $degradationplan = p6_load_course_degradation_plan(
        $phase6dir,
        $coursekey,
        $degradationplansha,
        $bundle['manifest_sha256']
    );
    if (($degradationplan['source_backup_sha256'] ?? '') !==
            (string)$entry['source_backup_sha256']) {
        throw new RuntimeException('DEGRADATION_PLAN_BACKUP_MISMATCH');
    }
    $degradationwarningcount = count($degradationplan['items'] ?? []);
    $historicalids = p6_historical_source_ids(
        $bundle['course_job']['backup_user_classification'],
        $bundle['phase4_global_source_by_key']
    );
    if ($historicalids) {
        // Los workers comparten un registro; restaurar cursos que contienen
        // usuarios históricos en serie evita crear dos identidades a la vez.
        $historicallock = $lockfactory->get_lock('historical-users', 3600, 86400);
        if (!$historicallock) {
            throw new RuntimeException('No se obtuvo el bloqueo de identidades históricas.');
        }
        foreach ($historicalids as $sourceuserid) {
            $path = p6_historical_registry_path(
                $phase6dir, (string)$courseplan['source'], $sourceuserid
            );
            $previous = is_file($path) ? p5_read_json($path) : null;
            if ($previous !== null) {
                p6_historical_registry_update(
                    $previous, (string)$courseplan['source'], $sourceuserid,
                    (int)($previous['target_user_id'] ?? 0), $coursekey
                );
                p6_historical_assert_target($DB, (int)$previous['target_user_id']);
            }
            $historicalregistries[$sourceuserid] = [
                'path' => $path, 'previous' => $previous,
            ];
        }
        $priorhistoricalids = [];
        foreach ($historicalregistries as $sourceuserid => $registry) {
            if ($registry['previous'] !== null) {
                $priorhistoricalids[$sourceuserid] =
                    (int)$registry['previous']['target_user_id'];
            }
        }
        p6_historical_assert_disjoint(
            $priorhistoricalids, $bundle['phase4_global_source_by_key']
        );
    }
    $basename = p6_backup_basename($coursekey);
    $checkpointpath =
        $phase6dir . '/apply-checkpoints/checkpoint-' . $basename . '.json';
    $statepath = $phase6dir . '/apply-states/state-' . $basename . '.json';
    $diagnosticpath =
        $phase6dir . '/restore-diagnostics/diagnostic-' . $basename . '.json';
    $normalizationauditpath =
        $phase6dir . '/normalization-audits/audit-' . $basename . '.json';
    $effectiveinventorypath =
        $phase6dir . '/effective-source-inventories/inventory-' .
        $basename . '.json';
    $historicalauditpath = $phase6dir . '/historical-audits/audit-' .
        $basename . '.json';
    $targetinventorypath =
        $phase6dir . '/target-inventories/inventory-' . $basename . '.json';
    $quizreportpath = $phase6dir . '/restore-diagnostics/quiz_attempt_differences-' .
        $basename . '.json';

    $persistHistoricalAudit = function(
        bool $recoveredafterinterruption,
        array $expectedtargets = []
    ) use (
        &$historicalaudit,
        &$historicalregistries,
        &$state,
        $historicalids,
        $historicalauditpath,
        $statepath,
        $coursekey,
        $courseplan,
        $bundle,
        &$DB,
        &$CFG,
        &$courseid
    ): array {
        $resolutions = [];
        $idsbytarget = [];
        foreach ($historicalids as $sourceuserid) {
            $previous = $historicalregistries[$sourceuserid]['previous'] ?? null;
            $expectedtargetid = (int)($previous['target_user_id'] ??
                ($expectedtargets[$sourceuserid] ?? 0));
            $resolved = p6_historical_resolve_persistent_target(
                $DB,
                $CFG,
                $bundle['course_job']['backup_user_classification'],
                (string)$courseplan['source'],
                $sourceuserid,
                $bundle['phase4_global_source_by_key'],
                $expectedtargetid,
                true
            );
            $targetuserid = (int)$resolved['target_user_id'];
            if ($resolved['auth_normalized']) {
                cli_writeln(
                    'HISTORICAL_USER_AUTH_NORMALIZED source_user_id=' .
                    $sourceuserid . ' target_user_id=' . $targetuserid .
                    ' auth=manual'
                );
            }
            $registry = p6_historical_registry_update(
                $previous,
                (string)$courseplan['source'],
                $sourceuserid,
                $targetuserid,
                $coursekey,
                'persistent_deleted_user_fingerprint'
            );
            p5_write_json($historicalregistries[$sourceuserid]['path'], $registry);
            $historicalregistries[$sourceuserid]['previous'] = $registry;
            $idsbytarget[$sourceuserid] = $targetuserid;
            $resolutions[] = [
                'source_user_id' => $sourceuserid,
                'target_user_id' => $targetuserid,
                'resolution' => 'persistent_deleted_user_fingerprint',
                'target_user' => $resolved['target_user'],
                'registry_path' => $historicalregistries[$sourceuserid]['path'],
                'auth_normalized' => (bool)$resolved['auth_normalized'],
            ];
        }
        p6_historical_assert_disjoint(
            $idsbytarget,
            $bundle['phase4_global_source_by_key']
        );
        $historicalaudit = p6_historical_validated_audit(
            $coursekey,
            (string)$courseplan['source'],
            $courseid,
            $resolutions,
            $recoveredafterinterruption
        );
        p5_write_json($historicalauditpath, $historicalaudit);
        if (is_array($state) && $statepath !== '') {
            $state['historical_audit_sha256'] =
                hash_file('sha256', $historicalauditpath);
            p5_write_json($statepath, $state);
        }
        if ($recoveredafterinterruption) {
            cli_writeln(
                'HISTORICAL_AUDIT_RECOVERED course_key=' . $coursekey .
                ' users=' . count($resolutions) .
                ' resolution_state=validated'
            );
        }
        return $historicalaudit;
    };

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
                !is_readable($effectiveinventorypath) ||
                ($checkpoint['effective_source_inventory_sha256'] ?? '') !==
                    hash_file('sha256', $effectiveinventorypath) ||
                ($historicalids &&
                 ($checkpoint['historical_audit_sha256'] ?? '') !==
                    (is_file($historicalauditpath) ? hash_file('sha256', $historicalauditpath) : null)) ||
                ($checkpoint['target_inventory_sha256'] ?? '') !==
                    hash_file('sha256', $targetinventorypath)) {
            throw new RuntimeException('El checkpoint aplicado perdió integridad.');
        }
        if ($historicalids) {
            $historicalaudit = p5_read_json($historicalauditpath);
            if (($historicalaudit['resolution_state'] ?? '') !== 'validated' ||
                    ($historicalaudit['course_key'] ?? '') !== $coursekey) {
                throw new RuntimeException('historical_history_lost: auditoría inválida.');
            }
            $historicalidsbytarget = [];
            foreach ($historicalaudit['resolutions'] ?? [] as $row) {
                if (!in_array((int)$row['source_user_id'], $historicalids, true) ||
                        isset($historicalidsbytarget[(int)$row['source_user_id']]) ||
                        $historicalregistries[(int)$row['source_user_id']]['previous'] === null) {
                    throw new RuntimeException('historical_user_duplicated: registro perdido.');
                }
                p6_historical_assert_target($DB, (int)$row['target_user_id']);
                p6_historical_registry_update(
                    $historicalregistries[(int)$row['source_user_id']]['previous'] ?? null,
                    (string)$courseplan['source'], (int)$row['source_user_id'],
                    (int)$row['target_user_id'], $coursekey
                );
                $historicalidsbytarget[(int)$row['source_user_id']] =
                    (int)$row['target_user_id'];
            }
            if (count($historicalaudit['resolutions'] ?? []) !== count($historicalids)) {
                throw new RuntimeException('historical_history_lost: auditoría incompleta.');
            }
            p6_historical_assert_disjoint(
                $historicalidsbytarget, $bundle['phase4_global_source_by_key']
            );
        }
        cli_writeln(
            'FASE6_COURSE_OK course_key=' . $coursekey .
            ' status=reused target_course_id=' . $targetcourseid
        );
        $courselock->release();
        $courselock = null;
        if ($historicallock !== null) {
            $historicallock->release();
            $historicallock = null;
        }
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
                if (p6_resume_completed_restore((string)($previous['state'] ?? ''))) {
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
            foreach ($bundle['course_job']['backup_user_classification']['users'] as $userrow) {
                if (in_array(
                    $userrow['classification'],
                    ['academic_mapped', 'auxiliary_mapped'],
                    true
                )) {
                    $expectedtargetids[(int)$userrow['target_user_id']] = true;
                }
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
                $effectiveinventorypath,
                (string)$entry['source_backup_sha256'],
                (int)$entry['source_backup_bytes'],
                $degradationplan
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
            $precheckdecision = p6_restore_precheck_decision(
                $precheckok,
                $precheck
            );
            $precheckerrors = (int)$precheckdecision['errors'];
            $precheckwarnings = (int)$precheckdecision['warnings'];
            cli_writeln(
                'MOODLE_PRECHECK_RESULT ok=' . ($precheckok ? '1' : '0') .
                ' errors=' . $precheckerrors .
                ' warnings=' . $precheckwarnings
            );
            if ($precheckdecision['mode'] === 'block') {
                throw new RuntimeException(
                    'El precheck de Moodle rechazó el curso: ' .
                    substr(
                        json_encode($precheck, JSON_UNESCAPED_UNICODE) ?: '',
                        0,
                        1600
                    )
                );
            }
            $restoreid = $controller->get_restoreid();
            $applyhistoricaltempids = function(string $activeRestoreId) use (
                $historicalids,
                &$historicalplannedtargets,
                &$historicalregistries,
                $bundle,
                $courseplan,
                &$DB,
                &$CFG
            ): void {
                foreach ($historicalids as $sourceuserid) {
                    $mapping = restore_dbops::get_backup_ids_record(
                        $activeRestoreId,
                        'user',
                        $sourceuserid
                    );
                    if (!$mapping) {
                        throw new RuntimeException(
                            'historical_history_lost: precheck sin usuario ' .
                            $sourceuserid
                        );
                    }
                    $existingid = (int)($historicalregistries[$sourceuserid]
                        ['previous']['target_user_id'] ?? 0);
                    $plannedid = (int)($mapping->newitemid ?? 0);
                    if ($existingid > 0 && $plannedid > 0 &&
                            $plannedid !== $existingid) {
                        throw new RuntimeException(
                            'historical_user_duplicated: el precheck asignó otra identidad.'
                        );
                    }
                    if ($existingid === 0 && $plannedid > 0) {
                        $adopted = p6_historical_resolve_persistent_target(
                            $DB,
                            $CFG,
                            $bundle['course_job']['backup_user_classification'],
                            (string)$courseplan['source'],
                            $sourceuserid,
                            $bundle['phase4_global_source_by_key'],
                            $plannedid,
                            true
                        );
                        $historicalplannedtargets[$sourceuserid] =
                            (int)$adopted['target_user_id'];
                        if ($adopted['auth_normalized']) {
                            cli_writeln(
                                'HISTORICAL_USER_AUTH_NORMALIZED source_user_id=' .
                                $sourceuserid . ' target_user_id=' . $plannedid .
                                ' auth=manual'
                            );
                        }
                        cli_writeln(
                            'HISTORICAL_USER_ADOPTED source_user_id=' .
                            $sourceuserid . ' target_user_id=' . $plannedid .
                            ' resolution=persistent_deleted_user_fingerprint'
                        );
                        continue;
                    }
                    if ($existingid > 0 && $plannedid === 0) {
                        restore_dbops::set_backup_ids_record(
                            $activeRestoreId,
                            'user',
                            $sourceuserid,
                            $existingid
                        );
                        $forced = restore_dbops::get_backup_ids_record(
                            $activeRestoreId,
                            'user',
                            $sourceuserid
                        );
                        if (!$forced || (int)$forced->newitemid !== $existingid) {
                            throw new RuntimeException(
                                'historical_user_duplicated: no se pudo fijar ' .
                                'el usuario histórico.'
                            );
                        }
                    }
                }
            };
            if ($precheckdecision['mode'] === 'ready') {
                $applyhistoricaltempids($restoreid);
            } else if ($precheckdecision['mode'] === 'rehydrate') {
                $rehydratedrestoreid = $restoreid;
                $rehydration = p6_restore_rehydrate_tempids(
                    $controller,
                    $courseid,
                    (int)$admin->id,
                    $applyhistoricaltempids
                );
                cli_writeln(
                    'MOODLE_PRECHECK_REHYDRATED original_warnings=' .
                    $precheckwarnings .
                    ' role_warnings=' . (int)$rehydration['role_warnings'] .
                    ' question_warnings=' .
                    (int)$rehydration['question_warnings']
                );
            } else {
                throw new RuntimeException(
                    'MOODLE_PRECHECK_INCONSISTENT ok=0 errors=0 warnings=0.'
                );
            }
            $stage = 'restore_execute';
            $controller->execute_plan();
            // execute_plan() es nuevamente la autoridad del ciclo normal de
            // vida de las tablas temporales después de una rehidratación.
            $rehydratedrestoreid = null;
            $stage = 'restore_completed';
            $state['state'] = 'restored_pending_verification';
            p5_write_json($statepath, $state);
            if ($historicalids) {
                $historicalaudit = $persistHistoricalAudit(
                    false,
                    $historicalplannedtargets
                );
            }
            $controller->destroy();
            $controller = null;
            if (is_dir($restorepath)) {
                fulldelete($restorepath);
            }
            $restorepath = '';
        }
    }
    if ($historicalids && $courseid > 0) {
        if ($historicalaudit === null && is_file($historicalauditpath)) {
            $historicalaudit = p5_read_json($historicalauditpath);
        }
        $auditexpectedtargets = [];
        foreach ($historicalaudit['resolutions'] ?? [] as $resolution) {
            $auditexpectedtargets[(int)($resolution['source_user_id'] ?? 0)] =
                (int)($resolution['target_user_id'] ?? 0);
        }
        $registrycomplete = true;
        foreach ($historicalids as $sourceuserid) {
            if (($historicalregistries[$sourceuserid]['previous'] ?? null) === null) {
                $registrycomplete = false;
                break;
            }
        }
        $auditvalid = is_array($historicalaudit) &&
            ($historicalaudit['course_key'] ?? '') === $coursekey &&
            ($historicalaudit['source_id'] ?? '') === (string)$courseplan['source'] &&
            (int)($historicalaudit['target_course_id'] ?? 0) === $courseid &&
            ($historicalaudit['resolution_state'] ?? '') === 'validated' &&
            count($historicalaudit['resolutions'] ?? []) === count($historicalids);
        if (!$auditvalid || !$registrycomplete) {
            $historicalaudit = $persistHistoricalAudit(
                true,
                $auditexpectedtargets + $historicalplannedtargets
            );
        }
    }
    $historicalmap = [];
    $historicalidsbytarget = [];
    if ($historicalids) {
        if (($historicalaudit['course_key'] ?? '') !== $coursekey ||
                ($historicalaudit['source_id'] ?? '') !== (string)$courseplan['source'] ||
                (int)($historicalaudit['target_course_id'] ?? 0) !== $courseid ||
                ($historicalaudit['resolution_state'] ?? '') !== 'validated' ||
                count($historicalaudit['resolutions'] ?? []) !== count($historicalids)) {
            throw new RuntimeException('historical_history_lost: auditoría de restauración incompleta.');
        }
        foreach ($historicalaudit['resolutions'] as $resolution) {
            $sourceuserid = (int)($resolution['source_user_id'] ?? 0);
            $targetuserid = (int)($resolution['target_user_id'] ?? 0);
            if (!in_array($sourceuserid, $historicalids, true) ||
                    isset($historicalidsbytarget[$sourceuserid]) ||
                    ($historicalregistries[$sourceuserid]['previous'] ?? null) === null) {
                throw new RuntimeException('historical_user_duplicated: auditoría ambigua.');
            }
            p6_historical_assert_target($DB, $targetuserid);
            p6_historical_registry_update(
                $historicalregistries[$sourceuserid]['previous'],
                (string)$courseplan['source'], $sourceuserid, $targetuserid,
                $coursekey
            );
            $historicalmap[(string)$courseplan['source'] . ':' . $sourceuserid] = [
                'target_user_id' => $targetuserid, 'canonical_id' => '',
            ];
            $historicalidsbytarget[$sourceuserid] = $targetuserid;
        }
        p6_historical_assert_disjoint(
            $historicalidsbytarget, $bundle['phase4_global_source_by_key']
        );
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
    if (!is_readable($effectiveinventorypath)) {
        throw new RuntimeException('Falta el inventario efectivo sellado del curso.');
    }
    $sourceinventory = p5_read_json($effectiveinventorypath);
    $stage = 'verify_inventory';
    $historyissues = p6_historical_relation_issues(
        $sourceinventory['inventory'] ?? $sourceinventory,
        $inventory,
        $historicalidsbytarget
    );
    $comparison = p6_compare_applied_course(
        $sourceinventory,
        $inventory,
        count($expectedens),
        count($expectedroles),
        (string)($bundle['course_job']['source_state_sha256'] ?? ''),
        $bundle['phase4_global_source_by_key'],
        (string)$courseplan['source'],
        $historicalmap,
        (int)$courseplan['source_course_id'],
        $courseid
    );
    $quizreportsha = p5_write_quiz_attempt_report(
        $quizreportpath,
        $comparison['quiz_attempt_diagnostic'],
        [
            'course_key' => $coursekey,
            'source_id' => (string)$courseplan['source'],
            'source_course_id' => (int)$courseplan['source_course_id'],
            'target_course_id' => $courseid,
        ]
    );
    if (($comparison['complete'] ?? false) !== true) {
        throw new RuntimeException(
            'El inventario académico restaurado difiere: ' .
            substr(json_encode($comparison['issues'][0] ?? [], JSON_UNESCAPED_UNICODE) ?: '', 0, 1200)
        );
    }
    if ($historyissues) {
        $comparison['historical_relation_issues'] = $historyissues;
        throw new RuntimeException(
            'historical_history_lost: ' . json_encode($historyissues[0], JSON_UNESCAPED_UNICODE)
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
        'effective_source_inventory_sha256' =>
            hash_file('sha256', $effectiveinventorypath),
        'historical_audit_sha256' => $historicalids ?
            hash_file('sha256', $historicalauditpath) : null,
        'target_inventory_sha256' => hash_file('sha256', $targetinventorypath),
        'quiz_attempt_differences_sha256' => $quizreportsha,
        'degradation_plan_sha256' => $degradationplansha,
        'degradation_warning_count' => $degradationwarningcount,
        'completion_state' => $degradationwarningcount > 0
            ? 'completed_with_warnings' : 'completed',
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
    if ($historicallock !== null) {
        $historicallock->release();
        $historicallock = null;
    }
    if ($degradationwarningcount > 0) {
        foreach ($degradationplan['items'] as $warningitem) {
            if (($warningitem['type'] ?? '') === 'missing_payload') {
                continue;
            }
            $event = 'STRUCTURAL_OBSERVATION';
            cli_writeln($event . ' ' . json_encode([
                'course_key' => $coursekey,
                'type' => (string)($warningitem['type'] ?? ''),
                'file_id' => $warningitem['file_id'] ?? null,
                'component' => (string)($warningitem['component'] ?? ''),
                'filearea' => (string)($warningitem['filearea'] ?? ''),
                'filename' => (string)($warningitem['filename'] ?? ''),
                'relation' => (string)($warningitem['relation'] ?? ''),
                'action' => (string)($warningitem['action'] ?? ''),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        cli_writeln(
            'COURSE_RESTORE_WARNING course_key=' . $coursekey .
            ' missing_payloads_omitted=' .
                (int)$degradationplan['missing_payloads'] .
            ' structural_warnings=' .
                (int)$degradationplan['structural_warnings'] .
            ' blocking_errors=0'
        );
    }
    cli_writeln(
        'FASE6_COURSE_OK course_key=' . $coursekey .
        ' status=' . ($degradationwarningcount > 0
            ? 'completed_with_warnings' : 'restored') .
        ' target_course_id=' . $courseid .
        ' users=' . count($expectedens) .
        ' roles=' . count($expectedroles) .
        ' extractions=1 copied_archives=0 normalized_archives=0'
    );
} catch (Throwable $error) {
    if ($rehydratedrestoreid !== null) {
        try {
            p6_restore_drop_rehydrated_tempids($rehydratedrestoreid);
            $rehydratedrestoreid = null;
        } catch (Throwable $cleanupError) {
            $error = new RuntimeException(
                $error->getMessage() . ' | ' . $cleanupError->getMessage(),
                0,
                $error
            );
        }
    }
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
    $previousstate = $state;
    if ($statepath !== '' && is_readable($statepath)) {
        $previousstate = p5_read_json($statepath);
    }
    $restored = p6_resume_completed_restore((string)($previousstate['state'] ?? ''));
    if ($restored && $courseid > 0 &&
            $DB->record_exists('course', ['id' => $courseid])) {
        $cleanup = 'retained_for_verification';
        $previousstate['state'] = 'verification_failed';
        $previousstate['last_error'] = $error->getMessage();
        p5_write_json($statepath, $previousstate);
    } elseif ($courseid > 0 && $DB->record_exists('course', ['id' => $courseid])) {
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
                'failure_type' => $restored ? 'verification_failed' : 'restore_failed',
                'failure_category' => in_array($stage, [
                    'single_extract', 'normalize_in_place'
                ], true) ? 'SOURCE_DATA_DEFECT' :
                    (in_array($stage, [
                        'restore_precheck', 'restore_execute',
                        'post_restore_verification'
                    ], true) ? 'MOODLE_RESTORE_INCOMPATIBILITY' :
                        'PRECONDITION_BUG'),
                'inventory_comparison' => $comparison,
                'quiz_attempt_differences_path' =>
                    is_file($quizreportpath ?? '') ? $quizreportpath : null,
                'quiz_attempt_differences_sha256' =>
                    is_file($quizreportpath ?? '') ? hash_file('sha256', $quizreportpath) : null,
                'safe_to_retry' => !str_starts_with($cleanup, 'failed'),
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
    if ($historicallock !== null) {
        try {
            $historicallock->release();
        } catch (Throwable $ignored) {
        }
        $historicallock = null;
    }
    \core\session\manager::set_user($olduser);
    cli_error(
        'FASE6_COURSE_ERROR course_key=' . $coursekey .
        ' stage=' . $stage .
        ' cleanup=' . preg_replace('/\s+/', '_', $cleanup) .
        ' message=' . $error->getMessage()
    );
}
