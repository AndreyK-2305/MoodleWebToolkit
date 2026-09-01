<?php
// Fase 3: conciliador determinista y no destructivo de identidades Moodle.

declare(strict_types=1);

$options = getopt('', [
    'input:', 'output:', 'sources:', 'targetid:', 'targetname:', 'confighash:',
    'resolutions:', 'identitypolicy:', 'expectlab::', 'help::',
]);
if (isset($options['help'])) {
    echo "Uso: php reconcile-identities.php --input=/exports/phase3 --output=/exports/phase3 " .
        "--sources=origen1,origen2 --resolutions=/tmp/identity_resolutions.csv " .
        "--identitypolicy=/tmp/identity-policy.json [--expectlab=1]\n";
    exit(0);
}
$inputdir = rtrim((string)($options['input'] ?? ''), '/\\');
$outputdir = rtrim((string)($options['output'] ?? ''), '/\\');
$sourcesvalue = trim((string)($options['sources'] ?? ''));
$targetid = trim((string)($options['targetid'] ?? ''));
$targetname = trim((string)($options['targetname'] ?? ''));
$confighash = strtolower(trim((string)($options['confighash'] ?? '')));
$resolutionspath = trim((string)($options['resolutions'] ?? ''));
$identitypolicypath = trim((string)($options['identitypolicy'] ?? ''));
$expectlab = (bool)(int)($options['expectlab'] ?? 0);
if ($inputdir === '' || $outputdir === '' || $sourcesvalue === '' ||
        $targetid === '' || $targetname === '' || $confighash === '' ||
        $resolutionspath === '' || $identitypolicypath === '') {
    fwrite(
        STDERR,
        "Debe indicar --input, --output, --sources, --targetid, --targetname, " .
        "--confighash, --resolutions y --identitypolicy.\n"
    );
    exit(2);
}
if (!preg_match('/^[a-z][a-z0-9_-]*$/', $targetid) ||
        !preg_match('/^[a-f0-9]{64}$/', $confighash)) {
    fwrite(STDERR, "El destino o hash de configuración es inválido.\n");
    exit(2);
}

/**
 * Detiene el proceso con un error legible.
 */
function lab3_fail(string $message): never {
    fwrite(STDERR, 'FASE3_ERROR ' . $message . PHP_EOL);
    exit(1);
}

/**
 * Normaliza texto de identidad.
 */
function lab3_norm(string $value): string {
    return trim(mb_strtolower($value, 'UTF-8'));
}

/**
 * Clave fuerte compuesta issuer + sub.
 */
function lab3_strong_key(array $account): string {
    if (($account['google_sub_verified'] ?? false) !== true) {
        return '';
    }
    $issuer = rtrim(lab3_norm((string)($account['google_issuer'] ?? '')), '/');
    $sub = trim((string)($account['google_sub'] ?? ''));
    if ($issuer === '' || $sub === '' ||
            filter_var($sub, FILTER_VALIDATE_EMAIL) !== false) {
        return '';
    }
    return $issuer . '|' . $sub;
}

/**
 * Clave operativa de un linked login cuyo identificador externo es un correo.
 * No convierte el correo en google_sub.
 */
function lab3_oauth_email_key(array $account): string {
    if (lab3_strong_key($account) !== '' ||
            lab3_norm((string)($account['auth'] ?? '')) !== 'oauth2' ||
            (string)($account['oauth_identifier_kind'] ?? '') !== 'email' ||
            (int)($account['confirmed_google_oauth_links'] ?? 0) !== 1) {
        return '';
    }
    $issuer = rtrim(lab3_norm((string)($account['google_issuer'] ?? '')), '/');
    $linkedusername = lab3_norm((string)($account['oauth_linked_username'] ?? ''));
    $accountemail = lab3_norm((string)($account['email'] ?? ''));
    if ($issuer === '' ||
            filter_var($linkedusername, FILTER_VALIDATE_EMAIL) === false ||
            $linkedusername !== $accountemail) {
        return '';
    }
    return $issuer . '|' . $linkedusername;
}

/**
 * Método operativo de identidad utilizable por el destino.
 */
function lab3_identity_method(array $account): string {
    if (lab3_strong_key($account) !== '') {
        return 'google_sub';
    }
    if (lab3_oauth_email_key($account) !== '') {
        return 'oauth_email';
    }
    if (lab3_manual_key($account) !== '') {
        return 'manual_username';
    }
    return 'source_account';
}

/**
 * Clave utilizable para comprobar fusiones y separaciones auditadas.
 */
function lab3_operational_key(array $account): string {
    $strong = lab3_strong_key($account);
    if ($strong !== '') {
        return 'sub|' . $strong;
    }
    $oauthemail = lab3_oauth_email_key($account);
    if ($oauthemail !== '') {
        return 'email|' . $oauthemail;
    }
    $manual = lab3_manual_key($account);
    return $manual === '' ? '' : 'manual|' . $manual;
}

/**
 * Clave de conciliacion para cuentas locales que ingresan con credenciales.
 * El correo no participa: Moodle identifica estas cuentas por username.
 */
function lab3_manual_key(array $account): string {
    if (lab3_strong_key($account) !== '' ||
            lab3_norm((string)($account['auth'] ?? '')) !== 'manual') {
        return '';
    }
    return lab3_norm((string)($account['username'] ?? ''));
}

/**
 * Determina si un correo pertenece a un dominio institucional autorizado.
 */
function lab3_is_institutional_email(string $email, array $domains): bool {
    $email = lab3_norm($email);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    $domain = substr(strrchr($email, '@') ?: '', 1);
    foreach ($domains as $allowed) {
        if ($domain === $allowed || str_ends_with($domain, '.' . $allowed)) {
            return true;
        }
    }
    return false;
}

/**
 * Carga la política explícita para usar emisor + correo OAuth confirmado como
 * llave secundaria. Los dominios institucionales se conservan como metadato
 * de política, pero no limitan la convergencia de correos personales.
 */
function lab3_load_identity_policy(string $path): array {
    if (!is_readable($path)) {
        lab3_fail('No se puede leer la política de identidad ' . $path . '.');
    }
    $raw = file_get_contents($path);
    $policy = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($policy) || ($policy['schema_version'] ?? '') !== '1.1') {
        lab3_fail('identity-policy.json debe declarar schema_version 1.1.');
    }
    $versions = array_values(array_unique(array_map(
        'strval',
        $policy['accepted_identity_schema_versions'] ?? []
    )));
    if ($versions !== ['1.2']) {
        lab3_fail(
            'identity-policy.json debe aceptar exclusivamente identidades.json 1.2.'
        );
    }
    $domains = [];
    foreach ($policy['institutional_email_domains'] ?? [] as $domain) {
        $domain = strtolower(trim((string)$domain));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            lab3_fail('Dominio institucional inválido en identity-policy.json: ' . $domain . '.');
        }
        $domains[$domain] = true;
    }
    if (!$domains) {
        lab3_fail('identity-policy.json debe declarar al menos un dominio institucional.');
    }
    if (($policy['confirmed_oauth_email_policy'] ?? '') !==
            'issuer_plus_normalized_email') {
        lab3_fail(
            'identity-policy.json debe aprobar confirmed_oauth_email_policy=' .
            'issuer_plus_normalized_email.'
        );
    }
    $domains = array_keys($domains);
    sort($domains, SORT_STRING);
    return [
        'domains' => $domains,
        'confirmed_oauth_email_policy' =>
            (string)$policy['confirmed_oauth_email_policy'],
        'file_sha256' => hash('sha256', (string)$raw),
    ];
}

/**
 * Exige el contrato 1.2 del Recolector antes de conciliar una sola cuenta.
 */
function lab3_validate_identity_payload(array $payload, string $source): void {
    $metadata = $payload['metadata'] ?? null;
    if (!is_array($metadata) ||
            ($metadata['schema_version'] ?? '') !== '1.2' ||
            ($metadata['google_sub_policy'] ?? '') !== 'verified_only') {
        lab3_fail(
            'El inventario de ' . $source .
            ' no usa identidades.json 1.2 con google_sub_policy=verified_only.'
        );
    }
    $users = $payload['users'] ?? null;
    if (!is_array($users) || !array_is_list($users)) {
        lab3_fail('El inventario de ' . $source . ' no contiene users como lista.');
    }
    $seenids = [];
    foreach ($users as $index => $account) {
        $label = $source . '.users[' . $index . ']';
        if (!is_array($account)) {
            lab3_fail($label . ' no es un objeto.');
        }
        if (array_key_exists('google_sub_candidate', $account)) {
            lab3_fail($label . ' conserva el campo obsoleto google_sub_candidate.');
        }
        $userid = (int)($account['source_user_id'] ?? 0);
        if ($userid < 1 || isset($seenids[$userid])) {
            lab3_fail($label . ' tiene source_user_id inválido o repetido.');
        }
        $seenids[$userid] = true;
        $verified = $account['google_sub_verified'] ?? null;
        $issuer = rtrim(lab3_norm((string)($account['google_issuer'] ?? '')), '/');
        $sub = trim((string)($account['google_sub'] ?? ''));
        $kind = (string)($account['oauth_identifier_kind'] ?? '');
        $linkedusername = trim((string)($account['oauth_linked_username'] ?? ''));
        if (!is_bool($verified) ||
                !in_array($kind, ['sub', 'email', 'opaque', 'unknown'], true) ||
                !is_array($account['oauth_links'] ?? null) ||
                !array_is_list($account['oauth_links'])) {
            lab3_fail($label . ' incumple los campos obligatorios del contrato 1.2.');
        }
        if ($verified &&
                ($issuer === '' || $sub === '' ||
                 filter_var($sub, FILTER_VALIDATE_EMAIL) !== false)) {
            lab3_fail($label . ' declara un google_sub verificado inválido.');
        }
        if (!$verified && $sub !== '') {
            lab3_fail($label . ' publica google_sub sin evidencia verificada.');
        }
        if ($kind === 'email' &&
                filter_var($linkedusername, FILTER_VALIDATE_EMAIL) === false) {
            lab3_fail($label . ' clasifica como email un identificador no válido.');
        }
        if ($kind === 'sub' &&
                (!$verified || $linkedusername === '' || $linkedusername !== $sub)) {
            lab3_fail($label . ' clasifica como sub un vínculo no comprobado.');
        }
    }
}

/**
 * Clave unica de una cuenta de origen.
 */
function lab3_account_key(array $account): string {
    return (string)$account['source'] . ':' . (string)$account['source_user_id'];
}

/**
 * ID canonico determinista, sin exponer el identificador externo.
 */
function lab3_canonical_id(string $basis): string {
    return 'CAN-' . strtoupper(substr(hash('sha256', $basis), 0, 12));
}

/**
 * Une valores separados por |, elimina vacios y ordena.
 */
function lab3_union_pipe(array $values): string {
    $items = [];
    foreach ($values as $value) {
        foreach (explode('|', (string)$value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[$item] = true;
            }
        }
    }
    $result = array_keys($items);
    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return implode('|', $result);
}

/**
 * Escribe CSV UTF-8 con BOM y columnas estables.
 */
function lab3_write_csv(string $path, array $columns, array $rows): void {
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        lab3_fail('No fue posible crear ' . $path . '.');
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
 * Lee un CSV UTF-8 con columnas estrictas.
 */
function lab3_read_csv(string $path): array {
    if (!is_readable($path)) {
        lab3_fail('No se puede leer el archivo de resoluciones ' . $path . '.');
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        lab3_fail('No fue posible abrir ' . $path . '.');
    }
    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    if ($headers === false) {
        fclose($handle);
        lab3_fail($path . ' no contiene encabezados.');
    }
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    if (count($headers) !== count(array_unique($headers))) {
        fclose($handle);
        lab3_fail($path . ' contiene columnas repetidas.');
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
            lab3_fail($path . ', fila ' . $line . ': cantidad de columnas inválida.');
        }
        $row = array_combine($headers, $values);
        if ($row === false) {
            fclose($handle);
            lab3_fail($path . ', fila ' . $line . ': no se pudo interpretar.');
        }
        $row['_line'] = $line;
        $rows[] = $row;
    }
    fclose($handle);
    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * Convierte una bandera CSV sin aceptar valores ambiguos.
 */
function lab3_csv_bool(mixed $value, string $context): bool {
    $normalized = lab3_norm((string)$value);
    if (in_array($normalized, ['1', 'true', 'yes', 'si'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', ''], true)) {
        return false;
    }
    lab3_fail($context . ': active debe ser 1 o 0.');
}

/**
 * Huella estable de un conflicto. No depende del consecutivo CF-0001.
 */
function lab3_conflict_fingerprint(array $conflict): string {
    $basis = implode('|', [
        (string)($conflict['type'] ?? ''),
        (string)($conflict['source_accounts'] ?? ''),
        (string)($conflict['identity_key_hash'] ?? ''),
        lab3_norm((string)($conflict['email'] ?? '')),
    ]);
    return 'CFP-' . strtoupper(substr(hash('sha256', $basis), 0, 16));
}

/**
 * Separa, normaliza y ordena una lista delimitada por |.
 */
function lab3_pipe_items(string $value): array {
    $items = array_values(array_unique(array_filter(array_map(
        'trim',
        explode('|', $value)
    ), 'strlen')));
    sort($items, SORT_STRING);
    return $items;
}

/**
 * Carga decisiones manuales, valida cobertura exacta y produce grupos canónicos.
 *
 * El archivo contiene una fila por cuenta de origen. Una decisión puede:
 * - merge: agrupar todas las cuentas cubiertas;
 * - keep_separate: conservar una identidad por target_group;
 * - exclude: excluir las cuentas de la creación de usuarios.
 */
function lab3_prepare_identity_resolutions(
    string $path,
    array &$conflicts,
    array $accounts
): array {
    $requiredcolumns = [
        'resolution_id', 'conflict_fingerprints', 'action', 'source_account',
        'target_group', 'selected_email', 'corrected_google_issuer',
        'corrected_google_sub', 'approved_by', 'approved_at_utc',
        'evidence_reference', 'justification', 'active',
    ];
    $parsed = lab3_read_csv($path);
    if ($parsed['headers'] !== $requiredcolumns) {
        lab3_fail(
            'identity_resolutions.csv debe conservar exactamente estas columnas y orden: ' .
            implode(',', $requiredcolumns) . '.'
        );
    }

    $conflictbyfingerprint = [];
    foreach ($conflicts as $index => &$conflict) {
        $fingerprint = lab3_conflict_fingerprint($conflict);
        $conflict['conflict_fingerprint'] = $fingerprint;
        $conflict['resolution_status'] = 'unresolved';
        $conflict['resolution_ids'] = '';
        $conflictbyfingerprint[$fingerprint] = $index;
    }
    unset($conflict);

    $activegroups = [];
    $auditrows = [];
    foreach ($parsed['rows'] as $row) {
        $line = (int)$row['_line'];
        $active = lab3_csv_bool($row['active'], 'identity_resolutions.csv, fila ' . $line);
        if (!$active) {
            continue;
        }
        $resolutionid = trim((string)$row['resolution_id']);
        if (!preg_match('/^RES-[A-Z0-9][A-Z0-9_-]{2,63}$/', $resolutionid)) {
            lab3_fail(
                'identity_resolutions.csv, fila ' . $line .
                ': resolution_id debe usar el formato RES-IDENTIFICADOR.'
            );
        }
        $row['_fingerprints'] = lab3_pipe_items((string)$row['conflict_fingerprints']);
        if (!$row['_fingerprints']) {
            lab3_fail(
                'identity_resolutions.csv, fila ' . $line .
                ': conflict_fingerprints está vacío.'
            );
        }
        $accountkey = trim((string)$row['source_account']);
        if (!isset($accounts[$accountkey])) {
            lab3_fail(
                'identity_resolutions.csv, fila ' . $line .
                ': source_account no existe en la extracción: ' . $accountkey . '.'
            );
        }
        $row['source_account'] = $accountkey;
        $row['action'] = lab3_norm((string)$row['action']);
        $activegroups[$resolutionid][] = $row;
    }

    $coveredaccounts = [];
    $modifiedaccounts = $accounts;
    $canonicalgroups = [];
    $resolvedfingerprints = [];
    foreach ($activegroups as $resolutionid => $rows) {
        $first = $rows[0];
        $fingerprints = $first['_fingerprints'];
        $action = $first['action'];
        $metadatafields = [
            'approved_by', 'approved_at_utc', 'evidence_reference', 'justification',
        ];
        if (!in_array($action, ['merge', 'keep_separate', 'exclude'], true)) {
            lab3_fail(
                $resolutionid . ': action debe ser merge, keep_separate o exclude.'
            );
        }
        foreach ($metadatafields as $field) {
            if (trim((string)$first[$field]) === '') {
                lab3_fail($resolutionid . ': falta ' . $field . '.');
            }
        }
        $approvedat = trim((string)$first['approved_at_utc']);
        if (!preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',
            $approvedat
        ) || strtotime($approvedat) === false) {
            lab3_fail(
                $resolutionid .
                ': approved_at_utc debe ser ISO 8601 con zona horaria.'
            );
        }

        $rowaccounts = [];
        foreach ($rows as $row) {
            if ($row['_fingerprints'] !== $fingerprints || $row['action'] !== $action) {
                lab3_fail(
                    $resolutionid .
                    ': todas sus filas deben repetir conflict_fingerprints y action.'
                );
            }
            foreach ($metadatafields as $field) {
                if (trim((string)$row[$field]) !== trim((string)$first[$field])) {
                    lab3_fail(
                        $resolutionid . ': todas sus filas deben repetir ' . $field . '.'
                    );
                }
            }
            $accountkey = $row['source_account'];
            if (isset($rowaccounts[$accountkey])) {
                lab3_fail($resolutionid . ': repite la cuenta ' . $accountkey . '.');
            }
            if (isset($coveredaccounts[$accountkey])) {
                lab3_fail(
                    $accountkey . ' aparece en más de una resolución activa: ' .
                    $coveredaccounts[$accountkey] . ' y ' . $resolutionid . '.'
                );
            }
            $rowaccounts[$accountkey] = $row;
        }

        $expectedaccounts = [];
        foreach ($fingerprints as $fingerprint) {
            if (!isset($conflictbyfingerprint[$fingerprint])) {
                lab3_fail(
                    $resolutionid . ': la huella ' . $fingerprint .
                    ' no existe en los conflictos actuales. Vuelva a revisar identity_conflicts.csv.'
                );
            }
            $conflict = $conflicts[$conflictbyfingerprint[$fingerprint]];
            if (!in_array((string)$conflict['type'], [
                'DUPLICATE_STRONG_IDENTITY_SAME_SOURCE',
                'DUPLICATE_INSTITUTIONAL_EMAIL_SAME_SOURCE',
                'DUPLICATE_OAUTH_EMAIL_SAME_SOURCE',
                'EMAIL_COLLISION_DIFFERENT_STRONG_IDENTITY',
                'MISSING_STRONG_IDENTITY',
            ], true)) {
                lab3_fail(
                    $resolutionid . ': ' . $fingerprint .
                    ' no es un conflicto de identidad resoluble por este archivo.'
                );
            }
            foreach (lab3_pipe_items((string)$conflict['source_accounts']) as $accountkey) {
                $expectedaccounts[$accountkey] = true;
            }
        }
        $actualkeys = array_keys($rowaccounts);
        $expectedkeys = array_keys($expectedaccounts);
        sort($actualkeys, SORT_STRING);
        sort($expectedkeys, SORT_STRING);
        if ($actualkeys !== $expectedkeys) {
            lab3_fail(
                $resolutionid . ': las cuentas deben coincidir exactamente con los conflictos. ' .
                'Esperadas=' . implode('|', $expectedkeys) .
                '; recibidas=' . implode('|', $actualkeys) . '.'
            );
        }

        $effectivegroups = [];
        foreach ($rowaccounts as $accountkey => $row) {
            $account = $accounts[$accountkey];
            $effectiveemail = lab3_norm((string)$row['selected_email']);
            $effectiveissuer = trim((string)$row['corrected_google_issuer']) !== ''
                ? rtrim(lab3_norm((string)$row['corrected_google_issuer']), '/')
                : rtrim(lab3_norm((string)($account['google_issuer'] ?? '')), '/');
            $effectivesub = trim((string)$row['corrected_google_sub']) !== ''
                ? trim((string)$row['corrected_google_sub'])
                : trim((string)($account['google_sub'] ?? ''));
            $targetgroup = trim((string)$row['target_group']);

            if ($action !== 'exclude') {
                if ($targetgroup === '' ||
                        $effectiveemail === '' ||
                        filter_var($effectiveemail, FILTER_VALIDATE_EMAIL) === false ||
                        $effectiveissuer === '') {
                    lab3_fail(
                        $resolutionid . ', cuenta ' . $accountkey .
                        ': para incluirla se requieren target_group, correo válido e issuer efectivo.'
                    );
                }
                if ($effectivesub !== '' &&
                        filter_var($effectivesub, FILTER_VALIDATE_EMAIL) !== false) {
                    lab3_fail(
                        $resolutionid . ', cuenta ' . $accountkey .
                        ': corrected_google_sub no puede contener un correo.'
                    );
                }
                $modifiedaccounts[$accountkey]['_source_email'] =
                    (string)($account['email'] ?? '');
                $modifiedaccounts[$accountkey]['email'] = $effectiveemail;
                $modifiedaccounts[$accountkey]['google_issuer'] = $effectiveissuer;
                $modifiedaccounts[$accountkey]['google_sub'] = $effectivesub;
                $modifiedaccounts[$accountkey]['google_sub_verified'] =
                    $effectivesub !== '';
                if ($effectivesub !== '') {
                    $modifiedaccounts[$accountkey]['oauth_identifier_kind'] = 'sub';
                    $modifiedaccounts[$accountkey]['oauth_linked_username'] = $effectivesub;
                    $modifiedaccounts[$accountkey]['_identity_method_override'] = 'google_sub';
                } else if (lab3_oauth_email_key($modifiedaccounts[$accountkey]) !== '') {
                    $modifiedaccounts[$accountkey]['_identity_method_override'] = 'oauth_email';
                } else {
                    lab3_fail(
                        $resolutionid . ', cuenta ' . $accountkey .
                        ': falta un google_sub verificado o un linked login OAuth por correo utilizable.'
                    );
                }
                $effectivegroups[$targetgroup][] = $accountkey;
            } elseif ($targetgroup !== '') {
                lab3_fail(
                    $resolutionid . ', cuenta ' . $accountkey .
                    ': target_group debe quedar vacío para exclude.'
                );
            }

            $auditrows[] = [
                'resolution_id' => $resolutionid,
                'conflict_fingerprints' => implode('|', $fingerprints),
                'action' => $action,
                'source_account' => $accountkey,
                'target_group' => $targetgroup,
                'original_email' => (string)($account['email'] ?? ''),
                'effective_email' => $action === 'exclude'
                    ? ''
                    : $effectiveemail,
                'original_google_issuer' => (string)($account['google_issuer'] ?? ''),
                'original_google_sub' => (string)($account['google_sub'] ?? ''),
                'effective_google_issuer' => $action === 'exclude'
                    ? ''
                    : $effectiveissuer,
                'effective_google_sub' => $action === 'exclude'
                    ? ''
                    : $effectivesub,
                'original_oauth_linked_username' =>
                    (string)($account['oauth_linked_username'] ?? ''),
                'effective_oauth_linked_username' => $action === 'exclude'
                    ? ''
                    : (string)($modifiedaccounts[$accountkey]['oauth_linked_username'] ?? ''),
                'effective_identity_method' => $action === 'exclude'
                    ? 'source_account'
                    : lab3_identity_method($modifiedaccounts[$accountkey]),
                'approved_by' => trim((string)$row['approved_by']),
                'approved_at_utc' => trim((string)$row['approved_at_utc']),
                'evidence_reference' => trim((string)$row['evidence_reference']),
                'justification' => trim((string)$row['justification']),
                'status' => 'applied',
            ];
            $coveredaccounts[$accountkey] = $resolutionid;
        }

        if ($action === 'merge') {
            if (count($effectivegroups) !== 1) {
                lab3_fail($resolutionid . ': merge requiere un único target_group.');
            }
            $emails = [];
            $identitykeys = [];
            $methods = [];
            foreach (array_keys($rowaccounts) as $accountkey) {
                $account = $modifiedaccounts[$accountkey];
                $emails[lab3_norm((string)$account['email'])] = true;
                $identitykeys[lab3_operational_key($account)] = true;
                $methods[lab3_identity_method($account)] = true;
            }
            if (count($emails) !== 1 || count($identitykeys) !== 1 ||
                    count($methods) !== 1 || isset($identitykeys[''])) {
                lab3_fail(
                    $resolutionid .
                    ': merge exige el mismo correo seleccionado y el mismo identificador OAuth efectivo.'
                );
            }
        } elseif ($action === 'keep_separate') {
            if (count($effectivegroups) !== count($rowaccounts)) {
                lab3_fail(
                    $resolutionid .
                    ': keep_separate exige un target_group distinto por cuenta.'
                );
            }
            $emails = [];
            $identitykeys = [];
            foreach (array_keys($rowaccounts) as $accountkey) {
                $account = $modifiedaccounts[$accountkey];
                $email = lab3_norm((string)$account['email']);
                $identitykey = lab3_operational_key($account);
                if ($identitykey === '' ||
                        isset($emails[$email]) || isset($identitykeys[$identitykey])) {
                    lab3_fail(
                        $resolutionid .
                        ': keep_separate exige correos e identificadores OAuth efectivos únicos.'
                    );
                }
                $emails[$email] = true;
                $identitykeys[$identitykey] = true;
            }
        } else {
            foreach (array_keys($rowaccounts) as $accountkey) {
                $effectivegroups['excluded-' . $accountkey][] = $accountkey;
            }
        }

        foreach ($effectivegroups as $targetgroup => $accountkeys) {
            sort($accountkeys, SORT_STRING);
            $canonicalgroups[] = [
                'resolution_id' => $resolutionid,
                'conflict_fingerprints' => implode('|', $fingerprints),
                'action' => $action,
                'target_group' => $targetgroup,
                'account_keys' => $accountkeys,
            ];
        }
        foreach ($fingerprints as $fingerprint) {
            $resolvedfingerprints[$fingerprint][] = $resolutionid;
        }
    }

    foreach ($resolvedfingerprints as $fingerprint => $resolutionids) {
        $index = $conflictbyfingerprint[$fingerprint];
        $resolutionids = array_values(array_unique($resolutionids));
        sort($resolutionids, SORT_STRING);
        $conflicts[$index]['resolution_status'] = 'resolved';
        $conflicts[$index]['resolution_ids'] = implode('|', $resolutionids);
    }
    usort($canonicalgroups, static fn(array $a, array $b): int => [
        $a['resolution_id'], $a['target_group'],
    ] <=> [
        $b['resolution_id'], $b['target_group'],
    ]);
    usort($auditrows, static fn(array $a, array $b): int => [
        $a['resolution_id'], $a['source_account'],
    ] <=> [
        $b['resolution_id'], $b['source_account'],
    ]);

    return [
        'file_sha256' => hash_file('sha256', $path),
        'active_rows' => count($auditrows),
        'decision_count' => count($activegroups),
        'accounts' => $modifiedaccounts,
        'covered_accounts' => $coveredaccounts,
        'groups' => $canonicalgroups,
        'audit_rows' => $auditrows,
        'resolved_conflicts' => count($resolvedfingerprints),
    ];
}

/**
 * Agrega un conflicto o advertencia.
 */
function lab3_add_conflict(
    array &$conflicts,
    string $severity,
    string $type,
    string $identitykey,
    string $email,
    array $accountkeys,
    string $details,
    string $action
): void {
    sort($accountkeys, SORT_STRING);
    $conflicts[] = [
        'conflict_id' => '',
        'severity' => $severity,
        'type' => $type,
        'identity_key_hash' => $identitykey === '' ? '' : substr(hash('sha256', $identitykey), 0, 16),
        'email' => $email,
        'source_accounts' => implode('|', $accountkeys),
        'details' => $details,
        'recommended_action' => $action,
    ];
}

/**
 * Normaliza roles estandar y condensa cualquier rol personalizado.
 *
 * Los roles personalizados se conservan con su nombre original para auditoria,
 * pero su rol destino es "personalizado" y su perfil es "student_readonly".
 */
function lab3_classify_role(array $role, array $catalog = []): array {
    $shortname = lab3_norm((string)($role['role_shortname'] ?? $catalog['role_shortname'] ?? ''));
    $archetype = lab3_norm((string)($role['role_archetype'] ?? $catalog['archetype'] ?? ''));
    $signals = array_values(array_unique(array_map(
        'strval',
        $catalog['allowed_classification_capabilities'] ?? []
    )));
    sort($signals, SORT_STRING);

    $standardmap = [
        'siteadmin' => 'administrador',
        'manager' => 'administrador',
        'coursecreator' => 'administrador',
        'editingteacher' => 'docente',
        'teacher' => 'docente',
        'student' => 'estudiante',
        'user' => 'estudiante',
        'guest' => 'estudiante',
        'frontpage' => 'estudiante',
    ];
    $customrole = !array_key_exists($shortname, $standardmap);
    if ($customrole) {
        return [
            'normalized_role' => 'personalizado',
            'target_permission_profile' => 'student_readonly',
            'classification_rule' => 'custom_role_condensed_by_policy',
            'classification_confidence' => 'high',
            'custom_role' => true,
            'allowed_signals' => implode('|', $signals),
            'privileged_review' => false,
            'requires_review' => false,
        ];
    }

    $normalized = '';
    $rule = '';
    $confidence = '';

    if (isset($standardmap[$shortname])) {
        $normalized = $standardmap[$shortname];
        $rule = 'standard_shortname';
        $confidence = 'high';
    }

    $contextlevel = (string)($role['context_level'] ?? '');
    $safesystemroles = ['user', 'guest', 'frontpage'];
    if ($contextlevel === 'system' &&
            $normalized === 'estudiante' &&
            !in_array($shortname, $safesystemroles, true)) {
        $normalized = 'administrador';
        $rule = 'system_context_conservative';
        $confidence = 'low';
    }

    $privileged = $normalized === 'administrador' ||
        ($contextlevel === 'system' && !in_array($shortname, $safesystemroles, true));
    // Esta equivalencia forma parte de la política institucional aprobada.
    // siteadmin se preserva aparte como administrador de sitio y los demás
    // roles administrativos estándar conservan el perfil administrador.
    $requiresreview = false;
    return [
        'normalized_role' => $normalized,
        'target_permission_profile' => $normalized,
        'classification_rule' => $rule,
        'classification_confidence' => $confidence,
        'custom_role' => $customrole,
        'allowed_signals' => implode('|', $signals),
        'privileged_review' => false,
        'requires_review' => $requiresreview,
    ];
}

$sources = array_values(array_filter(array_map('trim', explode(',', $sourcesvalue)), 'strlen'));
if (!$sources || count($sources) !== count(array_unique($sources))) {
    lab3_fail('La lista --sources está vacía o contiene identificadores repetidos.');
}
foreach ($sources as $source) {
    if (!preg_match('/^[a-z][a-z0-9_-]*$/', $source)) {
        lab3_fail('Identificador de fuente inválido: ' . $source . '.');
    }
}
$identitypolicy = lab3_load_identity_policy($identitypolicypath);
$institutionaldomains = $identitypolicy['domains'];
$accounts = [];
$roles = [];
$rolecatalog = [];
$enrolments = [];
$inputhashes = [];
foreach ($sources as $source) {
    $path = $inputdir . DIRECTORY_SEPARATOR . 'identity-' . $source . '.json';
    if (!is_readable($path)) {
        lab3_fail('Falta el inventario ' . $path . '.');
    }
    $raw = file_get_contents($path);
    $payload = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
    if (($payload['metadata']['source'] ?? '') !== $source) {
        lab3_fail('El archivo ' . $path . ' no corresponde a ' . $source . '.');
    }
    lab3_validate_identity_payload($payload, $source);
    $inputhashes[$source] = hash('sha256', (string)$raw);
    foreach ($payload['users'] ?? [] as $account) {
        $key = lab3_account_key($account);
        if (isset($accounts[$key])) {
            lab3_fail('Cuenta de origen duplicada: ' . $key . '.');
        }
        $accounts[$key] = $account;
    }
    foreach ($payload['roles'] ?? [] as $role) {
        $roles[] = $role;
    }
    foreach ($payload['role_catalog'] ?? [] as $definition) {
        $key = $source . ':' . (string)($definition['source_role_id'] ?? '') . ':' .
            (string)($definition['role_shortname'] ?? '');
        $rolecatalog[$key] = $definition;
    }
    foreach ($payload['enrolments'] ?? [] as $enrolment) {
        $enrolments[] = $enrolment;
    }
}
if (!$accounts) {
    lab3_fail('Los inventarios no contienen usuarios.');
}

ksort($accounts, SORT_STRING);
$stronggroups = [];
$oauthemailgroups = [];
$manualgroups = [];
$emailgroups = [];
foreach ($accounts as $accountkey => $account) {
    $strongkey = lab3_strong_key($account);
    if ($strongkey !== '') {
        $stronggroups[$strongkey][] = $accountkey;
    } else {
        $oauthemailkey = lab3_oauth_email_key($account);
        if ($oauthemailkey !== '') {
            $oauthemailgroups[$oauthemailkey][] = $accountkey;
        } else {
            $manualkey = lab3_manual_key($account);
            if ($manualkey !== '') {
                $manualgroups[$manualkey][] = $accountkey;
            }
        }
    }
    $email = lab3_norm((string)($account['email'] ?? ''));
    if ($email !== '') {
        $emailgroups[$email][] = $accountkey;
    }
}
ksort($stronggroups, SORT_STRING);
ksort($oauthemailgroups, SORT_STRING);
ksort($manualgroups, SORT_STRING);
ksort($emailgroups, SORT_STRING);

$conflicts = [];
$blockedstrong = [];
$blockedreasons = [];

// Una identidad fuerte repetida dentro de la misma fuente no se fusiona.
foreach ($stronggroups as $strongkey => $accountkeys) {
    $bysource = [];
    foreach ($accountkeys as $accountkey) {
        $bysource[$accounts[$accountkey]['source']][] = $accountkey;
    }
    foreach ($bysource as $source => $sourceaccounts) {
        if (count($sourceaccounts) < 2) {
            continue;
        }
        $blockedstrong[$strongkey] = true;
        foreach ($accountkeys as $key) {
            $blockedreasons[$key]['duplicate_same_source'] = true;
        }
        lab3_add_conflict(
            $conflicts,
            'critical',
            'DUPLICATE_STRONG_IDENTITY_SAME_SOURCE',
            $strongkey,
            '',
            $accountkeys,
            'Dos o mas cuentas de ' . $source . ' comparten issuer + sub.',
            'Bloquear la fusion y validar la cuenta en el proveedor institucional.'
        );
    }
}

// Un identificador OAuth por correo confirmado repetido dentro de una
// misma fuente es ambiguo y no se fusiona automáticamente.
$blockedoauthemail = [];
foreach ($oauthemailgroups as $identitykey => $accountkeys) {
    $bysource = [];
    foreach ($accountkeys as $accountkey) {
        $bysource[$accounts[$accountkey]['source']][] = $accountkey;
    }
    foreach ($bysource as $source => $sourceaccounts) {
        if (count($sourceaccounts) < 2) {
            continue;
        }
        $blockedoauthemail[$identitykey] = true;
        foreach ($accountkeys as $key) {
            $blockedreasons[$key]['duplicate_oauth_email_same_source'] = true;
        }
        lab3_add_conflict(
            $conflicts,
            'critical',
            'DUPLICATE_OAUTH_EMAIL_SAME_SOURCE',
            $identitykey,
            '',
            $accountkeys,
            'Dos o más cuentas de ' . $source .
                ' comparten el mismo linked login OAuth confirmado por correo.',
            'Bloquear la fusión y confirmar cuál cuenta de origen es la correcta.'
        );
    }
}

// Un mismo correo asociado a dos identidades fuertes distintas es colision.
foreach ($emailgroups as $email => $accountkeys) {
    $keys = [];
    foreach ($accountkeys as $accountkey) {
        $strongkey = lab3_strong_key($accounts[$accountkey]);
        if ($strongkey !== '') {
            $keys[$strongkey] = true;
        }
    }
    if (count($keys) < 2) {
        continue;
    }
    foreach (array_keys($keys) as $strongkey) {
        $blockedstrong[$strongkey] = true;
        foreach ($stronggroups[$strongkey] ?? [] as $key) {
            $blockedreasons[$key]['email_collision'] = true;
        }
    }
    lab3_add_conflict(
        $conflicts,
        'critical',
        'EMAIL_COLLISION_DIFFERENT_STRONG_IDENTITY',
        '',
        $email,
        $accountkeys,
        'El mismo correo aparece asociado a valores issuer + sub diferentes.',
        'No fusionar por correo; confirmar titularidad con la fuente institucional.'
    );
}

// Solo las cuentas sin identidad fuerte, correo OAuth confirmado utilizable ni
// credencial local requieren revisión. Un correo confirmado puede ser una
// llave OAuth operativa, pero nunca se convierte en google_sub.
foreach ($accounts as $accountkey => $account) {
    $oauthemailkey = lab3_oauth_email_key($account);
    if (lab3_strong_key($account) !== '' ||
            $oauthemailkey !== '' ||
            lab3_manual_key($account) !== '') {
        continue;
    }
    $details = 'No se encontro issuer + sub verificable.';
    if (trim((string)($account['oauth_linked_username'] ?? '')) !== '') {
        $details .= ' El linked login usa un identificador OAuth no comprobado.';
    }
    lab3_add_conflict(
        $conflicts,
        'review',
        'MISSING_STRONG_IDENTITY',
        '',
        (string)($account['email'] ?? ''),
        [$accountkey],
        $details,
        'Revisar manualmente; no registrar el correo como google_sub.'
    );
}

$identityresolutions = lab3_prepare_identity_resolutions(
    $resolutionspath,
    $conflicts,
    $accounts
);
$resolvedaccounts = $identityresolutions['accounts'];
$resolvedaccountkeys = array_fill_keys(
    array_keys($identityresolutions['covered_accounts']),
    true
);

$canonicalrows = [];
$maprows = [];
$mapbyaccount = [];

/**
 * Registra una identidad canonica y sus cuentas de origen.
 */
function lab3_register_canonical(
    array $accountkeys,
    string $basis,
    string $decision,
    bool $requiresreview,
    string $identitymethod,
    array $accounts,
    array &$canonicalrows,
    array &$maprows,
    array &$mapbyaccount,
    string $resolutionids = ''
): void {
    sort($accountkeys, SORT_STRING);
    $canonicalid = lab3_canonical_id($basis);
    $group = array_map(static fn(string $key): array => $accounts[$key], $accountkeys);
    $emails = [];
    $documents = [];
    $programs = [];
    foreach ($group as $account) {
        $email = lab3_norm((string)($account['email'] ?? ''));
        if ($email !== '') {
            $emails[$email] = true;
        }
        $document = trim((string)($account['idnumber'] ?? ''));
        if ($document !== '') {
            $documents[$document] = true;
        }
        $programs[] = (string)($account['program_codes'] ?? '');
    }
    $emailvalues = array_keys($emails);
    $documentvalues = array_keys($documents);
    sort($emailvalues, SORT_STRING);
    sort($documentvalues, SORT_STRING);
    $first = $group[0];
    $strongkey = lab3_strong_key($first);
    [$issuer, $sub] = $strongkey === '' ? ['', ''] : explode('|', $strongkey, 2);
    $oauthlinkedusername = '';
    $oauthidentifierkind = '';
    if ($identitymethod === 'oauth_email') {
        $issuer = rtrim(lab3_norm((string)($first['google_issuer'] ?? '')), '/');
        $oauthlinkedusername = lab3_norm(
            (string)($first['oauth_linked_username'] ?? '')
        );
        $oauthidentifierkind = 'email';
    } else if ($identitymethod === 'google_sub') {
        $oauthlinkedusername = $sub;
        $oauthidentifierkind = 'sub';
    }
    $canonicalusername = $identitymethod === 'manual_username'
        ? lab3_norm((string)($first['username'] ?? ''))
        : 'canonical_' . strtolower(substr($canonicalid, 4));

    $approved = !$requiresreview &&
        !in_array($decision, ['blocked', 'manual_review', 'excluded'], true);
    $canonicalrows[] = [
        'canonical_id' => $canonicalid,
        'canonical_username' => $canonicalusername,
        'identity_method' => $identitymethod,
        'proposed_email' => count($emailvalues) === 1 ? $emailvalues[0] : '',
        'email_candidates' => implode('|', $emailvalues),
        'firstname' => (string)($first['firstname'] ?? ''),
        'lastname' => (string)($first['lastname'] ?? ''),
        'google_issuer' => $issuer,
        'google_sub' => $sub,
        'oauth_linked_username' => $oauthlinkedusername,
        'oauth_identifier_kind' => $oauthidentifierkind,
        'document_candidates' => implode('|', $documentvalues),
        'program_codes' => lab3_union_pipe($programs),
        'source_account_count' => count($accountkeys),
        'source_accounts' => implode('|', $accountkeys),
        'decision' => $decision,
        'resolution_ids' => $resolutionids,
        'requires_review' => $requiresreview,
        'approved_for_apply' => $approved,
    ];

    foreach ($accountkeys as $accountkey) {
        $account = $accounts[$accountkey];
        $row = [
            'source' => (string)$account['source'],
            'source_user_id' => (int)$account['source_user_id'],
            'source_username' => (string)$account['username'],
            'source_email' => (string)($account['_source_email'] ?? $account['email']),
            'identity_source' => (string)($account['identity_source'] ?? ''),
            'identity_method' => $identitymethod,
            'oauth_linked_username' =>
                (string)($account['oauth_linked_username'] ?? ''),
            'oauth_identifier_kind' =>
                (string)($account['oauth_identifier_kind'] ?? ''),
            'canonical_id' => $canonicalid,
            'decision' => $decision,
            'resolution_ids' => $resolutionids,
            'requires_review' => $requiresreview,
            'approved_for_apply' => $approved,
        ];
        $maprows[] = $row;
        $mapbyaccount[$accountkey] = $row;
    }
}

// Las decisiones activas y verificadas se materializan antes de las reglas
// automáticas. Las cuentas cubiertas no vuelven a participar en otra fusión.
foreach ($identityresolutions['groups'] as $resolutiongroup) {
    $action = (string)$resolutiongroup['action'];
    $decision = match ($action) {
        'merge' => 'resolved_merge',
        'keep_separate' => 'resolved_keep_separate',
        'exclude' => 'excluded',
        default => lab3_fail('Acción de resolución interna no reconocida: ' . $action . '.'),
    };
    $firstresolvedkey = $resolutiongroup['account_keys'][0] ?? '';
    $identitymethod = $action === 'exclude'
        ? 'source_account'
        : lab3_identity_method($resolvedaccounts[$firstresolvedkey] ?? []);
    lab3_register_canonical(
        $resolutiongroup['account_keys'],
        'resolution|' . $resolutiongroup['resolution_id'] . '|' .
            $resolutiongroup['target_group'],
        $decision,
        false,
        $identitymethod,
        $resolvedaccounts,
        $canonicalrows,
        $maprows,
        $mapbyaccount,
        (string)$resolutiongroup['resolution_id']
    );
}

// Identidades fuertes validas: fusion entre fuentes o cuenta unica separada.
foreach ($stronggroups as $strongkey => $accountkeys) {
    $accountkeys = array_values(array_filter(
        $accountkeys,
        static fn(string $accountkey): bool => !isset($resolvedaccountkeys[$accountkey])
    ));
    if (!$accountkeys) {
        continue;
    }
    if (isset($blockedstrong[$strongkey])) {
        foreach ($accountkeys as $accountkey) {
            lab3_register_canonical(
                [$accountkey],
                'blocked-account|' . $accountkey,
                'blocked',
                true,
                'google_sub',
                $accounts,
                $canonicalrows,
                $maprows,
                $mapbyaccount
            );
        }
        continue;
    }

    $emails = [];
    $documents = [];
    foreach ($accountkeys as $accountkey) {
        $account = $accounts[$accountkey];
        if (trim((string)$account['email']) !== '') {
            $emails[lab3_norm((string)$account['email'])] = true;
        }
        if (trim((string)$account['idnumber']) !== '') {
            $documents[trim((string)$account['idnumber'])] = true;
        }
    }
    $warning = count($emails) > 1 || count($documents) > 1;
    $decision = count($accountkeys) > 1
        ? ($warning ? 'merge_with_warning' : 'merge')
        : 'keep_separate';
    lab3_register_canonical(
        $accountkeys,
        'strong|' . $strongkey,
        $decision,
        // La advertencia permanece en identity_conflicts.csv, pero la llave
        // fuerte ya confirmó la fusión y no bloquea la creación de la cuenta.
        false,
        'google_sub',
        $accounts,
        $canonicalrows,
        $maprows,
        $mapbyaccount
    );

    if (count($emails) > 1) {
        lab3_add_conflict(
            $conflicts,
            'warning',
            'EMAIL_CHANGED_SAME_STRONG_IDENTITY',
            $strongkey,
            '',
            $accountkeys,
            'La misma identidad fuerte tiene correos diferentes: ' . implode('|', array_keys($emails)) . '.',
            'Fusionar la identidad y seleccionar el correo vigente mediante revision institucional.'
        );
    }
    if (count($documents) > 1) {
        lab3_add_conflict(
            $conflicts,
            'warning',
            'DOCUMENT_MISMATCH_SAME_STRONG_IDENTITY',
            $strongkey,
            '',
            $accountkeys,
            'La misma identidad fuerte tiene documentos diferentes: ' . implode('|', array_keys($documents)) . '.',
            'Confirmar el documento antes de aplicar la identidad canonica.'
        );
    }
}

// Linked logins por correo confirmado: issuer + correo normalizado es una
// llave OAuth explícita. Se conserva como oauth_email y nunca como google_sub.
foreach ($oauthemailgroups as $identitykey => $accountkeys) {
    $accountkeys = array_values(array_filter(
        $accountkeys,
        static fn(string $accountkey): bool => !isset($resolvedaccountkeys[$accountkey])
    ));
    if (!$accountkeys) {
        continue;
    }
    if (isset($blockedoauthemail[$identitykey])) {
        foreach ($accountkeys as $accountkey) {
            lab3_register_canonical(
                [$accountkey],
                'blocked-oauth-email-account|' . $accountkey,
                'blocked',
                true,
                'oauth_email',
                $accounts,
                $canonicalrows,
                $maprows,
                $mapbyaccount
            );
        }
        continue;
    }
    $decision = count($accountkeys) > 1
        ? 'merge_oauth_email'
        : 'keep_oauth_email';
    lab3_register_canonical(
        $accountkeys,
        'oauth-email|' . $identitykey,
        $decision,
        false,
        'oauth_email',
        $accounts,
        $canonicalrows,
        $maprows,
        $mapbyaccount
    );
}

// Cuentas locales sin sub: mismo username significa la misma identidad
// propuesta. Usernames diferentes permanecen como identidades diferentes.
foreach ($manualgroups as $manualkey => $accountkeys) {
    $accountkeys = array_values(array_filter(
        $accountkeys,
        static fn(string $accountkey): bool => !isset($resolvedaccountkeys[$accountkey])
    ));
    if (!$accountkeys) {
        continue;
    }
    $decision = count($accountkeys) > 1
        ? 'merge_manual_username'
        : 'keep_manual_username';
    lab3_register_canonical(
        $accountkeys,
        'manual-username|' . $manualkey,
        $decision,
        false,
        'manual_username',
        $accounts,
        $canonicalrows,
        $maprows,
        $mapbyaccount
    );
}

// Cuentas de autenticacion externa sin sub y sin username manual utilizable:
// se conservan separadas, pero requieren validacion de identidad.
foreach ($accounts as $accountkey => $account) {
    if (isset($resolvedaccountkeys[$accountkey])) {
        continue;
    }
    $oauthemailkey = lab3_oauth_email_key($account);
    if (lab3_strong_key($account) !== '' ||
            $oauthemailkey !== '' ||
            lab3_manual_key($account) !== '') {
        continue;
    }
    lab3_register_canonical(
        [$accountkey],
        'unresolved|' . $accountkey,
        'manual_review',
        true,
        'source_account',
        $accounts,
        $canonicalrows,
        $maprows,
        $mapbyaccount
    );
}

usort($canonicalrows, static fn(array $a, array $b): int => strcmp($a['canonical_id'], $b['canonical_id']));
usort($maprows, static fn(array $a, array $b): int => [$a['source'], $a['source_user_id']] <=> [$b['source'], $b['source_user_id']]);
if (count($maprows) !== count($accounts) || count($mapbyaccount) !== count($accounts)) {
    lab3_fail('No todas las cuentas recibieron exactamente un mapeo.');
}

$enrolbyaccountcourse = [];
foreach ($enrolments as $enrolment) {
    $accountkey = (string)$enrolment['source'] . ':' . (string)$enrolment['source_user_id'];
    $key = $accountkey . '|' . (string)$enrolment['course_key'];
    $enrolbyaccountcourse[$key] = $enrolment;
}

$rolerows = [];
$normalizedgrouped = [];
$roleclassificationbykey = [];
$roleexceptions = [];
$rolesbycanonical = [];
$rolereviewbycanonical = [];
$siteadmincanonical = [];
foreach ($accounts as $accountkey => $account) {
    if (($account['is_site_admin'] ?? false) !== true) {
        continue;
    }
    $mapping = $mapbyaccount[$accountkey] ?? null;
    if (!$mapping) {
        lab3_fail('Siteadmin sin mapeo de usuario: ' . $accountkey . '.');
    }
    $siteadmincanonical[$mapping['canonical_id']][] =
        $accountkey . ':is_site_admin';
}
foreach ($roles as $role) {
    $accountkey = (string)$role['source'] . ':' . (string)$role['source_user_id'];
    if (!isset($mapbyaccount[$accountkey], $accounts[$accountkey])) {
        lab3_fail('Rol sin mapeo de usuario: ' . $accountkey . '.');
    }
    $mapping = $mapbyaccount[$accountkey];
    $account = $accounts[$accountkey];
    $enrolment = null;
    if (($role['context_level'] ?? '') === 'course') {
        $enrolment = $enrolbyaccountcourse[$accountkey . '|' . $role['context_key']] ?? null;
    }

    $catalogkey = (string)$role['source'] . ':' . (string)($role['source_role_id'] ?? '') . ':' .
        (string)$role['role_shortname'];
    $catalog = $rolecatalog[$catalogkey] ?? [
        'source' => (string)$role['source'],
        'source_role_id' => (int)($role['source_role_id'] ?? 0),
        'role_shortname' => (string)$role['role_shortname'],
        'role_name' => (string)($role['role_name'] ?? ''),
        'archetype' => (string)($role['role_archetype'] ?? ''),
        'allowed_classification_capabilities' => [],
    ];
    $classification = lab3_classify_role($role, $catalog);
    $normalizedrole = $classification['normalized_role'];
    $rolesbycanonical[$mapping['canonical_id']][$normalizedrole] = true;
    if ($classification['requires_review']) {
        $rolereviewbycanonical[$mapping['canonical_id']] = true;
    }
    if (lab3_norm((string)$role['role_shortname']) === 'siteadmin') {
        $siteadmincanonical[$mapping['canonical_id']][] =
            $accountkey . ':' . $role['role_shortname'];
    }

    $approved = (bool)$mapping['approved_for_apply'] && !$classification['requires_review'];
    $rolerow = [
        'canonical_id' => $mapping['canonical_id'],
        'source' => (string)$role['source'],
        'source_user_id' => (int)$role['source_user_id'],
        'source_username' => (string)$account['username'],
        'context_level' => (string)$role['context_level'],
        'context_key' => (string)$role['context_key'],
        'context_name' => (string)$role['context_name'],
        'source_role_id' => (int)($role['source_role_id'] ?? 0),
        'role_shortname' => (string)$role['role_shortname'],
        'role_name' => (string)($role['role_name'] ?? ''),
        'role_archetype' => (string)($role['role_archetype'] ?? ''),
        'normalized_role' => $normalizedrole,
        'target_permission_profile' => $classification['target_permission_profile'],
        'classification_rule' => $classification['classification_rule'],
        'classification_confidence' => $classification['classification_confidence'],
        'custom_role' => $classification['custom_role'],
        'enrol_method' => (string)($enrolment['enrol_method'] ?? ''),
        'enrol_status' => isset($enrolment['status']) ? (int)$enrolment['status'] : '',
        'assignment_component' => (string)($role['component'] ?? ''),
        'identity_decision' => $mapping['decision'],
        'role_review_required' => $classification['requires_review'],
        'privileged_review' => $classification['privileged_review'],
        'approved_for_apply' => $approved,
    ];
    $rolerows[] = $rolerow;

    if (!isset($roleclassificationbykey[$catalogkey])) {
        $roleclassificationbykey[$catalogkey] = [
            'source' => (string)$role['source'],
            'source_role_id' => (int)($role['source_role_id'] ?? 0),
            'role_shortname' => (string)$role['role_shortname'],
            'role_name' => (string)($role['role_name'] ?? ''),
            'role_archetype' => (string)($role['role_archetype'] ?? ''),
            'normalized_role' => $normalizedrole,
            'target_permission_profile' => $classification['target_permission_profile'],
            'classification_rule' => $classification['classification_rule'],
            'classification_confidence' => $classification['classification_confidence'],
            'allowed_signals' => $classification['allowed_signals'],
            'custom_role' => $classification['custom_role'],
            'privileged_review' => $classification['privileged_review'],
            'requires_review' => $classification['requires_review'],
            'assignment_count' => 0,
        ];
    }
    $roleclassificationbykey[$catalogkey]['assignment_count']++;

    $normalizedkey = implode("\x1F", [
        $mapping['canonical_id'], (string)$role['source'], (string)$role['context_level'],
        (string)$role['context_key'], $normalizedrole,
    ]);
    if (!isset($normalizedgrouped[$normalizedkey])) {
        $normalizedgrouped[$normalizedkey] = [
            'canonical_id' => $mapping['canonical_id'],
            'source' => (string)$role['source'],
            'source_user_ids_set' => [],
            'source_usernames_set' => [],
            'context_level' => (string)$role['context_level'],
            'context_key' => (string)$role['context_key'],
            'context_name' => (string)$role['context_name'],
            'normalized_role' => $normalizedrole,
            'target_permission_profile' => $classification['target_permission_profile'],
            'source_roles_set' => [],
            'assignment_count' => 0,
            'enrol_methods_set' => [],
            'enrol_statuses_set' => [],
            'requires_review' => false,
            'privileged_review' => false,
            'approved_for_apply' => true,
        ];
    }
    $group = &$normalizedgrouped[$normalizedkey];
    $group['source_user_ids_set'][(string)$role['source_user_id']] = true;
    $group['source_usernames_set'][(string)$account['username']] = true;
    $group['source_roles_set'][(string)$role['role_shortname']] = true;
    if ($enrolment && trim((string)$enrolment['enrol_method']) !== '') {
        $group['enrol_methods_set'][(string)$enrolment['enrol_method']] = true;
        $group['enrol_statuses_set'][(string)$enrolment['status']] = true;
    }
    $group['assignment_count']++;
    $group['requires_review'] = $group['requires_review'] || $classification['requires_review'];
    $group['privileged_review'] = $group['privileged_review'] || $classification['privileged_review'];
    $group['approved_for_apply'] = $group['approved_for_apply'] && $approved;
    unset($group);
}
usort($rolerows, static fn(array $a, array $b): int => [
    $a['canonical_id'], $a['source'], $a['context_key'], $a['role_shortname'],
] <=> [
    $b['canonical_id'], $b['source'], $b['context_key'], $b['role_shortname'],
]);

$normalizedrolerows = [];
foreach ($normalizedgrouped as $group) {
    foreach (['source_user_ids_set', 'source_usernames_set', 'source_roles_set',
              'enrol_methods_set', 'enrol_statuses_set'] as $setname) {
        $values = array_keys($group[$setname]);
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);
        $group[str_replace('_set', '', $setname)] = implode('|', $values);
        unset($group[$setname]);
    }
    $normalizedrolerows[] = $group;
}
usort($normalizedrolerows, static fn(array $a, array $b): int => [
    $a['canonical_id'], $a['source'], $a['context_key'], $a['normalized_role'],
] <=> [
    $b['canonical_id'], $b['source'], $b['context_key'], $b['normalized_role'],
]);

$roleclassificationrows = array_values($roleclassificationbykey);
usort($roleclassificationrows, static fn(array $a, array $b): int => [
    $a['source'], $a['role_shortname'],
] <=> [
    $b['source'], $b['role_shortname'],
]);
foreach ($roleclassificationrows as $definition) {
    if (!$definition['requires_review']) {
        continue;
    }
    $reason = 'Rol privilegiado estandar: requiere aprobacion institucional antes de aplicarse.';
    $roleexceptions[] = [
        'source' => $definition['source'],
        'source_role_id' => $definition['source_role_id'],
        'role_shortname' => $definition['role_shortname'],
        'role_name' => $definition['role_name'],
        'proposed_normalized_role' => $definition['normalized_role'],
        'classification_confidence' => $definition['classification_confidence'],
        'assignment_count' => $definition['assignment_count'],
        'privileged_review' => $definition['privileged_review'],
        'reason' => $reason,
        'manual_action' => 'Aprobar, cambiar la equivalencia o definir una excepcion documentada.',
        'approved_for_apply' => false,
    ];
}

$rolepriority = [
    'estudiante' => 1,
    'docente' => 2,
    'personalizado' => 3,
    'administrador' => 4,
];
foreach ($canonicalrows as &$canonicalrow) {
    $normalizedroles = array_keys($rolesbycanonical[$canonicalrow['canonical_id']] ?? []);
    usort($normalizedroles, static fn(string $a, string $b): int =>
        ($rolepriority[$a] ?? 0) <=> ($rolepriority[$b] ?? 0)
    );
    $canonicalrow['normalized_roles'] = implode('|', $normalizedroles);
    $canonicalrow['highest_normalized_role'] = $normalizedroles
        ? $normalizedroles[count($normalizedroles) - 1]
        : '';
    $canonicalrow['role_review_required'] = isset($rolereviewbycanonical[$canonicalrow['canonical_id']]);
    $canonicalrow['roles_approved_for_apply'] =
        (bool)$canonicalrow['approved_for_apply'] && !$canonicalrow['role_review_required'];
    $canonicalrow['siteadmin_required'] =
        isset($siteadmincanonical[$canonicalrow['canonical_id']]);
}
unset($canonicalrow);

usort($conflicts, static fn(array $a, array $b): int => [
    $a['severity'], $a['type'], $a['source_accounts'],
] <=> [
    $b['severity'], $b['type'], $b['source_accounts'],
]);
foreach ($conflicts as $index => &$conflict) {
    $conflict['conflict_id'] = sprintf('CF-%04d', $index + 1);
    if (empty($conflict['conflict_fingerprint'])) {
        $conflict['conflict_fingerprint'] = lab3_conflict_fingerprint($conflict);
    }
    if (!isset($conflict['resolution_status'])) {
        $conflict['resolution_status'] = 'not_applicable';
    }
    if (!isset($conflict['resolution_ids'])) {
        $conflict['resolution_ids'] = '';
    }
}
unset($conflict);

if (!is_dir($outputdir) && !mkdir($outputdir, 0770, true) && !is_dir($outputdir)) {
    lab3_fail('No fue posible crear ' . $outputdir . '.');
}

$canonicalcolumns = [
    'canonical_id', 'canonical_username', 'identity_method', 'proposed_email', 'email_candidates',
    'firstname', 'lastname', 'google_issuer', 'google_sub',
    'oauth_linked_username', 'oauth_identifier_kind', 'document_candidates',
    'program_codes', 'source_account_count', 'source_accounts', 'decision',
    'resolution_ids',
    'normalized_roles', 'highest_normalized_role', 'role_review_required',
    'roles_approved_for_apply', 'siteadmin_required',
    'requires_review', 'approved_for_apply',
];
$mapcolumns = [
    'source', 'source_user_id', 'source_username', 'source_email', 'identity_source',
    'identity_method', 'oauth_linked_username', 'oauth_identifier_kind',
    'canonical_id', 'decision', 'resolution_ids',
    'requires_review', 'approved_for_apply',
];
$conflictcolumns = [
    'conflict_id', 'conflict_fingerprint', 'severity', 'type',
    'identity_key_hash', 'email', 'source_accounts', 'resolution_status',
    'resolution_ids', 'details', 'recommended_action',
];
$identityresolutioncolumns = [
    'resolution_id', 'conflict_fingerprints', 'action', 'source_account',
    'target_group', 'original_email', 'effective_email',
    'original_google_issuer', 'original_google_sub',
    'effective_google_issuer', 'effective_google_sub',
    'original_oauth_linked_username', 'effective_oauth_linked_username',
    'effective_identity_method',
    'approved_by', 'approved_at_utc', 'evidence_reference',
    'justification', 'status',
];
$rolecolumns = [
    'canonical_id', 'source', 'source_user_id', 'source_username', 'context_level',
    'context_key', 'context_name', 'source_role_id', 'role_shortname', 'role_name',
    'role_archetype', 'normalized_role', 'target_permission_profile', 'classification_rule',
    'classification_confidence', 'custom_role', 'enrol_method', 'enrol_status',
    'assignment_component', 'identity_decision', 'role_review_required',
    'privileged_review', 'approved_for_apply',
];
$normalizedrolecolumns = [
    'canonical_id', 'source', 'source_user_ids', 'source_usernames', 'context_level',
    'context_key', 'context_name', 'normalized_role', 'target_permission_profile', 'source_roles',
    'assignment_count', 'enrol_methods', 'enrol_statuses', 'requires_review',
    'privileged_review', 'approved_for_apply',
];
$roleclassificationcolumns = [
    'source', 'source_role_id', 'role_shortname', 'role_name', 'role_archetype',
    'normalized_role', 'target_permission_profile', 'classification_rule', 'classification_confidence',
    'allowed_signals', 'custom_role', 'privileged_review', 'requires_review',
    'assignment_count',
];
$roleexceptioncolumns = [
    'source', 'source_role_id', 'role_shortname', 'role_name',
    'proposed_normalized_role', 'classification_confidence', 'assignment_count',
    'privileged_review', 'reason', 'manual_action', 'approved_for_apply',
];

lab3_write_csv($outputdir . '/canonical_users.csv', $canonicalcolumns, $canonicalrows);
lab3_write_csv($outputdir . '/source_user_map.csv', $mapcolumns, $maprows);
lab3_write_csv($outputdir . '/identity_conflicts.csv', $conflictcolumns, $conflicts);
lab3_write_csv(
    $outputdir . '/identity_resolution_audit.csv',
    $identityresolutioncolumns,
    $identityresolutions['audit_rows']
);
lab3_write_csv($outputdir . '/role_assignments.csv', $rolecolumns, $rolerows);
lab3_write_csv($outputdir . '/normalized_role_assignments.csv', $normalizedrolecolumns, $normalizedrolerows);
lab3_write_csv($outputdir . '/role_classification.csv', $roleclassificationcolumns, $roleclassificationrows);
lab3_write_csv($outputdir . '/role_classification_exceptions.csv', $roleexceptioncolumns, $roleexceptions);

$decisioncounts = [];
foreach ($canonicalrows as $row) {
    $decisioncounts[$row['decision']] = ($decisioncounts[$row['decision']] ?? 0) + 1;
}
ksort($decisioncounts, SORT_STRING);
$normalizedrolecounts = [];
foreach ($rolerows as $row) {
    $role = $row['normalized_role'];
    $normalizedrolecounts[$role] = ($normalizedrolecounts[$role] ?? 0) + 1;
}
ksort($normalizedrolecounts, SORT_STRING);
$customroledefinitions = count(array_filter(
    $roleclassificationrows,
    static fn(array $row): bool => (bool)$row['custom_role']
));
$unresolvedidentityconflicts = count(array_filter(
    $conflicts,
    static fn(array $row): bool =>
        in_array((string)$row['type'], [
            'DUPLICATE_STRONG_IDENTITY_SAME_SOURCE',
            'DUPLICATE_INSTITUTIONAL_EMAIL_SAME_SOURCE',
            'DUPLICATE_OAUTH_EMAIL_SAME_SOURCE',
            'EMAIL_COLLISION_DIFFERENT_STRONG_IDENTITY',
            'MISSING_STRONG_IDENTITY',
        ], true) &&
        (string)$row['resolution_status'] === 'unresolved'
));
$phase4blocked = count(array_filter(
    $canonicalrows,
    static fn(array $row): bool => $row['decision'] === 'blocked'
));
$phase4review = count(array_filter(
    $canonicalrows,
    static fn(array $row): bool => $row['decision'] === 'manual_review'
));
$phase4excluded = count(array_filter(
    $canonicalrows,
    static fn(array $row): bool => $row['decision'] === 'excluded'
));
$phase4applicable = count($canonicalrows) -
    $phase4blocked - $phase4review - $phase4excluded;
$summary = [
    'schema_version' => '1.6',
    'algorithm' => 'verified issuer+sub, confirmed OAuth issuer+email, or manual username reconciliation with audited identity resolutions and approved role taxonomy',
    'accepted_identity_schema_versions' => ['1.2'],
    'normalized_role_taxonomy' => ['estudiante', 'docente', 'personalizado', 'administrador'],
    'generated_at_utc' => gmdate('c'),
    'config_sha256' => $confighash,
    'configured_sources' => $sources,
    'configured_source_count' => count($sources),
    'configured_target' => [
        'id' => $targetid,
        'name' => $targetname,
    ],
    'input_sha256' => $inputhashes,
    'identity_policy_sha256' => $identitypolicy['file_sha256'],
    'institutional_email_domains' => $institutionaldomains,
    'confirmed_oauth_email_policy' =>
        $identitypolicy['confirmed_oauth_email_policy'],
    'identity_resolutions_sha256' => $identityresolutions['file_sha256'],
    'identity_resolution_active_rows' => $identityresolutions['active_rows'],
    'identity_resolution_decisions_applied' => $identityresolutions['decision_count'],
    'identity_resolution_accounts_covered' => count($identityresolutions['covered_accounts']),
    'identity_conflicts_resolved' => $identityresolutions['resolved_conflicts'],
    'identity_conflicts_unresolved' => $unresolvedidentityconflicts,
    'identity_resolution_audit_sha256' => hash_file(
        'sha256',
        $outputdir . '/identity_resolution_audit.csv'
    ),
    'source_accounts' => count($accounts),
    'canonical_identities' => count($canonicalrows),
    'source_role_assignments' => count($roles),
    'output_role_assignments' => count($rolerows),
    'normalized_context_assignments' => count($normalizedrolerows),
    'role_definitions_classified' => count($roleclassificationrows),
    'custom_role_definitions' => $customroledefinitions,
    'role_classification_exceptions' => count($roleexceptions),
    'normalized_role_counts' => $normalizedrolecounts,
    'conflicts_and_warnings' => count($conflicts),
    'siteadmin_canonical_identities' => count($siteadmincanonical),
    'decision_counts' => $decisioncounts,
    'phase4_expected' => [
        'applicable_identities' => $phase4applicable,
        'blocked_identities' => $phase4blocked,
        'identity_review_pending' => $phase4review,
        'excluded_identities' => $phase4excluded,
    ],
    'apply_performed' => false,
];

if ($expectlab) {
    $byusername = [];
    foreach ($maprows as $row) {
        $byusername[$row['source_username']] = $row;
    }
    $required = [
        'ana.v', 'atorres.master', 'ana.torres',
        'luis.old', 'luis.new',
        'docente.v', 'docente.m', 'docente.p',
        'gestor.v', 'gestor.m', 'gestor.p',
        'collision.a', 'collision.b', 'dup.one', 'dup.two', 'sin.sub',
    ];
    foreach ($required as $username) {
        if (!isset($byusername[$username])) {
            lab3_fail('Validacion LAB: falta ' . $username . '.');
        }
    }
    foreach ([
        ['ana.v', 'atorres.master', 'ana.torres'],
        ['luis.old', 'luis.new'],
        ['docente.v', 'docente.m', 'docente.p'],
        ['gestor.v', 'gestor.m', 'gestor.p'],
    ] as $mergednames) {
        $ids = array_unique(array_map(static fn(string $name): string => $byusername[$name]['canonical_id'], $mergednames));
        if (count($ids) !== 1) {
            lab3_fail('Validacion LAB: no se fusiono ' . implode('|', $mergednames) . '.');
        }
    }
    $labconflictusers = ['collision.a', 'collision.b', 'dup.one', 'dup.two'];
    if ($identityresolutions['active_rows'] === 0) {
        foreach ($labconflictusers as $username) {
            if ($byusername[$username]['decision'] !== 'blocked') {
                lab3_fail('Validacion LAB: ' . $username . ' debia quedar bloqueado.');
            }
        }
    } else {
        foreach ($labconflictusers as $username) {
            if (!in_array($byusername[$username]['decision'], [
                'resolved_merge', 'resolved_keep_separate', 'excluded',
            ], true)) {
                lab3_fail(
                    'Validacion LAB: ' . $username .
                    ' no quedo cubierto por una resolucion valida.'
                );
            }
        }
        if ($identityresolutions['resolved_conflicts'] !== 2) {
            lab3_fail(
                'Validacion LAB: las resoluciones activas debian cubrir los dos conflictos bloqueantes.'
            );
        }
    }
    if ($byusername['sin.sub']['decision'] !== 'keep_manual_username' ||
            $byusername['sin.sub']['approved_for_apply'] !== true) {
        lab3_fail('Validacion LAB: sin.sub debia conservarse como cuenta manual aplicable.');
    }
    $adminmaps = array_values(array_filter(
        $maprows,
        static fn(array $row): bool => $row['source_username'] === 'admin'
    ));
    $admincanonicalids = array_unique(array_column($adminmaps, 'canonical_id'));
    if (count($adminmaps) < 3 || count($admincanonicalids) !== 1 ||
            $adminmaps[0]['decision'] !== 'merge_manual_username') {
        lab3_fail('Validacion LAB: las cuentas manuales admin no se conciliaron por username.');
    }
    $managerroles = array_filter($rolerows, static fn(array $row): bool =>
        $row['role_shortname'] === 'manager' &&
        $row['normalized_role'] === 'administrador' &&
        $row['approved_for_apply'] === true
    );
    if (count($managerroles) < 3) {
        lab3_fail('Validacion LAB: faltan roles manager privilegiados de las tres fuentes.');
    }
    $administratorroles = array_filter($rolerows, static fn(array $row): bool =>
        $row['role_shortname'] === 'siteadmin' &&
        $row['normalized_role'] === 'administrador' &&
        $row['approved_for_apply'] === true
    );
    if (count($administratorroles) < 3) {
        lab3_fail('Validacion LAB: faltan administradores inventariados de las tres fuentes.');
    }
    $observednormalized = [];
    foreach ($rolerows as $row) {
        $observednormalized[$row['normalized_role']] = true;
    }
    foreach (['estudiante', 'docente', 'personalizado', 'administrador'] as $expectedrole) {
        if (!isset($observednormalized[$expectedrole])) {
            lab3_fail('Validacion LAB: no se genero el rol normalizado ' . $expectedrole . '.');
        }
    }
    $customtutors = array_filter($rolerows, static fn(array $row): bool =>
        $row['role_shortname'] === 'lab_tutor_academico' &&
        $row['normalized_role'] === 'personalizado' &&
        $row['target_permission_profile'] === 'student_readonly' &&
        $row['custom_role'] === true &&
        $row['role_review_required'] === false
    );
    if (!$customtutors) {
        lab3_fail('Validacion LAB: el tutor no se condenso como personalizado con perfil student_readonly.');
    }
    $summary['lab_validation'] = 'passed';
}

$summaryjson = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($summaryjson === false || file_put_contents($outputdir . '/summary.json', $summaryjson . PHP_EOL) === false) {
    lab3_fail('No fue posible escribir summary.json.');
}

echo 'FASE3_OK source_accounts=' . count($accounts) .
    ' canonical=' . count($canonicalrows) .
    ' conflicts=' . count($conflicts) .
    ' roles=' . count($rolerows) .
    ' normalized=' . count($normalizedrolerows) .
    ' role_exceptions=' . count($roleexceptions) . PHP_EOL;
