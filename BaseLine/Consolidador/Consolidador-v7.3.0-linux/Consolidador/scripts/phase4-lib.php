<?php
// Funciones compartidas de la fase 4: plan, aplicación y verificación.

declare(strict_types=1);

defined('MOODLE_INTERNAL') || die();

/**
 * Convierte valores CSV a booleano sin aceptar textos ambiguos.
 */
function p4_bool(mixed $value): bool {
    if (is_bool($value)) {
        return $value;
    }
    return in_array(core_text::strtolower(trim((string)$value)), ['1', 'true', 'yes', 'si'], true);
}

/**
 * Normaliza identificadores, usernames y correos para comparaciones.
 */
function p4_norm(string $value): string {
    return core_text::strtolower(trim($value));
}

/**
 * Lee un JSON y exige que su raíz sea un objeto.
 */
function p4_read_json(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException('No se puede leer ' . $path . '.');
    }
    $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException($path . ' no contiene un objeto JSON.');
    }
    return $data;
}

/**
 * Lee CSV UTF-8, con o sin BOM, y devuelve filas asociativas.
 */
function p4_read_csv(string $path): array {
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
            throw new RuntimeException($path . ', fila ' . $line . ': cantidad de columnas inválida.');
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

/**
 * Escribe CSV UTF-8 con BOM y columnas estables.
 */
function p4_write_csv(string $path, array $columns, array $rows): void {
    $handle = fopen($path, 'wb');
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
            }
            $values[] = $value;
        }
        fputcsv($handle, $values, ',', '"', '\\', "\r\n");
    }
    fclose($handle);
}

/**
 * Escribe JSON legible y determinista.
 */
function p4_write_json(string $path, array $data): void {
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($json === false || file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('No fue posible crear ' . $path . '.');
    }
}

/**
 * Calcula hashes de un conjunto de archivos.
 */
function p4_hash_files(array $paths): array {
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

/**
 * Carga y valida el contrato aprobado de la fase 3.
 */
function p4_load_phase3(
    string $inputdir,
    string $configsha,
    string $targetid,
    bool $expectlab
): array {
    $inputdir = rtrim($inputdir, '/\\');
    $paths = [
        'canonical_users.csv' => $inputdir . '/canonical_users.csv',
        'source_user_map.csv' => $inputdir . '/source_user_map.csv',
        'identity_conflicts.csv' => $inputdir . '/identity_conflicts.csv',
        'identity_resolution_audit.csv' => $inputdir . '/identity_resolution_audit.csv',
        'summary.json' => $inputdir . '/summary.json',
    ];
    $summary = p4_read_json($paths['summary.json']);
    if (($summary['schema_version'] ?? '') !== '1.6' ||
            ($summary['accepted_identity_schema_versions'] ?? null) !== ['1.2']) {
        throw new RuntimeException(
            'La fase 3 no usa el contrato de identidades 1.2 requerido. Ejecute nuevamente el comando 11.'
        );
    }
    if (($summary['config_sha256'] ?? '') !== $configsha) {
        throw new RuntimeException(
            'La fase 3 no corresponde al config.yaml confirmado. Ejecute nuevamente el comando 11.'
        );
    }
    if (($summary['configured_target']['id'] ?? '') !== $targetid) {
        throw new RuntimeException('La fase 3 fue generada para otro destino.');
    }
    if (($summary['apply_performed'] ?? null) !== false) {
        throw new RuntimeException('summary.json de fase 3 no conserva apply_performed=false.');
    }
    if ($expectlab && ($summary['lab_validation'] ?? '') !== 'passed') {
        throw new RuntimeException('La validación LAB de la fase 3 no está aprobada.');
    }
    $sources = array_values(array_map('strval', $summary['configured_sources'] ?? []));
    if (!$sources || count($sources) !== count(array_unique($sources))) {
        throw new RuntimeException('summary.json no contiene una lista válida de fuentes.');
    }

    $canonical = p4_read_csv($paths['canonical_users.csv']);
    $sourcemap = p4_read_csv($paths['source_user_map.csv']);
    $resolutionaudit = p4_read_csv($paths['identity_resolution_audit.csv']);
    if (count($canonical) !== (int)($summary['canonical_identities'] ?? -1)) {
        throw new RuntimeException('canonical_users.csv no coincide con el total de summary.json.');
    }
    if (count($sourcemap) !== (int)($summary['source_accounts'] ?? -1)) {
        throw new RuntimeException('source_user_map.csv no coincide con el total de summary.json.');
    }
    if (count($resolutionaudit) !==
            (int)($summary['identity_resolution_active_rows'] ?? -1)) {
        throw new RuntimeException(
            'identity_resolution_audit.csv no coincide con el total de summary.json.'
        );
    }
    if (hash_file('sha256', $paths['identity_resolution_audit.csv']) !==
            (string)($summary['identity_resolution_audit_sha256'] ?? '')) {
        throw new RuntimeException(
            'identity_resolution_audit.csv no coincide con el hash de summary.json.'
        );
    }

    $canonicalids = [];
    foreach ($canonical as $row) {
        $canonicalid = trim((string)($row['canonical_id'] ?? ''));
        if (!preg_match('/^CAN-[A-F0-9]{12}$/', $canonicalid)) {
            throw new RuntimeException('canonical_users.csv contiene un canonical_id inválido.');
        }
        if (isset($canonicalids[$canonicalid])) {
            throw new RuntimeException('canonical_users.csv repite ' . $canonicalid . '.');
        }
        $canonicalids[$canonicalid] = true;
    }

    $accounts = [];
    $sourceorder = [];
    foreach ($sources as $position => $sourceid) {
        $sourceorder[$sourceid] = $position;
        $identitypath = $inputdir . '/identity-' . $sourceid . '.json';
        $paths['identity-' . $sourceid . '.json'] = $identitypath;
        $identity = p4_read_json($identitypath);
        if (($identity['metadata']['source'] ?? '') !== $sourceid) {
            throw new RuntimeException($identitypath . ' pertenece a otra fuente.');
        }
        foreach ($identity['users'] ?? [] as $account) {
            $key = $sourceid . ':' . (string)($account['source_user_id'] ?? '');
            if (isset($accounts[$key])) {
                throw new RuntimeException('Cuenta de origen repetida en fase 3: ' . $key . '.');
            }
            $account['source'] = $sourceid;
            $accounts[$key] = $account;
        }
    }
    foreach ($sourcemap as $row) {
        $key = (string)($row['source'] ?? '') . ':' . (string)($row['source_user_id'] ?? '');
        if (!isset($accounts[$key])) {
            throw new RuntimeException('source_user_map.csv referencia una cuenta ausente: ' . $key . '.');
        }
        if (!isset($canonicalids[(string)($row['canonical_id'] ?? '')])) {
            throw new RuntimeException('source_user_map.csv referencia una identidad canónica ausente.');
        }
    }

    return [
        'summary' => $summary,
        'canonical' => $canonical,
        'source_map' => $sourcemap,
        'accounts' => $accounts,
        'source_order' => $sourceorder,
        'resolution_audit' => $resolutionaudit,
        'paths' => $paths,
        'hashes' => p4_hash_files($paths),
    ];
}

/**
 * Devuelve un documento solo cuando la conciliación produjo uno inequívoco.
 */
function p4_single_pipe_value(string $value): string {
    $items = array_values(array_unique(array_filter(array_map(
        'trim',
        explode('|', $value)
    ), 'strlen')));
    return count($items) === 1 ? $items[0] : '';
}

/**
 * Resuelve el correo vigente de una identidad ya fusionada.
 *
 * proposed_email tiene prioridad. Para merge_with_warning se toma la cuenta
 * de origen modificada más recientemente; los empates respetan el orden de
 * fuentes confirmado y luego el ID local. La regla queda visible en el plan.
 */
function p4_desired_identity(array $row, array $phase3): array {
    $email = p4_norm((string)($row['proposed_email'] ?? ''));
    $emailrule = $email !== '' ? 'phase3_proposed_email' : '';
    if ($email === '') {
        $candidates = [];
        foreach (explode('|', (string)($row['source_accounts'] ?? '')) as $accountkey) {
            $accountkey = trim($accountkey);
            if ($accountkey === '' || !isset($phase3['accounts'][$accountkey])) {
                continue;
            }
            $account = $phase3['accounts'][$accountkey];
            $candidateemail = p4_norm((string)($account['email'] ?? ''));
            if ($candidateemail === '') {
                continue;
            }
            $candidates[] = [
                'email' => $candidateemail,
                'timemodified' => (int)($account['timemodified'] ?? 0),
                'sourceorder' => (int)($phase3['source_order'][(string)$account['source']] ?? -1),
                'source_user_id' => (int)($account['source_user_id'] ?? 0),
            ];
        }
        usort($candidates, static fn(array $a, array $b): int => [
            $b['timemodified'], $b['sourceorder'], $b['source_user_id'], $b['email'],
        ] <=> [
            $a['timemodified'], $a['sourceorder'], $a['source_user_id'], $a['email'],
        ]);
        if ($candidates) {
            $email = $candidates[0]['email'];
            $emailrule = count(array_unique(array_column($candidates, 'email'))) > 1
                ? 'latest_source_timemodified'
                : 'single_source_email';
        }
    }

    $identitymethod = (string)($row['identity_method'] ?? '');
    return [
        'canonical_id' => (string)($row['canonical_id'] ?? ''),
        'username' => p4_norm((string)($row['canonical_username'] ?? '')),
        'email' => $email,
        'email_selection_rule' => $emailrule,
        'firstname' => trim((string)($row['firstname'] ?? '')),
        'lastname' => trim((string)($row['lastname'] ?? '')),
        'idnumber' => p4_single_pipe_value((string)($row['document_candidates'] ?? '')),
        'program_codes' => trim((string)($row['program_codes'] ?? '')),
        'google_issuer' => rtrim(p4_norm((string)($row['google_issuer'] ?? '')), '/'),
        'google_sub' => trim((string)($row['google_sub'] ?? '')),
        'oauth_linked_username' => p4_norm(
            (string)($row['oauth_linked_username'] ?? '')
        ),
        'oauth_identifier_kind' =>
            (string)($row['oauth_identifier_kind'] ?? ''),
        'auth' => in_array($identitymethod, ['google_sub', 'oauth_email'], true)
            ? 'oauth2'
            : 'manual',
    ];
}

/**
 * Obtiene los campos de perfil usados por la consolidación.
 */
function p4_profile_field_ids(): array {
    global $DB;
    $shortnames = [
        'migration_canonical_id', 'google_issuer', 'google_sub',
        'oauth_linked_username', 'oauth_identifier_kind', 'program_codes',
    ];
    $records = $DB->get_records_list('user_info_field', 'shortname', $shortnames, '', 'id,shortname,datatype');
    $result = [];
    foreach ($records as $record) {
        $result[(string)$record->shortname] = [
            'id' => (int)$record->id,
            'datatype' => (string)$record->datatype,
        ];
    }
    return $result;
}

/**
 * Inventario de usuarios existentes en el destino.
 */
function p4_target_inventory(): array {
    global $CFG, $DB;
    $records = $DB->get_records_select(
        'user',
        'deleted = 0 AND username <> :guest',
        ['guest' => 'guest'],
        'id ASC',
        'id,auth,confirmed,suspended,username,firstname,lastname,email,idnumber'
    );
    $fieldids = p4_profile_field_ids();
    $fieldbyid = [];
    foreach ($fieldids as $shortname => $definition) {
        $fieldbyid[$definition['id']] = $shortname;
    }
    $profiles = [];
    $userids = array_map('intval', array_keys($records));
    foreach (array_chunk($userids, 400) as $chunk) {
        if (!$chunk || !$fieldbyid) {
            continue;
        }
        [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'p4user');
        [$fieldsql, $fieldparams] = $DB->get_in_or_equal(
            array_keys($fieldbyid),
            SQL_PARAMS_NAMED,
            'p4field'
        );
        $data = $DB->get_records_select(
            'user_info_data',
            'userid ' . $insql . ' AND fieldid ' . $fieldsql,
            $params + $fieldparams,
            'id ASC',
            'id,userid,fieldid,data'
        );
        foreach ($data as $item) {
            $shortname = $fieldbyid[(int)$item->fieldid] ?? null;
            if ($shortname !== null) {
                $profiles[(int)$item->userid][$shortname] = trim((string)$item->data);
            }
        }
    }

    $users = [];
    foreach ($records as $record) {
        $profile = $profiles[(int)$record->id] ?? [];
        $users[(int)$record->id] = [
            'id' => (int)$record->id,
            'auth' => (string)$record->auth,
            'confirmed' => (bool)$record->confirmed,
            'suspended' => (bool)$record->suspended,
            'username' => p4_norm((string)$record->username),
            'firstname' => trim((string)$record->firstname),
            'lastname' => trim((string)$record->lastname),
            'email' => p4_norm((string)$record->email),
            'idnumber' => trim((string)$record->idnumber),
            'canonical_id' => trim((string)($profile['migration_canonical_id'] ?? '')),
            'google_issuer' => rtrim(p4_norm((string)($profile['google_issuer'] ?? '')), '/'),
            'google_sub' => trim((string)($profile['google_sub'] ?? '')),
            'oauth_linked_username' => p4_norm(
                (string)($profile['oauth_linked_username'] ?? '')
            ),
            'oauth_identifier_kind' => trim(
                (string)($profile['oauth_identifier_kind'] ?? '')
            ),
            'program_codes' => trim((string)($profile['program_codes'] ?? '')),
        ];
    }
    return $users;
}

/**
 * Huella estable del estado de usuarios que fue consultado al simular.
 */
function p4_inventory_fingerprint(array $users): string {
    ksort($users, SORT_NUMERIC);
    return hash('sha256', (string)json_encode(
        array_values($users),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
}

/**
 * Construye índices multivalor del destino.
 */
function p4_inventory_indexes(array $users): array {
    $indexes = [
        'canonical' => [],
        'strong' => [],
        'username' => [],
        'email' => [],
    ];
    foreach ($users as $id => $user) {
        if ($user['canonical_id'] !== '') {
            $indexes['canonical'][$user['canonical_id']][] = (int)$id;
        }
        if ($user['google_issuer'] !== '' && $user['google_sub'] !== '') {
            $indexes['strong'][$user['google_issuer'] . '|' . $user['google_sub']][] = (int)$id;
        }
        if ($user['username'] !== '') {
            $indexes['username'][$user['username']][] = (int)$id;
        }
        if ($user['email'] !== '') {
            $indexes['email'][$user['email']][] = (int)$id;
        }
    }
    return $indexes;
}

/**
 * Intersección ordenada de dos listas de IDs.
 */
function p4_intersect_ids(array $a, array $b): array {
    $result = array_values(array_intersect(array_map('intval', $a), array_map('intval', $b)));
    sort($result, SORT_NUMERIC);
    return array_values(array_unique($result));
}

/**
 * Columnas públicas del plan.
 */
function p4_plan_columns(): array {
    return [
        'plan_row_id', 'canonical_id', 'source_accounts', 'identity_decision',
        'identity_method', 'source_approved_for_apply', 'role_review_required',
        'roles_approved_for_apply', 'siteadmin_required',
        'canonical_username', 'target_username',
        'target_email', 'email_selection_rule', 'firstname', 'lastname',
        'target_idnumber', 'program_codes', 'google_issuer', 'google_sub',
        'oauth_linked_username', 'oauth_identifier_kind',
        'desired_auth', 'matched_target_user_id', 'match_basis', 'action',
        'blocking_reason',
    ];
}

/**
 * Genera el plan de una identidad frente al estado actual del destino.
 */
function p4_plan_identity(
    array $source,
    array $phase3,
    array $users,
    array $indexes
): array {
    $desired = p4_desired_identity($source, $phase3);
    $canonicalid = $desired['canonical_id'];
    $decision = (string)($source['decision'] ?? '');
    $identitymethod = (string)($source['identity_method'] ?? '');
    $canonicalmatches = $indexes['canonical'][$canonicalid] ?? [];

    $row = [
        'plan_row_id' => '',
        'canonical_id' => $canonicalid,
        'source_accounts' => (string)($source['source_accounts'] ?? ''),
        'identity_decision' => $decision,
        'identity_method' => $identitymethod,
        'source_approved_for_apply' => p4_bool($source['approved_for_apply'] ?? false),
        'role_review_required' => p4_bool($source['role_review_required'] ?? false),
        'roles_approved_for_apply' => p4_bool($source['roles_approved_for_apply'] ?? false),
        'siteadmin_required' => p4_bool($source['siteadmin_required'] ?? false),
        'canonical_username' => $desired['username'],
        'target_username' => $desired['username'],
        'target_email' => $desired['email'],
        'email_selection_rule' => $desired['email_selection_rule'],
        'firstname' => $desired['firstname'],
        'lastname' => $desired['lastname'],
        'target_idnumber' => $desired['idnumber'],
        'program_codes' => $desired['program_codes'],
        'google_issuer' => $desired['google_issuer'],
        'google_sub' => $desired['google_sub'],
        'oauth_linked_username' => $desired['oauth_linked_username'],
        'oauth_identifier_kind' => $desired['oauth_identifier_kind'],
        'desired_auth' => $desired['auth'],
        'matched_target_user_id' => '',
        'match_basis' => '',
        'action' => '',
        'blocking_reason' => '',
    ];

    if ($decision === 'blocked') {
        if ($canonicalmatches) {
            $row['action'] = 'conflict_blocked_identity_present';
            $row['blocking_reason'] = 'Una identidad bloqueada ya está marcada en el destino.';
        } else {
            $row['action'] = 'skip_blocked';
            $row['blocking_reason'] = 'Conflicto crítico de identidad en fase 3.';
        }
        return $row;
    }
    if ($decision === 'manual_review') {
        if ($canonicalmatches) {
            $row['action'] = 'conflict_review_identity_present';
            $row['blocking_reason'] = 'Una identidad pendiente de revisión ya está marcada en el destino.';
        } else {
            $row['action'] = 'skip_identity_review';
            $row['blocking_reason'] = 'Identidad externa sin llave utilizable.';
        }
        return $row;
    }
    if ($decision === 'excluded') {
        if ($canonicalmatches) {
            $row['action'] = 'conflict_excluded_identity_present';
            $row['blocking_reason'] = 'Una identidad excluida ya está marcada en el destino.';
        } else {
            $row['action'] = 'skip_excluded';
            $row['blocking_reason'] = 'Exclusión aprobada y registrada en fase 3.';
        }
        return $row;
    }

    $alloweddecisions = [
        'merge', 'merge_manual_username', 'merge_with_warning',
        'merge_institutional_email', 'keep_institutional_email',
        'merge_oauth_email', 'keep_oauth_email',
        'keep_separate', 'keep_manual_username',
        'resolved_merge', 'resolved_keep_separate',
    ];
    if (!in_array($decision, $alloweddecisions, true)) {
        $row['action'] = 'conflict_unknown_decision';
        $row['blocking_reason'] = 'Decisión de fase 3 no reconocida: ' . $decision . '.';
        return $row;
    }
    if ($desired['username'] === '' ||
            $desired['firstname'] === '' ||
            $desired['lastname'] === '' ||
            $desired['email'] === '') {
        $row['action'] = 'conflict_incomplete_identity';
        $row['blocking_reason'] = 'Faltan username, nombre, apellido o correo para crear el usuario.';
        return $row;
    }
    if ($desired['username'] !== core_user::clean_field($desired['username'], 'username') ||
            $desired['username'] !== core_text::strtolower($desired['username'])) {
        $row['action'] = 'conflict_invalid_username';
        $row['blocking_reason'] = 'El username canónico no es válido para Moodle.';
        return $row;
    }
    if (!validate_email($desired['email'])) {
        $row['action'] = 'conflict_invalid_email';
        $row['blocking_reason'] = 'El correo seleccionado no tiene un formato válido.';
        return $row;
    }
    if ($identitymethod === 'google_sub' &&
            ($desired['google_issuer'] === '' || $desired['google_sub'] === '')) {
        $row['action'] = 'conflict_incomplete_strong_identity';
        $row['blocking_reason'] = 'La identidad google_sub no conserva issuer + sub.';
        return $row;
    }
    if ($identitymethod === 'oauth_email' &&
            ($desired['google_issuer'] === '' ||
             $desired['oauth_identifier_kind'] !== 'email' ||
             !validate_email($desired['oauth_linked_username']))) {
        $row['action'] = 'conflict_incomplete_oauth_email_identity';
        $row['blocking_reason'] =
            'La identidad oauth_email no conserva issuer y linked username válidos.';
        return $row;
    }

    $selected = null;
    $matchbasis = '';
    if (count($canonicalmatches) > 1) {
        $row['action'] = 'conflict_duplicate_canonical_marker';
        $row['blocking_reason'] = 'Más de un usuario destino contiene el mismo canonical_id.';
        return $row;
    }
    if (count($canonicalmatches) === 1) {
        $selected = (int)$canonicalmatches[0];
        $matchbasis = 'canonical_id';
    }

    if ($selected === null && $desired['google_issuer'] !== '' && $desired['google_sub'] !== '') {
        $strongkey = $desired['google_issuer'] . '|' . $desired['google_sub'];
        $strongmatches = $indexes['strong'][$strongkey] ?? [];
        if (count($strongmatches) > 1) {
            $row['action'] = 'conflict_duplicate_strong_identity';
            $row['blocking_reason'] = 'Más de un usuario destino comparte issuer + sub.';
            return $row;
        }
        if (count($strongmatches) === 1) {
            $selected = (int)$strongmatches[0];
            $matchbasis = 'google_issuer_sub';
        }
    }

    $usernamematches = $indexes['username'][$desired['username']] ?? [];
    $emailmatches = $indexes['email'][$desired['email']] ?? [];
    if ($selected === null) {
        $exactmatches = p4_intersect_ids($usernamematches, $emailmatches);
        if (count($exactmatches) > 1) {
            $row['action'] = 'conflict_duplicate_exact_match';
            $row['blocking_reason'] = 'Username y correo coinciden con varios usuarios destino.';
            return $row;
        }
        if (count($exactmatches) === 1) {
            $selected = (int)$exactmatches[0];
            $matchbasis = 'username_and_email';
        }
    }
    if ($selected === null && $identitymethod === 'manual_username') {
        if (count($usernamematches) > 1) {
            $row['action'] = 'conflict_duplicate_manual_username';
            $row['blocking_reason'] = 'El username manual coincide con varios usuarios destino.';
            return $row;
        }
        if (count($usernamematches) === 1) {
            $selected = (int)$usernamematches[0];
            $matchbasis = 'manual_username';
        }
    }

    if ($selected === null) {
        if ($usernamematches) {
            $row['action'] = 'conflict_username_collision';
            $row['blocking_reason'] = 'El username canónico ya pertenece a otro usuario destino.';
            return $row;
        }
        if ($emailmatches) {
            $row['action'] = 'conflict_email_collision';
            $row['blocking_reason'] = 'El correo seleccionado ya pertenece a otro usuario destino.';
            return $row;
        }
        $row['action'] = 'create';
        $row['match_basis'] = 'none';
        return $row;
    }

    $target = $users[$selected];
    if ($target['canonical_id'] !== '' && $target['canonical_id'] !== $canonicalid) {
        $row['action'] = 'conflict_target_claimed_by_other_canonical';
        $row['blocking_reason'] = 'El usuario destino está marcado con otro canonical_id.';
        return $row;
    }
    $otheremails = array_values(array_diff($emailmatches, [$selected]));
    if ($otheremails) {
        $row['action'] = 'conflict_email_collision';
        $row['blocking_reason'] = 'El correo seleccionado también pertenece a otro usuario destino.';
        return $row;
    }

    $row['matched_target_user_id'] = $selected;
    $row['match_basis'] = $matchbasis;
    // Un username existente se conserva como identidad de acceso del destino.
    $row['target_username'] = $target['username'];

    $same = $target['firstname'] === $desired['firstname'] &&
        $target['lastname'] === $desired['lastname'] &&
        $target['email'] === $desired['email'] &&
        ($desired['idnumber'] === '' || $target['idnumber'] === $desired['idnumber']) &&
        $target['canonical_id'] === $canonicalid &&
        $target['google_issuer'] === $desired['google_issuer'] &&
        $target['google_sub'] === $desired['google_sub'] &&
        $target['oauth_linked_username'] === $desired['oauth_linked_username'] &&
        $target['oauth_identifier_kind'] === $desired['oauth_identifier_kind'] &&
        $target['program_codes'] === $desired['program_codes'];
    if ($matchbasis === 'canonical_id') {
        $row['action'] = $same ? 'reuse_existing' : 'update_existing';
    } else {
        $row['action'] = 'adopt_existing';
    }
    return $row;
}

/**
 * Construye el plan completo y evita que dos identidades reclamen el mismo ID.
 */
function p4_build_plan(array $phase3, array $users): array {
    $indexes = p4_inventory_indexes($users);
    $rows = [];
    foreach ($phase3['canonical'] as $source) {
        $rows[] = p4_plan_identity($source, $phase3, $users, $indexes);
    }
    usort($rows, static fn(array $a, array $b): int =>
        strcmp((string)$a['canonical_id'], (string)$b['canonical_id'])
    );
    foreach ($rows as $index => &$row) {
        $row['plan_row_id'] = sprintf('P4-%04d', $index + 1);
    }
    unset($row);

    $claims = [];
    foreach ($rows as $index => $row) {
        $targetid = (int)($row['matched_target_user_id'] ?? 0);
        if ($targetid > 0 && in_array($row['action'], [
            'reuse_existing', 'update_existing', 'adopt_existing',
        ], true)) {
            $claims[$targetid][] = $index;
        }
    }
    foreach ($claims as $targetid => $indices) {
        if (count($indices) < 2) {
            continue;
        }
        foreach ($indices as $index) {
            $rows[$index]['action'] = 'conflict_target_claimed_multiple_times';
            $rows[$index]['blocking_reason'] =
                'El usuario destino ' . $targetid . ' fue reclamado por varias identidades canónicas.';
        }
    }
    return $rows;
}

/**
 * Detecta marcadores de migración que existen en el destino, pero ya no
 * pertenecen a la conciliación actual. Esto protege cambios o retiros de una
 * resolución después de haber creado usuarios.
 */
function p4_orphan_canonical_markers(array $phase3, array $users): array {
    $known = [];
    foreach ($phase3['canonical'] as $row) {
        $known[(string)$row['canonical_id']] = true;
    }
    $orphans = [];
    foreach ($users as $user) {
        $canonicalid = trim((string)($user['canonical_id'] ?? ''));
        if ($canonicalid === '' || isset($known[$canonicalid])) {
            continue;
        }
        $orphans[] = [
            'target_user_id' => (int)($user['id'] ?? 0),
            'canonical_id' => $canonicalid,
            'username' => (string)($user['username'] ?? ''),
        ];
    }
    usort($orphans, static fn(array $a, array $b): int => [
        $a['canonical_id'], $a['target_user_id'],
    ] <=> [
        $b['canonical_id'], $b['target_user_id'],
    ]);
    return $orphans;
}

/**
 * Resume las acciones del plan.
 */
function p4_action_counts(array $rows): array {
    $counts = [];
    foreach ($rows as $row) {
        $action = (string)($row['action'] ?? '');
        $counts[$action] = ($counts[$action] ?? 0) + 1;
    }
    ksort($counts, SORT_STRING);
    return $counts;
}

/**
 * Identifica acciones que impiden aplicar el plan completo.
 */
function p4_is_conflict_action(string $action): bool {
    return str_starts_with($action, 'conflict_');
}

/**
 * Acciones que deben producir un target_user_id.
 */
function p4_is_apply_action(string $action): bool {
    return in_array($action, [
        'create', 'reuse_existing', 'update_existing', 'adopt_existing',
    ], true);
}

/**
 * Carga y valida el plan previamente simulado.
 */
function p4_load_plan(
    string $phase3dir,
    string $phase4dir,
    string $configsha,
    string $targetid,
    bool $expectlab
): array {
    $phase4dir = rtrim($phase4dir, '/\\');
    $planpath = $phase4dir . '/target_user_plan.csv';
    $summarypath = $phase4dir . '/plan_summary.json';
    $summary = p4_read_json($summarypath);
    if (($summary['config_sha256'] ?? '') !== $configsha ||
            ($summary['target_id'] ?? '') !== $targetid) {
        throw new RuntimeException('El plan de fase 4 corresponde a otra configuración o destino.');
    }
    $planhash = hash_file('sha256', $planpath);
    if (($summary['plan_sha256'] ?? '') !== $planhash) {
        throw new RuntimeException('target_user_plan.csv cambió después de la simulación.');
    }
    $phase3 = p4_load_phase3($phase3dir, $configsha, $targetid, $expectlab);
    if (($summary['phase3_input_sha256'] ?? []) !== $phase3['hashes']) {
        throw new RuntimeException('Los resultados de fase 3 cambiaron después de generar el plan.');
    }
    $rows = p4_read_csv($planpath);
    if (count($rows) !== (int)($summary['canonical_identities'] ?? -1)) {
        throw new RuntimeException('El número de filas del plan no coincide con su resumen.');
    }
    $conflicts = array_filter(
        $rows,
        static fn(array $row): bool => p4_is_conflict_action((string)($row['action'] ?? ''))
    );
    if (count($conflicts) !== (int)($summary['row_blocking_conflicts'] ?? -1)) {
        throw new RuntimeException('Los conflictos del plan no coinciden con plan_summary.json.');
    }
    return [
        'summary' => $summary,
        'rows' => $rows,
        'plan_path' => $planpath,
        'plan_hash' => $planhash,
        'phase3' => $phase3,
    ];
}

/**
 * Vuelve a simular contra el estado actual antes de cualquier escritura.
 *
 * Permite que una ejecución anterior ya haya materializado el plan, pero
 * rechaza colisiones nuevas, cambios de target_user_id o identidades excluidas
 * que hayan aparecido en el destino.
 */
function p4_preflight_plan(array $bundle, array $inventory): void {
    $orphans = p4_orphan_canonical_markers($bundle['phase3'], $inventory);
    if ($orphans) {
        throw new RuntimeException(
            'El destino contiene marcadores canónicos ajenos a la conciliación actual: ' .
            implode('|', array_column($orphans, 'canonical_id')) . '.'
        );
    }
    $currentrows = p4_build_plan($bundle['phase3'], $inventory);
    $currentbycanonical = [];
    foreach ($currentrows as $row) {
        $currentbycanonical[(string)$row['canonical_id']] = $row;
    }
    foreach ($bundle['rows'] as $planned) {
        $canonicalid = (string)$planned['canonical_id'];
        $current = $currentbycanonical[$canonicalid] ?? null;
        if ($current === null) {
            throw new RuntimeException($canonicalid . ': desapareció del plan recalculado.');
        }
        $currentaction = (string)$current['action'];
        if (p4_is_conflict_action($currentaction)) {
            throw new RuntimeException(
                $canonicalid . ': el destino cambió y ahora produce ' . $currentaction . '.'
            );
        }
        $plannedaction = (string)$planned['action'];
        if (p4_is_apply_action($plannedaction)) {
            if (!p4_is_apply_action($currentaction)) {
                throw new RuntimeException(
                    $canonicalid . ': dejó de ser aplicable después de la simulación.'
                );
            }
            $plannedid = (int)($planned['matched_target_user_id'] ?? 0);
            $currentid = (int)($current['matched_target_user_id'] ?? 0);
            if ($plannedid > 0 && $currentid !== $plannedid) {
                throw new RuntimeException(
                    $canonicalid . ': ahora apunta a un target_user_id diferente.'
                );
            }
            // Una fila originalmente create puede aparecer como reutilizada
            // cuando se reintenta una aplicación ya completada o interrumpida.
            continue;
        }
        if ($plannedaction !== $currentaction) {
            throw new RuntimeException(
                $canonicalid . ': cambió de ' . $plannedaction . ' a ' . $currentaction . '.'
            );
        }
    }
}

/**
 * Crea o recupera los campos de perfil de trazabilidad.
 */
function p4_ensure_profile_fields(): array {
    global $DB;
    $definitions = [
        'migration_canonical_id' => ['ID canónico de migración', 1],
        'google_issuer' => ['Google issuer', 0],
        'google_sub' => ['Google subject', 0],
        'oauth_linked_username' => ['OAuth linked username', 0],
        'oauth_identifier_kind' => ['OAuth identifier kind', 0],
        'program_codes' => ['Códigos de programa', 0],
    ];
    $category = $DB->get_record(
        'user_info_category',
        ['name' => 'Migración Moodle'],
        '*',
        IGNORE_MISSING
    );
    if (!$category) {
        $maxsort = (int)$DB->get_field_sql('SELECT COALESCE(MAX(sortorder), 0) FROM {user_info_category}');
        $category = (object)[
            'name' => 'Migración Moodle',
            'sortorder' => $maxsort + 1,
        ];
        $category->id = $DB->insert_record('user_info_category', $category);
    }

    $result = [];
    foreach ($definitions as $shortname => [$name, $forceunique]) {
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname], '*', IGNORE_MISSING);
        if ($field) {
            if ((string)$field->datatype !== 'text') {
                throw new RuntimeException(
                    'El campo de perfil ' . $shortname . ' existe con un tipo incompatible.'
                );
            }
            $result[$shortname] = (int)$field->id;
            continue;
        }
        $maxsort = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(sortorder), 0) FROM {user_info_field} WHERE categoryid = ?',
            [(int)$category->id]
        );
        $field = (object)[
            'shortname' => $shortname,
            'name' => $name,
            'datatype' => 'text',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'categoryid' => (int)$category->id,
            'sortorder' => $maxsort + 1,
            'required' => 0,
            'locked' => 1,
            // PROFILE_VISIBLE_NONE vale 0; se usa el literal para no cargar
            // las clases de formulario del editor de perfiles en CLI.
            'visible' => 0,
            'forceunique' => $forceunique,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => FORMAT_HTML,
            'param1' => 255,
            'param2' => 2048,
            'param3' => 0,
            'param4' => '',
            'param5' => '',
        ];
        $result[$shortname] = (int)$DB->insert_record('user_info_field', $field);
    }
    return $result;
}

/**
 * Guarda un valor de perfil sin pasar por formularios interactivos.
 */
function p4_set_profile_value(int $userid, int $fieldid, string $value): void {
    global $DB;
    $record = $DB->get_record(
        'user_info_data',
        ['userid' => $userid, 'fieldid' => $fieldid],
        '*',
        IGNORE_MISSING
    );
    if ($record) {
        if ((string)$record->data !== $value || (int)$record->dataformat !== FORMAT_PLAIN) {
            $record->data = $value;
            $record->dataformat = FORMAT_PLAIN;
            $DB->update_record('user_info_data', $record);
        }
        return;
    }
    $DB->insert_record('user_info_data', (object)[
        'userid' => $userid,
        'fieldid' => $fieldid,
        'data' => $value,
        'dataformat' => FORMAT_PLAIN,
    ]);
}

/**
 * Busca IDs que ya contienen un canonical_id.
 */
function p4_find_canonical_users(int $fieldid, string $canonicalid): array {
    global $DB;
    $records = $DB->get_records('user_info_data', [
        'fieldid' => $fieldid,
        'data' => $canonicalid,
    ], 'userid ASC', 'id,userid');
    return array_values(array_map(
        static fn(stdClass $record): int => (int)$record->userid,
        $records
    ));
}

/**
 * Carga el issuer OAuth2 ya configurado manualmente y exige que pueda iniciar
 * sesión. No expone el Client ID ni el secreto.
 */
function p4_get_oauth2_issuer(int $issuerid): \core\oauth2\issuer {
    if ($issuerid < 1 || !is_enabled_auth('oauth2')) {
        throw new RuntimeException(
            'OAuth2 no está habilitado o no conserva un issuer válido.'
        );
    }
    $issuer = \core\oauth2\issuer::get_record(['id' => $issuerid]);
    if (!$issuer || !$issuer->is_available_for_login()) {
        throw new RuntimeException(
            'El issuer OAuth2 dejó de estar disponible para iniciar sesión.'
        );
    }
    return $issuer;
}

/**
 * Indica si una fila debe conservar autenticación OAuth2 nativa.
 */
function p4_is_oauth_identity_method(string $method): bool {
    return in_array($method, ['google_sub', 'oauth_email'], true);
}

/**
 * Devuelve exactamente el identificador externo que Moodle guarda en
 * auth_oauth2_linked_login.username, sin convertir correos en google_sub.
 */
function p4_oauth_identifier(array $row): string {
    $method = (string)($row['identity_method'] ?? '');
    return match ($method) {
        'google_sub' => trim((string)($row['google_sub'] ?? '')),
        'oauth_email' => p4_norm((string)($row['oauth_linked_username'] ?? '')),
        default => '',
    };
}

/**
 * Rechaza una configuración explícita del issuer que contradiga el tipo de
 * linked username planificado. Sin mapeo explícito se conserva el valor
 * comprobado por los orígenes.
 */
function p4_validate_oauth2_identifier_mapping(array $rows, int $issuerid): void {
    global $DB;
    $dbman = $DB->get_manager();
    if (!$dbman->table_exists(new xmldb_table('oauth2_user_field_mapping'))) {
        return;
    }
    $records = $DB->get_records('oauth2_user_field_mapping', [
        'issuerid' => $issuerid,
        'internalfield' => 'username',
    ], 'id ASC', 'id,externalfield');
    $externalfields = [];
    foreach ($records as $record) {
        $field = p4_norm((string)$record->externalfield);
        if ($field !== '') {
            $externalfields[$field] = true;
        }
    }
    $externalfields = array_keys($externalfields);
    sort($externalfields, SORT_STRING);
    if (!$externalfields) {
        return;
    }
    foreach ($rows as $row) {
        if (!p4_is_apply_action((string)($row['action'] ?? ''))) {
            continue;
        }
        $method = (string)($row['identity_method'] ?? '');
        if ($method === 'oauth_email' && $externalfields === ['sub']) {
            throw new RuntimeException(
                'El issuer destino mapea sub a username, pero el plan requiere linked usernames por correo.'
            );
        }
        if ($method === 'google_sub' &&
                in_array('email', $externalfields, true) &&
                !in_array('sub', $externalfields, true)) {
            throw new RuntimeException(
                'El issuer destino mapea email a username, pero el plan requiere linked usernames por sub.'
            );
        }
    }
}

/**
 * Comprueba todas las colisiones OAuth2 antes de crear el primer usuario.
 */
function p4_preflight_oauth2_links(
    array $rows,
    array $inventory,
    int $issuerid
): void {
    global $DB;
    $indexes = p4_inventory_indexes($inventory);
    $plannedidentifiers = [];
    foreach ($rows as $row) {
        if (!p4_is_apply_action((string)($row['action'] ?? '')) ||
                !p4_is_oauth_identity_method(
                    (string)($row['identity_method'] ?? '')
                )) {
            continue;
        }
        $canonicalid = (string)$row['canonical_id'];
        $identifier = p4_oauth_identifier($row);
        if ($identifier === '') {
            throw new RuntimeException(
                $canonicalid . ': falta el identificador para crear el vínculo OAuth2.'
            );
        }
        if (isset($plannedidentifiers[$identifier]) &&
                $plannedidentifiers[$identifier] !== $canonicalid) {
            throw new RuntimeException(
                'El identificador OAuth ' . $identifier .
                ' aparece en varias identidades canónicas.'
            );
        }
        $plannedidentifiers[$identifier] = $canonicalid;

        $expecteduserid = (int)($row['matched_target_user_id'] ?? 0);
        $canonicalmatches = $indexes['canonical'][$canonicalid] ?? [];
        if (count($canonicalmatches) > 1) {
            throw new RuntimeException(
                $canonicalid . ': el destino repite el marcador canónico.'
            );
        }
        if ($expecteduserid < 1 && count($canonicalmatches) === 1) {
            $expecteduserid = (int)$canonicalmatches[0];
        }

        $subjectlinks = $DB->get_records('auth_oauth2_linked_login', [
            'issuerid' => $issuerid,
            'username' => $identifier,
        ], 'id ASC');
        if (count($subjectlinks) > 1) {
            throw new RuntimeException(
                $canonicalid . ': el identificador OAuth ya está vinculado más de una vez.'
            );
        }
        if ($subjectlinks) {
            $linked = reset($subjectlinks);
            if ($expecteduserid < 1 || (int)$linked->userid !== $expecteduserid) {
                throw new RuntimeException(
                    $canonicalid . ': el identificador OAuth ya pertenece a otro usuario destino.'
                );
            }
        }
        if ($expecteduserid > 0) {
            $userlinks = $DB->get_records('auth_oauth2_linked_login', [
                'issuerid' => $issuerid,
                'userid' => $expecteduserid,
            ], 'id ASC');
            foreach ($userlinks as $linked) {
                if ((string)$linked->username !== $identifier) {
                    throw new RuntimeException(
                        $canonicalid . ': el usuario destino ya posee otro identificador Google.'
                    );
                }
            }
        }
    }
}

/**
 * Crea o confirma de forma idempotente el linked login nativo de Moodle.
 */
function p4_ensure_oauth2_link(
    int $userid,
    string $identifier,
    string $email,
    \core\oauth2\issuer $issuer
): string {
    global $DB, $USER;
    $issuerid = (int)$issuer->get('id');
    $subjectlinks = $DB->get_records('auth_oauth2_linked_login', [
        'issuerid' => $issuerid,
        'username' => $identifier,
    ], 'id ASC');
    if (count($subjectlinks) > 1) {
        throw new RuntimeException(
            'El identificador Google ya aparece vinculado a más de una cuenta.'
        );
    }
    if ($subjectlinks) {
        $linked = reset($subjectlinks);
        if ((int)$linked->userid !== $userid) {
            throw new RuntimeException(
                'El identificador Google ya pertenece a otro usuario destino.'
            );
        }
        $changed = false;
        if ((string)$linked->email !== $email) {
            $linked->email = $email;
            $changed = true;
        }
        if ((string)$linked->confirmtoken !== '' ||
                (int)$linked->confirmtokenexpires !== 0) {
            $linked->confirmtoken = '';
            $linked->confirmtokenexpires = 0;
            $changed = true;
        }
        if ($changed) {
            $linked->timemodified = time();
            $linked->usermodified = (int)$USER->id;
            $DB->update_record('auth_oauth2_linked_login', $linked);
            return 'updated';
        }
        return 'reused';
    }

    $userlinks = $DB->get_records('auth_oauth2_linked_login', [
        'issuerid' => $issuerid,
        'userid' => $userid,
    ], 'id ASC');
    foreach ($userlinks as $linked) {
        if ((string)$linked->username !== $identifier) {
            throw new RuntimeException(
                'El usuario destino ya posee otro identificador Google.'
            );
        }
    }
    \auth_oauth2\api::link_login(
        ['username' => $identifier, 'email' => $email],
        $issuer,
        $userid,
        true
    );
    $created = $DB->get_record('auth_oauth2_linked_login', [
        'issuerid' => $issuerid,
        'username' => $identifier,
    ], '*', MUST_EXIST);
    if ((int)$created->userid !== $userid) {
        throw new RuntimeException(
            'Moodle creó el vínculo Google para un usuario inesperado.'
        );
    }
    if ((string)$created->confirmtoken !== '' ||
            (int)$created->confirmtokenexpires !== 0) {
        $created->confirmtoken = '';
        $created->confirmtokenexpires = 0;
        $created->timemodified = time();
        $created->usermodified = (int)$USER->id;
        $DB->update_record('auth_oauth2_linked_login', $created);
    }
    return 'created';
}

/**
 * Aplica una fila del plan de forma idempotente.
 */
function p4_apply_plan_row(
    array $row,
    array $fieldids,
    \core\oauth2\issuer $oauthissuer
): array {
    global $CFG, $DB;
    $canonicalid = (string)$row['canonical_id'];
    $plannedaction = (string)$row['action'];
    $canonicalmatches = p4_find_canonical_users(
        $fieldids['migration_canonical_id'],
        $canonicalid
    );
    if (count($canonicalmatches) > 1) {
        throw new RuntimeException('El destino ya repite el marcador ' . $canonicalid . '.');
    }

    $userid = $canonicalmatches[0] ?? 0;
    $status = '';
    $created = false;
    if ($userid > 0) {
        $status = 'already_applied';
    } else {
        $expectedid = (int)($row['matched_target_user_id'] ?? 0);
        if ($expectedid > 0) {
            $candidate = $DB->get_record('user', [
                'id' => $expectedid,
                'deleted' => 0,
            ], '*', IGNORE_MISSING);
            if (!$candidate) {
                throw new RuntimeException(
                    $canonicalid . ': el usuario destino previsto ya no existe.'
                );
            }
            $userid = (int)$candidate->id;
            $status = $plannedaction === 'adopt_existing' ? 'adopted' : 'updated';
        } else {
            $username = p4_norm((string)$row['target_username']);
            $email = p4_norm((string)$row['target_email']);
            $usernameowner = $DB->get_record('user', [
                'username' => $username,
                'mnethostid' => $CFG->mnet_localhost_id,
                'deleted' => 0,
            ], '*', IGNORE_MISSING);
            $emailowners = $DB->get_records('user', [
                'email' => $email,
                'deleted' => 0,
            ], 'id ASC', 'id');
            if ($usernameowner || $emailowners) {
                throw new RuntimeException(
                    $canonicalid . ': apareció una colisión de username o correo después de simular.'
                );
            }
            $auth = (string)$row['desired_auth'];
            $user = (object)[
                'username' => $username,
                'firstname' => (string)$row['firstname'],
                'lastname' => (string)$row['lastname'],
                'email' => $email,
                'idnumber' => (string)$row['target_idnumber'],
                'auth' => $auth,
                'confirmed' => 1,
                'suspended' => 0,
                'mnethostid' => $CFG->mnet_localhost_id,
                'lang' => current_language(),
                'description' => '[MIGRACION-CANONICA] ' . $canonicalid,
                'descriptionformat' => FORMAT_PLAIN,
            ];
            if ($auth === 'manual') {
                $user->password = random_string(28) . 'Aa1!';
                $userid = user_create_user($user, true, true);
                set_user_preference('auth_forcepasswordchange', 1, $userid);
            } else {
                $user->password = AUTH_PASSWORD_NOT_CACHED;
                $userid = user_create_user($user, false, true);
            }
            $created = true;
            $status = 'created';
        }
    }

    $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
    $desiredemail = p4_norm((string)$row['target_email']);
    $otheremail = $DB->get_record_select(
        'user',
        'deleted = 0 AND email = :email AND id <> :userid',
        ['email' => $desiredemail, 'userid' => $userid],
        'id',
        IGNORE_MISSING
    );
    if ($otheremail) {
        throw new RuntimeException($canonicalid . ': el correo pertenece a otro usuario destino.');
    }
    if (p4_norm((string)$user->username) !== p4_norm((string)$row['target_username'])) {
        throw new RuntimeException(
            $canonicalid . ': el username destino cambió después de simular.'
        );
    }

    $update = (object)['id' => $userid];
    $changed = false;
    foreach ([
        'firstname' => (string)$row['firstname'],
        'lastname' => (string)$row['lastname'],
        'email' => $desiredemail,
    ] as $field => $value) {
        if ((string)$user->$field !== $value) {
            $update->$field = $value;
            $changed = true;
        }
    }
    $desiredidnumber = trim((string)$row['target_idnumber']);
    if ($desiredidnumber !== '' && (string)$user->idnumber !== $desiredidnumber) {
        $update->idnumber = $desiredidnumber;
        $changed = true;
    }
    $desiredauth = (string)$row['desired_auth'];
    if ((string)$user->auth !== $desiredauth) {
        $update->auth = $desiredauth;
        $changed = true;
    }
    if ($changed) {
        user_update_user($update, false, true);
        if (!$created && $status === 'already_applied') {
            $status = 'updated';
        }
    }

    $existingcanonical = p4_find_canonical_users(
        $fieldids['migration_canonical_id'],
        $canonicalid
    );
    if ($existingcanonical && !in_array($userid, $existingcanonical, true)) {
        throw new RuntimeException($canonicalid . ': otro usuario ya posee el marcador canónico.');
    }
    p4_set_profile_value(
        $userid,
        $fieldids['migration_canonical_id'],
        $canonicalid
    );
    p4_set_profile_value(
        $userid,
        $fieldids['google_issuer'],
        (string)$row['google_issuer']
    );
    p4_set_profile_value(
        $userid,
        $fieldids['google_sub'],
        (string)$row['google_sub']
    );
    p4_set_profile_value(
        $userid,
        $fieldids['oauth_linked_username'],
        (string)$row['oauth_linked_username']
    );
    p4_set_profile_value(
        $userid,
        $fieldids['oauth_identifier_kind'],
        (string)$row['oauth_identifier_kind']
    );
    p4_set_profile_value(
        $userid,
        $fieldids['program_codes'],
        (string)$row['program_codes']
    );

    $oauthlinkstatus = 'not_applicable';
    $oauthissuerid = '';
    $oauthsubject = '';
    if (p4_is_oauth_identity_method((string)$row['identity_method'])) {
        $oauthsubject = p4_oauth_identifier($row);
        $oauthissuerid = (int)$oauthissuer->get('id');
        $oauthlinkstatus = p4_ensure_oauth2_link(
            $userid,
            $oauthsubject,
            $desiredemail,
            $oauthissuer
        );
    }

    $final = $DB->get_record('user', ['id' => $userid], 'id,username,email', MUST_EXIST);
    return [
        'plan_row_id' => (string)$row['plan_row_id'],
        'canonical_id' => $canonicalid,
        'planned_action' => $plannedaction,
        'apply_status' => $status,
        'target_user_id' => $userid,
        'target_username' => (string)$final->username,
        'target_email' => (string)$final->email,
        'canonical_marker' => $canonicalid,
        'oauth2_issuer_id' => $oauthissuerid,
        'oauth2_subject' => $oauthsubject,
        'oauth2_link_status' => $oauthlinkstatus,
        'roles_applied' => false,
        'enrolments_applied' => false,
        'message' => 'Identidad canónica y vínculo de acceso aplicados; permisos y matrículas permanecen pendientes.',
    ];
}

/**
 * Columnas del mapa materializado.
 */
function p4_map_columns(): array {
    return [
        'plan_row_id', 'canonical_id', 'planned_action', 'apply_status',
        'target_user_id', 'target_username', 'target_email', 'canonical_marker',
        'oauth2_issuer_id', 'oauth2_subject', 'oauth2_link_status',
        'roles_applied', 'enrolments_applied', 'message',
    ];
}

/**
 * Materializa el encadenamiento completo origen -> canónico -> destino.
 */
function p4_build_source_target_map(array $sourcemap, array $targetmap): array {
    $targetbycanonical = [];
    foreach ($targetmap as $row) {
        $targetbycanonical[(string)$row['canonical_id']] = $row;
    }
    $result = [];
    foreach ($sourcemap as $source) {
        $canonicalid = (string)$source['canonical_id'];
        $target = $targetbycanonical[$canonicalid] ?? null;
        $targetuserid = $target ? (int)($target['target_user_id'] ?? 0) : 0;
        $result[] = [
            'source' => (string)$source['source'],
            'source_user_id' => (string)$source['source_user_id'],
            'source_username' => (string)$source['source_username'],
            'canonical_id' => $canonicalid,
            'identity_decision' => (string)$source['decision'],
            'target_user_id' => $targetuserid > 0 ? $targetuserid : '',
            'mapping_status' => $targetuserid > 0 ? 'mapped' : 'excluded',
        ];
    }
    usort($result, static fn(array $a, array $b): int => [
        $a['source'], (int)$a['source_user_id'],
    ] <=> [
        $b['source'], (int)$b['source_user_id'],
    ]);
    return $result;
}

/**
 * Columnas del mapa directo de cuentas de origen.
 */
function p4_source_target_columns(): array {
    return [
        'source', 'source_user_id', 'source_username', 'canonical_id',
        'identity_decision', 'target_user_id', 'mapping_status',
    ];
}

/**
 * Lista estable de administradores actuales del sitio.
 */
function p4_current_site_administrator_ids(): array {
    $ids = [];
    foreach (get_admins() as $admin) {
        $userid = (int)$admin->id;
        if ($userid > 0) {
            $ids[$userid] = true;
        }
    }
    $ids = array_keys($ids);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/**
 * Conserva los siteadmin de origen apenas se materializan los usuarios.
 * La operación solo añade IDs aprobados; nunca elimina administradores que ya
 * estuvieran configurados en el Moodle destino.
 */
function p4_apply_planned_site_administrators(
    array $planrows,
    array $resultrows
): array {
    $targetbycanonical = [];
    foreach ($resultrows as $result) {
        $targetuserid = (int)($result['target_user_id'] ?? 0);
        if ($targetuserid > 0) {
            $targetbycanonical[(string)$result['canonical_id']] = $targetuserid;
        }
    }

    $plannedids = [];
    foreach ($planrows as $row) {
        if (!p4_bool($row['siteadmin_required'] ?? false) ||
                !p4_is_apply_action((string)($row['action'] ?? ''))) {
            continue;
        }
        $canonicalid = (string)$row['canonical_id'];
        $targetuserid = (int)($targetbycanonical[$canonicalid] ?? 0);
        if ($targetuserid < 1) {
            throw new RuntimeException(
                'El siteadmin canónico ' . $canonicalid .
                ' no tiene un usuario destino aplicado.'
            );
        }
        $plannedids[$targetuserid] = true;
    }
    $plannedids = array_keys($plannedids);
    sort($plannedids, SORT_NUMERIC);

    $before = p4_current_site_administrator_ids();
    $expected = array_values(array_unique(array_merge($before, $plannedids)));
    sort($expected, SORT_NUMERIC);
    set_config('siteadmins', implode(',', $expected));
    $after = p4_current_site_administrator_ids();
    if ($after !== $expected) {
        throw new RuntimeException(
            'Moodle no conservó la lista aprobada de administradores del sitio.'
        );
    }
    return [
        'before_ids' => $before,
        'planned_ids' => $plannedids,
        'after_ids' => $after,
        'added' => count(array_diff($after, $before)),
    ];
}
