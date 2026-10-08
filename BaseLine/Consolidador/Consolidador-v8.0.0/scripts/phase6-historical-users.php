<?php
// Registro de identidades históricas reutilizadas entre restauraciones.
declare(strict_types=1);

function p6_historical_source_ids(array $classification, array $globalmap): array {
    $source = (string)($classification['source'] ?? '');
    $ids = [];
    foreach ($classification['users'] ?? [] as $row) {
        if (($row['classification'] ?? '') !== 'historical_deleted') {
            continue;
        }
        $userid = (int)$row['source_user_id'];
        if ($userid < 1 || !($row['deleted'] ?? false) ||
                isset($globalmap[$source . ':' . $userid]) ||
                (int)($row['target_user_id'] ?? 0) > 0 ||
                (string)($row['canonical_id'] ?? '') !== '') {
            throw new RuntimeException('historical_user_reactivated: clasificación o Phase4 inválidos.');
        }
        $ids[$userid] = true;
    }
    $result = array_map('intval', array_keys($ids));
    sort($result, SORT_NUMERIC);
    return $result;
}

function p6_historical_registry_path(string $phase6dir, string $source, int $userid): string {
    if (!preg_match('/^[a-z][a-z0-9_-]*$/', $source) || $userid < 1) {
        throw new RuntimeException('Clave histórica inválida.');
    }
    return rtrim($phase6dir, '/\\') . '/historical-users/' . $source . '-' . $userid . '.json';
}

function p6_historical_assert_disjoint(array $idsbytarget, array $globalmap): void {
    $activeids = [];
    foreach ($globalmap as $mapping) {
        $activeids[(int)($mapping['target_user_id'] ?? 0)] = true;
    }
    $seen = [];
    foreach ($idsbytarget as $sourceid => $targetid) {
        if ((int)$targetid < 1 || isset($activeids[(int)$targetid]) ||
                isset($seen[(int)$targetid])) {
            throw new RuntimeException(
                'historical_user_duplicated: fusión con usuario activo o histórico.'
            );
        }
        $seen[(int)$targetid] = (int)$sourceid;
    }
}

function p6_historical_registry_update(
    ?array $previous,
    string $source,
    int $userid,
    int $targetid,
    string $coursekey,
    string $resolution = 'moodle_backup_ids_user_mapping'
): array {
    if ($userid < 1 || $targetid < 1 || $coursekey === '' ||
            !in_array($resolution, [
                'moodle_backup_ids_user_mapping',
                'persistent_deleted_user_fingerprint',
            ], true)) {
        throw new RuntimeException('historical_history_lost: falta resolución del usuario.');
    }
    if ($previous !== null &&
            (($previous['source_id'] ?? '') !== $source ||
             (int)($previous['source_user_id'] ?? 0) !== $userid ||
             (int)($previous['target_user_id'] ?? 0) !== $targetid)) {
        throw new RuntimeException('historical_user_duplicated: el destino histórico cambió.');
    }
    $courses = $previous['observed_course_keys'] ?? [];
    $courses[] = $coursekey;
    $courses = array_values(array_unique($courses));
    sort($courses, SORT_STRING);
    return [
        'schema_version' => '1.0', 'phase' => '6-historical-user-registry',
        'source_id' => $source, 'source_user_id' => $userid,
        'target_user_id' => $targetid,
        'resolution' => $resolution,
        'observed_course_keys' => $courses,
    ];
}

function p6_historical_assert_target_record(
    object $db,
    object $record,
    bool $allowoauth2 = false
): array {
    $targetid = (int)($record->id ?? 0);
    if ($targetid < 1 || (int)($record->deleted ?? 0) !== 1 ||
            (!$allowoauth2 && (string)($record->auth ?? '') === 'oauth2')) {
        throw new RuntimeException('historical_user_reactivated: usuario activo u OAuth.');
    }
    if ($db->get_manager()->table_exists('auth_oauth2_linked_login') &&
            $db->record_exists('auth_oauth2_linked_login', ['userid' => $targetid])) {
        throw new RuntimeException('historical_user_reactivated: vínculo OAuth en usuario eliminado.');
    }
    foreach (['google_sub', 'migration_identity_status'] as $field) {
        $fieldrow = $db->get_record('user_info_field', ['shortname' => $field], 'id', IGNORE_MISSING);
        if (!$fieldrow) {
            continue;
        }
        $data = $db->get_field('user_info_data', 'data', [
            'fieldid' => (int)$fieldrow->id, 'userid' => $targetid,
        ]);
        if (($field === 'google_sub' && trim((string)$data) !== '') ||
                ($field === 'migration_identity_status' &&
                    trim((string)$data) === 'pending_relink')) {
            throw new RuntimeException('historical_user_reactivated: identidad OAuth o pending_relink.');
        }
    }
    return [
        'target_user_id' => $targetid, 'deleted' => true,
        'auth' => (string)$record->auth, 'username' => (string)$record->username,
        'email' => (string)$record->email, 'oauth_links' => 0,
    ];
}

function p6_historical_assert_target(object $db, int $targetid): array {
    $record = $db->get_record('user', ['id' => $targetid],
        'id,username,email,auth,deleted', IGNORE_MISSING);
    if (!$record) {
        throw new RuntimeException('historical_history_lost: usuario destino inexistente.');
    }
    return p6_historical_assert_target_record($db, $record, false);
}

/** Devuelve la fila histórica sellada correspondiente a un source_user_id. */
function p6_historical_classification_row(
    array $classification,
    string $source,
    int $sourceuserid
): array {
    if (($classification['source'] ?? '') !== $source || $sourceuserid < 1) {
        throw new RuntimeException('historical_history_lost: clasificación histórica inválida.');
    }
    $matches = [];
    foreach ($classification['users'] ?? [] as $row) {
        if ((int)($row['source_user_id'] ?? 0) === $sourceuserid) {
            $matches[] = $row;
        }
    }
    if (count($matches) !== 1 ||
            ($matches[0]['classification'] ?? '') !== 'historical_deleted' ||
            ($matches[0]['deleted'] ?? false) !== true ||
            trim((string)($matches[0]['source_username'] ?? '')) === '' ||
            trim((string)($matches[0]['source_email'] ?? '')) === '') {
        throw new RuntimeException('historical_history_lost: fingerprint fuente incompleto.');
    }
    return $matches[0];
}

/**
 * Resuelve/adopta una cuenta histórica mediante evidencia persistente de user.
 * Nunca depende de backup_ids_temp y solo normaliza oauth2 después de probar
 * el fingerprint completo de una cuenta deleted del host local.
 */
function p6_historical_resolve_persistent_target(
    object $db,
    object $cfg,
    array $classification,
    string $source,
    int $sourceuserid,
    array $globalmap,
    int $expectedtargetid = 0,
    bool $normalizeoauth2 = true
): array {
    $row = p6_historical_classification_row(
        $classification,
        $source,
        $sourceuserid
    );
    if (isset($globalmap[$source . ':' . $sourceuserid])) {
        throw new RuntimeException('historical_user_reactivated: conflicto con Phase4.');
    }
    $conditions = [
        'username' => (string)$row['source_username'],
        'email' => (string)$row['source_email'],
        'deleted' => 1,
        'mnethostid' => (int)$cfg->mnet_localhost_id,
    ];
    $records = $db->get_records(
        'user',
        $conditions,
        'id ASC',
        'id,username,email,auth,deleted,mnethostid'
    );
    if (count($records) !== 1) {
        throw new RuntimeException(
            'historical_user_duplicated: fingerprint persistente no es único.'
        );
    }
    $record = reset($records);
    $targetid = (int)($record->id ?? 0);
    if ($expectedtargetid > 0 && $targetid !== $expectedtargetid) {
        throw new RuntimeException(
            'historical_user_duplicated: el destino histórico cambió.'
        );
    }
    if ((string)($record->username ?? '') !== (string)$row['source_username'] ||
            (string)($record->email ?? '') !== (string)$row['source_email'] ||
            (int)($record->deleted ?? 0) !== 1 ||
            (int)($record->mnethostid ?? 0) !== (int)$cfg->mnet_localhost_id) {
        throw new RuntimeException(
            'historical_history_lost: fingerprint persistente no coincide.'
        );
    }
    p6_historical_assert_disjoint(
        [$sourceuserid => $targetid],
        $globalmap
    );
    p6_historical_assert_target_record($db, $record, true);
    $authnormalized = false;
    if ((string)$record->auth === 'oauth2') {
        if (!$normalizeoauth2) {
            throw new RuntimeException(
                'historical_user_reactivated: OAuth histórico no normalizado.'
            );
        }
        $db->update_record('user', (object)[
            'id' => $targetid,
            'auth' => 'manual',
        ]);
        $authnormalized = true;
    }
    $target = p6_historical_assert_target($db, $targetid);
    if ($target['username'] !== (string)$row['source_username'] ||
            $target['email'] !== (string)$row['source_email']) {
        throw new RuntimeException(
            'historical_history_lost: fingerprint cambió tras la adopción.'
        );
    }
    return [
        'source_user_id' => $sourceuserid,
        'target_user_id' => $targetid,
        'source_username' => (string)$row['source_username'],
        'source_email' => (string)$row['source_email'],
        'target_user' => $target,
        'auth_normalized' => $authnormalized,
        'resolution' => 'persistent_deleted_user_fingerprint',
    ];
}

/** Construye la auditoría sellable, incluida la ruta de recovery interrumpido. */
function p6_historical_validated_audit(
    string $coursekey,
    string $source,
    int $targetcourseid,
    array $resolutions,
    bool $recoveredafterinterruption
): array {
    if ($coursekey === '' || $source === '' || $targetcourseid < 1) {
        throw new RuntimeException('historical_history_lost: contexto de auditoría inválido.');
    }
    $sourceids = [];
    $targetids = [];
    foreach ($resolutions as $resolution) {
        $sourceid = (int)($resolution['source_user_id'] ?? 0);
        $targetid = (int)($resolution['target_user_id'] ?? 0);
        if ($sourceid < 1 || $targetid < 1 || isset($sourceids[$sourceid]) ||
                isset($targetids[$targetid])) {
            throw new RuntimeException(
                'historical_user_duplicated: resolución histórica ambigua.'
            );
        }
        $sourceids[$sourceid] = true;
        $targetids[$targetid] = true;
    }
    return [
        'schema_version' => '1.0',
        'phase' => '6-historical-user-restore-audit',
        'course_key' => $coursekey,
        'source_id' => $source,
        'target_course_id' => $targetcourseid,
        'resolution_state' => 'validated',
        'recovered_after_interruption' => $recoveredafterinterruption,
        'resolutions' => $resolutions,
    ];
}

function p6_historical_relation_issues(
    array $sourceinventory, array $targetinventory, array $idsbytarget
): array {
    $issues = [];
    foreach ($idsbytarget as $sourceid => $targetid) {
        foreach (($sourceinventory['relations'] ?? []) as $relation => $rows) {
            $actualrows = $targetinventory['relations'][$relation] ?? [];
            // El diagnóstico compartido de intentos, ejecutado por el comparador
            // principal de Fase 6 con este mismo mapa histórico, es la autoridad
            // para quiz_attempts. Repetir aquí una firma genérica volvería a exigir
            // campos fuertes que no existían en inventarios históricos.
            if ($relation === 'quiz_attempts') {
                continue;
            }
            if ($relation === 'files') {
                $rows = p5_filter_comparable_files($rows);
                $actualrows = p5_filter_comparable_files($actualrows);
            }
            if ($relation === 'course_completions') {
                $rows = array_values(array_filter($rows,
                    static fn(array $row): bool => ($row['completed'] ?? false) === true));
                $actualrows = array_values(array_filter($actualrows,
                    static fn(array $row): bool => ($row['completed'] ?? false) === true));
            }
            $signature = static function(array $candidates, int $userid) use ($relation): array {
                $counts = [];
                foreach ($candidates as $row) {
                    if ((int)($row['source_user_id'] ?? 0) !== $userid) {
                        continue;
                    }
                    unset($row['source_user_id'], $row['attempt_id'], $row['quiz_id'],
                        $row['source_module_id']);
                    if (in_array($relation, ['forum_discussions', 'forum_posts'], true) &&
                            array_key_exists('subject', $row)) {
                        $row['subject'] = rtrim(
                            (string)$row['subject'],
                            " \t\n\r\0\x0B"
                        );
                    }
                    $key = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
                        JSON_THROW_ON_ERROR);
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
                ksort($counts, SORT_STRING);
                return $counts;
            };
            $expected = $signature($rows, (int)$sourceid);
            $actual = $signature($actualrows, (int)$targetid);
            if ($expected !== $actual) {
                $issues[] = [
                    'error' => 'historical_history_lost', 'source_user_id' => (int)$sourceid,
                    'target_user_id' => (int)$targetid, 'relation' => $relation,
                    'expected' => $expected, 'actual' => $actual,
                ];
            }
        }
    }
    return $issues;
}
