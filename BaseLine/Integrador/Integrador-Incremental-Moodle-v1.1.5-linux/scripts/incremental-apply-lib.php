<?php
// Operaciones idempotentes de aplicación compartidas por coordinador y workers.

declare(strict_types=1);

defined('MOODLE_INTERNAL') || die();

/**
 * Busca cuentas activas por correo sin depender de la capitalización ni de la
 * intercalación concreta de MariaDB/PostgreSQL.
 */
function inc_active_users_by_email(string $email): array {
    global $DB;

    $email = inc_norm($email);
    $comparison = $DB->sql_equal('email', ':email', false);
    return $DB->get_records_select(
        'user',
        'deleted = 0 AND ' . $comparison,
        ['email' => $email],
        'id ASC'
    );
}

function inc_course_ownership_key(string $coursekey): string {
    return 'course_' . substr(hash('sha256', $coursekey), 0, 32);
}

function inc_course_ownership_value(
    string $plansha256,
    string $coursekey,
    int $courseid
): string {
    return $courseid . '|' . $plansha256 . '|' . $coursekey;
}

function inc_mark_course_owned(
    string $plansha256,
    string $coursekey,
    int $courseid
): void {
    if ($courseid < 1 || !inc_is_sha256($plansha256)) {
        throw new RuntimeException('No se puede marcar un curso incremental inválido.');
    }
    if (!set_config(
            inc_course_ownership_key($coursekey),
            inc_course_ownership_value($plansha256, $coursekey, $courseid),
            'integrator_v1'
        )) {
        throw new RuntimeException('No se pudo persistir la marca de propiedad del curso.');
    }
}

function inc_course_is_owned(
    string $plansha256,
    string $coursekey,
    int $courseid
): bool {
    $actual = get_config('integrator_v1', inc_course_ownership_key($coursekey));
    $expected = inc_course_ownership_value($plansha256, $coursekey, $courseid);
    return is_string($actual) && hash_equals($expected, $actual);
}

function inc_clear_course_ownership(string $coursekey): void {
    unset_config(inc_course_ownership_key($coursekey), 'integrator_v1');
}

function inc_identity_plan_for_source_user(array $plan, int $sourceuserid): array {
    $email = (string)($plan['source_user_email_map'][(string)$sourceuserid] ??
        $plan['source_user_email_map'][$sourceuserid] ?? '');
    $identity = $plan['identities_by_email'][$email] ?? null;
    if ($email === '' || !is_array($identity)) {
        throw new RuntimeException(
            'No existe un plan de identidad para source_user_id=' . $sourceuserid . '.'
        );
    }
    return $identity;
}

/**
 * Resuelve por correo y crea solo usuarios ausentes. El usuario ya existente
 * es autoridad absoluta: no cambia auth, nombres, estado ni perfil.
 */
function inc_ensure_target_user(array $identity, string $sourceid): array {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/user/lib.php');
    $email = inc_norm((string)$identity['email']);
    $lockfactory = \core\lock\lock_config::get_lock_factory('inc_v1_identity');
    $lock = $lockfactory->get_lock('email-' . hash('sha256', $email), 60);
    if (!$lock) {
        throw new RuntimeException('No se pudo bloquear la identidad ' . $email . '.');
    }
    try {
        $matches = inc_active_users_by_email($email);
        if (count($matches) > 1) {
            throw new RuntimeException(
                'El destino contiene varias cuentas activas para ' . $email . '.'
            );
        }
        if (count($matches) === 1) {
            $user = reset($matches);
            $plannedid = (int)($identity['target_user_id'] ?? 0);
            if ($plannedid > 0 && (int)$user->id !== $plannedid) {
                throw new RuntimeException(
                    'El correo ' . $email . ' ahora apunta a otro target_user_id.'
                );
            }
            return [
                'target_user_id' => (int)$user->id,
                'target_username' => (string)$user->username,
                'target_email' => inc_norm((string)$user->email),
                'action' => $plannedid > 0 ? 'reused_existing' : 'adopted_existing_after_plan',
                'target_profile_modified' => false,
                'auth_resolution' => 'target_authoritative',
            ];
        }
        if (($identity['action'] ?? '') !== 'create_new') {
            throw new RuntimeException(
                'El usuario destino previsto para ' . $email . ' desapareció.'
            );
        }
        $username = inc_norm((string)$identity['target_username']);
        $owner = $DB->get_record('user', [
            'username' => $username,
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ], 'id,email', IGNORE_MISSING);
        if ($owner) {
            throw new RuntimeException(
                'El username planeado ' . $username . ' fue ocupado después del preflight.'
            );
        }
        $firstname = trim((string)$identity['firstname']);
        $lastname = trim((string)$identity['lastname']);
        if ($firstname === '') {
            $firstname = 'Usuario';
        }
        if ($lastname === '') {
            $lastname = 'Importado';
        }
        $sourceuserid = (int)$identity['representative_source_user_id'];
        $marker = inc_user_marker($sourceid, $sourceuserid, $email);
        $targetauth = 'manual';
        $oauthissuer = null;
        $oauthidentifier = '';
        $sourceissuer = rtrim(inc_norm((string)($identity['google_issuer'] ?? '')), '/');
        $sourcesub = trim((string)($identity['google_sub'] ?? ''));
        if ((string)($identity['source_auth'] ?? '') === 'oauth2' &&
                ($identity['google_sub_verified'] ?? false) === true &&
                $sourceissuer !== '' && $sourcesub !== '' &&
                is_enabled_auth('oauth2')) {
            $issuermatches = [];
            foreach ($DB->get_records('oauth2_issuer', null, 'id ASC') as $issuerrecord) {
                if (rtrim(inc_norm((string)$issuerrecord->baseurl), '/') === $sourceissuer) {
                    $issuermatches[] = $issuerrecord;
                }
            }
            if (count($issuermatches) === 1) {
                $candidateissuer = \core\oauth2\issuer::get_record([
                    'id' => (int)$issuermatches[0]->id,
                ]);
                if ($candidateissuer && $candidateissuer->is_available_for_login()) {
                    $conflicts = $DB->get_records('auth_oauth2_linked_login', [
                        'issuerid' => (int)$candidateissuer->get('id'),
                        'username' => $sourcesub,
                    ]);
                    if ($conflicts) {
                        throw new RuntimeException(
                            'El identificador OAuth2 del usuario nuevo ya pertenece a otra cuenta.'
                        );
                    }
                    $targetauth = 'oauth2';
                    $oauthissuer = $candidateissuer;
                    $oauthidentifier = $sourcesub;
                }
            }
        }
        $user = (object)[
            'username' => $username,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'idnumber' => trim((string)$identity['idnumber']),
            'auth' => $targetauth,
            'password' => $targetauth === 'manual'
                ? random_string(32) . 'Aa1!'
                : AUTH_PASSWORD_NOT_CACHED,
            'confirmed' => 1,
            'suspended' => 0,
            'mnethostid' => $CFG->mnet_localhost_id,
            'lang' => current_language(),
            'description' => '[INTEGRADOR-INCREMENTAL-V1] ' . $marker .
                ' Origen=' . $sourceid . '.',
            'descriptionformat' => FORMAT_PLAIN,
        ];
        $transaction = $DB->start_delegated_transaction();
        try {
            $userid = (int)user_create_user($user, $targetauth === 'manual', true);
            if ($targetauth === 'manual') {
                set_user_preference('auth_forcepasswordchange', 1, $userid);
            } else {
                \auth_oauth2\api::link_login(
                    ['username' => $oauthidentifier, 'email' => $email],
                    $oauthissuer,
                    $userid,
                    true
                );
            }
            $transaction->allow_commit();
        } catch (Throwable $error) {
            $transaction->rollback($error);
        }
        $created = $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0],
            'id,username,email',
            MUST_EXIST
        );
        return [
            'target_user_id' => $userid,
            'target_username' => (string)$created->username,
            'target_email' => inc_norm((string)$created->email),
            'action' => $targetauth === 'manual'
                ? 'created_manual_force_password_change'
                : 'created_oauth2_linked',
            'target_profile_modified' => true,
            'auth_resolution' => $targetauth === 'manual'
                ? 'new_user_manual_fallback'
                : 'matching_verified_oauth2_issuer',
        ];
    } finally {
        $lock->release();
    }
}

function inc_materialize_source_users(
    array $plan,
    array $sourceuserids
): array {
    global $DB;

    $byemail = [];
    foreach ($sourceuserids as $sourceuserid) {
        $identity = inc_identity_plan_for_source_user($plan, (int)$sourceuserid);
        $byemail[(string)$identity['email']] = $identity;
    }
    ksort($byemail, SORT_STRING);
    $plannedbyid = [];
    foreach ($byemail as $identity) {
        $plannedid = (int)($identity['target_user_id'] ?? 0);
        if ($plannedid > 0) {
            $plannedbyid[$plannedid] = (string)$identity['email'];
        }
    }
    $existingbyid = [];
    foreach (array_chunk(array_keys($plannedbyid), 400) as $ids) {
        if (!$ids) {
            continue;
        }
        foreach ($DB->get_records_list(
            'user',
            'id',
            $ids,
            'id ASC',
            'id,username,email,auth,firstaccess,suspended,confirmed,deleted'
        ) as $record) {
            if ((int)$record->deleted === 0) {
                $existingbyid[(int)$record->id] = $record;
            }
        }
    }
    $result = [];
    foreach ($byemail as $email => $identity) {
        $plannedid = (int)($identity['target_user_id'] ?? 0);
        if ($plannedid > 0) {
            $record = $existingbyid[$plannedid] ?? null;
            if (!$record || inc_norm((string)$record->email) !== $email) {
                throw new RuntimeException(
                    'El usuario destino previsto para ' . $email .
                    ' desapareció o cambió de correo.'
                );
            }
            $result[$email] = [
                'target_user_id' => (int)$record->id,
                'target_username' => (string)$record->username,
                'target_email' => inc_norm((string)$record->email),
                'action' => 'reused_existing_bulk',
                'target_profile_modified' => false,
                'auth_resolution' => 'target_authoritative',
            ];
        } else {
            $result[$email] = inc_ensure_target_user(
                $identity,
                (string)$plan['source_id']
            );
        }
    }
    return $result;
}

function inc_user_mapping_bundle(
    array $plan,
    array $sourceuserids
): array {
    global $DB;

    $materialized = inc_materialize_source_users($plan, $sourceuserids);
    $targetids = array_values(array_unique(array_map(
        static fn(array $row): int => (int)$row['target_user_id'],
        $materialized
    )));
    $targetrecords = [];
    foreach (array_chunk($targetids, 400) as $ids) {
        if (!$ids) {
            continue;
        }
        foreach ($DB->get_records_list(
            'user',
            'id',
            $ids,
            'id ASC',
            'id,username,email,auth,firstaccess,suspended,confirmed,deleted'
        ) as $record) {
            if ((int)$record->deleted !== 0) {
                throw new RuntimeException('Un usuario destino fue eliminado.');
            }
            $targetrecords[(int)$record->id] = [
                'id' => (int)$record->id,
                'username' => (string)$record->username,
                'email' => inc_norm((string)$record->email),
                'auth' => (string)$record->auth,
                'firstaccess' => (int)$record->firstaccess,
                'suspended' => (int)$record->suspended,
                'confirmed' => (int)$record->confirmed,
            ];
        }
    }
    if (count($targetrecords) !== count($targetids)) {
        throw new RuntimeException('Falta un usuario destino materializado.');
    }
    $mapping = [];
    $targetusers = [];
    $targettosource = [];
    foreach ($sourceuserids as $sourceuserid) {
        $sourceuserid = (int)$sourceuserid;
        $identity = inc_identity_plan_for_source_user($plan, $sourceuserid);
        $target = $materialized[(string)$identity['email']];
        $targetuserid = (int)$target['target_user_id'];
        $targetrecord = $targetrecords[$targetuserid];
        $mapping[$plan['source_id'] . ':' . $sourceuserid] = [
            'canonical_id' => 'EMAIL-' . strtoupper(substr(
                hash('sha256', (string)$identity['email']),
                0,
                16
            )),
            'target_user_id' => $targetuserid,
            'target_username' => (string)$targetrecord['username'],
            'target_email' => (string)$targetrecord['email'],
            'identity_decision' => count($identity['source_user_ids']) > 1
                ? 'merge'
                : 'email_primary',
        ];
        $targetusers[$targetuserid] = $targetrecord;
        $targettosource[$targetuserid][] = $sourceuserid;
    }
    foreach ($targettosource as &$sourceids) {
        $sourceids = array_values(array_unique(array_map('intval', $sourceids)));
        sort($sourceids, SORT_NUMERIC);
    }
    unset($sourceids);
    return [
        'mapping' => $mapping,
        'target_users' => $targetusers,
        'target_to_source' => $targettosource,
        'materialization' => $materialized,
    ];
}

function inc_course_plan(array $plan, string $coursekey): array {
    foreach ($plan['courses'] ?? [] as $course) {
        if (($course['course_key'] ?? '') === $coursekey) {
            return $course;
        }
    }
    throw new RuntimeException('El curso ' . $coursekey . ' no pertenece al plan.');
}

function inc_load_category_map(
    string $workdir,
    bool $allowlegacyunsignedforverification = false
): array {
    $document = inc_read_json($workdir . '/category-map.json');
    $plan = inc_load_plan($workdir);
    $documenthash = (string)($document['category_map_sha256'] ?? '');
    $basevalid =
        ($document['schema_version'] ?? '') === INC_SCHEMA &&
        in_array(
            (string)($document['tool_version'] ?? ''),
            INC_COMPATIBLE_PLAN_VERSIONS,
            true
        ) &&
        ($document['plan_sha256'] ?? '') === (string)$plan['plan_sha256'] &&
        ($document['batch_id'] ?? '') === (string)$plan['batch_id'] &&
        ($document['status'] ?? '') === 'applied';
    $hashvalid =
        inc_is_sha256($documenthash) &&
        hash_equals(
            $documenthash,
            inc_document_hash($document, 'category_map_sha256')
        );
    $legacyunsigned =
        $allowlegacyunsignedforverification &&
        (string)($plan['tool_version'] ?? '') === '1.0.0-linux' &&
        (string)($document['tool_version'] ?? '') === '1.0.0-linux' &&
        !inc_is_sha256($documenthash);
    if (!$basevalid || (!$hashvalid && !$legacyunsigned)) {
        throw new RuntimeException('category-map.json no corresponde al plan.');
    }
    $document['_integrity_mode'] = $hashvalid
        ? 'sealed_sha256'
        : 'legacy_unsigned_live_revalidation';
    return $document;
}
