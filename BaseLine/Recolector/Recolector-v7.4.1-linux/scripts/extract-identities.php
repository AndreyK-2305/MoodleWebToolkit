<?php
// Fase 3: inventario no destructivo de identidades, roles y matriculas.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/identity-contract.php');
require(collector_moodle_config_path());
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'config' => null,
        'source' => null,
        'scope' => 'lab',
        'output' => null,
        'help' => false,
    ],
    ['h' => 'help']
);

if ($options['help']) {
    cli_writeln(<<<TXT
Extrae identidades, roles y matriculas sin modificar Moodle.

Uso:
  php extract-identities.php --source=virtual --scope=lab \
      --output=/exports/phase3/identity-virtual.json \
      --config=/var/www/html/config.php

Opciones:
  --scope=lab|all   lab usa usuarios [LAB-MIGRATION] y administradores.

TXT);
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

$source = (string)($options['source'] ?? '');
$scope = core_text::strtolower((string)$options['scope']);
$output = (string)($options['output'] ?? '');

if (!preg_match('/^[a-z][a-z0-9_-]*$/', $source)) {
    cli_error('El origen debe ser un identificador válido definido en config.yaml.');
}
if (!in_array($scope, ['lab', 'all'], true)) {
    cli_error('El scope debe ser lab o all.');
}
if ($output === '') {
    cli_error('Debe indicar --output.');
}

/**
 * Ejecuta una consulta por lotes de IDs para evitar limites de parametros.
 *
 * @param int[] $ids
 * @param callable $callback
 * @return array
 */
function lab3_by_chunks(array $ids, callable $callback): array {
    $result = [];
    foreach (array_chunk($ids, 400) as $chunk) {
        foreach ($callback($chunk) as $record) {
            $result[] = $record;
        }
    }
    return $result;
}

/**
 * Normaliza un issuer sin modificar su significado.
 */
function lab3_normalize_issuer(string $issuer): string {
    return rtrim(core_text::strtolower(trim($issuer)), '/');
}

/**
 * Reconoce un emisor Google usando la URL y, como respaldo, su nombre visible.
 */
function lab3_is_google_issuer(string $baseurl, string $name = ''): bool {
    return str_contains(lab3_normalize_issuer($baseurl), 'accounts.google.com') ||
        str_contains(core_text::strtolower(trim($name)), 'google');
}

/**
 * Describe un contexto de asignacion de rol con una clave auditable.
 */
function lab3_describe_context(
    stdClass $context,
    array $courses,
    array $categories,
    array $modules
): array {
    switch ((int)$context->contextlevel) {
        case CONTEXT_SYSTEM:
            return ['system', 'system', 'Sistema'];
        case CONTEXT_COURSECAT:
            $category = $categories[(int)$context->instanceid] ?? null;
            if ($category) {
                $key = trim((string)$category->idnumber) !== ''
                    ? 'category:' . $category->idnumber
                    : 'category-id:' . $category->id;
                return ['coursecat', $key, (string)$category->name];
            }
            return ['coursecat', 'category-id:' . $context->instanceid, 'Categoria no encontrada'];
        case CONTEXT_COURSE:
            $course = $courses[(int)$context->instanceid] ?? null;
            if ($course) {
                $key = trim((string)$course->idnumber) !== ''
                    ? 'course:' . $course->idnumber
                    : 'course-id:' . $course->id;
                return ['course', $key, (string)$course->fullname];
            }
            return ['course', 'course-id:' . $context->instanceid, 'Curso no encontrado'];
        case CONTEXT_MODULE:
            $module = $modules[(int)$context->instanceid] ?? null;
            if ($module) {
                $course = $courses[(int)$module->course] ?? null;
                $coursekey = $course && trim((string)$course->idnumber) !== ''
                    ? (string)$course->idnumber
                    : 'course-id-' . $module->course;
                $modulekey = trim((string)$module->idnumber) !== ''
                    ? (string)$module->idnumber
                    : 'cm-id-' . $module->id;
                return [
                    'module',
                    'module:' . $coursekey . ':' . $module->modname . ':' . $modulekey,
                    $module->modname . ' / ' . $modulekey,
                ];
            }
            return ['module', 'module-id:' . $context->instanceid, 'Actividad no encontrada'];
        case CONTEXT_USER:
            return ['user', 'user-id:' . $context->instanceid, 'Contexto de usuario'];
        default:
            return [
                'other',
                'context-' . $context->contextlevel . ':' . $context->instanceid,
                'Contexto nivel ' . $context->contextlevel,
            ];
    }
}

global $DB, $CFG;

$admins = get_admins();
$adminids = array_map('intval', array_keys($admins));

$params = ['guestid' => guest_user()->id];
$where = 'u.deleted = 0 AND u.id <> :guestid';
if ($scope === 'lab') {
    $markerclause = $DB->sql_like('u.description', ':marker', false);
    $params['marker'] = '%[LAB-MIGRATION]%';
    if ($adminids) {
        [$adminsql, $adminparams] = $DB->get_in_or_equal($adminids, SQL_PARAMS_NAMED, 'siteadmin');
        $where .= ' AND (' . $markerclause . ' OR u.id ' . $adminsql . ')';
        $params += $adminparams;
    } else {
        $where .= ' AND ' . $markerclause;
    }
}

$userrecords = $DB->get_records_sql(
    "SELECT u.* FROM {user} u WHERE {$where} ORDER BY u.id ASC",
    $params
);
$userids = array_map('intval', array_keys($userrecords));
if (!$userids) {
    cli_error('No se encontraron usuarios para scope=' . $scope . '.');
}

$profilevalues = [];
$profilefields = $DB->get_records_list(
    'user_info_field',
    'shortname',
    ['google_issuer', 'google_sub', 'program_codes'],
    '',
    'id,shortname'
);
$fieldnames = [];
foreach ($profilefields as $field) {
    $fieldnames[(int)$field->id] = (string)$field->shortname;
}
if ($fieldnames) {
    $profiledata = lab3_by_chunks($userids, static function(array $chunk) use ($DB): array {
        [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'profileuser');
        return array_values($DB->get_records_select(
            'user_info_data',
            'userid ' . $insql,
            $inparams,
            'id ASC'
        ));
    });
    foreach ($profiledata as $data) {
        if (!isset($fieldnames[(int)$data->fieldid])) {
            continue;
        }
        $profilevalues[(int)$data->userid][$fieldnames[(int)$data->fieldid]] = trim((string)$data->data);
    }
}

$oauthlinks = [];
$oauthfieldmappings = [];
$oauthissuers = [];
$dbman = $DB->get_manager();
if ($dbman->table_exists(new xmldb_table('oauth2_user_field_mapping'))) {
    $mappingrecords = $DB->get_records(
        'oauth2_user_field_mapping',
        null,
        'id ASC',
        'id,issuerid,externalfield,internalfield'
    );
    foreach ($mappingrecords as $mapping) {
        $oauthfieldmappings[(int)$mapping->issuerid][] = [
            'mapping_id' => (int)$mapping->id,
            'external_field' => trim((string)$mapping->externalfield),
            'internal_field' => trim((string)$mapping->internalfield),
        ];
    }
}
if ($dbman->table_exists(new xmldb_table('auth_oauth2_linked_login'))) {
    $linkedrecords = lab3_by_chunks($userids, static function(array $chunk) use ($DB): array {
        [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'oauthuser');
        $sql = "SELECT l.id, l.userid, l.issuerid, l.username, l.email,
                       l.confirmtoken, i.name AS issuername, i.baseurl
                  FROM {auth_oauth2_linked_login} l
                  JOIN {oauth2_issuer} i ON i.id = l.issuerid
                 WHERE l.userid {$insql}
              ORDER BY l.id ASC";
        return array_values($DB->get_records_sql($sql, $inparams));
    });
    foreach ($linkedrecords as $link) {
        $issuerid = (int)$link->issuerid;
        $linkedusername = trim((string)$link->username);
        $linkedemail = core_text::strtolower(trim((string)$link->email));
        $mappings = $oauthfieldmappings[$issuerid] ?? [];
        $classification = collector_classify_oauth_identifier(
            $linkedusername,
            $linkedemail,
            $mappings
        );
        $issuerbaseurl = lab3_normalize_issuer((string)$link->baseurl);
        $issuername = trim((string)$link->issuername);
        $oauthlinks[(int)$link->userid][] = [
            'issuer_id' => $issuerid,
            'issuer_name' => $issuername,
            'issuer_baseurl' => $issuerbaseurl,
            'linked_username' => $linkedusername,
            'linked_email' => $linkedemail,
            'identifier_kind' => $classification['kind'],
            'identifier_evidence' => $classification['evidence'],
            'sub_verified' => $classification['sub_verified'],
            'mapping_consistent' => $classification['mapping_consistent'],
            'username_external_fields' =>
                $classification['username_external_fields'],
            'is_google' => lab3_is_google_issuer($issuerbaseurl, $issuername),
            'confirmed' => trim((string)$link->confirmtoken) === '',
        ];
        if (!isset($oauthissuers[$issuerid])) {
            $oauthissuers[$issuerid] = [
                'issuer_id' => $issuerid,
                'issuer_name' => $issuername,
                'issuer_baseurl' => $issuerbaseurl,
                'is_google' => lab3_is_google_issuer($issuerbaseurl, $issuername),
                'user_field_mappings' => $mappings,
            ];
        }
    }
}
ksort($oauthissuers, SORT_NUMERIC);
$oauthissuers = array_values($oauthissuers);

$users = [];
foreach ($userrecords as $user) {
    $values = $profilevalues[(int)$user->id] ?? [];
    $reportedissuer = lab3_normalize_issuer(
        (string)($values['google_issuer'] ?? '')
    );
    $reportedsub = trim((string)($values['google_sub'] ?? ''));
    $issuer = '';
    $sub = '';
    $subverified = false;
    $identitysource = 'missing';
    if ($reportedissuer !== '' &&
            $reportedsub !== '' &&
            lab3_is_google_issuer($reportedissuer) &&
            filter_var($reportedsub, FILTER_VALIDATE_EMAIL) === false) {
        $issuer = $reportedissuer;
        $sub = $reportedsub;
        $subverified = true;
        $identitysource = 'profile_google_sub';
    } else if ($reportedsub !== '' || $reportedissuer !== '') {
        $identitysource = 'profile_google_identity_incomplete_or_invalid';
    }

    $useroauthlinks = $oauthlinks[(int)$user->id] ?? [];
    $googlelinks = array_values(array_filter(
        $useroauthlinks,
        static fn(array $link): bool =>
            ($link['confirmed'] ?? false) === true &&
            ($link['is_google'] ?? false) === true
    ));
    $primarylink = null;
    if (count($googlelinks) === 1) {
        $primarylink = $googlelinks[0];
    } else if (count($googlelinks) > 1 && $reportedissuer !== '') {
        $matches = array_values(array_filter(
            $googlelinks,
            static fn(array $link): bool =>
                ($link['issuer_baseurl'] ?? '') === $reportedissuer
        ));
        if (count($matches) === 1) {
            $primarylink = $matches[0];
        }
    }

    $oauthissuerid = 0;
    $oauthlinkedusername = '';
    $oauthidentifierkind = 'unknown';
    $oauthidentifierevidence = count($googlelinks) > 1
        ? 'multiple_google_links_require_review'
        : 'no_confirmed_google_link';
    $oauthusernameexternalfields = [];
    if (is_array($primarylink)) {
        $oauthissuerid = (int)$primarylink['issuer_id'];
        $oauthlinkedusername = trim((string)$primarylink['linked_username']);
        $oauthidentifierkind = (string)$primarylink['identifier_kind'];
        $oauthidentifierevidence = (string)$primarylink['identifier_evidence'];
        $oauthusernameexternalfields =
            $primarylink['username_external_fields'] ?? [];
        if (!$subverified &&
                ($primarylink['sub_verified'] ?? false) === true &&
                $oauthidentifierkind === 'sub' &&
                $oauthlinkedusername !== '') {
            $issuer = (string)$primarylink['issuer_baseurl'];
            $sub = $oauthlinkedusername;
            $subverified = true;
            $identitysource = 'oauth_linked_login_mapped_sub';
        } else if (!$subverified) {
            $issuer = (string)$primarylink['issuer_baseurl'];
            if ($oauthidentifierkind === 'email') {
                $identitysource = 'oauth_linked_login_email_identifier';
            } else if ($oauthidentifierkind === 'opaque') {
                $identitysource = 'oauth_linked_login_opaque_identifier';
            } else {
                $identitysource = 'oauth_linked_login_unclassified';
            }
        }
    } else if (!$subverified && count($googlelinks) > 1) {
        $identitysource = 'multiple_google_oauth_links_require_review';
    }

    $users[] = [
        'source' => $source,
        'source_user_id' => (int)$user->id,
        'username' => (string)$user->username,
        'firstname' => (string)$user->firstname,
        'lastname' => (string)$user->lastname,
        'email' => core_text::strtolower(trim((string)$user->email)),
        'auth' => (string)$user->auth,
        'idnumber' => trim((string)$user->idnumber),
        'suspended' => (bool)$user->suspended,
        'timecreated' => (int)$user->timecreated,
        'timemodified' => (int)$user->timemodified,
        'google_issuer' => $issuer,
        'google_sub' => $sub,
        'google_sub_verified' => $subverified,
        'google_issuer_reported' => $reportedissuer,
        'google_sub_reported' => $reportedsub,
        'oauth_issuer_id' => $oauthissuerid,
        'oauth_linked_username' => $oauthlinkedusername,
        'oauth_identifier_kind' => $oauthidentifierkind,
        'oauth_identifier_evidence' => $oauthidentifierevidence,
        'oauth_username_external_fields' => $oauthusernameexternalfields,
        'confirmed_google_oauth_links' => count($googlelinks),
        'program_codes' => trim((string)($values['program_codes'] ?? '')),
        'identity_source' => $identitysource,
        'is_site_admin' => in_array((int)$user->id, $adminids, true),
        'oauth_links' => $useroauthlinks,
    ];
}

$courses = $DB->get_records('course', null, 'id ASC', 'id,idnumber,shortname,fullname,category');
$categories = $DB->get_records('course_categories', null, 'id ASC', 'id,name,idnumber,path');
$modules = $DB->get_records_sql(
    "SELECT cm.id, cm.course, cm.idnumber, m.name AS modname
       FROM {course_modules} cm
       JOIN {modules} m ON m.id = cm.module
   ORDER BY cm.id ASC"
);

$roles = lab3_by_chunks($userids, static function(array $chunk) use ($DB): array {
    [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'roleuser');
    $sql = "SELECT ra.id, ra.userid, ra.roleid, ra.contextid, ra.component, ra.itemid,
                   r.shortname AS roleshortname, r.name AS rolename, r.archetype,
                   ctx.contextlevel, ctx.instanceid
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
              JOIN {context} ctx ON ctx.id = ra.contextid
             WHERE ra.userid {$insql}
          ORDER BY ra.id ASC";
    return array_values($DB->get_records_sql($sql, $inparams));
});

$classificationcapabilities = [
    'moodle/site:config',
    'moodle/user:create',
    'moodle/user:update',
    'moodle/role:assign',
    'moodle/role:manage',
    'moodle/course:create',
    'moodle/course:update',
    'moodle/course:manageactivities',
    'moodle/grade:edit',
    'mod/assign:grade',
    'mod/assign:submit',
    'mod/forum:replypost',
    'moodle/course:view',
];
$assignedroleids = array_values(array_unique(array_map(
    static fn(stdClass $role): int => (int)$role->roleid,
    $roles
)));
$roledefinitions = $assignedroleids
    ? $DB->get_records_list('role', 'id', $assignedroleids, 'id ASC', 'id,shortname,name,archetype')
    : [];
$rolesignals = [];
if ($assignedroleids) {
    $rolecapabilities = lab3_by_chunks($assignedroleids, static function(array $chunk) use ($DB): array {
        [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'caprole');
        return array_values($DB->get_records_select(
            'role_capabilities',
            'roleid ' . $insql,
            $inparams,
            'id ASC',
            'id,roleid,capability,permission,contextid'
        ));
    });
    foreach ($rolecapabilities as $capability) {
        $name = (string)$capability->capability;
        if (!in_array($name, $classificationcapabilities, true)) {
            continue;
        }
        if ((int)$capability->permission === CAP_ALLOW) {
            $rolesignals[(int)$capability->roleid][$name] = true;
        }
    }
}

$rolecatalog = [];
foreach ($roledefinitions as $definition) {
    $signals = array_keys($rolesignals[(int)$definition->id] ?? []);
    sort($signals, SORT_STRING);
    $rolecatalog[] = [
        'source' => $source,
        'source_role_id' => (int)$definition->id,
        'role_shortname' => (string)$definition->shortname,
        'role_name' => (string)$definition->name,
        'archetype' => (string)$definition->archetype,
        'allowed_classification_capabilities' => $signals,
    ];
}
if (array_intersect($adminids, $userids)) {
    $rolecatalog[] = [
        'source' => $source,
        'source_role_id' => 0,
        'role_shortname' => 'siteadmin',
        'role_name' => 'Administrador del sitio',
        'archetype' => 'siteadmin',
        'allowed_classification_capabilities' => ['moodle/site:config'],
    ];
}

$roleoutput = [];
foreach ($roles as $role) {
    [$level, $key, $name] = lab3_describe_context($role, $courses, $categories, $modules);
    $roleoutput[] = [
        'source' => $source,
        'source_user_id' => (int)$role->userid,
        'source_role_assignment_id' => (int)$role->id,
        'source_role_id' => (int)$role->roleid,
        'source_context_id' => (int)$role->contextid,
        'context_level' => $level,
        'context_key' => $key,
        'context_name' => $name,
        'role_shortname' => (string)$role->roleshortname,
        'role_name' => (string)$role->rolename,
        'role_archetype' => (string)$role->archetype,
        'component' => (string)$role->component,
        'item_id' => (int)$role->itemid,
    ];
}

// En Moodle el administrador del sitio no es una asignacion de rol ordinaria.
// Se agrega como fila sintetica para que el plan de normalizacion lo trate de
// forma explicita y siempre requiera aprobacion manual.
$systemcontextid = (int)context_system::instance()->id;
foreach (array_intersect($adminids, $userids) as $adminid) {
    $roleoutput[] = [
        'source' => $source,
        'source_user_id' => (int)$adminid,
        'source_role_assignment_id' => 0,
        'source_role_id' => 0,
        'source_context_id' => $systemcontextid,
        'context_level' => 'system',
        'context_key' => 'system',
        'context_name' => 'Sistema',
        'role_shortname' => 'siteadmin',
        'role_name' => 'Administrador del sitio',
        'role_archetype' => 'siteadmin',
        'component' => 'core_siteadmin',
        'item_id' => 0,
    ];
}

$enrolments = lab3_by_chunks($userids, static function(array $chunk) use ($DB): array {
    [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'enroluser');
    $sql = "SELECT ue.id, ue.userid, ue.status, ue.timestart, ue.timeend,
                   e.enrol, e.courseid, c.idnumber AS courseidnumber,
                   c.shortname AS courseshortname, c.fullname AS coursefullname
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
              JOIN {course} c ON c.id = e.courseid
             WHERE ue.userid {$insql}
          ORDER BY ue.id ASC";
    return array_values($DB->get_records_sql($sql, $inparams));
});
$enroloutput = [];
foreach ($enrolments as $enrolment) {
    $coursekey = trim((string)$enrolment->courseidnumber) !== ''
        ? 'course:' . $enrolment->courseidnumber
        : 'course-id:' . $enrolment->courseid;
    $enroloutput[] = [
        'source' => $source,
        'source_user_id' => (int)$enrolment->userid,
        'source_enrolment_id' => (int)$enrolment->id,
        'course_id' => (int)$enrolment->courseid,
        'course_key' => $coursekey,
        'course_shortname' => (string)$enrolment->courseshortname,
        'course_fullname' => (string)$enrolment->coursefullname,
        'enrol_method' => (string)$enrolment->enrol,
        'status' => (int)$enrolment->status,
        'time_start' => (int)$enrolment->timestart,
        'time_end' => (int)$enrolment->timeend,
    ];
}

$payload = [
    'metadata' => [
        'schema_version' => COLLECTOR_IDENTITY_SCHEMA_VERSION,
        'source' => $source,
        'scope' => $scope,
        'site_shortname' => (string)$SITE->shortname,
        'moodle_release' => (string)$CFG->release,
        'generated_at_utc' => gmdate('c'),
        'google_sub_policy' => COLLECTOR_GOOGLE_SUB_POLICY,
        'oauth_linked_username_semantics' =>
            'moodle_auth_oauth2_linked_login_username',
        'admin_accounts_excluded' => false,
        'admin_accounts_included' => true,
    ],
    'users' => $users,
    'oauth_issuers' => $oauthissuers,
    'roles' => $roleoutput,
    'role_catalog' => $rolecatalog,
    'enrolments' => $enroloutput,
];

$contracterrors = collector_validate_identity_payload($payload);
if ($contracterrors) {
    cli_error('Contrato de identidades inválido: ' . implode(' ', $contracterrors));
}

$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
    cli_error('No fue posible crear el directorio ' . $directory . '.');
}
$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($output, $json . PHP_EOL) === false) {
    cli_error('No fue posible escribir ' . $output . '.');
}

cli_writeln(
    'EXTRACCION_OK source=' . $source .
    ' users=' . count($users) .
    ' roles=' . count($roleoutput) .
    ' enrolments=' . count($enroloutput)
);
