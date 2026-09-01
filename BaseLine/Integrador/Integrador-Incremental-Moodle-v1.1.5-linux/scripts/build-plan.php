<?php
// Construye el plan incremental sin modificar el Moodle destino.

declare(strict_types=1);
define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once('/opt/integrator-v1/incremental-common.php');

try {
    $options = inc_cli_options([
        'workdir' => '',
        'package' => '',
        'snapshot' => '',
        'targetid' => 'target',
    ]);
    $workdir = inc_safe_workdir((string)$options['workdir']);
    $package = inc_load_package((string)$options['package']);
    $snapshot = inc_read_json((string)$options['snapshot']);
    $targetid = inc_safe_component((string)$options['targetid'], 'targetid');
    if (($snapshot['schema_version'] ?? '') !== INC_SCHEMA ||
            ($snapshot['tool_version'] ?? '') !== INC_VERSION ||
            ($snapshot['target_id'] ?? '') !== $targetid ||
            ($snapshot['write_performed'] ?? null) !== false) {
        throw new RuntimeException('El inventario del destino no es utilizable.');
    }

    $sourceid = (string)$package['manifest']['source_id'];
    $sourcename = trim((string)$package['manifest']['source_name']);
    $sourceversion = (int)$package['manifest']['source_moodle_version'];
    $targetversion = (int)$snapshot['moodle_version'];
    $blocking = [];
    $warnings = [];
    if ($sourceversion < 1 || $targetversion < 1 || $sourceversion > $targetversion) {
        $blocking[] = 'El Moodle origen (' . $sourceversion .
            ') es posterior o no comparable con el destino (' . $targetversion . ').';
    }
    $personalizadorole = $snapshot['personalizado_role'] ?? null;
    if (is_array($personalizadorole) &&
            ($personalizadorole['exists'] ?? false) === true &&
            ($personalizadorole['safe'] ?? false) !== true) {
        $blocking[] = 'El rol destino personalizado existente no cumple mínimo privilegio: ' .
            implode(' ', array_map('strval', $personalizadorole['issues'] ?? []));
    }

    $targetplugin = [];
    foreach ($snapshot['plugins'] ?? [] as $plugin) {
        $targetplugin[inc_norm((string)$plugin['component'])] = $plugin;
    }
    $usedcomponents = [];
    foreach ($package['plugins']['used_components'] ?? [] as $component) {
        $component = inc_norm((string)$component);
        if (preg_match('/^[a-z][a-z0-9_]*$/', $component)) {
            $usedcomponents[$component] = true;
        }
    }
    foreach ($package['plugins']['used_activity_modules'] ?? [] as $module) {
        $usedcomponents['mod_' . inc_norm((string)$module)] = true;
    }
    foreach ($package['inventory']['courses'] ?? [] as $course) {
        $format = inc_norm((string)($course['format'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_]*$/', $format)) {
            $usedcomponents['format_' . $format] = true;
        }
        foreach (array_keys($course['modules_by_type'] ?? []) as $module) {
            $module = inc_norm((string)$module);
            if (preg_match('/^[a-z][a-z0-9_]*$/', $module)) {
                $usedcomponents['mod_' . $module] = true;
            }
        }
        foreach ($course['enrolments'] ?? [] as $enrolment) {
            $method = inc_norm((string)($enrolment['enrol_method'] ?? ''));
            if (preg_match('/^[a-z][a-z0-9_]*$/', $method)) {
                $usedcomponents['enrol_' . $method] = true;
            }
        }
        foreach ($course['roles'] ?? [] as $role) {
            $component = inc_norm((string)($role['component'] ?? ''));
            if (preg_match('/^[a-z][a-z0-9_]*$/', $component)) {
                $usedcomponents[$component] = true;
            }
        }
    }
    $pluginaudit = [];
    $sourceplugincomponents = [];
    foreach ($package['plugins']['plugins'] ?? [] as $plugin) {
        $component = inc_norm((string)($plugin['component'] ?? ''));
        if ($component !== '') {
            $sourceplugincomponents[$component] = true;
        }
        $used = isset($usedcomponents[$component]);
        $target = $targetplugin[$component] ?? null;
        $status = 'compatible';
        $isblocking = false;
        $message = 'Disponible en el destino.';
        if ($target === null && $used) {
            $status = 'missing_used_plugin';
            $isblocking = true;
            $message = 'El componente se usa en los cursos y falta en el destino.';
        } else if ($target === null) {
            $status = 'missing_unused_plugin';
            $message = 'No está instalado, pero no figura como utilizado por estos cursos.';
            $warnings[] = $component . ': ' . $message;
        } else if ($used && ($plugin['source'] ?? '') === 'additional' &&
                (int)($plugin['version_disk'] ?? 0) > 0 &&
                (int)($target['version_disk'] ?? 0) > 0 &&
                (int)$target['version_disk'] < (int)$plugin['version_disk']) {
            $status = 'target_plugin_older';
            $isblocking = true;
            $message = 'El plugin adicional del destino es anterior al del origen.';
        } else if (!$used && ($plugin['source'] ?? '') === 'additional' &&
                (int)($plugin['version_disk'] ?? 0) > 0 &&
                (int)($target['version_disk'] ?? 0) > 0 &&
                (int)$target['version_disk'] < (int)$plugin['version_disk']) {
            $status = 'target_plugin_older_unused';
            $message = 'El plugin adicional es anterior, pero no lo usa este lote.';
            $warnings[] = $component . ': ' . $message;
        }
        if ($isblocking) {
            $blocking[] = $component . ': ' . $message;
        }
        if ($used || ($plugin['source'] ?? '') === 'additional') {
            $pluginaudit[] = [
                'component' => $component,
                'used_by_courses' => $used,
                'source_version' => $plugin['version_disk'] ?? null,
                'target_version' => $target['version_disk'] ?? null,
                'status' => $status,
                'blocking' => $isblocking,
                'message' => $message,
            ];
        }
    }
    foreach (array_keys($usedcomponents) as $component) {
        if (!isset($sourceplugincomponents[$component])) {
            $blocking[] = $component .
                ': el lote lo referencia, pero no figura instalado en el inventario del origen.';
        }
    }

    $entries = inc_manifest_entries($package);
    if (!$entries) {
        $blocking[] = 'El paquete no contiene cursos para incorporar.';
    }
    $summarycourses = [];
    foreach ($package['inventory']['courses'] ?? [] as $course) {
        $summarycourses[(int)$course['source_course_id']] = $course;
    }
    $identityusers = inc_identity_users($package);
    $referencedids = [];
    $courseinventories = [];
    foreach ($entries as $courseid => $entry) {
        $document = inc_read_json((string)$entry['_paths']['inventory_file']);
        $courseinventories[$courseid] = $document;
        $unsupportedmethods = [];
        foreach ($document['inventory']['enrolments'] ?? [] as $enrolment) {
            $method = inc_norm((string)($enrolment['enrol_method'] ?? ''));
            if (in_array($method, ['cohort', 'meta'], true)) {
                $unsupportedmethods[$method] = true;
            }
        }
        foreach (array_keys($unsupportedmethods) as $method) {
            $blocking[] = 'El curso de origen ' . $courseid . ' usa enrol_' . $method .
                ', que depende de datos globales fuera del alcance de la V1.';
        }
        foreach (inc_source_user_ids_from_inventory($document) as $userid) {
            $referencedids[$userid] = true;
        }
    }
    foreach ($identityusers as $userid => $identity) {
        if (($identity['is_site_admin'] ?? false) === true) {
            $referencedids[$userid] = true;
        }
    }
    foreach (array_keys($referencedids) as $userid) {
        if (!isset($identityusers[$userid])) {
            $blocking[] = 'El contenido referencia source_user_id=' . $userid .
                ', ausente en identidades.json.';
        }
    }

    $targetbyemail = [];
    $reservedusernames = [];
    foreach ($snapshot['users'] ?? [] as $user) {
        $email = inc_norm((string)$user['email']);
        if ($email !== '') {
            $targetbyemail[$email][] = $user;
        }
        $reservedusernames[inc_norm((string)$user['username'])] = true;
    }
    $sourcebyemail = [];
    foreach ($identityusers as $userid => $identity) {
        $sourcebyemail[(string)$identity['email']][$userid] = $identity;
    }
    ksort($sourcebyemail, SORT_STRING);
    $identityplans = [];
    $sourceusermap = [];
    $sourceadminids = [];
    foreach ($sourcebyemail as $email => $accounts) {
        ksort($accounts, SORT_NUMERIC);
        $matches = $targetbyemail[$email] ?? [];
        if (count($matches) > 1) {
            $blocking[] = 'El destino contiene ' . count($matches) .
                ' usuarios activos con el correo ' . $email . '.';
            continue;
        }
        $representativeid = (int)array_key_first($accounts);
        $representative = $accounts[$representativeid];
        if (count($matches) === 1) {
            $target = $matches[0];
            $action = 'reuse_existing_by_email';
            $targetuserid = (int)$target['id'];
            $targetusername = (string)$target['username'];
        } else {
            $action = 'create_new';
            $targetuserid = null;
            $targetusername = inc_username_candidate(
                $sourceid,
                (string)$representative['username'],
                $representativeid,
                $reservedusernames
            );
        }
        $sourceids = array_map('intval', array_keys($accounts));
        $isadmin = false;
        foreach ($accounts as $sourceuserid => $identity) {
            if (($identity['is_site_admin'] ?? false) === true) {
                $isadmin = true;
                $sourceadminids[(int)$sourceuserid] = true;
            }
            $sourceusermap[(int)$sourceuserid] = $email;
        }
        $materializeinitially = false;
        foreach ($sourceids as $sourceuserid) {
            if (isset($referencedids[(int)$sourceuserid])) {
                $materializeinitially = true;
                break;
            }
        }
        $identityplans[$email] = [
            'email' => $email,
            'source_user_ids' => $sourceids,
            'representative_source_user_id' => $representativeid,
            'source_username' => (string)$representative['username'],
            'firstname' => (string)$representative['firstname'],
            'lastname' => (string)$representative['lastname'],
            'idnumber' => (string)$representative['idnumber'],
            'source_auth' => (string)$representative['auth'],
            'google_issuer' => (string)($representative['google_issuer'] ?? ''),
            'google_sub' => (string)($representative['google_sub'] ?? ''),
            'google_sub_verified' => (bool)($representative['google_sub_verified'] ?? false),
            'target_user_id' => $targetuserid,
            'target_username' => $targetusername,
            'action' => $action,
            'materialize_initially' => $materializeinitially,
            'source_site_admin' => $isadmin,
            'target_profile_authoritative' => $action !== 'create_new',
        ];
    }
    ksort($sourceusermap, SORT_NUMERIC);

    $batchid = 'inc-' . inc_slug($sourceid, 28) . '-' . substr($package['package_sha256'], 0, 12);
    $parentmarker = inc_parent_category_marker($batchid);
    $existingparent = null;
    foreach ($snapshot['categories'] ?? [] as $category) {
        if ((string)$category['idnumber'] === $parentmarker) {
            if ($existingparent !== null) {
                $blocking[] = 'El destino repite el marcador de la categoría padre.';
            }
            $existingparent = $category;
        }
    }
    if ($existingparent !== null) {
        $blocking[] = 'El mismo lote ya creó su categoría padre en el destino. ' .
            'Reanude con el directorio exports/integrator original; no inicie otra importación.';
    }
    $date = gmdate('Y-m-d');
    $parentname = $existingparent !== null
        ? (string)$existingparent['name']
        : 'Consolidacion-' . $sourcename . '-' . $date;

    $sourcecategories = [];
    foreach ($package['inventory']['categories'] ?? [] as $category) {
        $sourcecategories[(int)$category['source_category_id']] = $category;
    }
    $neededcategories = [];
    foreach ($summarycourses as $course) {
        $current = (int)$course['source_category_id'];
        $guard = [];
        if ($current < 1 || !isset($sourcecategories[$current])) {
            $blocking[] = 'El curso de origen ' .
                (int)$course['source_course_id'] .
                ' referencia una categoría inexistente.';
            continue;
        }
        while ($current > 0 && isset($sourcecategories[$current])) {
            if (isset($guard[$current])) {
                $blocking[] = 'El árbol de categorías del origen contiene un ciclo.';
                break;
            }
            $guard[$current] = true;
            $neededcategories[$current] = true;
            $current = (int)$sourcecategories[$current]['source_parent_id'];
        }
        if ($current > 0) {
            $blocking[] = 'La jerarquía del curso de origen ' .
                (int)$course['source_course_id'] .
                ' referencia un padre de categoría inexistente.';
        }
    }
    $categoryplans = [];
    foreach (array_keys($neededcategories) as $categoryid) {
        $category = $sourcecategories[$categoryid];
        $parentid = (int)$category['source_parent_id'];
        $categoryplans[] = [
            'source_category_id' => $categoryid,
            'source_parent_id' => isset($neededcategories[$parentid]) ? $parentid : 0,
            'source_depth' => (int)$category['depth'],
            'name' => (string)$category['name'],
            'marker' => inc_category_marker($batchid, $categoryid),
            'visible' => false,
        ];
    }
    usort($categoryplans, static fn(array $a, array $b): int =>
        [$a['source_depth'], $a['source_category_id']] <=>
        [$b['source_depth'], $b['source_category_id']]);

    $reservedshortnames = [];
    $reservedfullnames = [];
    $targetbymarker = [];
    foreach ($snapshot['courses'] ?? [] as $course) {
        $reservedshortnames[inc_norm((string)$course['shortname'])] = true;
        $reservedfullnames[inc_norm((string)$course['fullname'])] = true;
        if (str_starts_with((string)$course['idnumber'], 'INC-V1-COURSE-')) {
            $targetbymarker[(string)$course['idnumber']][] = $course;
        }
    }
    $courseplans = [];
    foreach ($entries as $courseid => $entry) {
        $summary = $summarycourses[$courseid] ?? null;
        if (!is_array($summary)) {
            $blocking[] = 'Falta el resumen del curso de origen ' . $courseid . '.';
            continue;
        }
        $marker = inc_course_marker($sourceid, $courseid);
        $existing = $targetbymarker[$marker] ?? [];
        if (count($existing) > 1) {
            $blocking[] = 'El destino repite el marcador del curso ' . $courseid . '.';
            continue;
        }
        if (count($existing) === 1) {
            $targetcourse = $existing[0];
            $fullname = (string)$targetcourse['fullname'];
            $shortname = (string)$targetcourse['shortname'];
            $action = 'blocked_already_integrated';
            $blocking[] = 'El curso source_id=' . $sourceid .
                ', source_course_id=' . $courseid .
                ' ya fue incorporado. La V1 no actualiza ni vuelve a importar cursos.';
        } else {
            $fullname = inc_allocate_course_name(
                (string)$summary['fullname'],
                $sourcename,
                $courseid,
                $reservedfullnames
            );
            $shortname = inc_allocate_shortname(
                $sourceid,
                (string)$summary['shortname'],
                $courseid,
                $reservedshortnames
            );
            $action = 'restore_new_hidden';
        }
        $document = $courseinventories[$courseid];
        $courseplans[] = [
            'course_key' => (string)$entry['course_key'],
            'source_course_id' => $courseid,
            'source_category_id' => (int)$summary['source_category_id'],
            'source_fullname' => (string)$summary['fullname'],
            'source_shortname' => (string)$summary['shortname'],
            'target_fullname' => $fullname,
            'target_shortname' => $shortname,
            'target_marker' => $marker,
            'action' => $action,
            'backup_file' => (string)$entry['backup_file'],
            'backup_sha256' => (string)$entry['backup_sha256'],
            'backup_bytes' => (int)$entry['backup_bytes'],
            'inventory_file' => (string)$entry['inventory_file'],
            'inventory_sha256' => (string)$entry['inventory_sha256'],
            'source_state_sha256' => (string)$document['source_state_sha256'],
            'initial_source_user_ids' => inc_source_user_ids_from_inventory($document),
            'visible' => false,
        ];
    }
    usort($courseplans, static fn(array $a, array $b): int =>
        [$b['backup_bytes'], $a['course_key']] <=> [$a['backup_bytes'], $b['course_key']]);

    $plan = [
        'schema_version' => INC_SCHEMA,
        'tool_version' => INC_VERSION,
        'phase' => 'incremental-plan',
        'generated_at_utc' => gmdate('c'),
        'status' => $blocking ? 'blocked' : 'applicable',
        'target_id' => $targetid,
        'target_wwwroot' => (string)$snapshot['target_wwwroot'],
        'target_moodle_version' => (string)$snapshot['moodle_version'],
        'target_moodle_release' => (string)$snapshot['moodle_release'],
        'target_snapshot_sha256' => hash_file('sha256', (string)$options['snapshot']),
        'target_site_admin_ids_before' => array_values(array_map(
            'intval',
            $snapshot['site_admin_ids'] ?? []
        )),
        'package_sha256' => (string)$package['package_sha256'],
        'package_directory' => (string)$package['root'],
        'source_id' => $sourceid,
        'source_name' => $sourcename,
        'source_moodle_version' => (string)$package['manifest']['source_moodle_version'],
        'source_moodle_release' => (string)$package['manifest']['source_moodle_release'],
        'batch_id' => $batchid,
        'parent_category' => [
            'name' => $parentname,
            'marker' => $parentmarker,
            'existing_target_id' => $existingparent['id'] ?? null,
            'visible' => false,
        ],
        'categories' => $categoryplans,
        'identities_by_email' => $identityplans,
        'source_user_email_map' => $sourceusermap,
        'source_site_admin_ids' => array_values(array_map(
            'intval',
            array_keys($sourceadminids)
        )),
        'courses' => $courseplans,
        'plugin_audit' => $pluginaudit,
        'blocking_issues' => $blocking,
        'warnings' => $warnings,
        'policies' => [
            'identity_key' => 'normalized_email',
            'existing_target_profile_authoritative' => true,
            'source_site_admin_to_global_site_admin' => false,
            'source_site_admin_target_role' => 'manager_at_batch_parent_category',
            'course_fullname' => 'original - [source_name]',
            'all_new_content_hidden' => true,
            'single_extraction_per_course' => true,
            'normalized_mbz_created' => false,
        ],
        'destination_write_performed' => false,
    ];
    if ($blocking) {
        inc_write_json($workdir . '/blocked-plan.json', $plan);
        throw new RuntimeException(
            'El plan tiene ' . count($blocking) .
            ' bloqueo(s). Revise blocked-plan.json.'
        );
    }
    $plan['plan_sha256'] = inc_plan_hash($plan);
    inc_write_json($workdir . '/plan.json', $plan);
    cli_writeln(
        'INCREMENTAL_PLAN_OK source=' . $sourceid .
        ' identities=' . count($identityplans) .
        ' courses=' . count($courseplans) .
        ' warnings=' . count($warnings) .
        ' write=0 plan_sha256=' . $plan['plan_sha256']
    );
} catch (Throwable $error) {
    cli_error('INCREMENTAL_PLAN_ERROR ' . $error->getMessage());
}
