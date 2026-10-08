<?php
// Funciones compartidas de la fase 5: contrato, inventario, backup y normalización.

declare(strict_types=1);

defined('MOODLE_INTERNAL') || die();
umask(0007);

global $CFG;
require_once($CFG->libdir . '/filelib.php');

function p5_norm(string $value): string {
    return core_text::strtolower(trim($value));
}

function p5_read_json(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException('No se puede leer ' . $path . '.');
    }
    $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException($path . ' no contiene un objeto JSON.');
    }
    return $data;
}

function p5_write_json(string $path, array $data): void {
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $directory = dirname($path);
    if (!is_dir($directory) &&
            !mkdir($directory, 0770, true) &&
            !is_dir($directory)) {
        throw new RuntimeException('No fue posible crear ' . $directory . '.');
    }
    $temporary = $path . '.partial-' . bin2hex(random_bytes(6));
    if ($json === false ||
            file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false ||
            !rename($temporary, $path)) {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
        throw new RuntimeException('No fue posible crear ' . $path . '.');
    }
}

function p5_read_csv(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException('No se puede leer ' . $path . '.');
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('No fue posible abrir ' . $path . '.');
    }
    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    if ($headers === false) {
        fclose($handle);
        throw new RuntimeException($path . ' no contiene encabezados.');
    }
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    if (count($headers) !== count(array_unique($headers))) {
        fclose($handle);
        throw new RuntimeException($path . ' contiene columnas repetidas.');
    }
    $rows = [];
    $line = 1;
    while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $line++;
        if ($values === [null] || $values === []) {
            continue;
        }
        if (count($values) !== count($headers)) {
            fclose($handle);
            throw new RuntimeException($path . ', fila ' . $line . ': columnas inválidas.');
        }
        $row = array_combine($headers, $values);
        if ($row === false) {
            fclose($handle);
            throw new RuntimeException($path . ', fila ' . $line . ': no se pudo interpretar.');
        }
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

function p5_write_csv(string $path, array $columns, array $rows): void {
    $directory = dirname($path);
    if (!is_dir($directory) &&
            !mkdir($directory, 0770, true) &&
            !is_dir($directory)) {
        throw new RuntimeException('No fue posible crear ' . $directory . '.');
    }
    $temporary = $path . '.partial-' . bin2hex(random_bytes(6));
    $handle = fopen($temporary, 'wb');
    if ($handle === false) {
        throw new RuntimeException('No fue posible crear ' . $path . '.');
    }
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $columns, ',', '"', '\\', "\r\n");
    foreach ($rows as $row) {
        $values = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } else if (is_array($value)) {
                $value = implode('|', array_map('strval', $value));
            }
            $values[] = $value;
        }
        fputcsv($handle, $values, ',', '"', '\\', "\r\n");
    }
    if (!fclose($handle) || !rename($temporary, $path)) {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
        throw new RuntimeException('No fue posible sellar ' . $path . '.');
    }
}

function p5_hash_files(array $paths): array {
    $hashes = [];
    foreach ($paths as $name => $path) {
        if (!is_readable($path)) {
            throw new RuntimeException('Falta el archivo requerido ' . $path . '.');
        }
        $hashes[$name] = hash_file('sha256', $path);
    }
    ksort($hashes, SORT_STRING);
    return $hashes;
}

function p5_assert_artifact_hashes(array $expected, array $paths): array {
    $actual = p5_hash_files($paths);
    if ($expected !== $actual) {
        throw new RuntimeException('Un artefacto de fase 5 cambió después de la simulación.');
    }
    return $actual;
}

function p5_require_sha256(string $value, string $label): string {
    $value = p5_norm($value);
    if (!preg_match('/^[a-f0-9]{64}$/', $value)) {
        throw new RuntimeException($label . ' no contiene un SHA-256 válido.');
    }
    return $value;
}

/**
 * Valida que la fase 4 aplicada y verificada siga siendo exactamente la aprobada.
 */
function p5_load_phase4_contract(
    string $phase4dir,
    string $configsha,
    string $targetid,
    bool $expectlab
): array {
    $phase4dir = rtrim($phase4dir, '/\\');
    $paths = [
        'target_user_plan.csv' => $phase4dir . '/target_user_plan.csv',
        'plan_summary.json' => $phase4dir . '/plan_summary.json',
        'target_user_map.csv' => $phase4dir . '/target_user_map.csv',
        'source_to_target_user_map.csv' => $phase4dir . '/source_to_target_user_map.csv',
        'apply_summary.json' => $phase4dir . '/apply_summary.json',
        'verification.csv' => $phase4dir . '/verification.csv',
        'verification.json' => $phase4dir . '/verification.json',
    ];
    $hashes = p5_hash_files($paths);
    $plansummary = p5_read_json($paths['plan_summary.json']);
    $applysummary = p5_read_json($paths['apply_summary.json']);
    $verification = p5_read_json($paths['verification.json']);
    foreach ([$plansummary, $applysummary, $verification] as $summary) {
        if (($summary['config_sha256'] ?? '') !== $configsha ||
                ($summary['target_id'] ?? '') !== $targetid) {
            throw new RuntimeException('La fase 4 corresponde a otra configuración o destino.');
        }
    }
    if (($verification['validation'] ?? '') !== 'passed' ||
            (int)($verification['failed_checks'] ?? -1) !== 0 ||
            (int)($verification['oauth2_links_failed'] ?? -1) !== 0 ||
            (int)($verification['oauth2_links_verified'] ?? -1) !==
                (int)($verification['oauth2_links_expected'] ?? -2) ||
            (int)($verification['oauth2_issuer_id'] ?? 0) < 1 ||
            (int)($applysummary['oauth2_issuer_id'] ?? 0) !==
                (int)($verification['oauth2_issuer_id'] ?? -1)) {
        throw new RuntimeException(
            'La fase 4 no tiene identidades y accesos OAuth2 aprobados. ' .
            'Ejecute nuevamente su verificación.'
        );
    }
    if ($expectlab && ($verification['lab_validation'] ?? '') !== 'passed') {
        throw new RuntimeException('La validación LAB de la fase 4 no está aprobada.');
    }
    if (($applysummary['target_user_map_sha256'] ?? '') !==
            $hashes['target_user_map.csv'] ||
            ($applysummary['source_to_target_user_map_sha256'] ?? '') !==
            $hashes['source_to_target_user_map.csv']) {
        throw new RuntimeException('Los mapas de fase 4 cambiaron después de su aplicación.');
    }
    if (($verification['target_user_map_sha256'] ?? '') !==
            $hashes['target_user_map.csv'] ||
            ($verification['source_to_target_user_map_sha256'] ?? '') !==
            $hashes['source_to_target_user_map.csv'] ||
            ($verification['verification_csv_sha256'] ?? '') !==
            $hashes['verification.csv']) {
        throw new RuntimeException('Los resultados de fase 4 cambiaron después de verificarse.');
    }
    if (($applysummary['roles_applied'] ?? null) !== false ||
            ($applysummary['enrolments_applied'] ?? null) !== false ||
            ($verification['roles_applied'] ?? null) !== false ||
            ($verification['enrolments_applied'] ?? null) !== false) {
        throw new RuntimeException('La fase 4 declara permisos fuera de su alcance aprobado.');
    }

    $targetrows = p5_read_csv($paths['target_user_map.csv']);
    $sourcerows = p5_read_csv($paths['source_to_target_user_map.csv']);
    $planrows = p5_read_csv($paths['target_user_plan.csv']);
    $targetbycanonical = [];
    foreach ($targetrows as $row) {
        $canonicalid = trim((string)($row['canonical_id'] ?? ''));
        $targetuserid = (int)($row['target_user_id'] ?? 0);
        if ($canonicalid === '' || $targetuserid < 1 || isset($targetbycanonical[$canonicalid])) {
            throw new RuntimeException('target_user_map.csv contiene una fila inválida o repetida.');
        }
        $targetbycanonical[$canonicalid] = $row;
    }
    $planbycanonical = [];
    foreach ($planrows as $row) {
        $canonicalid = trim((string)($row['canonical_id'] ?? ''));
        if ($canonicalid === '' || isset($planbycanonical[$canonicalid])) {
            throw new RuntimeException('target_user_plan.csv contiene una identidad repetida.');
        }
        $planbycanonical[$canonicalid] = $row;
    }
    $sourcebykey = [];
    foreach ($sourcerows as $row) {
        $source = trim((string)($row['source'] ?? ''));
        $sourceuserid = (int)($row['source_user_id'] ?? 0);
        $canonicalid = trim((string)($row['canonical_id'] ?? ''));
        $key = $source . ':' . $sourceuserid;
        if ($source === '' || $sourceuserid < 1 || isset($sourcebykey[$key])) {
            throw new RuntimeException('source_to_target_user_map.csv contiene una cuenta inválida.');
        }
        $target = $targetbycanonical[$canonicalid] ?? null;
        $plan = $planbycanonical[$canonicalid] ?? null;
        $mappedid = (int)($row['target_user_id'] ?? 0);
        if (($row['mapping_status'] ?? '') !== 'mapped' ||
                !$target || !$plan || $mappedid < 1 ||
                $mappedid !== (int)$target['target_user_id']) {
            throw new RuntimeException(
                'La cuenta ' . $key . ' no posee un usuario destino verificable.'
            );
        }
        $row['target_username'] = (string)$target['target_username'];
        $row['target_email'] = (string)$target['target_email'];
        $row['target_auth'] = (string)($plan['desired_auth'] ?? '');
        $sourcebykey[$key] = $row;
    }
    return [
        'paths' => $paths,
        'hashes' => $hashes,
        'plan_summary' => $plansummary,
        'apply_summary' => $applysummary,
        'verification' => $verification,
        'source_rows' => $sourcerows,
        'source_by_key' => $sourcebykey,
        'target_by_canonical' => $targetbycanonical,
        'plan_by_canonical' => $planbycanonical,
    ];
}

function p5_default_source_alias(string $sourceid): string {
    $compact = preg_replace('/[^a-z0-9]+/i', '', trim($sourceid));
    if (!is_string($compact) || strlen($compact) < 3) {
        throw new RuntimeException(
            'El source_id no permite derivar un alias operativo de tres caracteres.'
        );
    }
    return strtoupper(substr($compact, 0, 3));
}

function p5_source_alias(string $value): string {
    $alias = strtoupper(trim($value));
    if (!preg_match('/^[A-Z0-9]{3}$/', $alias)) {
        throw new RuntimeException(
            'El alias del origen debe contener exactamente tres caracteres A-Z/0-9.'
        );
    }
    return $alias;
}

function p5_operational_component(string $value): string {
    $value = trim($value);
    $value = preg_replace('/[^\p{L}\p{N}_.:-]+/u', '-', $value);
    if (!is_string($value)) {
        throw new RuntimeException('No fue posible normalizar el código operativo.');
    }
    $value = preg_replace('/-+/u', '-', $value);
    return trim((string)$value, '-');
}

function p5_normalize_terminal_period(string $value): string {
    $normalized = preg_replace_callback(
        '/-(II|I)$/iu',
        static function(array $match): string {
            return '-' . (core_text::strtoupper((string)$match[1]) === 'II' ? '2' : '1');
        },
        $value
    );
    return is_string($normalized) ? $normalized : $value;
}

function p5_course_marker(string $sourcealias, string $sourceshortname): string {
    $alias = p5_source_alias($sourcealias);
    $shortname = p5_operational_component($sourceshortname);
    if ($shortname === '') {
        throw new RuntimeException(
            'El curso no contiene un shortname utilizable para su código operativo.'
        );
    }
    $shortname = p5_normalize_terminal_period($shortname);
    $code = $alias . '-' . $shortname;
    if (core_text::strlen($code) > 100) {
        throw new RuntimeException(
            'El código operativo del curso supera los 100 caracteres; requiere resolución manual.'
        );
    }
    return $code;
}

function p5_module_key(string $modname, string $idnumber, string $name): string {
    $identity = trim($idnumber) !== '' ? trim($idnumber) : trim($name);
    return p5_norm($modname) . '|' . p5_norm($identity);
}

const P5_MODULE_IDNUMBER_PREFIX = 'MIG-P5-MOD-';
const P5_MODULE_IDNUMBER_MAX_LENGTH = 100;

/**
 * Identidad técnica persistente para un course_module sin idnumber.
 *
 * El valor no depende del orden, del reloj ni de IDs asignados por el Moodle
 * destino. Los 128 bits visibles del SHA-256 mantienen el resultado muy por
 * debajo de los 100 caracteres admitidos por course_modules.idnumber.
 */
function p5_generated_module_idnumber(
    string $sourceid,
    int $sourcecourseid,
    int $sourcemoduleid,
    string $modname,
    int $instance
): string {
    if (!preg_match('/^[a-z][a-z0-9_-]*$/', p5_norm($sourceid)) ||
            $sourcecourseid < 1 || $sourcemoduleid < 1 ||
            p5_norm($modname) === '' || $instance < 1) {
        throw new RuntimeException(
            'No es posible generar un idnumber técnico con identidad de módulo incompleta.'
        );
    }
    $seed = implode('|', [
        p5_norm($sourceid),
        (string)$sourcecourseid,
        (string)$sourcemoduleid,
        p5_norm($modname),
        (string)$instance,
    ]);
    $idnumber = P5_MODULE_IDNUMBER_PREFIX . strtoupper(substr(hash('sha256', $seed), 0, 32));
    if (strlen($idnumber) > P5_MODULE_IDNUMBER_MAX_LENGTH) {
        throw new RuntimeException('El idnumber técnico excede el máximo compatible con Moodle.');
    }
    return $idnumber;
}

function p5_effective_module_key(string $modname, string $effectiveidnumber): string {
    if (trim($effectiveidnumber) === '') {
        throw new RuntimeException('Un módulo normalizado quedó sin effective_idnumber.');
    }
    return p5_norm($modname) . '|' . p5_norm($effectiveidnumber);
}

/**
 * Calcula identidades efectivas sin alterar el backup ni el inventario recibido.
 */
function p5_prepare_module_identities(
    array $inventory,
    string $sourceid,
    int $sourcecourseid
): array {
    if (!is_array($inventory['modules'] ?? null) || $sourcecourseid < 1) {
        throw new RuntimeException('El inventario no contiene módulos normalizables.');
    }
    $prepared = $inventory;
    $used = [];
    $byid = [];
    $legacykeys = [];
    $audit = [];
    $preserved = 0;
    $generated = 0;

    // Los idnumber institucionales se reservan primero: uno repetido es una
    // colisión real y nunca debe ocultarse generando otro valor.
    foreach ($inventory['modules'] as $module) {
        $sourcemoduleid = (int)($module['source_module_id'] ?? 0);
        $modname = p5_norm((string)($module['modname'] ?? ''));
        $instance = (int)($module['instance'] ?? 0);
        if ($sourcemoduleid < 1 || $modname === '' || $instance < 1 ||
                array_key_exists($sourcemoduleid, $byid)) {
            throw new RuntimeException(
                'El inventario contiene un módulo sin identidad de origen válida o repetida.'
            );
        }
        $byid[$sourcemoduleid] = null;
        $original = trim((string)($module['idnumber'] ?? ''));
        if ($original === '') {
            continue;
        }
        if (strlen($original) > P5_MODULE_IDNUMBER_MAX_LENGTH) {
            throw new RuntimeException(
                'El idnumber original del módulo ' . $sourcemoduleid .
                ' excede el máximo compatible con Moodle.'
            );
        }
        $normalized = p5_norm($original);
        if (isset($used[$normalized])) {
            throw new RuntimeException(
                'MODULE_IDNUMBER_DUPLICATE Los módulos ' . $used[$normalized] .
                ' y ' . $sourcemoduleid . ' comparten un idnumber no vacío.'
            );
        }
        $used[$normalized] = $sourcemoduleid;
    }
    $byid = [];

    foreach ($inventory['modules'] as $index => $module) {
        $sourcemoduleid = (int)$module['source_module_id'];
        $modname = p5_norm((string)$module['modname']);
        $instance = (int)$module['instance'];
        $name = (string)($module['name'] ?? '');
        $original = trim((string)($module['idnumber'] ?? ''));
        $effective = $original;
        $reason = 'original_idnumber';
        $origin = 'original';
        if ($effective === '') {
            $effective = p5_generated_module_idnumber(
                $sourceid,
                $sourcecourseid,
                $sourcemoduleid,
                $modname,
                $instance
            );
            $normalized = p5_norm($effective);
            if (isset($used[$normalized])) {
                throw new RuntimeException(
                    'MODULE_IDNUMBER_COLLISION El idnumber técnico del módulo ' .
                    $sourcemoduleid . ' colisiona con el módulo ' . $used[$normalized] . '.'
                );
            }
            $used[$normalized] = $sourcemoduleid;
            $reason = 'missing_idnumber';
            $origin = 'generated';
            $generated++;
        } else {
            $preserved++;
        }
        $legacykey = p5_module_key($modname, $original, $name);
        $module['original_idnumber'] = $original;
        $module['generated_idnumber'] = $origin === 'generated' ? $effective : '';
        $module['effective_idnumber'] = $effective;
        $module['idnumber_origin'] = $origin;
        // idnumber refleja el valor que viajará en la copia normalizada.
        $module['idnumber'] = $effective;
        $module['module_key'] = p5_effective_module_key($modname, $effective);
        $prepared['modules'][$index] = $module;
        $byid[$sourcemoduleid] = $module;
        $legacykeys[$legacykey][] = $sourcemoduleid;
        $audit[] = [
            'source_module_id' => $sourcemoduleid,
            'modname' => $modname,
            'instance' => $instance,
            'name' => $name,
            'original_idnumber' => $original,
            'effective_idnumber' => $effective,
            'reason' => $reason,
        ];
    }
    $modulekeys = array_column($prepared['modules'], 'module_key');
    if (count($modulekeys) !== count(array_unique($modulekeys))) {
        throw new RuntimeException('MODULE_KEY_COLLISION La normalización no produjo llaves únicas.');
    }
    $prepared['modules'] = p5_sorted_rows(
        $prepared['modules'],
        static fn(array $row): string => (string)$row['module_key']
    );
    usort($audit, static fn(array $a, array $b): int =>
        ((int)$a['source_module_id']) <=> ((int)$b['source_module_id'])
    );
    return [
        'inventory' => $prepared,
        'modules_by_source_id' => $byid,
        'legacy_module_ids_by_key' => $legacykeys,
        'audit_rows' => $audit,
        'metrics' => [
            'module_idnumbers_preserved' => $preserved,
            'module_idnumbers_generated' => $generated,
            'module_idnumber_collisions' => 0,
            'module_keys_unique' => count($modulekeys),
        ],
    ];
}

function p5_sorted_rows(array $rows, callable $keybuilder): array {
    usort($rows, static function(array $left, array $right) use ($keybuilder): int {
        return strcmp((string)$keybuilder($left), (string)$keybuilder($right));
    });
    return $rows;
}

/**
 * Inventario semántico que puede compararse aunque cambien los IDs internos.
 */
function p5_collect_course_inventory(int $courseid, bool $keepmodulelinks = false): array {
    global $DB;

    $course = $DB->get_record(
        'course',
        ['id' => $courseid],
        'id,category,fullname,shortname,idnumber,startdate,enddate,format,enablecompletion',
        MUST_EXIST
    );
    $context = context_course::instance($courseid);
    $modules = [];
    $modulebyid = [];
    $modulebyinstance = [];
    $modulerecords = $DB->get_records_sql(
        'SELECT cm.id, cm.instance, cm.idnumber, cm.section, cm.completion,
                cs.section AS sectionnumber, m.name AS modname
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
      LEFT JOIN {course_sections} cs ON cs.id = cm.section
          WHERE cm.course = :courseid AND cm.deletioninprogress = 0
       ORDER BY m.name, cm.id',
        ['courseid' => $courseid]
    );
    $instancesbytype = [];
    foreach ($modulerecords as $record) {
        $instancesbytype[(string)$record->modname][] = (int)$record->instance;
    }
    $namesbytype = [];
    foreach ($instancesbytype as $modname => $instanceids) {
        if (!$DB->get_manager()->table_exists($modname)) {
            continue;
        }
        $instanceids = array_values(array_unique(array_map('intval', $instanceids)));
        foreach ($DB->get_records_list(
            $modname,
            'id',
            $instanceids,
            '',
            'id,name'
        ) as $instance) {
            $namesbytype[$modname][(int)$instance->id] = (string)$instance->name;
        }
    }
    $forumtypes = [];
    if (!empty($instancesbytype['forum'])) {
        $forumids = array_values(array_unique(array_map(
            'intval',
            $instancesbytype['forum']
        )));
        foreach ($DB->get_records_list(
            'forum',
            'id',
            $forumids,
            '',
            'id,type'
        ) as $forum) {
            $forumtypes[(int)$forum->id] = (string)$forum->type;
        }
    }

    foreach ($modulerecords as $record) {
        $name = (string)($namesbytype[(string)$record->modname][
            (int)$record->instance
        ] ?? '');
        $key = p5_module_key((string)$record->modname, (string)$record->idnumber, $name);
        $row = [
            'source_module_id' => (int)$record->id,
            'modname' => (string)$record->modname,
            'instance' => (int)$record->instance,
            'idnumber' => (string)$record->idnumber,
            'name' => $name,
            'module_key' => $key,
            'completion_mode' => (int)$record->completion,
            'section_number' => isset($record->sectionnumber)
                ? (int)$record->sectionnumber
                : -1,
            'forum_type' =>
                (string)$record->modname === 'forum'
                    ? (string)($forumtypes[(int)$record->instance] ?? '')
                    : '',
        ];
        $modules[] = $row;
        $modulebyid[(int)$record->id] = $row;
        $modulebyinstance[(string)$record->modname][(int)$record->instance] = $row;
    }
    $modules = p5_sorted_rows($modules, static fn(array $row): string => $row['module_key']);

    $enrolments = [];
    $enrolrecords = $DB->get_records_sql(
        'SELECT ue.id, ue.userid, ue.status, e.enrol, e.id AS enrolid,
                u.username, u.email
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
           JOIN {user} u ON u.id = ue.userid
          WHERE e.courseid = :courseid AND u.deleted = 0
       ORDER BY ue.userid, e.id',
        ['courseid' => $courseid]
    );
    foreach ($enrolrecords as $record) {
        $enrolments[] = [
            'source_user_id' => (int)$record->userid,
            'source_username' => (string)$record->username,
            'source_email' => (string)$record->email,
            'enrol_method' => (string)$record->enrol,
            'enrol_status' => (int)$record->status,
        ];
    }
    $enrolments = p5_sorted_rows(
        $enrolments,
        static fn(array $row): string => sprintf('%012d|%s', $row['source_user_id'], $row['enrol_method'])
    );

    $roles = [];
    $rolerecords = $DB->get_records_sql(
        'SELECT ra.id, ra.userid, r.shortname, r.archetype, ra.component, ra.itemid
           FROM {role_assignments} ra
           JOIN {role} r ON r.id = ra.roleid
          WHERE ra.contextid = :contextid
       ORDER BY ra.userid, r.shortname',
        ['contextid' => (int)$context->id]
    );
    foreach ($rolerecords as $record) {
        $roles[] = [
            'source_user_id' => (int)$record->userid,
            'role_shortname' => (string)$record->shortname,
            'role_archetype' => (string)$record->archetype,
            'component' => (string)$record->component,
            'itemid' => (int)$record->itemid,
        ];
    }
    $roles = p5_sorted_rows(
        $roles,
        static fn(array $row): string => sprintf(
            '%012d|%s|%s|%012d',
            $row['source_user_id'],
            $row['role_shortname'],
            $row['component'],
            $row['itemid']
        )
    );

    $submissions = [];
    if ($DB->get_manager()->table_exists('assign_submission')) {
        $records = $DB->get_records_sql(
            "SELECT s.id, s.userid, s.status, s.latest, a.id AS activityinstance
               FROM {assign_submission} s
               JOIN {assign} a ON a.id = s.assignment
              WHERE a.course = :courseid AND s.latest = 1 AND s.status <> :newstatus
           ORDER BY a.id, s.userid",
            ['courseid' => $courseid, 'newstatus' => 'new']
        );
        foreach ($records as $record) {
            $module = $modulebyinstance['assign'][(int)$record->activityinstance] ?? null;
            if (!$module) {
                throw new RuntimeException('Una entrega no corresponde a un course_module assign.');
            }
            $row = [
                'source_user_id' => (int)$record->userid,
                'activity_key' => (string)$module['module_key'],
                'status' => (string)$record->status,
            ];
            if ($keepmodulelinks) {
                $row['_source_module_id'] = (int)$module['source_module_id'];
            }
            $submissions[] = $row;
        }
    }

    $assignmentgrades = [];
    if ($DB->get_manager()->table_exists('assign_grades')) {
        $records = $DB->get_records_sql(
            'SELECT g.id, g.userid, g.grade, a.id AS activityinstance
               FROM {assign_grades} g
               JOIN {assign} a ON a.id = g.assignment
              WHERE a.course = :courseid AND g.grade >= 0
           ORDER BY a.id, g.userid',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $module = $modulebyinstance['assign'][(int)$record->activityinstance] ?? null;
            if (!$module) {
                throw new RuntimeException('Una nota no corresponde a un course_module assign.');
            }
            $row = [
                'source_user_id' => (int)$record->userid,
                'activity_key' => (string)$module['module_key'],
                'grade' => round((float)$record->grade, 5),
            ];
            if ($keepmodulelinks) {
                $row['_source_module_id'] = (int)$module['source_module_id'];
            }
            $assignmentgrades[] = $row;
        }
    }

    $forumdiscussions = [];
    $forumposts = [];
    if ($DB->get_manager()->table_exists('forum_discussions')) {
        $records = $DB->get_records_sql(
            'SELECT d.id, d.userid, d.name, f.id AS activityinstance
               FROM {forum_discussions} d
               JOIN {forum} f ON f.id = d.forum
              WHERE f.course = :courseid
           ORDER BY f.id, d.name, d.userid',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $module = $modulebyinstance['forum'][(int)$record->activityinstance] ?? null;
            if (!$module) {
                throw new RuntimeException('Una discusión no corresponde a un course_module forum.');
            }
            $row = [
                'source_user_id' => (int)$record->userid,
                'activity_key' => (string)$module['module_key'],
                'subject' => (string)$record->name,
            ];
            if ($keepmodulelinks) {
                $row['_source_module_id'] = (int)$module['source_module_id'];
            }
            $forumdiscussions[] = $row;
        }
        $records = $DB->get_records_sql(
            'SELECT p.id, p.userid, p.subject, f.id AS activityinstance
               FROM {forum_posts} p
               JOIN {forum_discussions} d ON d.id = p.discussion
               JOIN {forum} f ON f.id = d.forum
              WHERE f.course = :courseid
           ORDER BY f.id, p.id',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $module = $modulebyinstance['forum'][(int)$record->activityinstance] ?? null;
            if (!$module) {
                throw new RuntimeException('Un mensaje no corresponde a un course_module forum.');
            }
            $row = [
                'source_user_id' => (int)$record->userid,
                'activity_key' => (string)$module['module_key'],
                'subject' => (string)$record->subject,
            ];
            if ($keepmodulelinks) {
                $row['_source_module_id'] = (int)$module['source_module_id'];
            }
            $forumposts[] = $row;
        }
    }

    $quizattempts = [];
    if ($DB->get_manager()->table_exists('quiz_attempts')) {
        $quizmodules = [];
        foreach ($modules as $module) {
            if ($module['modname'] === 'quiz') {
                $quizmodules[(int)$module['instance']] = (int)$module['source_module_id'];
            }
        }
        $records = $DB->get_records_sql(
            'SELECT qa.id, qa.quiz AS quizid, qa.userid, qa.state, qa.sumgrades, q.name,
                    qa.attempt, qa.timestart, qa.timefinish, qa.preview
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course = :courseid
                AND qa.preview = 0
           ORDER BY q.name, qa.userid, qa.attempt',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $moduleid = $quizmodules[(int)$record->quizid] ?? null;
            $row = [
                'source_user_id' => (int)$record->userid,
                'activity_key' => $moduleid !== null && isset($modulebyid[$moduleid]) ?
                    $modulebyid[$moduleid]['module_key'] :
                    p5_module_key('quiz', '', (string)$record->name),
                'quiz_id' => (int)$record->quizid,
                'source_module_id' => $moduleid,
                'state' => (string)$record->state,
                'sumgrades' => $record->sumgrades === null ? null : round((float)$record->sumgrades, 5),
                'attempt' => (int)$record->attempt,
                'timestart' => (int)$record->timestart,
                'timefinish' => (int)$record->timefinish,
                'preview' => (int)$record->preview,
                'attempt_id' => (int)$record->id,
            ];
            if ($keepmodulelinks && $moduleid !== null) {
                $row['_source_module_id'] = (int)$moduleid;
            }
            $quizattempts[] = $row;
        }
    }

    $activitycompletions = [];
    if ($DB->get_manager()->table_exists('course_modules_completion')) {
        $records = $DB->get_records_sql(
            'SELECT cmc.id, cmc.userid, cmc.completionstate, cmc.overrideby, cm.id AS cmid
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cm.course = :courseid AND cmc.completionstate > 0
           ORDER BY cm.id, cmc.userid',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            if (!isset($modulebyid[(int)$record->cmid])) {
                continue;
            }
            $row = [
                'source_user_id' => (int)$record->userid,
                'activity_key' => $modulebyid[(int)$record->cmid]['module_key'],
                'completion_state' => (int)$record->completionstate,
            ];
            if ($keepmodulelinks) {
                $row['_source_module_id'] = (int)$record->cmid;
            }
            $activitycompletions[] = $row;
        }
    }

    $coursecompletions = [];
    if ($DB->get_manager()->table_exists('course_completions')) {
        $records = $DB->get_records(
            'course_completions',
            ['course' => $courseid],
            'userid ASC',
            'id,userid,timecompleted'
        );
        foreach ($records as $record) {
            $coursecompletions[] = [
                'source_user_id' => (int)$record->userid,
                'completed' => $record->timecompleted !== null && (int)$record->timecompleted > 0,
            ];
        }
    }

    $files = [];
    $filerecords = $DB->get_records_sql(
        'SELECT f.id, f.userid, f.component, f.filearea, f.filename, cm.id AS cmid
           FROM {files} f
           JOIN {context} ctx ON ctx.id = f.contextid AND ctx.contextlevel = :modulelevel
           JOIN {course_modules} cm ON cm.id = ctx.instanceid
          WHERE cm.course = :courseid AND f.filename <> :dot
       ORDER BY cm.id, f.component, f.filearea, f.filename, f.id',
        ['modulelevel' => CONTEXT_MODULE, 'courseid' => $courseid, 'dot' => '.']
    );
    foreach ($filerecords as $record) {
        if (!isset($modulebyid[(int)$record->cmid])) {
            continue;
        }
        $row = [
            'source_user_id' => (int)$record->userid,
            'activity_key' => $modulebyid[(int)$record->cmid]['module_key'],
            'component' => (string)$record->component,
            'filearea' => (string)$record->filearea,
            'filename' => (string)$record->filename,
        ];
        if (!p5_is_module_file_relation($row)) {
            continue;
        }
        if ($keepmodulelinks) {
            $row['_source_module_id'] = (int)$record->cmid;
        }
        $files[] = $row;
    }

    $modulesbytype = [];
    foreach ($modules as $module) {
        $modname = (string)$module['modname'];
        $modulesbytype[$modname] = ($modulesbytype[$modname] ?? 0) + 1;
    }
    ksort($modulesbytype, SORT_STRING);
    $counts = [
        'sections' => $DB->count_records('course_sections', ['course' => $courseid]),
        'activities' => count($modules),
        'enrolments' => count($enrolments),
        'course_role_assignments' => count($roles),
        'assignment_submissions' => count($submissions),
        'assignment_grades' => count($assignmentgrades),
        'forum_discussions' => count($forumdiscussions),
        'forum_posts' => count($forumposts),
        'quiz_attempts' => count($quizattempts),
        'activity_completions' => count($activitycompletions),
        'course_completions' => count($coursecompletions),
        'module_files' => count($files),
    ];
    return [
        'course' => [
            'source_course_id' => (int)$course->id,
            'category_id' => (int)$course->category,
            'fullname' => (string)$course->fullname,
            'shortname' => (string)$course->shortname,
            'idnumber' => (string)$course->idnumber,
            'startdate' => (int)$course->startdate,
            'enddate' => (int)$course->enddate,
            'format' => (string)$course->format,
            'enablecompletion' => (int)$course->enablecompletion,
        ],
        'counts' => $counts,
        'modules_by_type' => $modulesbytype,
        'modules' => $modules,
        'enrolments' => $enrolments,
        'roles' => $roles,
        'relations' => [
            'assignment_submissions' => $submissions,
            'assignment_grades' => $assignmentgrades,
            'forum_discussions' => $forumdiscussions,
            'forum_posts' => $forumposts,
            'quiz_attempts' => $quizattempts,
            'activity_completions' => $activitycompletions,
            'course_completions' => $coursecompletions,
            'files' => $files,
        ],
    ];
}

/**
 * Contrato común para una fila de files perteneciente a contexto de módulo.
 * Se usa tanto al consultar Moodle como al reconstruir files.xml.
 */
function p5_is_module_file_relation(array $row): bool {
    return (string)($row['filename'] ?? '') !== '' &&
        (string)($row['filename'] ?? '') !== '.' &&
        (string)($row['component'] ?? '') !== '' &&
        (string)($row['filearea'] ?? '') !== '';
}

/**
 * Indica si una fila de files representa un derivado regenerable de
 * assignfeedback_editpdf y no un archivo académico aportado por el usuario.
 *
 * Moodle puede reconstruir estos PDF intermedios al abrir la anotación. No se
 * excluyen stamps, submission_files ni ninguna otra área del componente.
 */
function p5_is_regenerable_editpdf_file(array $row): bool {
    return (string)($row['component'] ?? '') === 'assignfeedback_editpdf' &&
        in_array(
            (string)($row['filearea'] ?? ''),
            ['combined', 'pages', 'partial', 'tmp_jpg_to_pdf'],
            true
        );
}

/**
 * Conserva únicamente archivos comparables entre Moodle 4.5 y 5.x.
 */
function p5_filter_comparable_files(array $rows): array {
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => !p5_is_regenerable_editpdf_file($row)
    ));
}

/**
 * Construye la vista comparable de finalizaciones de curso.
 *
 * Moodle conserva filas con timecompleted NULL para seguimiento. Esas filas
 * permanecen en los inventarios crudos, pero no representan una finalización
 * académica efectiva y no forman parte del contrato estricto comparable.
 */
function p5_course_completion_comparison_view(
    array $expectedrows,
    array $actualrows
): array {
    $effective = static fn(array $rows): array => array_values(array_filter(
        $rows,
        static fn(array $row): bool => ($row['completed'] ?? false) === true
    ));
    $effectiveexpected = $effective($expectedrows);
    $effectiveactual = $effective($actualrows);
    return [
        'expected_rows' => $effectiveexpected,
        'actual_rows' => $effectiveactual,
        'course_completions_raw_expected' => count($expectedrows),
        'course_completions_raw_actual' => count($actualrows),
        'course_completions_effective_expected' => count($effectiveexpected),
        'course_completions_effective_actual' => count($effectiveactual),
        'course_completions_tracking_ignored_expected' =>
            count($expectedrows) - count($effectiveexpected),
        'course_completions_tracking_ignored_actual' =>
            count($actualrows) - count($effectiveactual),
    ];
}

/**
 * Compara inventarios de curso entre versiones distintas de Moodle.
 *
 * Desde Moodle 5.0, las categorías de preguntas que antes vivían en el
 * contexto de curso pueden restaurarse dentro de actividades mod_qbank.
 * Esos módulos técnicos no existían en Moodle 4.5 y, por tanto, no deben
 * interpretarse como pérdida o duplicación de una actividad académica.
 *
 * Moodle también puede omitir durante la restauración los derivados
 * regenerables de assignfeedback_editpdf en combined, pages y partial. La
 * comparación descuenta esas filas en ambos extremos, pero continúa validando
 * estrictamente stamps, submission_files y cualquier otra área de archivos.
 */
function p5_compare_course_inventories(array $expected, array $actual): array {
    $expectedmodulesbytype = $expected['modules_by_type'] ?? [];
    $actualmodulesbytype = $actual['modules_by_type'] ?? [];
    $expectedqbank = (int)($expectedmodulesbytype['qbank'] ?? 0);
    $actualqbank = (int)($actualmodulesbytype['qbank'] ?? 0);
    $ignoredqbank = $expectedqbank === 0 ? $actualqbank : 0;

    $expectedmodulekeylookup = [];
    foreach ($expected['modules'] ?? [] as $module) {
        $modulekey = (string)($module['module_key'] ?? '');
        if ($modulekey !== '') {
            $expectedmodulekeylookup[$modulekey] = true;
        }
    }

    $targetnewsforumcandidates = [];
    foreach ($actual['modules'] ?? [] as $module) {
        $modulekey = (string)($module['module_key'] ?? '');

        $isgeneratednewsforum =
            (string)($module['modname'] ?? '') === 'forum' &&
            (string)($module['forum_type'] ?? '') === 'news' &&
            trim((string)($module['idnumber'] ?? '')) === '' &&
            (int)($module['section_number'] ?? -1) === 0 &&
            $modulekey !== '' &&
            !isset($expectedmodulekeylookup[$modulekey]);

        if ($isgeneratednewsforum) {
            $targetnewsforumcandidates[] = $module;
        }
    }

    // Fail-closed: Moodle debe aportar como máximo un foro news técnico
    // target-only por curso. Si aparecen varios, no normalizamos ninguno.
    $ignorednewsforumrows =
        count($targetnewsforumcandidates) === 1
            ? $targetnewsforumcandidates
            : [];

    $ignorednewsforum = count($ignorednewsforumrows);

    $ignorednewsforumids = [];
    $ignorednewsforumkeys = [];

    foreach ($ignorednewsforumrows as $module) {
        $moduleid = (int)($module['source_module_id'] ?? 0);
        if ($moduleid > 0) {
            $ignorednewsforumids[$moduleid] = true;
        }

        $modulekey = (string)($module['module_key'] ?? '');
        if ($modulekey !== '') {
            $ignorednewsforumkeys[] = $modulekey;
        }
    }

    $comparableexpectedcounts = $expected['counts'] ?? [];
    $comparableactualcounts = $actual['counts'] ?? [];
    $rawexpectedcoursecompletions =
        $expected['relations']['course_completions'] ?? [];
    $rawactualcoursecompletions =
        $actual['relations']['course_completions'] ?? [];
    $coursecompletionview = p5_course_completion_comparison_view(
        is_array($rawexpectedcoursecompletions) ? $rawexpectedcoursecompletions : [],
        is_array($rawactualcoursecompletions) ? $rawactualcoursecompletions : []
    );
    if (isset($comparableexpectedcounts['course_completions']) &&
            is_array($expected['relations']['course_completions'] ?? null)) {
        $comparableexpectedcounts['course_completions'] =
            $coursecompletionview['course_completions_effective_expected'];
    }
    if (isset($comparableactualcounts['course_completions']) &&
            is_array($actual['relations']['course_completions'] ?? null)) {
        $comparableactualcounts['course_completions'] =
            $coursecompletionview['course_completions_effective_actual'];
    }
    if ($ignoredqbank > 0 && array_key_exists('activities', $comparableactualcounts)) {
        $comparableactualcounts['activities'] =
            (int)$comparableactualcounts['activities'] - $ignoredqbank;
    }

    if ($ignorednewsforum > 0 &&
            array_key_exists('activities', $comparableactualcounts)) {
        $comparableactualcounts['activities'] =
            (int)$comparableactualcounts['activities'] - $ignorednewsforum;
    }

    $rawexpectedfiles = $expected['relations']['files'] ?? null;
    $rawactualfiles = $actual['relations']['files'] ?? null;
    $comparableexpectedfiles = is_array($rawexpectedfiles)
        ? p5_filter_comparable_files($rawexpectedfiles)
        : null;
    $comparableactualfiles = is_array($rawactualfiles)
        ? p5_filter_comparable_files($rawactualfiles)
        : null;
    $ignoredexpectededitpdf = is_array($rawexpectedfiles)
        ? count($rawexpectedfiles) - count($comparableexpectedfiles)
        : 0;
    $ignoredactualeditpdf = is_array($rawactualfiles)
        ? count($rawactualfiles) - count($comparableactualfiles)
        : 0;
    $ignoredtargetqbankfiles = 0;
    if ($ignoredqbank > 0 && is_array($comparableactualfiles)) {
        $beforeqbankfilter = count($comparableactualfiles);
        $comparableactualfiles = array_values(array_filter(
            $comparableactualfiles,
            static fn(array $row): bool => !str_starts_with(
                p5_norm((string)($row['activity_key'] ?? '')),
                'qbank|'
            )
        ));
        $ignoredtargetqbankfiles =
            $beforeqbankfilter - count($comparableactualfiles);
    }
    if ($comparableexpectedfiles !== null &&
            array_key_exists('module_files', $comparableexpectedcounts)) {
        $comparableexpectedcounts['module_files'] = count($comparableexpectedfiles);
    }
    if ($comparableactualfiles !== null &&
            array_key_exists('module_files', $comparableactualcounts)) {
        $comparableactualcounts['module_files'] = count($comparableactualfiles);
    }

    $comparableactualmodulesbytype = $actualmodulesbytype;
    if ($ignoredqbank > 0) {
        unset($comparableactualmodulesbytype['qbank']);
    }

    if ($ignorednewsforum > 0) {
        $forumcount =
            (int)($comparableactualmodulesbytype['forum'] ?? 0) -
            $ignorednewsforum;

        if ($forumcount > 0) {
            $comparableactualmodulesbytype['forum'] = $forumcount;
        } else {
            unset($comparableactualmodulesbytype['forum']);
        }
    }

    ksort($expectedmodulesbytype, SORT_STRING);
    ksort($comparableactualmodulesbytype, SORT_STRING);

    $expectedmodulekeys = array_values(array_column(
        $expected['modules'] ?? [],
        'module_key'
    ));
    $comparableactualmodules = array_values(array_filter(
        $actual['modules'] ?? [],
        static fn(array $row): bool =>
            !(
                $ignoredqbank > 0 &&
                (string)($row['modname'] ?? '') === 'qbank'
            ) &&
            !(
                $ignorednewsforum > 0 &&
                isset($ignorednewsforumids[
                    (int)($row['source_module_id'] ?? 0)
                ])
            )
    ));
    $actualmodulekeys = array_values(array_column(
        $comparableactualmodules,
        'module_key'
    ));
    sort($expectedmodulekeys, SORT_STRING);
    sort($actualmodulekeys, SORT_STRING);

    $issues = [];
    foreach ($comparableexpectedcounts as $name => $expectedcount) {
        $rawexpected = (int)(($expected['counts'] ?? [])[$name] ?? -1);
        $rawactual = (int)(($actual['counts'] ?? [])[$name] ?? -1);
        $comparableactual = (int)($comparableactualcounts[$name] ?? -1);
        if ($comparableactual !== (int)$expectedcount) {
            $issues[] = [
                'field' => 'counts.' . $name,
                'expected' => (int)$expectedcount,
                'raw_expected' => $rawexpected,
                'actual' => $rawactual,
                'comparable_actual' => $comparableactual,
            ];
        }
    }
    if ($expectedmodulesbytype !== $comparableactualmodulesbytype) {
        $issues[] = [
            'field' => 'modules_by_type',
            'expected' => $expectedmodulesbytype,
            'actual' => $actualmodulesbytype,
            'comparable_actual' => $comparableactualmodulesbytype,
        ];
    }
    if ($expectedmodulekeys !== $actualmodulekeys) {
        $issues[] = [
            'field' => 'module_keys',
            'expected' => $expectedmodulekeys,
            'actual' => array_values(array_column(
                $actual['modules'] ?? [],
                'module_key'
            )),
            'comparable_actual' => $actualmodulekeys,
        ];
    }

    $compatibilityreasons = [];
    if ($ignoredqbank > 0) {
        $compatibilityreasons[] =
            'Moodle 5.x materializa bancos de preguntas heredados como mod_qbank.';
    }

    if ($ignorednewsforum > 0) {
        $compatibilityreasons[] =
            'El Moodle destino materializó un foro técnico de anuncios ' .
            '(mod_forum type=news, sección 0, sin idnumber) ausente del ' .
            'inventario académico esperado.';
    }

    if (count($targetnewsforumcandidates) > 1) {
        $compatibilityreasons[] =
            'Se detectaron múltiples foros news target-only candidatos; ' .
            'no se aplicó normalización automática.';
    }

    if ($ignoredexpectededitpdf > 0 || $ignoredactualeditpdf > 0) {
        $compatibilityreasons[] =
            'Se excluyeron derivados regenerables de assignfeedback_editpdf en ' .
            'combined, pages, partial y tmp_jpg_to_pdf; stamps y ' .
            'submission_files permanecen estrictos.';
    }

    return [
        'complete' => $issues === [],
        'issues' => $issues,
        'raw_expected_counts' => $expected['counts'] ?? [],
        'expected_counts' => $comparableexpectedcounts,
        'actual_counts' => $actual['counts'] ?? [],
        'comparable_actual_counts' => $comparableactualcounts,
        'expected_modules_by_type' => $expectedmodulesbytype,
        'actual_modules_by_type' => $actualmodulesbytype,
        'comparable_actual_modules_by_type' => $comparableactualmodulesbytype,
        'expected_module_keys' => $expectedmodulekeys,
        'actual_module_keys' => array_values(array_column(
            $actual['modules'] ?? [],
            'module_key'
        )),
        'comparable_actual_module_keys' => $actualmodulekeys,
        'course_completions_comparison' => [
            'course_completions_raw_expected' =>
                $coursecompletionview['course_completions_raw_expected'],
            'course_completions_raw_actual' =>
                $coursecompletionview['course_completions_raw_actual'],
            'course_completions_effective_expected' =>
                $coursecompletionview['course_completions_effective_expected'],
            'course_completions_effective_actual' =>
                $coursecompletionview['course_completions_effective_actual'],
            'course_completions_tracking_ignored_expected' =>
                $coursecompletionview['course_completions_tracking_ignored_expected'],
            'course_completions_tracking_ignored_actual' =>
                $coursecompletionview['course_completions_tracking_ignored_actual'],
        ],
        'compatibility_adjustments' => [
            'ignored_target_qbank_modules' => $ignoredqbank,
            'target_news_forum_candidates' =>
                count($targetnewsforumcandidates),
            'ignored_target_news_forum_modules' =>
                $ignorednewsforum,
            'ignored_target_news_forum_keys' =>
                array_values($ignorednewsforumkeys),
            'news_forum_reason' => $ignorednewsforum > 0
                ? 'Foro técnico de anuncios generado por Moodle destino: ' .
                    'mod_forum type=news, sección 0, sin idnumber y sin ' .
                    'contraparte en el inventario esperado.'
                : '',
            'ignored_target_qbank_files' => $ignoredtargetqbankfiles,
            'ignored_source_assignfeedback_editpdf_files' =>
                $ignoredexpectededitpdf,
            'ignored_target_assignfeedback_editpdf_files' =>
                $ignoredactualeditpdf,
            'qbank_reason' => $ignoredqbank > 0
                ? 'Moodle 5.x materializa bancos de preguntas heredados como mod_qbank.'
                : '',
            'assignfeedback_editpdf_reason' =>
                ($ignoredexpectededitpdf > 0 || $ignoredactualeditpdf > 0)
                    ? 'Se excluyeron derivados regenerables de assignfeedback_editpdf ' .
                        'en combined, pages, partial y tmp_jpg_to_pdf; stamps y ' .
                        'submission_files ' .
                        'permanecen estrictos.'
                    : '',
            'reason' => implode(' ', $compatibilityreasons),
        ],
    ];
}

/**
 * Genera un backup oficial con usuarios y datos académicos.
 */
function p5_create_course_backup(int $courseid, string $rawpath): void {
    global $CFG, $USER;

    require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
    $admin = get_admin();
    if (!$admin) {
        throw new RuntimeException('No existe una cuenta administradora para crear el backup.');
    }
    $olduser = $USER;
    \core\session\manager::set_user($admin);
    $controller = null;
    try {
        $controller = new backup_controller(
            backup::TYPE_1COURSE,
            $courseid,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            (int)$admin->id
        );
        foreach ([
            'users' => 1,
            'anonymize' => 0,
            'activities' => 1,
            'blocks' => 1,
            'filters' => 1,
            'comments' => 1,
            'badges' => 1,
            'calendarevents' => 1,
            'competencies' => 1,
            'contentbankcontent' => 1,
        ] as $settingname => $value) {
            try {
                $controller->get_plan()->get_setting($settingname)->set_value($value);
            } catch (Throwable $ignored) {
                // Algunas ramas no exponen todos los ajustes; los obligatorios
                // se verifican después mediante users.xml y el inventario.
            }
        }
        $controller->execute_plan();
        $results = $controller->get_results();
        $destination = $results['backup_destination'] ?? null;
        if ($destination instanceof stored_file) {
            if (!$destination->copy_content_to($rawpath)) {
                throw new RuntimeException('Moodle no pudo copiar el backup a exports.');
            }
        } else if (is_string($destination) && is_readable($destination)) {
            if (!copy($destination, $rawpath)) {
                throw new RuntimeException('Moodle no pudo copiar el backup a exports.');
            }
        } else {
            throw new RuntimeException('Moodle no devolvió un backup_destination utilizable.');
        }
    } finally {
        if ($controller !== null) {
            $controller->destroy();
        }
        \core\session\manager::set_user($olduser);
    }
    if (!is_readable($rawpath) || filesize($rawpath) < 1) {
        throw new RuntimeException('El backup oficial quedó vacío o no puede leerse.');
    }
}

function p5_dom_text(DOMElement $user, string $tag): string {
    $nodes = $user->getElementsByTagName($tag);
    return $nodes->length > 0 ? trim((string)$nodes->item(0)->textContent) : '';
}

function p5_dom_set(DOMElement $user, string $tag, string $value): void {
    $nodes = $user->getElementsByTagName($tag);
    if ($nodes->length > 0) {
        $nodes->item(0)->nodeValue = $value;
    }
}

function p5_dom_direct_text(DOMElement $element, string $tag): ?string {
    foreach ($element->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $tag) {
            return trim((string)$child->textContent);
        }
    }
    return null;
}

/**
 * Devuelve todos los valores declarados para un dato estructural en la raíz
 * del XML primario de una actividad: atributo de raíz o hijo directo.
 */
function p5_primary_activity_values(DOMElement $root, array $names): array {
    $values = [];
    foreach ($names as $name) {
        if ($root->hasAttribute($name)) {
            $values[] = trim($root->getAttribute($name));
        }
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === $name) {
                $values[] = trim((string)$child->textContent);
            }
        }
    }
    return array_values(array_unique($values, SORT_STRING));
}

/**
 * Extrae y valida la identidad estructural del XML primario modname.xml.
 * module.xml no contiene contextid en los MBZ reales de Moodle 4.5.
 */
function p5_primary_activity_context_id(
    string $primarypath,
    int $sourcemoduleid,
    string $modname
): int {
    if (!is_readable($primarypath)) {
        throw new RuntimeException(
            'MODULE_CONTEXT_MISSING source_module_id=' . $sourcemoduleid .
            ' primary_xml=' . basename($primarypath) . '.'
        );
    }
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->load($primarypath, LIBXML_NONET | LIBXML_PARSEHUGE) ||
            !$dom->documentElement instanceof DOMElement) {
        throw new RuntimeException(
            'MODULE_CONTEXT_MISSING source_module_id=' . $sourcemoduleid .
            ' primary_xml=' . basename($primarypath) . ' invalid_xml=1.'
        );
    }
    $root = $dom->documentElement;

    $moduleids = p5_primary_activity_values($root, ['moduleid', 'module_id']);
    foreach ($moduleids as $moduleid) {
        if (!preg_match('/^[0-9]+$/', $moduleid) || (int)$moduleid !== $sourcemoduleid) {
            throw new RuntimeException(
                'MODULE_PRIMARY_XML_MODULE_ID_MISMATCH source_module_id=' .
                $sourcemoduleid . ' primary_module_id=' . $moduleid . '.'
            );
        }
    }

    $modulenames = p5_primary_activity_values($root, ['modulename', 'modname']);
    foreach ($modulenames as $primarymodname) {
        if (p5_norm($primarymodname) !== p5_norm($modname)) {
            throw new RuntimeException(
                'MODULE_PRIMARY_XML_MODNAME_MISMATCH source_module_id=' .
                $sourcemoduleid . ' expected=' . $modname .
                ' actual=' . $primarymodname . '.'
            );
        }
    }

    $rawcontexts = p5_primary_activity_values($root, ['contextid', 'context_id']);
    if (!$rawcontexts) {
        throw new RuntimeException(
            'MODULE_CONTEXT_MISSING source_module_id=' . $sourcemoduleid .
            ' primary_xml=' . basename($primarypath) . '.'
        );
    }
    $contexts = [];
    foreach ($rawcontexts as $rawcontext) {
        if (!preg_match('/^[0-9]+$/', $rawcontext) || (int)$rawcontext < 1) {
            throw new RuntimeException(
                'MODULE_CONTEXT_MISSING source_module_id=' . $sourcemoduleid .
                ' contextid=' . ($rawcontext === '' ? '(empty)' : $rawcontext) . '.'
            );
        }
        $contexts[] = (int)$rawcontext;
    }
    $contexts = array_values(array_unique($contexts, SORT_NUMERIC));
    if (count($contexts) !== 1) {
        throw new RuntimeException(
            'MODULE_CONTEXT_AMBIGUOUS source_module_id=' . $sourcemoduleid .
            ' contextids=' . implode('|', $contexts) . '.'
        );
    }
    return $contexts[0];
}

/**
 * Reescribe module.xml y devuelve un índice de actividades extraídas.
 */
function p5_rewrite_backup_module_idnumbers(
    string $backupdirectory,
    array $modulesbysourceid,
    ?array &$contextevidence = null
): array {
    $contextevidence = [
        'module_contexts_total' => count($modulesbysourceid),
        'module_contexts_resolved' => 0,
        'module_contexts_missing' => 0,
        'module_contexts_ambiguous' => 0,
        'module_context_duplicates' => 0,
        'module_context_mapping_sha256' => null,
    ];
    $activitiesroot = rtrim($backupdirectory, '/\\') . '/activities';
    if (!is_dir($activitiesroot)) {
        throw new RuntimeException('El backup no contiene el directorio activities.');
    }
    $activitydirectories = [];
    $contextowners = [];
    foreach (glob($activitiesroot . '/*/module.xml') ?: [] as $modulepath) {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        if (!$dom->load($modulepath, LIBXML_NONET) ||
                !$dom->documentElement instanceof DOMElement) {
            throw new RuntimeException('Un module.xml del backup no es XML válido.');
        }
        $root = $dom->documentElement;
        $sourcemoduleid = (int)$root->getAttribute('id');
        if ($sourcemoduleid < 1 && preg_match(
                '/_([0-9]+)$/',
                basename(dirname($modulepath)),
                $match
            )) {
            $sourcemoduleid = (int)$match[1];
        }
        if (!isset($modulesbysourceid[$sourcemoduleid])) {
            throw new RuntimeException(
                'module.xml referencia un source_module_id no inventariado: ' .
                $sourcemoduleid . '.'
            );
        }
        $module = $modulesbysourceid[$sourcemoduleid];
        $idnumbernodes = $root->getElementsByTagName('idnumber');
        if ($idnumbernodes->length !== 1) {
            throw new RuntimeException(
                'module.xml del módulo ' . $sourcemoduleid .
                ' no contiene exactamente un idnumber.'
            );
        }
        $idnumbernodes->item(0)->nodeValue = (string)$module['effective_idnumber'];
        if ($dom->save($modulepath) === false) {
            throw new RuntimeException('No fue posible guardar module.xml normalizado.');
        }
        $primarypath = dirname($modulepath) . '/' . (string)$module['modname'] . '.xml';
        try {
            $contextid = p5_primary_activity_context_id(
                $primarypath,
                $sourcemoduleid,
                (string)$module['modname']
            );
        } catch (Throwable $error) {
            if (str_starts_with($error->getMessage(), 'MODULE_CONTEXT_AMBIGUOUS')) {
                $contextevidence['module_contexts_ambiguous']++;
            } else {
                $contextevidence['module_contexts_missing']++;
            }
            throw $error;
        }
        if (isset($contextowners[$contextid]) &&
                $contextowners[$contextid] !== $sourcemoduleid) {
            $contextevidence['module_context_duplicates']++;
            throw new RuntimeException(
                'MODULE_CONTEXT_DUPLICATE contextid=' . $contextid .
                ' source_module_id_candidates=' . $contextowners[$contextid] .
                '|' . $sourcemoduleid . '.'
            );
        }
        $contextowners[$contextid] = $sourcemoduleid;
        $contextevidence['module_contexts_resolved']++;
        $activitydirectories[$sourcemoduleid] = [
            'path' => dirname($modulepath),
            'context_id' => $contextid,
            'module' => $module,
        ];
    }
    $missing = array_values(array_diff(
        array_map('intval', array_keys($modulesbysourceid)),
        array_map('intval', array_keys($activitydirectories))
    ));
    if ($missing) {
        throw new RuntimeException(
            'El backup no contiene module.xml para source_module_id=' .
            implode(',', $missing) . '.'
        );
    }
    ksort($activitydirectories, SORT_NUMERIC);
    $mapping = [];
    foreach ($activitydirectories as $sourcemoduleid => $activity) {
        $mapping[] = $sourcemoduleid . ':' . (int)$activity['context_id'];
    }
    $contextevidence['module_context_mapping_sha256'] = hash(
        'sha256', implode("\n", $mapping)
    );
    return $activitydirectories;
}

function p5_backup_xml_documents(string $directory): array {
    $documents = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item->isFile() || strtolower($item->getExtension()) !== 'xml' ||
                $item->getFilename() === 'module.xml') {
            continue;
        }
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!$dom->load(
                $item->getPathname(),
                LIBXML_NONET | LIBXML_PARSEHUGE
            )) {
            throw new RuntimeException(
                'El XML de actividad ' . $item->getFilename() . ' no es válido.'
            );
        }
        $documents[] = $dom;
    }
    return $documents;
}

/**
 * Conserva una muestra pequeña y determinista de evidencia de files.xml.
 * El orden de files.xml no determina qué filas aparecen en el diagnóstico.
 */
function p5_add_file_evidence_sample(
    array &$evidence,
    array $row,
    int $limit = 50
): void {
    $evidence['excluded_file_evidence'][] = $row;
    usort(
        $evidence['excluded_file_evidence'],
        static fn(array $left, array $right): int => strcmp(
            json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        )
    );
    if (count($evidence['excluded_file_evidence']) > $limit) {
        array_pop($evidence['excluded_file_evidence']);
    }
}

function p5_backup_original_course_context_id(string $backupdirectory): int {
    $path = rtrim($backupdirectory, '/\\') . '/moodle_backup.xml';
    if (!is_readable($path)) {
        return 0;
    }
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->load($path, LIBXML_NONET)) {
        throw new RuntimeException('moodle_backup.xml no es XML válido.');
    }
    $xpath = new DOMXPath($dom);
    $node = $xpath->query('//*[local-name()="original_course_contextid"]')->item(0);
    if (!$node instanceof DOMNode) {
        return 0;
    }
    $value = trim($node->textContent);
    return preg_match('/^[1-9][0-9]*$/', $value) ? (int)$value : 0;
}

function p5_backup_relation_candidates(
    string $backupdirectory,
    array $activitydirectories,
    ?array &$evidence = null
): array {
    $evidence = [
        'files_xml_rows_total' => 0,
        'files_inventory_rows' => 0,
        'files_module_context_candidates' => 0,
        'files_backup_candidates' => 0,
        // Métricas RC15 conservadas: file_id representa corroboración, no población.
        'files_matched_by_file_id' => 0,
        'files_matched_by_context' => 0,
        'files_confirmed_by_inforef' => 0,
        'files_inforef_only_excluded' => 0,
        'files_non_module_context_excluded' => 0,
        'files_unattributed_nonmodule_excluded' => 0,
        'files_context_inforef_conflicts' => 0,
        'files_field_contract_excluded' => 0,
        'files_unresolved' => 0,
        'files_ambiguous' => 0,
        'files_shared_inforef_accepted' => 0,
        'files_multiset_difference' => 0,
        'files_multiset_signature_difference' => 0,
        'files_multiset_row_difference' => 0,
        'files_regenerable_excluded_inventory' => 0,
        'files_regenerable_excluded_candidates' => 0,
        'excluded_file_evidence' => [],
        'unattributed_file_evidence' => [],
    ];
    $candidates = [
        'assignment_submissions' => [],
        'assignment_grades' => [],
        'forum_discussions' => [],
        'forum_posts' => [],
        'quiz_attempts' => [],
        'activity_completions' => [],
        'files' => [],
    ];
    $contexttomodule = [];
    $fileidtomodules = [];
    $originalcoursecontextid = null;
    foreach ($activitydirectories as $sourcemoduleid => $activity) {
        $module = $activity['module'];
        $modname = (string)$module['modname'];
        $modulekey = (string)$module['module_key'];
        $base = [
            '_source_module_id' => (int)$sourcemoduleid,
            'activity_key' => $modulekey,
        ];
        if ((int)$activity['context_id'] > 0) {
            $contextid = (int)$activity['context_id'];
            if (isset($contexttomodule[$contextid]) &&
                    $contexttomodule[$contextid] !== (int)$sourcemoduleid) {
                $evidence['files_ambiguous']++;
                p5_add_file_evidence_sample($evidence, [
                    'file_id' => null,
                    'contextid' => $contextid,
                    'component' => null,
                    'filearea' => null,
                    'filename' => null,
                    'userid' => null,
                    'inforef_source_module_id' => null,
                    'source_module_id_candidates' => [
                        $contexttomodule[$contextid],
                        (int)$sourcemoduleid,
                    ],
                    'reason' => 'duplicate_module_context_assignment',
                ]);
                throw new RuntimeException(
                    'MODULE_FILE_CONTEXT_AMBIGUOUS contextid=' . $contextid .
                    ' source_module_id_candidates=' .
                    $contexttomodule[$contextid] . '|' . (int)$sourcemoduleid . '.'
                );
            }
            $contexttomodule[$contextid] = (int)$sourcemoduleid;
        }
        $inforefpath = (string)$activity['path'] . '/inforef.xml';
        if (is_readable($inforefpath)) {
            $inforef = new DOMDocument();
            $inforef->preserveWhiteSpace = false;
            if (!$inforef->load($inforefpath, LIBXML_NONET)) {
                throw new RuntimeException('inforef.xml no es XML válido.');
            }
            $xpath = new DOMXPath($inforef);
            foreach ($xpath->query('//*[local-name()="fileref"]//*[local-name()="file"]/*[local-name()="id"]') ?: [] as $idnode) {
                $fileid = (int)$idnode->textContent;
                if ($fileid < 1) { continue; }
                if (!isset($fileidtomodules[$fileid])) {
                    $fileidtomodules[$fileid] = [];
                }
                $fileidtomodules[$fileid][(int)$sourcemoduleid] = true;
            }
        }
        foreach (p5_backup_xml_documents((string)$activity['path']) as $dom) {
            $xpath = new DOMXPath($dom);
            if ($modname === 'assign') {
                foreach ($xpath->query('//*[local-name()="submission"]') ?: [] as $node) {
                    if (!$node instanceof DOMElement) { continue; }
                    $userid = p5_dom_direct_text($node, 'userid');
                    $status = p5_dom_direct_text($node, 'status');
                    $latest = p5_dom_direct_text($node, 'latest');
                    if ($userid === null || $status === null ||
                            $status === 'new' || ($latest !== null && (int)$latest !== 1)) {
                        continue;
                    }
                    $candidates['assignment_submissions'][] = $base + [
                        'source_user_id' => (int)$userid,
                        'status' => $status,
                    ];
                }
                foreach ($xpath->query('//*[local-name()="grade"]') ?: [] as $node) {
                    if (!$node instanceof DOMElement) { continue; }
                    $userid = p5_dom_direct_text($node, 'userid');
                    $grade = p5_dom_direct_text($node, 'grade');
                    if ($userid === null || $grade === null || !is_numeric($grade) ||
                            (float)$grade < 0) {
                        continue;
                    }
                    $candidates['assignment_grades'][] = $base + [
                        'source_user_id' => (int)$userid,
                        'grade' => round((float)$grade, 5),
                    ];
                }
            } else if ($modname === 'forum') {
                foreach ($xpath->query('//*[local-name()="discussion"]') ?: [] as $node) {
                    if (!$node instanceof DOMElement) { continue; }
                    $userid = p5_dom_direct_text($node, 'userid');
                    $subject = p5_dom_direct_text($node, 'name');
                    if ($userid === null || $subject === null) { continue; }
                    $candidates['forum_discussions'][] = $base + [
                        'source_user_id' => (int)$userid,
                        'subject' => $subject,
                    ];
                }
                foreach ($xpath->query('//*[local-name()="post"]') ?: [] as $node) {
                    if (!$node instanceof DOMElement) { continue; }
                    $userid = p5_dom_direct_text($node, 'userid');
                    $subject = p5_dom_direct_text($node, 'subject');
                    if ($userid === null || $subject === null) { continue; }
                    $candidates['forum_posts'][] = $base + [
                        'source_user_id' => (int)$userid,
                        'subject' => $subject,
                    ];
                }
            } else if ($modname === 'quiz') {
                foreach ($xpath->query('//*[local-name()="attempt"]') ?: [] as $node) {
                    if (!$node instanceof DOMElement) { continue; }
                    $userid = p5_dom_direct_text($node, 'userid');
                    $state = p5_dom_direct_text($node, 'state');
                    if ($userid === null || $state === null) { continue; }
                    $sumgrades = p5_dom_direct_text($node, 'sumgrades');
                    if ($sumgrades !== null) {
                        $sumgrades = trim($sumgrades);
                        if ($sumgrades === '' || $sumgrades === '$@NULL@$') {
                            $sumgrades = null;
                        } else if (!is_numeric($sumgrades)) {
                            throw new RuntimeException(
                                'QUIZ_ATTEMPT_SUMGRADES_INVALID value=' .
                                substr($sumgrades, 0, 80) . '.'
                            );
                        } else {
                            $sumgrades = round((float)$sumgrades, 5);
                        }
                    }
                    $candidates['quiz_attempts'][] = $base + [
                        'source_user_id' => (int)$userid,
                        'quiz_id' => (int)$module['instance'],
                        'source_module_id' => (int)$sourcemoduleid,
                        'state' => $state,
                        'sumgrades' => $sumgrades,
                        'attempt' => (int)(p5_dom_direct_text($node, 'attempt') ?? 0),
                        'timestart' => (int)(p5_dom_direct_text($node, 'timestart') ?? 0),
                        'timefinish' => (int)(p5_dom_direct_text($node, 'timefinish') ?? 0),
                        'preview' => (int)(p5_dom_direct_text($node, 'preview') ?? 0),
                        'attempt_id' => (int)$node->getAttribute('id'),
                    ];
                }
            }
            foreach ($xpath->query('//*[local-name()="completion"]') ?: [] as $node) {
                if (!$node instanceof DOMElement) { continue; }
                $userid = p5_dom_direct_text($node, 'userid');
                $state = p5_dom_direct_text($node, 'completionstate');
                if ($userid === null || $state === null || (int)$state < 1) { continue; }
                $candidates['activity_completions'][] = $base + [
                    'source_user_id' => (int)$userid,
                    'completion_state' => (int)$state,
                ];
            }
        }
    }

    $filespath = rtrim($backupdirectory, '/\\') . '/files.xml';
    if (is_readable($filespath)) {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!$dom->load($filespath, LIBXML_NONET)) {
            throw new RuntimeException('files.xml no es XML válido.');
        }
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//*[local-name()="file"]') ?: [] as $node) {
            if (!$node instanceof DOMElement) { continue; }
            $evidence['files_xml_rows_total']++;
            $contextid = (int)(p5_dom_direct_text($node, 'contextid') ?? 0);
            $fileid = (int)$node->getAttribute('id');
            $filename = p5_dom_direct_text($node, 'filename');
            $component = (string)(p5_dom_direct_text($node, 'component') ?? '');
            $filearea = (string)(p5_dom_direct_text($node, 'filearea') ?? '');
            $userid = (int)(p5_dom_direct_text($node, 'userid') ?? 0);
            $filemodules = array_map('intval', array_keys($fileidtomodules[$fileid] ?? []));
            sort($filemodules, SORT_NUMERIC);
            $contextmodule = $contexttomodule[$contextid] ?? null;
            $sharedinforef = count($filemodules) > 1;
            if ($sharedinforef) {
                if ($originalcoursecontextid === null) {
                    $originalcoursecontextid = p5_backup_original_course_context_id(
                        $backupdirectory
                    );
                }
                $sharedcustomcertimage = $component === 'mod_customcert' &&
                    $filearea === 'image' &&
                    (int)(p5_dom_direct_text($node, 'itemid') ?? 0) === 0 &&
                    $contextmodule === null &&
                    $originalcoursecontextid > 0 &&
                    $contextid === $originalcoursecontextid;
                foreach ($filemodules as $candidateid) {
                    if (!isset($activitydirectories[$candidateid]) ||
                            (string)$activitydirectories[$candidateid]['module']['modname'] !== 'customcert') {
                        $sharedcustomcertimage = false;
                        break;
                    }
                }
                if (!$sharedcustomcertimage) {
                    $evidence['files_ambiguous']++;
                    p5_add_file_evidence_sample($evidence, [
                        'file_id' => $fileid,
                        'contextid' => $contextid,
                        'component' => $component,
                        'filearea' => $filearea,
                        'filename' => (string)$filename,
                        'userid' => $userid,
                        'inforef_source_module_id' => null,
                        'source_module_id_candidates' => $filemodules,
                        'reason' => 'duplicate_inforef_assignment',
                    ]);
                    throw new RuntimeException(
                        'MODULE_FILE_ID_AMBIGUOUS inforef.xml asigna file_id=' . $fileid .
                        ' a múltiples source_module_id=' . implode('|', $filemodules) . '.'
                    );
                }
                $evidence['files_shared_inforef_accepted']++;
            }
            $filemodule = count($filemodules) === 1 ? $filemodules[0] : null;
            if ($filemodule !== null && $contextmodule !== null &&
                    $filemodule !== $contextmodule) {
                $evidence['files_ambiguous']++;
                $evidence['files_context_inforef_conflicts']++;
                p5_add_file_evidence_sample($evidence, [
                    'file_id' => $fileid,
                    'contextid' => $contextid,
                    'component' => $component,
                    'filearea' => $filearea,
                    'filename' => (string)$filename,
                    'userid' => $userid,
                    'inforef_source_module_id' => $filemodule,
                    'source_module_id_candidates' => [$contextmodule, $filemodule],
                    'reason' => 'context_inforef_conflict',
                ]);
                throw new RuntimeException(
                    'MODULE_FILE_EVIDENCE_CONFLICT file_id=' . $fileid .
                    ' contextid=' . $contextid . ' component=' . $component .
                    ' filearea=' . $filearea . ' filename=' . (string)$filename .
                    ' userid=' . $userid . ' source_module_id_candidates=' .
                    $filemodule . '|' . $contextmodule . '.'
                );
            }
            $diagnostic = [
                'file_id' => $fileid,
                'contextid' => $contextid,
                'component' => $component,
                'filearea' => $filearea,
                'filename' => (string)$filename,
                'userid' => $userid,
                'inforef_source_module_id' => $filemodule,
                'source_module_id_candidates' => $filemodules,
            ];
            if (!p5_is_module_file_relation($diagnostic)) {
                $evidence['files_field_contract_excluded']++;
                continue;
            }
            // El contexto directo define la población, exactamente como el
            // JOIN CONTEXT_MODULE del inventario fuente. inforef solo corrobora.
            if ($contextmodule === null) {
                $evidence['files_non_module_context_excluded']++;
                if ($filemodule !== null || $sharedinforef) {
                    $evidence['files_inforef_only_excluded']++;
                    $diagnostic['reason'] = $sharedinforef
                        ? 'shared_inforef_reference_non_module_context'
                        : 'inforef_reference_non_module_context';
                } else {
                    $evidence['files_unattributed_nonmodule_excluded']++;
                    $diagnostic['reason'] = 'non_module_context';
                }
                p5_add_file_evidence_sample($evidence, $diagnostic);
                continue;
            }
            $sourcemoduleid = $contextmodule;
            $resolution = $filemodule === $contextmodule
                ? 'contextid+inforef'
                : 'contextid';
            $module = $activitydirectories[$sourcemoduleid]['module'];
            $row = [
                '_source_module_id' => $sourcemoduleid,
                '_file_id' => $fileid,
                '_context_id' => $contextid,
                '_resolution' => $resolution,
                'source_user_id' => $userid,
                'activity_key' => (string)$module['module_key'],
                'component' => $component,
                'filearea' => $filearea,
                'filename' => (string)$filename,
            ];
            $candidates['files'][] = $row;
            $evidence['files_module_context_candidates']++;
            $evidence['files_backup_candidates']++;
            if ($resolution === 'contextid+inforef') {
                $evidence['files_confirmed_by_inforef']++;
                // Alias histórico RC15: no implica que file_id defina población.
                $evidence['files_matched_by_file_id']++;
            } else {
                $evidence['files_matched_by_context']++;
            }
        }
    }
    return $candidates;
}

function p5_relation_canonical_value(string $field, mixed $value): mixed {
    if ($value === null) {
        return null;
    }
    if (in_array($field, [
        'source_user_id', 'quiz_id', 'source_module_id', 'attempt',
        'timestart', 'timefinish', 'preview', 'attempt_id', 'completion_state',
    ], true)) {
        return (int)$value;
    }
    if (in_array($field, ['grade', 'sumgrades'], true)) {
        return round((float)$value, 5);
    }
    if ($field === 'completed') {
        return (bool)$value;
    }
    return (string)$value;
}

function p5_relation_semantic_fields(array $rows, string $relationname): array {
    if (!$rows) {
        return [];
    }
    $fields = array_values(array_filter(
        array_keys($rows[0]),
        static fn(string $field): bool =>
            $field !== 'activity_key' && $field !== '_source_module_id'
    ));
    sort($fields, SORT_STRING);
    foreach ($rows as $row) {
        $rowfields = array_values(array_filter(
            array_keys($row),
            static fn(string $field): bool =>
                $field !== 'activity_key' && $field !== '_source_module_id'
        ));
        sort($rowfields, SORT_STRING);
        if ($rowfields !== $fields) {
            throw new RuntimeException(
                'MODULE_RELATION_SCHEMA_INVALID ' . $relationname .
                ' contiene filas con campos semánticos diferentes.'
            );
        }
    }
    return $fields;
}

function p5_relation_signature(array $row, array $fields, string $relationname): string {
    $semantic = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $row)) {
            throw new RuntimeException(
                'MODULE_RELATION_EVIDENCE_MISSING ' . $relationname .
                ' no contiene el campo ' . $field . '.'
            );
        }
        $semantic[$field] = p5_relation_canonical_value($field, $row[$field]);
    }
    return json_encode(
        $semantic,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
}

function p5_relation_multiset(array $rows, array $fields, string $relationname): array {
    $set = [];
    foreach ($rows as $row) {
        $signature = p5_relation_signature($row, $fields, $relationname);
        $set[$signature] = (int)($set[$signature] ?? 0) + 1;
    }
    ksort($set, SORT_STRING);
    return $set;
}

function p5_relation_multiset_difference(array $expected, array $actual): array {
    $signatures = array_values(array_unique(array_merge(
        array_keys($expected),
        array_keys($actual)
    )));
    sort($signatures, SORT_STRING);
    $rows = [];
    foreach ($signatures as $signature) {
        $expectedcount = (int)($expected[$signature] ?? 0);
        $actualcount = (int)($actual[$signature] ?? 0);
        if ($expectedcount === $actualcount) {
            continue;
        }
        $rows[] = [
            'semantic_signature' => json_decode($signature, true, 512, JSON_THROW_ON_ERROR),
            'inventory_count' => $expectedcount,
            'backup_count' => $actualcount,
            'difference' => $actualcount - $expectedcount,
        ];
    }
    return $rows;
}

function p5_rebuilt_relation_row(
    array $template,
    array $candidate,
    array $modulesbysourceid,
    string $relationname
): array {
    $sourcemoduleid = (int)($candidate['_source_module_id'] ?? 0);
    if ($sourcemoduleid < 1 || !isset($modulesbysourceid[$sourcemoduleid])) {
        throw new RuntimeException(
            'MODULE_RELATION_STRUCTURAL_ID_MISSING relation=' . $relationname .
            ' source_module_id=' . $sourcemoduleid . '.'
        );
    }
    $row = [];
    foreach (array_keys($template) as $field) {
        if ($field === '_source_module_id') {
            continue;
        }
        if ($field === 'activity_key') {
            $row[$field] = (string)$modulesbysourceid[$sourcemoduleid]['module_key'];
            continue;
        }
        if (!array_key_exists($field, $candidate)) {
            throw new RuntimeException(
                'MODULE_RELATION_EVIDENCE_MISSING ' . $relationname .
                ' no contiene el campo ' . $field . '.'
            );
        }
        $row[$field] = p5_relation_canonical_value($field, $candidate[$field]);
    }
    return $row;
}

/**
 * Sustituye las llaves heredadas por las efectivas. Para paquetes antiguos,
 * las relaciones ambiguas se reconstruyen desde los XML del MBZ extraído.
 */
function p5_effective_inventory_from_backup(
    array $inventory,
    array $identityplan,
    string $backupdirectory,
    array $activitydirectories,
    ?array &$reconciliation = null,
    ?array $contextevidence = null
): array {
    $prepared = $inventory;
    $byid = $identityplan['modules_by_source_id'];
    $fileevidence = null;
    if ($contextevidence === null) {
        $resolvedcontexts = array_filter(
            $activitydirectories,
            static fn(array $activity): bool => (int)($activity['context_id'] ?? 0) > 0
        );
        $contextevidence = [
            'module_contexts_total' => count($activitydirectories),
            'module_contexts_resolved' => count($resolvedcontexts),
            'module_contexts_missing' => count($activitydirectories) - count($resolvedcontexts),
            'module_contexts_ambiguous' => 0,
            'module_context_duplicates' => 0,
            'module_context_mapping_sha256' => null,
        ];
    }
    $report = [
        'schema_version' => '1.0',
        'phase' => '5-module-relation-reconciliation',
        'status' => 'pending',
        'module_contexts_total' => (int)$contextevidence['module_contexts_total'],
        'module_contexts_resolved' => (int)$contextevidence['module_contexts_resolved'],
        'module_contexts_missing' => (int)$contextevidence['module_contexts_missing'],
        'module_contexts_ambiguous' => (int)$contextevidence['module_contexts_ambiguous'],
        'module_context_duplicates' => (int)$contextevidence['module_context_duplicates'],
        'module_context_mapping_sha256' =>
            $contextevidence['module_context_mapping_sha256'] ?? null,
        'relations' => [],
        'issues' => [],
    ];
    try {
        $candidates = p5_backup_relation_candidates(
            $backupdirectory,
            $activitydirectories,
            $fileevidence
        );
    } catch (Throwable $error) {
        $report['status'] = 'blocked';
        $report['files'] = $fileevidence;
        $report['issues'][] = [
            'relation' => 'files',
            'reason' => 'structural_evidence_ambiguous',
            'message' => $error->getMessage(),
        ];
        $reconciliation = $report;
        throw $error;
    }
    foreach ($prepared['relations'] ?? [] as $relationname => $rows) {
        if ($relationname === 'course_completions' || !is_array($rows)) {
            continue;
        }
        $candidaterows = $candidates[$relationname] ?? [];
        $rawinventorycount = count($rows);
        $rawcandidatecount = count($candidaterows);
        if ($relationname === 'files') {
            $fileevidence['files_inventory_rows'] = $rawinventorycount;
            $inventorycomparable = p5_filter_comparable_files($rows);
            $candidatecomparable = p5_filter_comparable_files($candidaterows);
            $fileevidence['files_regenerable_excluded_inventory'] =
                $rawinventorycount - count($inventorycomparable);
            $fileevidence['files_regenerable_excluded_candidates'] =
                $rawcandidatecount - count($candidatecomparable);
            $rows = $inventorycomparable;
            $candidaterows = $candidatecomparable;
        }
        $fields = p5_relation_semantic_fields($rows, $relationname);
        $inventoryset = p5_relation_multiset($rows, $fields, $relationname);
        $backupset = p5_relation_multiset($candidaterows, $fields, $relationname);
        $differences = p5_relation_multiset_difference($inventoryset, $backupset);
        $rowdifference = 0;
        foreach ($differences as $difference) {
            $rowdifference += abs((int)$difference['difference']);
        }
        $report['relations'][$relationname] = [
            'inventory_rows' => $rawinventorycount,
            'backup_candidates' => $rawcandidatecount,
            'comparable_inventory_rows' => count($rows),
            'comparable_backup_candidates' => count($candidaterows),
            'semantic_fields' => $fields,
            'multiset_difference' => count($differences),
            'multiset_signature_difference' => count($differences),
            'multiset_row_difference' => $rowdifference,
            'status' => $differences ? 'blocked' : 'matched',
        ];
        if ($differences) {
            $report['issues'][] = [
                'relation' => $relationname,
                'reason' => 'multiset_inconsistent',
                'differences' => array_slice($differences, 0, 100),
            ];
            if ($relationname === 'files') {
                $missing = 0;
                foreach ($differences as $difference) {
                    $delta = (int)$difference['difference'];
                    if ($delta < 0) {
                        $missing += abs($delta);
                    }
                }
                $fileevidence['files_unresolved'] = $missing;
                // Alias histórico: RC15/RC16 medían filas diferentes.
                $fileevidence['files_multiset_difference'] = $rowdifference;
                $fileevidence['files_multiset_signature_difference'] =
                    count($differences);
                $fileevidence['files_multiset_row_difference'] = $rowdifference;
            }
            continue;
        }
        $rebuilt = [];
        $template = $rows[0] ?? null;
        if ($template !== null) {
            foreach ($candidaterows as $candidate) {
                $rebuilt[] = p5_rebuilt_relation_row(
                    $template,
                    $candidate,
                    $byid,
                    $relationname
                );
            }
            usort($rebuilt, static fn(array $left, array $right): int => strcmp(
                json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ));
        }
        $prepared['relations'][$relationname] = $rebuilt;
        if ($relationname === 'files' && isset($prepared['counts']['module_files'])) {
            $prepared['counts']['module_files'] = count($rebuilt);
        }
    }
    $report['files'] = $fileevidence;
    if ($report['issues']) {
        $report['status'] = 'blocked';
        $reconciliation = $report;
        $first = $report['issues'][0];
        throw new RuntimeException(
            'MODULE_RELATION_MULTISET_INCONSISTENT relation=' .
            (string)$first['relation'] . ' reason=' . (string)$first['reason'] .
            ' inventory_rows=' .
            (int)($report['relations'][$first['relation']]['inventory_rows'] ?? 0) .
            ' backup_candidates=' .
            (int)($report['relations'][$first['relation']]['backup_candidates'] ?? 0) . '.'
        );
    }
    $report['status'] = 'passed';
    $reconciliation = $report;
    return $prepared;
}

function p5_archive_files(string $directory): array {
    $directory = rtrim($directory, '/\\');
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $fullpath = $item->getPathname();
        $relative = str_replace('\\', '/', substr($fullpath, strlen($directory) + 1));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../')) {
            throw new RuntimeException('Ruta insegura al reconstruir el backup: ' . $relative . '.');
        }
        $files[$relative] = $fullpath;
    }
    ksort($files, SORT_STRING);
    return $files;
}

/**
 * Moodle ha devuelto las entradas de file_packer::list_files() como objetos
 * stdClass y, en otras implementaciones, puede entregarlas como arreglos.
 */
function p5_archive_entry_pathname($item): string {
    if (is_array($item)) {
        return (string)($item['pathname'] ?? '');
    }
    if (is_object($item)) {
        return (string)($item->pathname ?? '');
    }
    throw new RuntimeException(
        'El listado del backup contiene una entrada con formato no compatible.'
    );
}

/**
 * Impide restaurar categorías ordinarias de preguntas sin categoría superior.
 *
 * Desde Moodle 3.5, parent=0 está reservado para la categoría especial "top".
 * Si una categoría con preguntas queda en la raíz, Moodle 5.2 puede omitir su
 * creación durante la conversión al módulo de banco y dejar la entrada del
 * banco sin questioncategoryid.
 */
function p5_validate_backup_question_hierarchy(string $questionspath): array {
    if (!is_readable($questionspath)) {
        return [
            'categories_checked' => 0,
            'categories_with_questions' => 0,
        ];
    }

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->load($questionspath, LIBXML_NONET)) {
        throw new RuntimeException('questions.xml no es XML válido.');
    }
    $xpath = new DOMXPath($dom);
    $categories = $xpath->query('/question_categories/question_category');
    if ($categories === false) {
        throw new RuntimeException('No fue posible inspeccionar las categorías de questions.xml.');
    }

    $checked = 0;
    $withquestions = 0;
    $invalid = [];
    foreach ($categories as $category) {
        if (!$category instanceof DOMElement) {
            continue;
        }
        $checked++;
        $entries = $xpath->query(
            './question_bank_entries/question_bank_entry | ./questions/question',
            $category
        );
        $entrycount = $entries === false ? 0 : $entries->length;
        if ($entrycount < 1) {
            continue;
        }
        $withquestions++;
        if ((int)p5_dom_text($category, 'parent') !== 0) {
            continue;
        }
        $name = p5_dom_text($category, 'name');
        $contextid = p5_dom_text($category, 'contextid');
        $detail = sprintf(
            'id=%s contextid=%s name="%s" idnumber="%s" entries=%d',
            $category->getAttribute('id'),
            $contextid,
            $name,
            p5_dom_text($category, 'idnumber'),
            $entrycount
        );
        if (core_text::strtolower(trim($name)) === 'top') {
            throw new RuntimeException(
                'QUESTION_TOP_CATEGORY_DIRECT_ENTRIES_UNRESTORABLE ' . $detail .
                '. La categoría especial top contiene entradas directas y Moodle 5.2 puede dejar ' .
                'question_bank_entries sin questioncategoryid; repare el banco en el Moodle origen ' .
                'y genere nuevamente el backup.'
            );
        }
        $invalid[] = $detail;
    }
    if ($invalid) {
        throw new RuntimeException(
            'QUESTION_CATEGORY_PARENT_ZERO_INVALID ' . implode('; ', $invalid) .
            '. Una categoría ordinaria con parent=0 no cumple la jerarquía moderna de Moodle; ' .
            'repare la jerarquía en el Moodle origen y genere nuevamente el backup.'
        );
    }
    return [
        'categories_checked' => $checked,
        'categories_with_questions' => $withquestions,
    ];
}

/**
 * Reescribe atributos de usuario y los idnumber técnicos de module.xml en la
 * copia normalizada; conserva los IDs internos para no romper relaciones.
 */
function p5_normalize_backup(
    string $rawpath,
    string $normalizedpath,
    string $sourceid,
    string $targeturl,
    array $contract,
    array $targetusersbyid,
    string $auditpath,
    string $relationauditpath,
    array $identityplan,
    array $inventory
): array {
    $rawsha256 = hash_file('sha256', $rawpath);
    if ($rawsha256 === false) {
        throw new RuntimeException('No fue posible sellar el backup crudo antes de normalizarlo.');
    }
    $packer = get_file_packer('application/vnd.moodle.backup');
    $tempdir = make_temp_directory(
        'phase5-normalize/' . sha1($sourceid . '|' . $rawpath . '|' . microtime(true))
    );
    try {
        $result = $packer->extract_to_pathname($rawpath, $tempdir);
        if ($result === false || !is_readable($tempdir . '/moodle_backup.xml') ||
                !is_readable($tempdir . '/users.xml')) {
            throw new RuntimeException(
                'El .mbz no contiene moodle_backup.xml y users.xml; compruebe que incluya usuarios.'
            );
        }
        $questionvalidation = p5_validate_backup_question_hierarchy(
            $tempdir . '/questions.xml'
        );
        $relationreconciliation = null;
        $modulecontextevidence = null;
        try {
            $activitydirectories = p5_rewrite_backup_module_idnumbers(
                $tempdir,
                $identityplan['modules_by_source_id'] ?? [],
                $modulecontextevidence
            );
            $effectiveinventory = p5_effective_inventory_from_backup(
                $inventory,
                $identityplan,
                $tempdir,
                $activitydirectories,
                $relationreconciliation,
                $modulecontextevidence
            );
            p5_write_json($relationauditpath, $relationreconciliation);
        } catch (Throwable $error) {
            $fallbackreport = [
                'schema_version' => '1.0',
                'phase' => '5-module-relation-reconciliation',
                'status' => 'blocked',
                'module_contexts_total' =>
                    (int)($modulecontextevidence['module_contexts_total'] ?? 0),
                'module_contexts_resolved' =>
                    (int)($modulecontextevidence['module_contexts_resolved'] ?? 0),
                'module_contexts_missing' =>
                    (int)($modulecontextevidence['module_contexts_missing'] ?? 0),
                'module_contexts_ambiguous' =>
                    (int)($modulecontextevidence['module_contexts_ambiguous'] ?? 0),
                'module_context_duplicates' =>
                    (int)($modulecontextevidence['module_context_duplicates'] ?? 0),
                'module_context_mapping_sha256' =>
                    $modulecontextevidence['module_context_mapping_sha256'] ?? null,
                'relations' => [],
                'issues' => [[
                    'reason' => 'module_context_validation_failed',
                    'message' => $error->getMessage(),
                ]],
            ];
            p5_write_json($relationauditpath, $relationreconciliation ?? $fallbackreport);
            throw $error;
        }
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        if (!$dom->load($tempdir . '/users.xml', LIBXML_NONET)) {
            throw new RuntimeException('users.xml no es XML válido.');
        }
        $audit = [];
        $targetids = [];
        foreach ($dom->getElementsByTagName('user') as $user) {
            if (!$user instanceof DOMElement) {
                continue;
            }
            $sourceuserid = (int)$user->getAttribute('id');
            $sourceusername = p5_dom_text($user, 'username');
            $sourceemail = p5_dom_text($user, 'email');
            $sourcefirstaccess = p5_dom_text($user, 'firstaccess');
            if ($sourceuserid < 1) {
                throw new RuntimeException('users.xml contiene un ID de usuario inválido.');
            }
            if (p5_norm($sourceusername) === 'guest') {
                $audit[] = [
                    'source' => $sourceid,
                    'source_user_id' => $sourceuserid,
                    'source_username' => $sourceusername,
                    'source_email' => $sourceemail,
                    'source_firstaccess' => $sourcefirstaccess,
                    'canonical_id' => '',
                    'target_user_id' => '',
                    'target_username' => 'guest',
                    'target_email' => '',
                    'target_firstaccess' => '',
                    'rewrite_status' => 'reserved_guest',
                    'message' => 'Cuenta reservada de Moodle; no representa una identidad migrada.',
                ];
                continue;
            }
            $key = $sourceid . ':' . $sourceuserid;
            $mapping = $contract['source_by_key'][$key] ?? null;
            if (!$mapping) {
                throw new RuntimeException(
                    'users.xml incluye ' . $key . ' sin target_user_id verificado.'
                );
            }
            $targetuserid = (int)$mapping['target_user_id'];
            if (isset($targetids[$targetuserid])) {
                throw new RuntimeException(
                    'El curso contiene varias cuentas de origen que convergen en target_user_id=' .
                    $targetuserid . '. Este caso requiere una estrategia de fusión de actividad.'
                );
            }
            $targetids[$targetuserid] = $key;
            $targetuser = $targetusersbyid[$targetuserid] ?? null;
            if (!$targetuser ||
                    p5_norm((string)$targetuser['username']) !==
                        p5_norm((string)$mapping['target_username']) ||
                    p5_norm((string)$targetuser['email']) !==
                        p5_norm((string)$mapping['target_email'])) {
                throw new RuntimeException(
                    'El inventario destino no confirma target_user_id=' . $targetuserid . '.'
                );
            }
            p5_dom_set($user, 'username', (string)$targetuser['username']);
            p5_dom_set($user, 'email', (string)$targetuser['email']);
            p5_dom_set($user, 'auth', (string)$targetuser['auth']);
            p5_dom_set($user, 'firstaccess', (string)(int)$targetuser['firstaccess']);
            p5_dom_set($user, 'mnethosturl', rtrim($targeturl, '/'));
            $audit[] = [
                'source' => $sourceid,
                'source_user_id' => $sourceuserid,
                'source_username' => $sourceusername,
                'source_email' => $sourceemail,
                'source_firstaccess' => $sourcefirstaccess,
                'canonical_id' => (string)$mapping['canonical_id'],
                'target_user_id' => $targetuserid,
                'target_username' => (string)$targetuser['username'],
                'target_email' => (string)$targetuser['email'],
                'target_firstaccess' => (int)$targetuser['firstaccess'],
                'rewrite_status' => 'mapped',
                'message' => 'Identidad alineada con el usuario canónico verificado.',
            ];
        }
        if (!$audit) {
            throw new RuntimeException('users.xml no contiene usuarios para validar.');
        }
        if ($dom->save($tempdir . '/users.xml') === false) {
            throw new RuntimeException('No fue posible guardar users.xml normalizado.');
        }

        $roleconversions = [];
        $rolespath = $tempdir . '/roles.xml';
        if (is_readable($rolespath)) {
            $rolesdom = new DOMDocument();
            $rolesdom->preserveWhiteSpace = false;
            $rolesdom->formatOutput = true;
            if (!$rolesdom->load($rolespath, LIBXML_NONET)) {
                throw new RuntimeException('roles.xml no es XML válido.');
            }
            foreach ($rolesdom->getElementsByTagName('role') as $role) {
                if (!$role instanceof DOMElement) {
                    continue;
                }
                $original = p5_norm(p5_dom_text($role, 'shortname'));
                if ($original === '') {
                    continue;
                }
                [$normalized, $target, $approved] = p5_role_policy($original);
                if (!$approved || $target === '') {
                    throw new RuntimeException(
                        'El rol ' . $original . ' no tiene una política aprobada.'
                    );
                }
                p5_dom_set($role, 'shortname', $target);
                p5_dom_set($role, 'name', ucfirst($normalized));
                p5_dom_set(
                    $role,
                    'archetype',
                    $target === 'personalizado' ? '' : $target
                );
                foreach ($role->getElementsByTagName('role_capabilities') as $caps) {
                    while ($caps->firstChild !== null) {
                        $caps->removeChild($caps->firstChild);
                    }
                }
                $roleconversions[$original . '|' . $target] = [
                    'source_role_shortname' => $original,
                    'target_role_shortname' => $target,
                ];
            }
            if ($rolesdom->save($rolespath) === false) {
                throw new RuntimeException('No fue posible guardar roles.xml normalizado.');
            }
        }
        p5_write_csv($auditpath, [
            'source', 'source_user_id', 'source_username', 'source_email',
            'source_firstaccess', 'canonical_id', 'target_user_id',
            'target_username', 'target_email', 'target_firstaccess',
            'rewrite_status', 'message',
        ], $audit);
        $files = p5_archive_files($tempdir);
        if (is_file($normalizedpath) && !unlink($normalizedpath)) {
            throw new RuntimeException('No fue posible reemplazar el backup normalizado anterior.');
        }
        if (!$packer->archive_to_pathname($files, $normalizedpath, false)) {
            throw new RuntimeException('Moodle no pudo reconstruir el .mbz normalizado.');
        }
        $listing = $packer->list_files($normalizedpath);
        $names = array_map(
            'p5_archive_entry_pathname',
            is_array($listing) ? $listing : []
        );
        if (!in_array('moodle_backup.xml', $names, true) ||
                !in_array('users.xml', $names, true)) {
            throw new RuntimeException('El backup reconstruido no conserva los XML obligatorios.');
        }
        return [
            'backup_users' => count($audit),
            'mapped_users' => count(array_filter(
                $audit,
                static fn(array $row): bool => $row['rewrite_status'] === 'mapped'
            )),
            'reserved_users' => count(array_filter(
                $audit,
                static fn(array $row): bool => $row['rewrite_status'] === 'reserved_guest'
            )),
            'question_categories_checked' =>
                (int)$questionvalidation['categories_checked'],
            'question_categories_with_questions' =>
                (int)$questionvalidation['categories_with_questions'],
            'role_conversions' => array_values($roleconversions),
            'audit_rows' => $audit,
            'effective_inventory' => $effectiveinventory,
            'module_identity_metrics' => $identityplan['metrics'] ?? [],
            'module_context_metrics' => $modulecontextevidence ?? [],
            'relation_reconciliation' => $relationreconciliation,
            'file_relation_metrics' => $relationreconciliation['files'] ?? [],
        ];
    } finally {
        $currentrawsha256 = is_readable($rawpath) ? hash_file('sha256', $rawpath) : false;
        if ($currentrawsha256 === false || !hash_equals($rawsha256, $currentrawsha256)) {
            throw new RuntimeException(
                'RAW_BACKUP_IMMUTABILITY_FAILED: la normalización modificó el MBZ original.'
            );
        }
        fulldelete($tempdir);
    }
}

function p5_role_policy(string $shortname): array {
    return match (p5_norm($shortname)) {
        'student' => ['estudiante', 'student', true, 'Rol académico estándar permitido.'],
        'editingteacher' => ['docente', 'editingteacher', true, 'Rol docente estándar permitido.'],
        'teacher' => ['docente', 'editingteacher', true, 'Rol docente normalizado.'],
        'manager', 'coursecreator', 'siteadmin' => [
            'administrador',
            'manager',
            true,
            'Rol administrativo conservado con alcance de curso.',
        ],
        default => [
            'personalizado',
            'personalizado',
            true,
            'Rol no estándar normalizado con acceso de solo lectura.',
        ],
    };
}

function p5_personalizado_allowed_capabilities(): array {
    return [
        'moodle/course:view',
        'mod/assign:view',
        'mod/book:read',
        'mod/data:viewentry',
        'mod/feedback:view',
        'mod/folder:view',
        'mod/forum:viewdiscussion',
        'mod/glossary:view',
        'mod/h5pactivity:view',
        'mod/imscp:view',
        'mod/lesson:view',
        'mod/page:view',
        'mod/qbank:view',
        'mod/quiz:view',
        'mod/resource:view',
        'mod/scorm:view',
        'mod/url:view',
        'mod/wiki:viewpage',
        'mod/workshop:view',
    ];
}

function p5_personalizado_role_status(): array {
    global $DB;

    $role = $DB->get_record(
        'role',
        ['shortname' => 'personalizado'],
        'id,name,shortname,description,archetype',
        IGNORE_MISSING
    );
    if (!$role) {
        return ['exists' => false, 'safe' => true, 'role_id' => null, 'issues' => []];
    }
    $issues = [];
    if (!str_contains((string)$role->description, 'MIG-P6')) {
        $issues[] =
            'El shortname personalizado ya existe, pero no pertenece a esta migración.';
    }
    $levels = array_values(array_map('intval', get_role_contextlevels((int)$role->id)));
    sort($levels, SORT_NUMERIC);
    if ($levels !== [CONTEXT_COURSE]) {
        $issues[] = 'El rol personalizado no está limitado al contexto de curso.';
    }
    $allowed = array_fill_keys(p5_personalizado_allowed_capabilities(), true);
    $granted = [];
    foreach ($DB->get_records(
        'role_capabilities',
        ['roleid' => (int)$role->id],
        'capability ASC',
        'id,capability,permission'
    ) as $capability) {
        if ((int)$capability->permission <= 0) {
            continue;
        }
        $granted[] = (string)$capability->capability;
        if (!isset($allowed[(string)$capability->capability])) {
            $issues[] = 'Capacidad no permitida: ' .
                (string)$capability->capability . '.';
        }
    }
    if (!in_array('moodle/course:view', $granted, true)) {
        $issues[] = 'El rol personalizado no permite consultar el curso.';
    }
    return [
        'exists' => true,
        'safe' => $issues === [],
        'role_id' => (int)$role->id,
        'granted_capabilities' => $granted,
        'issues' => $issues,
    ];
}

function p5_ensure_personalizado_role(): array {
    global $CFG;

    require_once($CFG->dirroot . '/admin/roles/lib.php');
    $status = p5_personalizado_role_status();
    if ($status['exists']) {
        if (!$status['safe']) {
            throw new RuntimeException(implode(' ', $status['issues']));
        }
        $status['action'] = 'reused';
        return $status;
    }
    $roleid = (int)create_role(
        'Personalizado (solo lectura)',
        'personalizado',
        'MIG-P6: acceso de consulta, sin edición ni administración.',
        ''
    );
    if ($roleid < 1) {
        throw new RuntimeException('Moodle no pudo crear el rol personalizado.');
    }
    set_role_contextlevels($roleid, [CONTEXT_COURSE]);
    $systemcontext = context_system::instance();
    foreach (p5_personalizado_allowed_capabilities() as $capability) {
        if (get_capability_info($capability) === null) {
            continue;
        }
        assign_capability(
            $capability,
            CAP_ALLOW,
            $roleid,
            (int)$systemcontext->id,
            true
        );
    }
    $status = p5_personalizado_role_status();
    if (!$status['exists'] || !$status['safe']) {
        throw new RuntimeException(
            'El rol personalizado creado no superó su validación de mínimo privilegio.'
        );
    }
    $status['action'] = 'created';
    return $status;
}

function p5_target_courses(): array {
    global $DB;
    $records = $DB->get_records_select(
        'course',
        'id <> :siteid',
        ['siteid' => SITEID],
        'id ASC',
        'id,category,fullname,shortname,idnumber'
    );
    $rows = [];
    foreach ($records as $record) {
        $rows[] = [
            'id' => (int)$record->id,
            'category' => (int)$record->category,
            'fullname' => (string)$record->fullname,
            'shortname' => (string)$record->shortname,
            'idnumber' => (string)$record->idnumber,
        ];
    }
    return $rows;
}

function p5_target_capabilities(): array {
    global $DB;
    $roles = array_values(array_map(
        static fn(stdClass $role): string => (string)$role->shortname,
        $DB->get_records('role', null, 'shortname ASC', 'id,shortname')
    ));
    $modules = array_values(array_map(
        static fn(stdClass $module): string => (string)$module->name,
        $DB->get_records('modules', ['visible' => 1], 'name ASC', 'id,name')
    ));
    return ['roles' => $roles, 'modules' => $modules];
}

/**
 * Carga y valida todos los archivos firmados producidos por el comando 16.
 */
function p5_load_plan(
    string $phase4dir,
    string $phase5dir,
    string $configsha,
    string $targetid,
    bool $expectlab
): array {
    $phase5dir = rtrim($phase5dir, '/\\');
    $summarypath = $phase5dir . '/plan_summary.json';
    $summary = p5_read_json($summarypath);
    if (($summary['config_sha256'] ?? '') !== $configsha ||
            ($summary['target_id'] ?? '') !== $targetid) {
        throw new RuntimeException('El plan de fase 5 corresponde a otra configuración o destino.');
    }
    $sourcealias = strtoupper(trim((string)($summary['source_alias'] ?? '')));
    $targetshortname = trim((string)($summary['target_shortname'] ?? ''));
    $targetmarker = trim((string)($summary['target_course_marker'] ?? ''));
    if (!preg_match('/^[A-Z0-9]{3}$/', $sourcealias) ||
            $targetshortname === '' ||
            $targetmarker === '' ||
            $targetshortname !== $targetmarker ||
            core_text::strlen($targetmarker) > 100) {
        throw new RuntimeException(
            'El plan de fase 5 no conserva una identidad operativa válida.'
        );
    }
    if ($expectlab && ($summary['lab_validation'] ?? '') !== 'passed') {
        throw new RuntimeException('La simulación LAB de fase 5 no está aprobada.');
    }
    $paths = [
        'pilot_config.json' => $phase5dir . '/pilot_config.json',
        'pilot_course_plan.csv' => $phase5dir . '/pilot_course_plan.csv',
        'pilot_user_plan.csv' => $phase5dir . '/pilot_user_plan.csv',
        'pilot_role_plan.csv' => $phase5dir . '/pilot_role_plan.csv',
        'backup_user_rewrite.csv' => $phase5dir . '/backup_user_rewrite.csv',
        'module_idnumber_normalization.csv' =>
            $phase5dir . '/module_idnumber_normalization.csv',
        'module_relation_reconciliation.json' =>
            $phase5dir . '/module_relation_reconciliation.json',
        'source_course_inventory.json' => $phase5dir . '/source_course_inventory.json',
        'target_preflight.json' => $phase5dir . '/target_preflight.json',
        'raw_backup.mbz' => $phase5dir . '/backups/' .
            (string)($summary['raw_backup_file'] ?? ''),
        'normalized_backup.mbz' => $phase5dir . '/backups/' .
            (string)($summary['normalized_backup_file'] ?? ''),
    ];
    $hashes = p5_assert_artifact_hashes(
        $summary['artifacts_sha256'] ?? [],
        $paths
    );
    $contract = p5_load_phase4_contract(
        $phase4dir,
        $configsha,
        $targetid,
        $expectlab
    );
    if (($summary['phase4_input_sha256'] ?? []) !== $contract['hashes']) {
        throw new RuntimeException('La fase 4 cambió después de generar el plan de curso.');
    }
    $courserows = p5_read_csv($paths['pilot_course_plan.csv']);
    if (count($courserows) !== 1) {
        throw new RuntimeException('pilot_course_plan.csv debe contener exactamente un curso.');
    }
    $courserow = $courserows[0];
    if ((string)($courserow['source_alias'] ?? '') !== $sourcealias ||
            (string)($courserow['target_shortname'] ?? '') !== $targetshortname ||
            (string)($courserow['target_course_marker'] ?? '') !== $targetmarker) {
        throw new RuntimeException(
            'pilot_course_plan.csv no conserva la identidad operativa aprobada.'
        );
    }
    $userrows = p5_read_csv($paths['pilot_user_plan.csv']);
    $rolerows = p5_read_csv($paths['pilot_role_plan.csv']);
    if (count($userrows) !== (int)($summary['enrolments_planned'] ?? -1) ||
            count($rolerows) !== (int)($summary['roles_planned'] ?? -1)) {
        throw new RuntimeException('Los planes de usuarios o roles no coinciden con el resumen.');
    }
    if ((int)($summary['blocking_conflicts'] ?? -1) !== 0) {
        throw new RuntimeException('El plan conserva conflictos bloqueantes.');
    }
    return [
        'summary' => $summary,
        'paths' => $paths,
        'hashes' => $hashes,
        'course_row' => $courserows[0],
        'user_rows' => $userrows,
        'role_rows' => $rolerows,
        'source_inventory' => p5_read_json($paths['source_course_inventory.json']),
        'target_preflight' => p5_read_json($paths['target_preflight.json']),
        'phase4' => $contract,
    ];
}
