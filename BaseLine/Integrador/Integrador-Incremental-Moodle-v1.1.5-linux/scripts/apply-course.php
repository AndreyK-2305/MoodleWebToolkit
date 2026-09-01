<?php
// Worker idempotente: una extracción, normalización en sitio, restore y verificación.

declare(strict_types=1);
define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once('/opt/integrator-v1/phase5-lib.php');
require_once('/opt/integrator-v1/phase6-lib.php');
require_once('/opt/integrator-v1/incremental-common.php');
require_once('/opt/integrator-v1/incremental-apply-lib.php');

$courseid = 0;
$controller = null;
$restorepath = '';
$stage = 'initializing';
$lock = null;
$olduser = $USER;
$createdthisrun = false;
$ownedresidual = false;

function inc_course_canonical_rows(array $rows, array $keys): array {
    sort($keys, SORT_STRING);
    $encoded = [];
    foreach ($rows as $row) {
        $projected = [];
        foreach ($keys as $key) {
            $projected[$key] = $row[$key] ?? null;
        }
        $encoded[] = json_encode(
            $projected,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
                JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }
    sort($encoded, SORT_STRING);
    return $encoded;
}

/**
 * Resume la primera diferencia multiconjunto sin perder filas duplicadas.
 * Las cadenas ya son proyecciones canónicas y pueden incluir hashes, por lo
 * que el diagnóstico demuestra qué contenido sellado no coincidió.
 */
function inc_course_first_relation_difference(
    array $expectedcanonical,
    array $actualcanonical
): array {
    $expectedcounts = array_count_values($expectedcanonical);
    $actualcounts = array_count_values($actualcanonical);
    $onlyexpected = null;
    $onlyactual = null;
    foreach ($expectedcounts as $row => $count) {
        if ($count > ($actualcounts[$row] ?? 0)) {
            $onlyexpected = json_decode($row, true);
            break;
        }
    }
    foreach ($actualcounts as $row => $count) {
        if ($count > ($expectedcounts[$row] ?? 0)) {
            $onlyactual = json_decode($row, true);
            break;
        }
    }
    return [
        'only_expected' => $onlyexpected,
        'only_actual' => $onlyactual,
    ];
}

/**
 * Acepta únicamente la reatribución que Moodle hace al operador de restore.
 * Cualquier otro cambio de propietario sigue siendo un incumplimiento.
 */
function inc_course_file_owner_rewrites_valid(
    array $expectedrows,
    array $actualrows,
    int $restoreuserid
): array {
    $keys = [];
    foreach ($expectedrows as $row) {
        foreach (array_keys($row) as $key) {
            if ($key !== 'source_user_id') {
                $keys[$key] = true;
            }
        }
    }
    $keys = array_keys($keys);
    $group = static function (array $rows) use ($keys): array {
        $groups = [];
        foreach ($rows as $row) {
            $signature = inc_course_canonical_rows([$row], $keys)[0];
            $owner = (int)($row['source_user_id'] ?? 0);
            $groups[$signature][$owner] =
                ($groups[$signature][$owner] ?? 0) + 1;
        }
        return $groups;
    };
    $expectedgroups = $group($expectedrows);
    $actualgroups = $group($actualrows);
    foreach ($expectedgroups as $signature => $expectedowners) {
        $actualowners = $actualgroups[$signature] ?? [];
        $remainingexpected = $expectedowners;
        $remainingactual = [];
        foreach ($actualowners as $owner => $count) {
            $matched = min($count, $remainingexpected[$owner] ?? 0);
            if ($matched > 0) {
                $remainingexpected[$owner] -= $matched;
            }
            if ($count > $matched) {
                $remainingactual[(int)$owner] = $count - $matched;
            }
        }
        $remainingexpectedcount = array_sum($remainingexpected);
        $remainingactualcount = array_sum($remainingactual);
        $unexpectedowners = array_filter(
            $remainingactual,
            static fn(int $count, int $owner): bool =>
                $count > 0 && $owner !== $restoreuserid,
            ARRAY_FILTER_USE_BOTH
        );
        if ($remainingexpectedcount !== $remainingactualcount ||
                $unexpectedowners) {
            return [
                'valid' => false,
                'file_signature' => json_decode($signature, true),
                'expected_owners' => $expectedowners,
                'actual_owners' => $actualowners,
                'allowed_restore_owner' => $restoreuserid,
            ];
        }
    }
    return [
        'valid' => true,
        'allowed_restore_owner' => $restoreuserid,
    ];
}

function inc_course_map_relation_rows(
    array $rows,
    array $mapping,
    string $sourceid
): array {
    $mapped = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('El inventario contiene una relación inválida.');
        }
        $sourceuserid = (int)($row['source_user_id'] ?? 0);
        if ($sourceuserid > 0) {
            $identity = $mapping[$sourceid . ':' . $sourceuserid] ?? null;
            if (!is_array($identity) || (int)($identity['target_user_id'] ?? 0) < 1) {
                throw new RuntimeException(
                    'No existe mapa para una relación académica de source_user_id=' .
                    $sourceuserid . '.'
                );
            }
            $row['source_user_id'] = (int)$identity['target_user_id'];
        }
        $mapped[] = $row;
    }
    return $mapped;
}

try {
    $options = inc_cli_options(['workdir' => '', 'coursekey' => '']);
    $workdir = inc_safe_workdir((string)$options['workdir']);
    $coursekey = (string)$options['coursekey'];
    if (!preg_match('/^COURSE-[A-Z0-9_-]+-[A-F0-9]{12}$/', $coursekey)) {
        throw new RuntimeException('coursekey inválido.');
    }
    $plan = inc_load_plan($workdir);
    $courseplan = inc_course_plan($plan, $coursekey);
    $categorymap = inc_load_category_map($workdir);
    $targetcategoryid = (int)(
        $categorymap['categories'][(string)$courseplan['source_category_id']]
            ['target_category_id'] ?? 0
    );
    if ($targetcategoryid < 1 ||
            !$DB->record_exists('course_categories', ['id' => $targetcategoryid])) {
        throw new RuntimeException('La categoría destino del curso no existe.');
    }
    $lockfactory = \core\lock\lock_config::get_lock_factory('inc_v1_course');
    $lock = $lockfactory->get_lock('course-' . hash('sha256', $coursekey), 5);
    if (!$lock) {
        throw new RuntimeException('Otro worker procesa este curso.');
    }

    $slug = strtolower($coursekey);
    $checkpointpath = $workdir . '/checkpoints/checkpoint-' . $slug . '.json';
    $statepath = $workdir . '/states/state-' . $slug . '.json';
    $auditpath = $workdir . '/audits/normalization-' . $slug . '.json';
    $inventorypath = $workdir . '/inventories/target-' . $slug . '.json';
    $diagnosticpath = $workdir . '/diagnostics/error-' . $slug . '.json';
    foreach (['checkpoints', 'states', 'audits', 'inventories', 'diagnostics'] as $directory) {
        if (!is_dir($workdir . '/' . $directory) &&
                !mkdir($workdir . '/' . $directory, 0770, true) &&
                !is_dir($workdir . '/' . $directory)) {
            throw new RuntimeException('No se pudo crear ' . $directory . '.');
        }
    }
    // Un diagnóstico anterior no debe contaminar la decisión de seguridad de
    // un intento nuevo del mismo curso.
    @unlink($diagnosticpath);

    if (is_readable($checkpointpath)) {
        $checkpoint = inc_read_json($checkpointpath);
        $targetcourseid = (int)($checkpoint['target_course_id'] ?? 0);
        $course = $DB->get_record(
            'course',
            ['id' => $targetcourseid],
            'id,category,fullname,shortname,idnumber,visible',
            MUST_EXIST
        );
        if (($checkpoint['status'] ?? '') !== 'applied' ||
                ($checkpoint['plan_sha256'] ?? '') !== (string)$plan['plan_sha256'] ||
                ($checkpoint['package_sha256'] ?? '') !== (string)$plan['package_sha256'] ||
                ($checkpoint['course_key'] ?? '') !== $coursekey ||
                (string)$course->idnumber !== (string)$courseplan['target_marker'] ||
                inc_norm((string)$course->fullname) !==
                    inc_norm((string)$courseplan['target_fullname']) ||
                inc_norm((string)$course->shortname) !==
                    inc_norm((string)$courseplan['target_shortname']) ||
                (int)$course->category !== $targetcategoryid ||
                (int)$course->visible !== 0) {
            throw new RuntimeException('El checkpoint del curso perdió integridad.');
        }
        $checkpointinventory = $workdir . '/inventories/target-' . $slug . '.json';
        if (!is_readable($checkpointinventory) ||
                !inc_is_sha256($checkpoint['target_inventory_sha256'] ?? null) ||
                !hash_equals(
                    (string)$checkpoint['target_inventory_sha256'],
                    (string)hash_file('sha256', $checkpointinventory)
                )) {
            throw new RuntimeException('El inventario sellado del checkpoint cambió.');
        }
        if (inc_course_is_owned(
                (string)$plan['plan_sha256'],
                $coursekey,
                $targetcourseid
            )) {
            inc_clear_course_ownership($coursekey);
        }
        cli_writeln(
            'INCREMENTAL_COURSE_OK course_key=' . $coursekey .
            ' status=reused target_course_id=' . $targetcourseid
        );
        $lock->release();
        $lock = null;
        exit(0);
    }

    $marked = $DB->get_records(
        'course',
        ['idnumber' => (string)$courseplan['target_marker']],
        'id ASC'
    );
    if (count($marked) > 1) {
        throw new RuntimeException('El destino repite el marcador del curso.');
    }
    $adopting = false;
    if (count($marked) === 1 && !is_readable($statepath)) {
        throw new RuntimeException(
            'Existe un curso marcado sin checkpoint ni estado recuperable; no se modificará.'
        );
    }
    if (is_readable($statepath)) {
        $previous = inc_read_json($statepath);
        if (($previous['plan_sha256'] ?? '') !== (string)$plan['plan_sha256'] ||
                ($previous['course_key'] ?? '') !== $coursekey) {
            throw new RuntimeException('El estado incompleto pertenece a otro plan.');
        }
        $previousrestore = (string)($previous['restore_directory'] ?? '');
        $restorebase = rtrim($CFG->tempdir, '/\\') . DIRECTORY_SEPARATOR .
            'backup' . DIRECTORY_SEPARATOR;
        if ($previousrestore !== '' && str_starts_with($previousrestore, $restorebase) &&
                is_dir($previousrestore)) {
            fulldelete($previousrestore);
            cli_writeln('INCREMENTAL_RECOVERY_OK course_key=' . $coursekey .
                ' removed_restore_directory=1');
        }
        $residualid = (int)($previous['target_course_id'] ?? 0);
        if (count($marked) === 1 &&
                (int)reset($marked)->id !== $residualid) {
            throw new RuntimeException(
                'El marcador existente no coincide con el curso registrado por este worker.'
            );
        }
        if ($residualid > 0 && $DB->record_exists('course', ['id' => $residualid])) {
            if (!inc_course_is_owned(
                    (string)$plan['plan_sha256'],
                    $coursekey,
                    $residualid
                )) {
                throw new RuntimeException(
                    'Se negó a tocar un curso residual sin marca de propiedad verificable.'
                );
            }
            $residual = $DB->get_record(
                'course',
                ['id' => $residualid],
                '*',
                MUST_EXIST
            );
            if (($previous['state'] ?? '') === 'restore_completed') {
                $courseid = $residualid;
                $adopting = true;
                $ownedresidual = true;
                $stage = 'adopting_owned_completed_restore';
            } else {
                if (!delete_course($residual, false)) {
                    throw new RuntimeException(
                        'No se pudo retirar el curso interrumpido propiedad del lote.'
                    );
                }
                inc_clear_course_ownership($coursekey);
                cli_writeln('INCREMENTAL_RECOVERY_OK course_key=' . $coursekey .
                    ' removed_interrupted_course_id=' . $residualid);
            }
        } else if ($residualid > 0) {
            if (inc_course_is_owned(
                    (string)$plan['plan_sha256'],
                    $coursekey,
                    $residualid
                )) {
                inc_clear_course_ownership($coursekey);
            }
        }
    }

    $package = rtrim((string)$plan['package_directory'], '/\\');
    $sourceinventorypath = $package . '/' . (string)$courseplan['inventory_file'];
    if (!is_readable($sourceinventorypath) ||
            !inc_is_sha256($courseplan['inventory_sha256'] ?? null) ||
            !hash_equals(
                (string)$courseplan['inventory_sha256'],
                (string)hash_file('sha256', $sourceinventorypath)
            )) {
        throw new RuntimeException('El inventario del curso cambió después del preflight.');
    }
    $sourceinventorydocument = inc_read_json($sourceinventorypath);
    if (($sourceinventorydocument['schema_version'] ?? '') !== '1.0' ||
            ($sourceinventorydocument['package_type'] ?? '') !==
                'moodle-consolidation-course-inventory' ||
            ($sourceinventorydocument['source_id'] ?? '') !==
                (string)$plan['source_id'] ||
            ($sourceinventorydocument['course_key'] ?? '') !== $coursekey ||
            ($sourceinventorydocument['write_performed'] ?? null) !== false ||
            ($sourceinventorydocument['source_state_sha256'] ?? '') !==
                (string)$courseplan['source_state_sha256'] ||
            !is_array($sourceinventorydocument['inventory'] ?? null)) {
        throw new RuntimeException('El inventario detallado no corresponde al plan.');
    }
    $sourceinventory = $sourceinventorydocument['inventory'];

    $normalization = null;
    if (!$adopting) {
        $admin = get_admin();
        if (!$admin) {
            throw new RuntimeException('El destino no tiene una cuenta administradora.');
        }
        \core\session\manager::set_user($admin);
        $backupdir = 'incv1_' . substr(hash('sha256', $coursekey), 0, 20) .
            '_' . bin2hex(random_bytes(4));
        $restorepath = rtrim($CFG->tempdir, '/\\') . DIRECTORY_SEPARATOR .
            'backup' . DIRECTORY_SEPARATOR . $backupdir;
        $state = [
            'schema_version' => INC_SCHEMA,
            'tool_version' => INC_VERSION,
            'generated_at_utc' => gmdate('c'),
            'plan_sha256' => (string)$plan['plan_sha256'],
            'package_sha256' => (string)$plan['package_sha256'],
            'course_key' => $coursekey,
            'target_course_id' => null,
            'restore_directory' => $restorepath,
            'state' => 'extracting',
        ];
        inc_write_json($statepath, $state);
        $stage = 'single_extract';
        $sourcebackup = $package . '/' . (string)$courseplan['backup_file'];
        $actualbytes = filesize($sourcebackup);
        if (!is_readable($sourcebackup) || $actualbytes === false ||
                (int)$actualbytes !== (int)$courseplan['backup_bytes'] ||
                !inc_is_sha256($courseplan['backup_sha256'] ?? null) ||
                !hash_equals(
                    (string)$courseplan['backup_sha256'],
                    (string)hash_file('sha256', $sourcebackup)
                )) {
            throw new RuntimeException('El MBZ cambió después del preflight.');
        }
        $packer = get_file_packer('application/vnd.moodle.backup');
        if (!$packer->extract_to_pathname($sourcebackup, $restorepath) ||
                !is_readable($restorepath . '/users.xml')) {
            throw new RuntimeException('Moodle no pudo extraer el MBZ completo.');
        }

        $stage = 'resolve_exact_backup_users';
        $usersdom = new DOMDocument();
        if (!$usersdom->load($restorepath . '/users.xml', LIBXML_NONET)) {
            throw new RuntimeException('users.xml no es válido.');
        }
        $sourceuserids = [];
        foreach ($usersdom->getElementsByTagName('user') as $usernode) {
            if (!$usernode instanceof DOMElement) {
                continue;
            }
            $sourceuserid = (int)$usernode->getAttribute('id');
            if ($sourceuserid < 1) {
                throw new RuntimeException('users.xml contiene un ID inválido.');
            }
            if (inc_norm(p5_dom_text($usernode, 'username')) === 'guest') {
                continue;
            }
            inc_identity_plan_for_source_user($plan, $sourceuserid);
            $sourceuserids[$sourceuserid] = true;
        }
        $sourceuserids = array_map('intval', array_keys($sourceuserids));
        sort($sourceuserids, SORT_NUMERIC);
        $mappingbundle = inc_user_mapping_bundle($plan, $sourceuserids);

        $userrows = [];
        foreach ($sourceuserids as $sourceuserid) {
            $mapping = $mappingbundle['mapping'][$plan['source_id'] . ':' . $sourceuserid];
            $userrows[] = [
                'source_user_id' => $sourceuserid,
                'target_user_id' => (int)$mapping['target_user_id'],
                'target_username' => (string)$mapping['target_username'],
                'enrol_method' => '__identity_only__',
                'enrol_status' => 0,
            ];
        }
        $rolerows = [];
        foreach ($sourceinventory['roles'] as $role) {
            $sourceuserid = (int)$role['source_user_id'];
            $mapping = $mappingbundle['mapping'][$plan['source_id'] . ':' . $sourceuserid] ?? null;
            if (!$mapping) {
                throw new RuntimeException('Falta la identidad de un rol del curso.');
            }
            $targetrole = inc_role_target((string)$role['role_shortname']);
            $rolerows[] = [
                'source_user_id' => $sourceuserid,
                'target_user_id' => (int)$mapping['target_user_id'],
                'source_role_shortname' => (string)$role['role_shortname'],
                'target_role_shortname' => $targetrole,
                'safety_profile' => $targetrole === 'personalizado'
                    ? 'student_readonly'
                    : 'standard_contextual',
            ];
        }
        $convergences = [];
        foreach ($mappingbundle['target_to_source'] as $targetuserid => $sourceids) {
            if (count($sourceids) > 1) {
                $convergences[$targetuserid] = [
                    'source_user_ids_parsed' => $sourceids,
                ];
            }
        }
        $bundle = [
            'phase4' => ['source_by_key' => $mappingbundle['mapping']],
            'user_rows_by_course' => [$coursekey => $userrows],
            'role_rows_by_course' => [$coursekey => $rolerows],
            'convergence_by_course_target' => [$coursekey => $convergences],
            'batch_config' => [
                'role_policy' => [
                    'student' => 'student',
                    'teacher' => 'editingteacher',
                    'editingteacher' => 'editingteacher',
                    'manager' => 'manager',
                    'fallback' => 'personalizado',
                ],
            ],
        ];
        $stage = 'normalize_in_single_extraction';
        $normalization = p6_normalize_extracted_backup(
            $restorepath,
            (string)$plan['source_id'],
            $coursekey,
            (string)$plan['target_wwwroot'],
            $bundle,
            $mappingbundle['target_users'],
            $auditpath,
            (string)$courseplan['backup_sha256'],
            (int)$courseplan['backup_bytes']
        );
        $state['state'] = 'restore_starting';
        $state['normalization_audit_sha256'] = $normalization['normalization_audit_sha256'];
        inc_write_json($statepath, $state);

        $stage = 'create_hidden_container';
        $courseid = (int)restore_dbops::create_new_course(
            'INC V1 ' . substr(hash('sha256', $coursekey), 0, 16),
            'INC-V1-' . substr(hash('sha256', $coursekey), 0, 16),
            $targetcategoryid
        );
        $createdthisrun = true;
        $state['target_course_id'] = $courseid;
        $state['state'] = 'restore_in_progress';
        inc_write_json($statepath, $state);
        inc_mark_course_owned((string)$plan['plan_sha256'], $coursekey, $courseid);
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
                'Moodle rechazó el precheck: ' . substr(
                    json_encode($precheck, JSON_UNESCAPED_UNICODE) ?: '',
                    0,
                    1800
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
        $state['state'] = 'restore_completed';
        inc_write_json($statepath, $state);
    }

    $stage = 'canonicalize_course_identity';
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $course->category = $targetcategoryid;
    $course->fullname = (string)$courseplan['target_fullname'];
    $course->shortname = (string)$courseplan['target_shortname'];
    $course->idnumber = (string)$courseplan['target_marker'];
    $course->visible = 0;
    update_course($course);
    rebuild_course_cache($courseid, true);

    $stage = 'normalize_course_roles';
    // Incluye también autores históricos de entregas, foros, intentos,
    // completitud y archivos aunque ya no conserven matrícula o rol activo.
    $neededsourceids = inc_source_user_ids_from_inventory(
        $sourceinventorydocument
    );
    if ($adopting || !isset($mappingbundle)) {
        $mappingbundle = inc_user_mapping_bundle($plan, $neededsourceids);
    } else {
        foreach ($neededsourceids as $neededsourceid) {
            if (!isset($mappingbundle['mapping'][
                $plan['source_id'] . ':' . $neededsourceid
            ])) {
                throw new RuntimeException(
                    'El backup restaurado omitió una identidad requerida por el inventario.'
                );
            }
        }
    }
    $expectedroles = [];
    foreach ($sourceinventory['roles'] as $role) {
        $sourceuserid = (int)$role['source_user_id'];
        $mapping = $mappingbundle['mapping'][$plan['source_id'] . ':' . $sourceuserid];
        $targetrole = inc_role_target((string)$role['role_shortname']);
        $expectedroles[(int)$mapping['target_user_id'] . '|' . $targetrole] = [
            'target_user_id' => (int)$mapping['target_user_id'],
            'target_role_shortname' => $targetrole,
        ];
    }
    $expectedroles = array_values($expectedroles);
    $context = context_course::instance($courseid);
    $affectedusers = [];
    foreach ($expectedroles as $role) {
        $affectedusers[(int)$role['target_user_id']] = true;
    }
    foreach ($DB->get_records('role_assignments', ['contextid' => (int)$context->id]) as $role) {
        $affectedusers[(int)$role->userid] = true;
    }
    foreach (array_keys($affectedusers) as $userid) {
        role_unassign_all(['userid' => (int)$userid, 'contextid' => (int)$context->id]);
    }
    $roleids = [];
    foreach (['student', 'editingteacher', 'manager', 'personalizado'] as $shortname) {
        $roleids[$shortname] = (int)$DB->get_field(
            'role',
            'id',
            ['shortname' => $shortname],
            MUST_EXIST
        );
    }
    foreach ($expectedroles as $role) {
        role_assign(
            $roleids[(string)$role['target_role_shortname']],
            (int)$role['target_user_id'],
            (int)$context->id
        );
    }

    $stage = 'deep_verify';
    $expectedenrolments = [];
    foreach ($sourceinventory['enrolments'] as $enrolment) {
        $sourceuserid = (int)$enrolment['source_user_id'];
        $mapping = $mappingbundle['mapping'][$plan['source_id'] . ':' . $sourceuserid];
        $key = (int)$mapping['target_user_id'] . '|' . inc_norm((string)$enrolment['enrol_method']);
        $row = [
            'target_user_id' => (int)$mapping['target_user_id'],
            'enrol_method' => inc_norm((string)$enrolment['enrol_method']),
            'enrol_status' => (int)$enrolment['enrol_status'],
        ];
        if (isset($expectedenrolments[$key]) &&
                $expectedenrolments[$key]['enrol_status'] !== $row['enrol_status']) {
            throw new RuntimeException('La convergencia por correo tiene matrículas incompatibles.');
        }
        $expectedenrolments[$key] = $row;
    }
    $inventory = p5_collect_course_inventory($courseid);
    $actualenrolments = [];
    foreach ($inventory['enrolments'] as $enrolment) {
        $key = (int)$enrolment['source_user_id'] . '|' . inc_norm((string)$enrolment['enrol_method']);
        $actualenrolments[$key] = [
            'target_user_id' => (int)$enrolment['source_user_id'],
            'enrol_method' => inc_norm((string)$enrolment['enrol_method']),
            'enrol_status' => (int)$enrolment['enrol_status'],
        ];
    }
    ksort($expectedenrolments, SORT_STRING);
    ksort($actualenrolments, SORT_STRING);
    if ($expectedenrolments !== $actualenrolments) {
        throw new RuntimeException('Las matrículas restauradas no coinciden con el origen.');
    }
    $actualroles = [];
    foreach ($inventory['roles'] as $role) {
        $actualroles[(int)$role['source_user_id'] . '|' .
            inc_norm((string)$role['role_shortname'])] = true;
    }
    $plannedroles = [];
    foreach ($expectedroles as $role) {
        $plannedroles[(int)$role['target_user_id'] . '|' .
            (string)$role['target_role_shortname']] = true;
    }
    ksort($actualroles, SORT_STRING);
    ksort($plannedroles, SORT_STRING);
    if ($actualroles !== $plannedroles) {
        throw new RuntimeException('Los roles del curso no coinciden con el plan.');
    }
    $comparison = p5_compare_course_inventories(
        array_replace_recursive($sourceinventory, [
            'counts' => [
                'enrolments' => count($expectedenrolments),
                'course_role_assignments' => count($expectedroles),
            ],
        ]),
        $inventory
    );
    if (($comparison['complete'] ?? false) !== true) {
        throw new RuntimeException(
            'El contenido restaurado difiere del inventario: ' . substr(
                json_encode($comparison['issues'][0] ?? [], JSON_UNESCAPED_UNICODE) ?: '',
                0,
                1600
            )
        );
    }
    foreach (['startdate', 'enddate', 'format', 'enablecompletion'] as $field) {
        if ((string)($sourceinventory['course'][$field] ?? '') !==
                (string)($inventory['course'][$field] ?? '')) {
            throw new RuntimeException(
                'La configuración académica del curso no coincide: ' . $field . '.'
            );
        }
    }
    $verifiedrelations = [];
    foreach ([
        'assignment_submissions',
        'assignment_grades',
        'forum_discussions',
        'forum_posts',
        'quiz_attempts',
        'activity_completions',
        'course_completions',
        'files',
    ] as $relation) {
        $expectedrows = inc_course_map_relation_rows(
            $sourceinventory['relations'][$relation] ?? [],
            $mappingbundle['mapping'],
            (string)$plan['source_id']
        );
        $actualrows = $inventory['relations'][$relation] ?? [];
        if ($relation === 'files') {
            $expectedrows = p5_filter_comparable_files($expectedrows);
            $actualrows = p5_filter_comparable_files($actualrows);
        }
        $keys = [];
        foreach ($expectedrows as $row) {
            foreach (array_keys($row) as $key) {
                $keys[$key] = true;
            }
        }
        if ($relation === 'files') {
            // El userid de {files} identifica al operador técnico que creó o
            // restauró el registro. Moodle puede reasignarlo entre 4.5 y 5.x.
            // Solo se acepta como sustituto el administrador que ejecutó el
            // restore; cualquier otro propietario continúa siendo estricto.
            unset($keys['source_user_id']);
        }
        // Un inventario antiguo puede no traer todavía hash/tamaño del archivo;
        // en ese caso se comparan todas las columnas que sí selló el origen.
        $comparisonkeys = array_keys($keys);
        $expectedcanonical = inc_course_canonical_rows(
            $expectedrows,
            $comparisonkeys
        );
        $actualcanonical = inc_course_canonical_rows(
            $actualrows,
            $comparisonkeys
        );
        $ownercomparison = ['valid' => true];
        if ($relation === 'files' &&
                count($expectedrows) === count($actualrows) &&
                $expectedcanonical === $actualcanonical) {
            $restoreadmin = get_admin();
            if (!$restoreadmin) {
                throw new RuntimeException(
                    'No existe administrador para validar propietarios de archivos.'
                );
            }
            $ownercomparison = inc_course_file_owner_rewrites_valid(
                $expectedrows,
                $actualrows,
                (int)$restoreadmin->id
            );
        }
        if (count($expectedrows) !== count($actualrows) ||
                $expectedcanonical !== $actualcanonical ||
                ($ownercomparison['valid'] ?? false) !== true) {
            $difference = inc_course_first_relation_difference(
                $expectedcanonical,
                $actualcanonical
            );
            throw new RuntimeException(
                'La relación académica restaurada no coincide: ' . $relation .
                '. detalle=' . substr(
                    json_encode([
                        'expected_count' => count($expectedrows),
                        'actual_count' => count($actualrows),
                        'compared_fields' => $comparisonkeys,
                        'file_owner_policy' => $ownercomparison,
                        ...$difference,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                    0,
                    1600
                )
            );
        }
        $verifiedrelations[] = $relation;
    }
    $finalcourse = $DB->get_record(
        'course',
        ['id' => $courseid],
        'id,category,fullname,shortname,idnumber,visible',
        MUST_EXIST
    );
    if ((int)$finalcourse->category !== $targetcategoryid ||
            inc_norm((string)$finalcourse->fullname) !==
                inc_norm((string)$courseplan['target_fullname']) ||
            inc_norm((string)$finalcourse->shortname) !==
                inc_norm((string)$courseplan['target_shortname']) ||
            (string)$finalcourse->idnumber !== (string)$courseplan['target_marker'] ||
            (int)$finalcourse->visible !== 0) {
        throw new RuntimeException('La identidad o visibilidad final del curso no coincide.');
    }
    $inventory['schema_version'] = INC_SCHEMA;
    $inventory['tool_version'] = INC_VERSION;
    $inventory['phase'] = 'incremental-target-course-inventory';
    $inventory['generated_at_utc'] = gmdate('c');
    $inventory['course_key'] = $coursekey;
    $inventory['target_course_id'] = $courseid;
    inc_write_json($inventorypath, $inventory);
    $auditsha = is_readable($auditpath) ? hash_file('sha256', $auditpath) : null;
    inc_write_json($checkpointpath, [
        'schema_version' => INC_SCHEMA,
        'tool_version' => INC_VERSION,
        'phase' => 'incremental-course-checkpoint',
        'generated_at_utc' => gmdate('c'),
        'plan_sha256' => (string)$plan['plan_sha256'],
        'package_sha256' => (string)$plan['package_sha256'],
        'course_key' => $coursekey,
        'source_course_id' => (int)$courseplan['source_course_id'],
        'target_course_id' => $courseid,
        'target_category_id' => $targetcategoryid,
        'target_fullname' => (string)$courseplan['target_fullname'],
        'target_shortname' => (string)$courseplan['target_shortname'],
        'target_marker' => (string)$courseplan['target_marker'],
        'source_backup_sha256' => (string)$courseplan['backup_sha256'],
        'source_backup_bytes' => (int)$courseplan['backup_bytes'],
        'single_extraction' => true,
        'normalized_mbz_created' => false,
        'normalization_audit_sha256' => $auditsha,
        'target_inventory_sha256' => hash_file('sha256', $inventorypath),
        'effective_enrolments' => count($expectedenrolments),
        'effective_roles' => count($expectedroles),
        'academic_relations_verified' => $verifiedrelations,
        'file_owner_rewrites_limited_to_restore_operator' => true,
        'academic_course_fields_verified' => [
            'startdate', 'enddate', 'format', 'enablecompletion',
        ],
        'file_content_hashes_verified' => !empty(
            $sourceinventory['relations']['files'][0]['content_sha1'] ?? null
        ),
        'visible' => false,
        'status' => 'applied',
        'resume_kind' => $adopting ? 'adopted_verified_marker' : 'restored',
    ]);
    inc_clear_course_ownership($coursekey);
    @unlink($statepath);
    \core\session\manager::set_user($olduser);
    $lock->release();
    $lock = null;
    cli_writeln(
        'INCREMENTAL_COURSE_OK course_key=' . $coursekey .
        ' status=' . ($adopting ? 'adopted' : 'restored') .
        ' target_course_id=' . $courseid .
        ' users=' . count($mappingbundle['target_users']) .
        ' enrolments=' . count($expectedenrolments) .
        ' roles=' . count($expectedroles) .
        ' extractions=' . ($adopting ? 0 : 1) .
        ' copied_archives=0 normalized_archives=0 visible=0'
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
    if (($createdthisrun || $ownedresidual) && $courseid > 0 &&
            $DB->record_exists('course', ['id' => $courseid])) {
        try {
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            // Solo elimina un contenedor respaldado por la marca persistente
            // que el worker escribió inmediatamente después de crearlo.
            if (isset($plan, $coursekey) && inc_course_is_owned(
                    (string)$plan['plan_sha256'],
                    (string)$coursekey,
                    $courseid
                )) {
                $cleanup = delete_course($course, false) ? 'ok' : 'failed';
                if ($cleanup === 'ok') {
                    inc_clear_course_ownership((string)$coursekey);
                }
            } else {
                $cleanup = 'refused_unmarked_course';
            }
        } catch (Throwable $cleanupError) {
            $cleanup = 'failed:' . $cleanupError->getMessage();
        }
    } else if (($createdthisrun || $ownedresidual) && $courseid > 0 &&
            isset($plan, $coursekey) && inc_course_is_owned(
                (string)$plan['plan_sha256'],
                (string)$coursekey,
                $courseid
            )) {
        inc_clear_course_ownership((string)$coursekey);
    }
    if (isset($diagnosticpath)) {
        try {
            inc_write_json($diagnosticpath, [
                'schema_version' => INC_SCHEMA,
                'tool_version' => INC_VERSION,
                'generated_at_utc' => gmdate('c'),
                'course_key' => $coursekey ?? '',
                'stage' => $stage,
                'error_class' => get_class($error),
                'error' => $error->getMessage(),
                'target_course_id' => $courseid ?: null,
                'course_cleanup' => $cleanup,
                'safe_to_retry' => in_array($cleanup, ['ok', 'not_needed'], true),
            ]);
        } catch (Throwable $ignored) {
        }
    }
    if ($lock !== null) {
        try {
            $lock->release();
        } catch (Throwable $ignored) {
        }
    }
    \core\session\manager::set_user($olduser);
    cli_error(
        'INCREMENTAL_COURSE_ERROR course_key=' . ($coursekey ?? '-') .
        ' stage=' . $stage .
        ' cleanup=' . preg_replace('/\s+/', '_', $cleanup) .
        ' message=' . $error->getMessage()
    );
}
