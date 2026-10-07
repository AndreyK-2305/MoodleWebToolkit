<?php
// Contrato puro de identidades OAuth compartido por extracción y validación.

declare(strict_types=1);

const COLLECTOR_IDENTITY_SCHEMA_VERSION = '1.2';
const COLLECTOR_GOOGLE_SUB_POLICY = 'verified_only';

/**
 * Clasifica el identificador que Moodle conserva en
 * auth_oauth2_linked_login.username sin asumir que siempre sea un sub.
 *
 * @param array<int,array{external_field:string,internal_field:string}> $mappings
 * @return array{
 *   kind:string,
 *   evidence:string,
 *   sub_verified:bool,
 *   mapping_consistent:bool,
 *   username_external_fields:array<int,string>
 * }
 */
function collector_classify_oauth_identifier(
    string $linkedusername,
    string $linkedemail,
    array $mappings
): array {
    $username = trim($linkedusername);
    $email = strtolower(trim($linkedemail));
    $externalfields = [];
    foreach ($mappings as $mapping) {
        $internal = strtolower(trim((string)($mapping['internal_field'] ?? '')));
        if ($internal !== 'username') {
            continue;
        }
        $external = strtolower(trim((string)($mapping['external_field'] ?? '')));
        if ($external !== '') {
            $externalfields[$external] = true;
        }
    }
    $externalfields = array_keys($externalfields);
    sort($externalfields, SORT_STRING);

    $result = [
        'kind' => 'unknown',
        'evidence' => 'linked_username_missing',
        'sub_verified' => false,
        'mapping_consistent' => true,
        'username_external_fields' => $externalfields,
    ];
    if ($username === '') {
        return $result;
    }

    $usernameisemail = filter_var($username, FILTER_VALIDATE_EMAIL) !== false;
    if ($usernameisemail) {
        $result['kind'] = 'email';
        if ($externalfields === ['sub']) {
            $result['evidence'] = 'email_value_conflicts_with_sub_mapping';
            $result['mapping_consistent'] = false;
        } else if (in_array('email', $externalfields, true)) {
            $result['evidence'] = 'issuer_mapping_email_to_username';
        } else if ($email !== '' && strtolower($username) === $email) {
            $result['evidence'] = 'linked_username_matches_linked_email';
        } else {
            $result['evidence'] = 'linked_username_email_shape';
        }
        return $result;
    }

    if ($externalfields === ['sub']) {
        $result['kind'] = 'sub';
        $result['evidence'] = 'issuer_mapping_sub_to_username';
        $result['sub_verified'] = true;
        return $result;
    }
    if (count($externalfields) > 1) {
        $result['kind'] = 'opaque';
        $result['evidence'] = 'ambiguous_username_mapping';
        $result['mapping_consistent'] = false;
        return $result;
    }
    if (count($externalfields) === 1) {
        $result['kind'] = 'opaque';
        $result['evidence'] = 'issuer_mapping_other_to_username';
        return $result;
    }

    $result['kind'] = 'opaque';
    $result['evidence'] = 'no_explicit_username_mapping';
    return $result;
}

/**
 * Devuelve errores de contrato sin depender de Moodle ni terminar el proceso.
 *
 * @return string[]
 */
function collector_validate_identity_payload(array $payload): array {
    $errors = [];
    $metadata = $payload['metadata'] ?? null;
    if (!is_array($metadata) ||
            ($metadata['schema_version'] ?? '') !== COLLECTOR_IDENTITY_SCHEMA_VERSION ||
            ($metadata['google_sub_policy'] ?? '') !== COLLECTOR_GOOGLE_SUB_POLICY) {
        $errors[] = 'metadata no declara el contrato de identidades ' .
            COLLECTOR_IDENTITY_SCHEMA_VERSION . '.';
    }

    $users = $payload['users'] ?? null;
    if (!is_array($users) || !array_is_list($users)) {
        return array_merge($errors, ['users no es una lista.']);
    }
    $seenids = [];
    $allowedkinds = ['sub', 'email', 'opaque', 'unknown'];
    foreach ($users as $index => $user) {
        $label = 'users[' . $index . ']';
        if (!is_array($user)) {
            $errors[] = $label . ' no es un objeto.';
            continue;
        }
        $userid = (int)($user['source_user_id'] ?? 0);
        if ($userid < 1 || isset($seenids[$userid])) {
            $errors[] = $label . ' tiene source_user_id inválido o repetido.';
        } else {
            $seenids[$userid] = true;
        }
        if (array_key_exists('google_sub_candidate', $user)) {
            $errors[] = $label . ' conserva el campo obsoleto google_sub_candidate.';
        }

        $kind = (string)($user['oauth_identifier_kind'] ?? '');
        $linkedusername = trim((string)($user['oauth_linked_username'] ?? ''));
        $googlesub = trim((string)($user['google_sub'] ?? ''));
        $googleissuer = trim((string)($user['google_issuer'] ?? ''));
        $verified = $user['google_sub_verified'] ?? null;
        if (!in_array($kind, $allowedkinds, true)) {
            $errors[] = $label . ' tiene oauth_identifier_kind inválido.';
        }
        if (!is_bool($verified)) {
            $errors[] = $label . ' no declara google_sub_verified como booleano.';
        } else if ($verified && ($googlesub === '' || $googleissuer === '')) {
            $errors[] = $label . ' marca un sub verificado sin issuer + sub.';
        } else if (!$verified && $googlesub !== '') {
            $errors[] = $label . ' publica google_sub sin evidencia verificada.';
        }
        if ($googlesub !== '' && filter_var($googlesub, FILTER_VALIDATE_EMAIL) !== false) {
            $errors[] = $label . ' intenta publicar un correo como google_sub.';
        }
        if ($kind === 'email' &&
                ($linkedusername === '' ||
                 filter_var($linkedusername, FILTER_VALIDATE_EMAIL) === false)) {
            $errors[] = $label . ' clasifica como email un identificador no válido.';
        }
        if ($kind === 'sub' &&
                (!$verified || $linkedusername === '' || $linkedusername !== $googlesub)) {
            $errors[] = $label . ' clasifica como sub un vínculo no comprobado.';
        }
        $links = $user['oauth_links'] ?? null;
        if (!is_array($links) || !array_is_list($links)) {
            $errors[] = $label . '.oauth_links no es una lista.';
        }
    }

    $issuers = $payload['oauth_issuers'] ?? null;
    if (!is_array($issuers) || !array_is_list($issuers)) {
        $errors[] = 'oauth_issuers no es una lista.';
    }
    return $errors;
}
