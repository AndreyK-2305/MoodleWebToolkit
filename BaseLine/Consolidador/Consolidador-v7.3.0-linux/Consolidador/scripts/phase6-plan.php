<?php
// Fase 6: construye el plan masivo sin modificar el Moodle destino.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once('/opt/consolidator/phase5-lib.php');
require_once('/opt/consolidator/phase6-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'phase4' => '/exports/phase4',
        'input' => '/exports/phase6',
        'output' => '/exports/phase6',
        'batchconfig' => '/exports/phase6/batch_config.json',
        'roleresolutions' => '/exports/phase6/role_resolutions.csv',
        'configsha' => null,
        'targetid' => null,
        'expectlab' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php phase6-plan.php --phase4=/exports/phase4 --input=/exports/phase6 " .
        "--output=/exports/phase6 --batchconfig=RUTA --roleresolutions=RUTA " .
        "--configsha=SHA256 --targetid=target [--expectlab=1]\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $phase4dir = rtrim((string)$options['phase4'], '/\\');
    $inputdir = rtrim((string)$options['input'], '/\\');
    $outputdir = rtrim((string)$options['output'], '/\\');
    $batchconfigpath = (string)$options['batchconfig'];
    $roleresolutionspath = (string)$options['roleresolutions'];
    $configsha = p5_require_sha256((string)$options['configsha'], 'configsha');
    $targetid = p5_norm((string)$options['targetid']);
    $expectlab = (bool)(int)$options['expectlab'];
    if (!preg_match('/^[a-z][a-z0-9_-]*$/', $targetid)) {
        throw new RuntimeException('targetid inválido.');
    }
    if (!is_dir($outputdir) &&
            !mkdir($outputdir, 0770, true) &&
            !is_dir($outputdir)) {
        throw new RuntimeException('No fue posible crear el directorio de fase 6.');
    }

    $batchconfig = p6_read_batch_config($batchconfigpath);
    $overrides = p6_load_role_overrides($roleresolutionspath);
    $contract = p5_load_phase4_contract(
        $phase4dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $targetpath = $inputdir . '/target_inventory.json';
    $target = p5_read_json($targetpath);
    if (($target['config_sha256'] ?? '') !== $configsha ||
            ($target['target_id'] ?? '') !== $targetid ||
            (int)($target['target_parent_category']['id'] ?? 0) !==
                (int)$batchconfig['target_parent_category_id'] ||
            ($target['approved_phase5_pilot']['verification_status'] ?? '') !== 'passed' ||
            ($target['write_performed'] ?? null) !== false ||
            ($target['phase4_input_sha256'] ?? []) !== $contract['hashes']) {
        throw new RuntimeException('target_inventory.json no corresponde al estado aprobado.');
    }

    $targetcategoriesbymarker = [];
    $targetcategoriesbyparentname = [];
    foreach ($target['categories'] ?? [] as $category) {
        $marker = (string)($category['idnumber'] ?? '');
        if ($marker !== '') {
            $targetcategoriesbymarker[$marker][] = $category;
        }
        $namekey = (int)($category['target_parent_id'] ?? 0) . '|' .
            p5_norm((string)($category['name'] ?? ''));
        $targetcategoriesbyparentname[$namekey][] = $category;
    }
    $targetcoursesbymarker = [];
    $targetcoursesbyshortname = [];
    $targetcoursesbyfullname = [];
    $targetcoursesbyidnumber = [];
    foreach ($target['courses'] ?? [] as $course) {
        $marker = (string)($course['idnumber'] ?? '');
        if ($marker !== '') {
            $targetcoursesbymarker[$marker][] = $course;
            $targetcoursesbyidnumber[$marker][] = $course;
        }
        $targetcoursesbyshortname[p5_norm((string)$course['shortname'])][] = $course;
        $targetcoursesbyfullname[p5_norm((string)$course['fullname'])][] = $course;
    }
    $availablemodules = array_fill_keys(array_map(
        'p5_norm',
        $target['available_modules'] ?? []
    ), true);
    $availableroles = array_fill_keys(array_map(
        'p5_norm',
        $target['available_roles'] ?? []
    ), true);

    $categoryrows = [];
    $categoryrowbykey = [];
    $categorykeybysourceid = [];
    $sourceinventories = [];
    $sourcepaths = [];
    $sourcecategorymaps = [];
    $globalissues = [];
    foreach ($batchconfig['sources'] as $sourceid) {
        $sourcepath = $inputdir . '/source-inventory-' . $sourceid . '.json';
        $inventory = p5_read_json($sourcepath);
        if (($inventory['config_sha256'] ?? '') !== $configsha ||
                ($inventory['source_id'] ?? '') !== $sourceid ||
                ($inventory['write_performed'] ?? null) !== false) {
            throw new RuntimeException(
                'El inventario de ' . $sourceid . ' no corresponde a la configuración.'
            );
        }
        if ((int)($inventory['counts']['orphaned_courses'] ?? -1) !== 0) {
            $globalissues[] = 'El origen ' . $sourceid . ' contiene cursos sin categoría.';
        }
        $sourceinventories[$sourceid] = $inventory;
        $sourcepaths['source_inventory_' . $sourceid . '.json'] = $sourcepath;

        $rootkey = p6_root_category_key($sourceid);
        $rootmarker = p6_category_marker($rootkey);
        $rootissues = [];
        $rootmatches = $targetcategoriesbymarker[$rootmarker] ?? [];
        $rootaction = 'create';
        $rootmatchedid = '';
        if (count($rootmatches) > 1) {
            $rootissues[] = 'El destino repite el marcador de la categoría raíz.';
        } else if (count($rootmatches) === 1) {
            $rootaction = 'reuse';
            $rootmatchedid = (int)$rootmatches[0]['target_category_id'];
            if ((int)$rootmatches[0]['target_parent_id'] !==
                    (int)$batchconfig['target_parent_category_id'] ||
                    p5_norm((string)$rootmatches[0]['name']) !==
                    p5_norm((string)$inventory['source_name'])) {
                $rootissues[] =
                    'La categoría raíz marcada cambió de nombre o categoría padre.';
            }
        } else {
            $namekey = (int)$batchconfig['target_parent_category_id'] . '|' .
                p5_norm((string)$inventory['source_name']);
            if (!empty($targetcategoriesbyparentname[$namekey])) {
                $rootissues[] =
                    'Ya existe una categoría raíz con el mismo nombre pero sin marcador.';
            }
        }
        if ($rootissues) {
            $rootaction = 'blocked';
        }
        $rootrow = [
            'category_key' => $rootkey,
            'source' => $sourceid,
            'source_category_id' => '',
            'source_parent_id' => '',
            'source_idnumber' => '',
            'category_name' => (string)$inventory['source_name'],
            'source_depth' => 0,
            'parent_category_key' => '',
            'target_parent_category_id' =>
                (int)$batchconfig['target_parent_category_id'],
            'target_category_marker' => $rootmarker,
            'matched_target_category_id' => $rootmatchedid,
            'action' => $rootaction,
            'blocking_reason' => p6_issue_text($rootissues),
        ];
        $categoryrowbykey[$rootkey] = count($categoryrows);
        $categoryrows[] = $rootrow;

        $categoriesbyid = [];
        foreach ($inventory['categories'] ?? [] as $category) {
            $categoryid = (int)($category['source_category_id'] ?? 0);
            if ($categoryid < 1 || isset($categoriesbyid[$categoryid])) {
                throw new RuntimeException(
                    'El inventario de ' . $sourceid . ' contiene categorías inválidas.'
                );
            }
            $categoriesbyid[$categoryid] = $category;
            $categorykeybysourceid[$sourceid . '|' . $categoryid] =
                p6_category_key($sourceid, $categoryid);
        }
        $sourcecategorymaps[$sourceid] = $categoriesbyid;
        uasort($categoriesbyid, static fn(array $a, array $b): int =>
            [(int)$a['depth'], (int)$a['source_category_id']] <=>
            [(int)$b['depth'], (int)$b['source_category_id']]
        );
        foreach ($categoriesbyid as $categoryid => $category) {
            $categorykey = $categorykeybysourceid[$sourceid . '|' . $categoryid];
            $parentid = (int)$category['source_parent_id'];
            $parentkey = $parentid === 0
                ? $rootkey
                : ($categorykeybysourceid[$sourceid . '|' . $parentid] ?? '');
            $issues = [];
            if ($parentkey === '') {
                $issues[] = 'La categoría padre no existe en el inventario.';
            }
            $marker = p6_category_marker($categorykey);
            $matches = $targetcategoriesbymarker[$marker] ?? [];
            $action = 'create';
            $matchedid = '';
            if (count($matches) > 1) {
                $issues[] = 'El destino repite el marcador de la categoría.';
            } else if (count($matches) === 1) {
                $action = 'reuse';
                $matchedid = (int)$matches[0]['target_category_id'];
                if (p5_norm((string)$matches[0]['name']) !==
                        p5_norm((string)$category['name'])) {
                    $issues[] = 'La categoría marcada cambió de nombre.';
                }
                $parentrowindex = $categoryrowbykey[$parentkey] ?? null;
                $plannedparentid = $parentrowindex === null
                    ? 0
                    : (int)($categoryrows[$parentrowindex]['matched_target_category_id'] ?? 0);
                if ($plannedparentid < 1 ||
                        (int)$matches[0]['target_parent_id'] !== $plannedparentid) {
                    $issues[] =
                        'La categoría marcada no conserva la jerarquía aprobada.';
                }
            } else {
                $parentrowindex = $categoryrowbykey[$parentkey] ?? null;
                $plannedparentid = $parentrowindex === null
                    ? 0
                    : (int)($categoryrows[$parentrowindex]['matched_target_category_id'] ?? 0);
                if ($plannedparentid > 0) {
                    $namekey = $plannedparentid . '|' .
                        p5_norm((string)$category['name']);
                    if (!empty($targetcategoriesbyparentname[$namekey])) {
                        $issues[] =
                            'Existe una categoría hermana con el mismo nombre pero sin marcador.';
                    }
                }
            }
            if ($parentkey !== '') {
                $parentrowindex = $categoryrowbykey[$parentkey] ?? null;
                if ($parentrowindex === null ||
                        $categoryrows[$parentrowindex]['action'] === 'blocked') {
                    $issues[] = 'La categoría padre está bloqueada.';
                }
            }
            if ($issues) {
                $action = 'blocked';
            }
            $row = [
                'category_key' => $categorykey,
                'source' => $sourceid,
                'source_category_id' => $categoryid,
                'source_parent_id' => $parentid,
                'source_idnumber' => (string)$category['idnumber'],
                'category_name' => (string)$category['name'],
                'source_depth' => (int)$category['depth'],
                'parent_category_key' => $parentkey,
                'target_parent_category_id' => '',
                'target_category_marker' => $marker,
                'matched_target_category_id' => $matchedid,
                'action' => $action,
                'blocking_reason' => p6_issue_text($issues),
            ];
            $categoryrowbykey[$categorykey] = count($categoryrows);
            $categoryrows[] = $row;
        }
    }

    $courserows = [];
    $courseissues = [];
    $userrows = [];
    $rolerows = [];
    $convergencerows = [];
    $approvedconvergences = 0;
    $blockedconvergences = 0;
    $convergencesourceaccounts = 0;
    $enrolmentrowscollapsed = 0;
    $rolerowscollapsed = 0;
    $fallbackstats = [];
    $pilot = $target['approved_phase5_pilot'];
    foreach ($sourceinventories as $sourceid => $inventory) {
        foreach ($inventory['courses'] ?? [] as $course) {
            $courseid = (int)($course['source_course_id'] ?? 0);
            $coursekey = p6_course_key($sourceid, $courseid);
            $issues = [];
            $action = 'restore_new';
            $matchedtargetid = '';
            $exclusionreason = '';
            $targetshortname = trim((string)$course['shortname']);
            $shortnameresolution = 'preserved';
            $targetfullname = trim((string)$course['fullname']);
            $fullnameresolution = 'preserved';
            $categorykey = $categorykeybysourceid[
                $sourceid . '|' . (int)$course['source_category_id']
            ] ?? '';
            if ($courseid < 1 || $categorykey === '') {
                $issues[] = 'El curso o su categoría de origen son inválidos.';
            }
            if ($categorykey !== '') {
                $categoryrowindex = $categoryrowbykey[$categorykey] ?? null;
                if ($categoryrowindex === null ||
                        $categoryrows[$categoryrowindex]['action'] === 'blocked') {
                    $issues[] = 'La categoría destino del curso está bloqueada.';
                }
            }
            if (!$batchconfig['selection']['include_hidden'] &&
                    (int)($course['visible'] ?? 0) !== 1) {
                $action = 'excluded_hidden';
                $exclusionreason = 'Curso oculto excluido por la configuración.';
            }
            $ispilot = $sourceid === (string)$pilot['source_id'] &&
                (
                    $courseid === (int)$pilot['source_course_id'] ||
                    (
                        trim((string)$course['idnumber']) !== '' &&
                        (string)$course['idnumber'] ===
                            (string)$pilot['source_course_idnumber']
                    )
                );
            if ($ispilot && $batchconfig['exclude_verified_phase5_pilot']) {
                $action = 'excluded_phase5_pilot';
                $matchedtargetid = (int)$pilot['target_course_id'];
                $exclusionreason =
                    'Curso piloto ya aplicado y verificado en la fase 5.';
            }

            $marker = p6_course_marker(
                $sourceid,
                (string)$course['idnumber'],
                $courseid
            );
            if (str_starts_with($action, 'excluded_')) {
                // Las exclusiones aprobadas no requieren analizar colisiones ni participantes.
            } else {
                $markermatches = $targetcoursesbymarker[$marker] ?? [];
                if (count($markermatches) > 1) {
                    $issues[] = 'El destino repite el marcador del curso.';
                } else if (count($markermatches) === 1) {
                    $action = 'already_migrated';
                    $matchedtargetid = (int)$markermatches[0]['id'];
                    $targetshortname =
                        trim((string)$markermatches[0]['shortname']);
                    $shortnameresolution = 'existing_migration';
                    $targetfullname =
                        trim((string)$markermatches[0]['fullname']);
                    $fullnameresolution = 'existing_migration';
                } else {
                    $originalidnumber = trim((string)$course['idnumber']);
                    if ($originalidnumber !== '' &&
                            !empty($targetcoursesbyidnumber[$originalidnumber])) {
                        $issues[] =
                            'El idnumber original ya pertenece a otro curso del destino.';
                    }
                }

                foreach (array_keys($course['modules_by_type'] ?? []) as $modname) {
                    if (!isset($availablemodules[p5_norm((string)$modname)])) {
                        $issues[] = 'Falta el módulo ' . $modname . ' en el destino.';
                    }
                }
                $participantsbytarget = [];
                $seenenrolments = [];
                foreach ($course['enrolments'] ?? [] as $enrolment) {
                    $sourceuserid = (int)$enrolment['source_user_id'];
                    $mappingkey = $sourceid . ':' . $sourceuserid;
                    $mapping = $contract['source_by_key'][$mappingkey] ?? null;
                    if (!$mapping) {
                        $issues[] = 'La matrícula ' . $mappingkey .
                            ' no tiene target_user_id.';
                        continue;
                    }
                    $enrolkey = $sourceuserid . '|' .
                        (string)$enrolment['enrol_method'];
                    if (isset($seenenrolments[$enrolkey])) {
                        $issues[] = 'La matrícula ' . $enrolkey . ' está repetida.';
                        continue;
                    }
                    $seenenrolments[$enrolkey] = true;
                    $targetuserid = (int)$mapping['target_user_id'];
                    if (!isset($participantsbytarget[$targetuserid])) {
                        $participantsbytarget[$targetuserid] = [
                            'source_accounts' => [],
                        ];
                    }
                    if (!isset(
                        $participantsbytarget[$targetuserid]['source_accounts'][$sourceuserid]
                    )) {
                        $participantsbytarget[$targetuserid]['source_accounts'][$sourceuserid] = [
                            'canonical_id' => (string)$mapping['canonical_id'],
                            'identity_decision' =>
                                (string)($mapping['identity_decision'] ?? ''),
                            'source_username' => (string)$enrolment['source_username'],
                            'enrolments' => [],
                            'roles' => [],
                        ];
                    }
                    $participantsbytarget[$targetuserid]['source_accounts'][$sourceuserid]
                        ['enrolments'][] = p5_norm((string)$enrolment['enrol_method']) .
                        ':' . (int)$enrolment['enrol_status'];
                    $userrows[] = [
                        'course_key' => $coursekey,
                        'source' => $sourceid,
                        'source_course_id' => $courseid,
                        'source_user_id' => $sourceuserid,
                        'source_username' => (string)$enrolment['source_username'],
                        'canonical_id' => (string)$mapping['canonical_id'],
                        'target_user_id' => $targetuserid,
                        'target_username' => (string)$mapping['target_username'],
                        'enrol_method' => (string)$enrolment['enrol_method'],
                        'enrol_status' => (int)$enrolment['enrol_status'],
                        'mapping_status' => 'mapped',
                    ];
                }
                foreach ($course['roles'] ?? [] as $role) {
                    $sourceuserid = (int)$role['source_user_id'];
                    $mappingkey = $sourceid . ':' . $sourceuserid;
                    $mapping = $contract['source_by_key'][$mappingkey] ?? null;
                    if (!$mapping) {
                        $issues[] = 'El rol de ' . $mappingkey .
                            ' no tiene target_user_id.';
                    }
                    [$normalizedrole, $targetrole, $approval, $reason, $customtarget] =
                        p6_role_policy(
                            $sourceid,
                            (string)$role['role_shortname'],
                            $batchconfig,
                            $overrides
                        );
                    if (!$customtarget && !isset($availableroles[$targetrole])) {
                        $issues[] = 'El rol objetivo ' . $targetrole .
                            ' no existe en el destino.';
                    }
                    if ($customtarget) {
                        $fallbackkey = $sourceid . '|' .
                            p5_norm((string)$role['role_shortname']);
                        if (!isset($fallbackstats[$fallbackkey])) {
                            $fallbackstats[$fallbackkey] = [
                                'source' => $sourceid,
                                'source_role_shortname' =>
                                    (string)$role['role_shortname'],
                                'normalized_role' => $normalizedrole,
                                'target_role_shortname' => $targetrole,
                                'normalization_status' => $approval,
                                'affected_courses' => [],
                                'affected_assignments' => 0,
                                'safety_profile' => 'student_readonly',
                                'reason' => $reason,
                            ];
                        }
                        $fallbackstats[$fallbackkey]['affected_courses'][$coursekey] = true;
                        $fallbackstats[$fallbackkey]['affected_assignments']++;
                    }
                    if ($mapping) {
                        $targetuserid = (int)$mapping['target_user_id'];
                        if (!isset($participantsbytarget[$targetuserid])) {
                            $participantsbytarget[$targetuserid] = [
                                'source_accounts' => [],
                            ];
                        }
                        if (!isset(
                            $participantsbytarget[$targetuserid]
                                ['source_accounts'][$sourceuserid]
                        )) {
                            $participantsbytarget[$targetuserid]
                                ['source_accounts'][$sourceuserid] = [
                                    'canonical_id' => (string)$mapping['canonical_id'],
                                    'identity_decision' =>
                                        (string)($mapping['identity_decision'] ?? ''),
                                    'source_username' =>
                                        (string)($mapping['source_username'] ?? ''),
                                    'enrolments' => [],
                                    'roles' => [],
                                ];
                        }
                        $participantsbytarget[$targetuserid]
                            ['source_accounts'][$sourceuserid]['roles'][] =
                                p5_norm($targetrole);
                    }
                    $rolerows[] = [
                        'course_key' => $coursekey,
                        'source' => $sourceid,
                        'source_course_id' => $courseid,
                        'source_user_id' => $sourceuserid,
                        'canonical_id' => $mapping
                            ? (string)$mapping['canonical_id']
                            : '',
                        'target_user_id' => $mapping
                            ? (int)$mapping['target_user_id']
                            : '',
                        'source_role_shortname' =>
                            (string)$role['role_shortname'],
                        'normalized_role' => $normalizedrole,
                        'target_role_shortname' => $targetrole,
                        'approval_status' => $approval,
                        'safety_profile' => $customtarget
                            ? 'student_readonly'
                            : 'standard',
                        'reason' => $reason,
                    ];
                }
                $convergence = p6_evaluate_identity_convergences(
                    $coursekey,
                    $sourceid,
                    $courseid,
                    $participantsbytarget
                );
                $issues = array_merge($issues, $convergence['issues']);
                $convergencerows = array_merge(
                    $convergencerows,
                    $convergence['rows']
                );
                $approvedconvergences += (int)$convergence['approved'];
                $blockedconvergences += (int)$convergence['blocked'];
                $convergencesourceaccounts +=
                    (int)$convergence['source_accounts'];
                $enrolmentrowscollapsed +=
                    (int)$convergence['enrolment_rows_collapsed'];
                $rolerowscollapsed +=
                    (int)$convergence['role_rows_collapsed'];
            }

            $row = [
                'course_key' => $coursekey,
                'source' => $sourceid,
                'source_course_id' => $courseid,
                'source_course_idnumber' => (string)$course['idnumber'],
                'source_shortname' => (string)$course['shortname'],
                'target_shortname' => $targetshortname,
                'shortname_resolution' => $shortnameresolution,
                'source_fullname' => (string)$course['fullname'],
                'target_fullname' => $targetfullname,
                'fullname_resolution' => $fullnameresolution,
                'source_visible' => (int)$course['visible'],
                'source_category_id' => (int)$course['source_category_id'],
                'target_category_key' => $categorykey,
                'target_course_marker' => $marker,
                'matched_target_course_id' => $matchedtargetid,
                'enrolments' => count($course['enrolments'] ?? []),
                'roles' => count($course['roles'] ?? []),
                'module_types' => implode(
                    '|',
                    array_keys($course['modules_by_type'] ?? [])
                ),
                'action' => $issues ? 'blocked' : $action,
                'blocking_reason' => p6_issue_text($issues),
                'exclusion_reason' => $exclusionreason,
            ];
            $courseissues[$coursekey] = $issues;
            $courserows[] = $row;
        }
    }

    // Primero se reservan los nombres únicos que pueden conservarse. Después
    // se prefijan únicamente las colisiones, de modo que un nombre generado
    // nunca desplace innecesariamente a otro shortname original.
    $shortnamecounts = [];
    foreach ($courserows as $row) {
        if (str_starts_with((string)$row['action'], 'excluded_') ||
                $row['action'] === 'already_migrated') {
            continue;
        }
        $normalized = p5_norm((string)$row['source_shortname']);
        $shortnamecounts[$normalized] =
            (int)($shortnamecounts[$normalized] ?? 0) + 1;
    }
    $reservedshortnames = [];
    foreach (array_keys($targetcoursesbyshortname) as $shortname) {
        if ($shortname !== '') {
            $reservedshortnames[$shortname] = true;
        }
    }
    $pendingallocations = [];
    foreach ($courserows as $index => &$row) {
        if (str_starts_with((string)$row['action'], 'excluded_')) {
            $row['shortname_resolution'] = 'excluded';
            continue;
        }
        if ($row['action'] === 'already_migrated') {
            continue;
        }
        $normalized = p5_norm((string)$row['source_shortname']);
        if ($normalized === '') {
            $coursekey = (string)$courserows[$index]['course_key'];
            $courseissues[$coursekey][] =
                'El curso no contiene un shortname de origen válido.';
            $courserows[$index]['action'] = 'blocked';
            $courserows[$index]['blocking_reason'] =
                p6_issue_text($courseissues[$coursekey]);
            continue;
        }
        if (($shortnamecounts[$normalized] ?? 0) === 1 &&
                !isset($reservedshortnames[$normalized])) {
            $allocation = p6_allocate_target_shortname(
                (string)$row['source'],
                (string)$row['source_shortname'],
                (int)$row['source_course_id'],
                false,
                $reservedshortnames
            );
            $row['target_shortname'] = $allocation['shortname'];
            $row['shortname_resolution'] = $allocation['resolution'];
            continue;
        }
        $pendingallocations[] = $index;
    }
    unset($row);
    foreach ($pendingallocations as $index) {
        $allocation = p6_allocate_target_shortname(
            (string)$courserows[$index]['source'],
            (string)$courserows[$index]['source_shortname'],
            (int)$courserows[$index]['source_course_id'],
            true,
            $reservedshortnames
        );
        $courserows[$index]['target_shortname'] = $allocation['shortname'];
        $courserows[$index]['shortname_resolution'] =
            $allocation['resolution'];
    }

    // Los fullnames homónimos se distinguen por la instancia de origen. Así
    // Moodle nunca necesita inventar sufijos ambiguos como "Copy 1".
    $fullnamecounts = [];
    foreach ($courserows as $row) {
        if (str_starts_with((string)$row['action'], 'excluded_') ||
                $row['action'] === 'already_migrated') {
            continue;
        }
        $normalized = p5_norm((string)$row['source_fullname']);
        $fullnamecounts[$normalized] =
            (int)($fullnamecounts[$normalized] ?? 0) + 1;
    }
    $reservedfullnames = [];
    foreach (array_keys($targetcoursesbyfullname) as $fullname) {
        if ($fullname !== '') {
            $reservedfullnames[$fullname] = true;
        }
    }
    $pendingfullnameallocations = [];
    foreach ($courserows as $index => &$row) {
        if (str_starts_with((string)$row['action'], 'excluded_')) {
            $row['fullname_resolution'] = 'excluded';
            continue;
        }
        if ($row['action'] === 'already_migrated') {
            continue;
        }
        $normalized = p5_norm((string)$row['source_fullname']);
        if ($normalized === '') {
            $coursekey = (string)$courserows[$index]['course_key'];
            $courseissues[$coursekey][] =
                'El curso no contiene un fullname de origen válido.';
            $courserows[$index]['action'] = 'blocked';
            $courserows[$index]['blocking_reason'] =
                p6_issue_text($courseissues[$coursekey]);
            continue;
        }
        if (($fullnamecounts[$normalized] ?? 0) === 1 &&
                !isset($reservedfullnames[$normalized])) {
            $allocation = p6_allocate_target_fullname(
                (string)$sourceinventories[(string)$row['source']]['source_name'],
                (string)$row['source_fullname'],
                (int)$row['source_course_id'],
                false,
                $reservedfullnames
            );
            $row['target_fullname'] = $allocation['fullname'];
            $row['fullname_resolution'] = $allocation['resolution'];
            continue;
        }
        $pendingfullnameallocations[] = $index;
    }
    unset($row);
    foreach ($pendingfullnameallocations as $index) {
        $sourceid = (string)$courserows[$index]['source'];
        $allocation = p6_allocate_target_fullname(
            (string)$sourceinventories[$sourceid]['source_name'],
            (string)$courserows[$index]['source_fullname'],
            (int)$courserows[$index]['source_course_id'],
            true,
            $reservedfullnames
        );
        $courserows[$index]['target_fullname'] = $allocation['fullname'];
        $courserows[$index]['fullname_resolution'] =
            $allocation['resolution'];
    }

    $fallbackrows = [];
    foreach ($fallbackstats as $fallback) {
        $fallback['affected_courses'] = count($fallback['affected_courses']);
        $fallbackrows[] = $fallback;
    }
    usort($fallbackrows, static fn(array $a, array $b): int =>
        [$a['source'], $a['source_role_shortname']] <=>
        [$b['source'], $b['source_role_shortname']]
    );

    $categorypath = $outputdir . '/category_plan.csv';
    $coursepath = $outputdir . '/course_plan.csv';
    $userpath = $outputdir . '/course_user_plan.csv';
    $rolepath = $outputdir . '/course_role_plan.csv';
    $fallbackpath = $outputdir . '/role_normalization.csv';
    $convergencepath = $outputdir . '/identity_convergence.csv';
    p5_write_csv($categorypath, [
        'category_key',
        'source',
        'source_category_id',
        'source_parent_id',
        'source_idnumber',
        'category_name',
        'source_depth',
        'parent_category_key',
        'target_parent_category_id',
        'target_category_marker',
        'matched_target_category_id',
        'action',
        'blocking_reason',
    ], $categoryrows);
    p5_write_csv($coursepath, [
        'course_key',
        'source',
        'source_course_id',
        'source_course_idnumber',
        'source_shortname',
        'target_shortname',
        'shortname_resolution',
        'source_fullname',
        'target_fullname',
        'fullname_resolution',
        'source_visible',
        'source_category_id',
        'target_category_key',
        'target_course_marker',
        'matched_target_course_id',
        'enrolments',
        'roles',
        'module_types',
        'action',
        'blocking_reason',
        'exclusion_reason',
    ], $courserows);
    p5_write_csv($userpath, [
        'course_key',
        'source',
        'source_course_id',
        'source_user_id',
        'source_username',
        'canonical_id',
        'target_user_id',
        'target_username',
        'enrol_method',
        'enrol_status',
        'mapping_status',
    ], $userrows);
    p5_write_csv($rolepath, [
        'course_key',
        'source',
        'source_course_id',
        'source_user_id',
        'canonical_id',
        'target_user_id',
        'source_role_shortname',
        'normalized_role',
        'target_role_shortname',
        'approval_status',
        'safety_profile',
        'reason',
    ], $rolerows);
    p5_write_csv($fallbackpath, [
        'source',
        'source_role_shortname',
        'normalized_role',
        'target_role_shortname',
        'normalization_status',
        'affected_courses',
        'affected_assignments',
        'safety_profile',
        'reason',
    ], $fallbackrows);
    p5_write_csv($convergencepath, [
        'convergence_id',
        'course_key',
        'source',
        'source_course_id',
        'canonical_id',
        'target_user_id',
        'source_user_ids',
        'source_usernames',
        'identity_decisions',
        'enrolment_signatures',
        'normalized_role_signatures',
        'merged_normalized_roles',
        'resolution_status',
        'planned_action',
        'safety_profile',
        'reason',
    ], $convergencerows);

    $blockedcategories = count(array_filter(
        $categoryrows,
        static fn(array $row): bool => $row['action'] === 'blocked'
    ));
    $blockedcourses = count(array_filter(
        $courserows,
        static fn(array $row): bool => $row['action'] === 'blocked'
    ));
    $newcourses = count(array_filter(
        $courserows,
        static fn(array $row): bool => $row['action'] === 'restore_new'
    ));
    $alreadymigrated = count(array_filter(
        $courserows,
        static fn(array $row): bool => $row['action'] === 'already_migrated'
    ));
    $excludedpilot = count(array_filter(
        $courserows,
        static fn(array $row): bool => $row['action'] === 'excluded_phase5_pilot'
    ));
    $excludedhidden = count(array_filter(
        $courserows,
        static fn(array $row): bool => $row['action'] === 'excluded_hidden'
    ));
    $adjustedshortnames = count(array_filter(
        $courserows,
        static fn(array $row): bool =>
            $row['action'] === 'restore_new' &&
            p5_norm((string)$row['source_shortname']) !==
                p5_norm((string)$row['target_shortname'])
    ));
    $adjustedfullnames = count(array_filter(
        $courserows,
        static fn(array $row): bool =>
            $row['action'] === 'restore_new' &&
            p5_norm((string)$row['source_fullname']) !==
                p5_norm((string)$row['target_fullname'])
    ));
    $plannedsiteadmins = p6_planned_site_administrators($contract);
    $blockingconflicts = $blockedcategories + $blockedcourses + count($globalissues);
    $artifactpaths = [
        'batch_config.json' => $batchconfigpath,
        'role_resolutions.csv' => $roleresolutionspath,
        'target_inventory.json' => $targetpath,
        'category_plan.csv' => $categorypath,
        'course_plan.csv' => $coursepath,
        'course_user_plan.csv' => $userpath,
        'course_role_plan.csv' => $rolepath,
        'role_normalization.csv' => $fallbackpath,
        'identity_convergence.csv' => $convergencepath,
    ] + $sourcepaths;
    $summary = [
        'schema_version' => '1.0',
        'phase' => '6-multi-course-inventory-plan',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'batch_id' => (string)$batchconfig['batch_id'],
        'target_parent_category_id' =>
            (int)$batchconfig['target_parent_category_id'],
        'selected_sources' => $batchconfig['sources'],
        'selection_mode' => (string)$batchconfig['selection']['mode'],
        'include_hidden' => (bool)$batchconfig['selection']['include_hidden'],
        'phase5_pilot_excluded' =>
            (bool)$batchconfig['exclude_verified_phase5_pilot'],
        'source_categories_discovered' => array_sum(array_map(
            static fn(array $inventory): int =>
                (int)$inventory['counts']['categories'],
            $sourceinventories
        )),
        'target_categories_planned' => count($categoryrows),
        'target_categories_to_create' => count(array_filter(
            $categoryrows,
            static fn(array $row): bool => $row['action'] === 'create'
        )),
        'target_categories_to_reuse' => count(array_filter(
            $categoryrows,
            static fn(array $row): bool => $row['action'] === 'reuse'
        )),
        'blocked_categories' => $blockedcategories,
        'courses_discovered' => count($courserows),
        'courses_to_restore' => $newcourses,
        'courses_already_migrated' => $alreadymigrated,
        'courses_excluded_phase5_pilot' => $excludedpilot,
        'courses_excluded_hidden' => $excludedhidden,
        'blocked_courses' => $blockedcourses,
        'course_shortnames_adjusted' => $adjustedshortnames,
        'course_shortname_policy' => 'preserve_or_prefix_source',
        'course_fullnames_adjusted' => $adjustedfullnames,
        'course_fullname_policy' => 'preserve_or_prefix_source_label',
        'enrolments_mapped' => count($userrows),
        'course_roles_normalized' => count($rolerows),
        'approved_identity_convergences' => $approvedconvergences,
        'blocked_identity_convergences' => $blockedconvergences,
        'source_accounts_in_identity_convergences' =>
            $convergencesourceaccounts,
        'enrolment_rows_collapsed_by_identity_merge' =>
            $enrolmentrowscollapsed,
        'course_role_rows_collapsed_by_identity_merge' =>
            $rolerowscollapsed,
        'effective_target_enrolments_planned' =>
            count($userrows) - $enrolmentrowscollapsed,
        'effective_target_course_roles_planned' =>
            count($rolerows) - $rolerowscollapsed,
        'nonstandard_role_types_normalized' => count($fallbackrows),
        'nonstandard_role_assignments_normalized' => array_sum(array_map(
            static fn(array $row): int => (int)$row['affected_assignments'],
            $fallbackrows
        )),
        'normalized_role_targets' => [
            'estudiante' => 'student',
            'docente' => 'editingteacher',
            'administrador' => 'manager',
            'personalizado' => 'personalizado',
        ],
        'personalizado_safety_profile' =>
            $batchconfig['role_policy']['personalizado_safety'],
        'site_administrators_preserved_separately' =>
            count($target['site_administrators'] ?? []),
        'site_administrators_planned' => count($plannedsiteadmins),
        'global_issues' => $globalissues,
        'blocking_conflicts' => $blockingconflicts,
        'plan_status' => $blockingconflicts === 0 ? 'applicable' : 'blocked',
        'phase4_input_sha256' => $contract['hashes'],
        'phase5_evidence_sha256' => $target['phase5_evidence_sha256'] ?? [],
        'artifacts_sha256' => p5_hash_files($artifactpaths),
        'destination_write_performed' => false,
        'backups_created' => false,
        'courses_restored' => false,
    ];
    if ($expectlab) {
        $summary['lab_validation'] =
            $blockingconflicts === 0 &&
            $excludedpilot === 1 &&
            $blockedconvergences === 0 &&
            count($courserows) > 1 &&
            $newcourses > 0 &&
            count($fallbackrows) > 0
                ? 'passed'
                : 'failed';
    }
    p5_write_json($outputdir . '/plan_summary.json', $summary);
    cli_writeln(
        'FASE6_PLAN_OK sources=' . count($batchconfig['sources']) .
        ' courses=' . count($courserows) .
        ' restore=' . $newcourses .
        ' pilot_excluded=' . $excludedpilot .
        ' blocked=' . $blockingconflicts .
        ' renamed=' . $adjustedshortnames .
        ' fullname_renamed=' . $adjustedfullnames .
        ' identity_merges=' . $approvedconvergences .
        ' custom_roles=' . count($fallbackrows) .
        ' write=0'
    );
} catch (Throwable $error) {
    cli_error('FASE6_PLAN_ERROR ' . $error->getMessage());
}
