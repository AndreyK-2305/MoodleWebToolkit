<?php
declare(strict_types=1);

function rc11_res_assert(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('RC11_RESOLUTION_FAILED ' . $label); }
}
function rc11_res_csv(string $path): array {
    $handle = fopen($path, 'rb');
    $headers = fgetcsv($handle);
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    $rows = [];
    while (($values = fgetcsv($handle)) !== false) {
        if ($values !== [null]) { $rows[] = array_combine($headers, $values); }
    }
    fclose($handle);
    return $rows;
}
function rc11_res_user(int $id, string $email, string $sub = ''): array {
    return [
        'source' => 'campus', 'source_user_id' => $id, 'username' => 'student.' . $id,
        'email' => $email, 'auth' => 'oauth2', 'firstname' => 'Persona',
        'lastname' => (string)$id, 'google_issuer' => $sub === '' ? '' : 'https://accounts.google.com',
        'google_sub' => $sub, 'google_sub_verified' => $sub !== '',
        'oauth_identifier_kind' => $sub === '' ? 'unknown' : 'sub',
        'oauth_linked_username' => $sub, 'oauth_links' => [],
    ];
}

$temp = sys_get_temp_dir() . '/rc11-res-' . bin2hex(random_bytes(6));
mkdir($temp);
try {
    $identity = $temp . '/identity-campus.json';
    file_put_contents($identity, json_encode([
        'metadata' => ['source' => 'campus', 'schema_version' => '1.2',
            'google_sub_policy' => 'verified_only'],
        'users' => [
            rc11_res_user(1, 'one@example.com', 'strong-1'),
            rc11_res_user(2, 'two@example.com', 'strong-1'),
            rc11_res_user(3, 'collision@example.com', 'strong-3'),
            rc11_res_user(4, 'collision@example.com', 'strong-4'),
            rc11_res_user(5, 'merge@example.com'),
            rc11_res_user(6, 'merge@example.com'),
            rc11_res_user(7, 'exclude@example.com'),
        ], 'roles' => [], 'role_catalog' => [], 'enrolments' => [],
    ], JSON_THROW_ON_ERROR));
    $originalhash = hash_file('sha256', $identity);
    $config = __DIR__ . '/../config/identity_resolutions.csv';
    $run = static function (string $out, string $resolutions) use ($identity): array {
        mkdir($out);
        copy($identity, $out . '/identity-campus.json');
        $command = 'php ' . escapeshellarg(__DIR__ . '/../scripts/reconcile-identities.php') .
            ' --input=' . escapeshellarg($out) . ' --output=' . escapeshellarg($out) .
            ' --sources=campus --targetid=destino --targetname=Destino' .
            ' --confighash=' . str_repeat('a', 64) .
            ' --resolutions=' . escapeshellarg($resolutions) .
            ' --identitypolicy=' . escapeshellarg(__DIR__ . '/../config/identity-policy.json');
        $log = [];
        exec($command . ' 2>&1', $log, $status);
        return [$status, implode("\n", $log)];
    };
    [$status, $log] = $run($temp . '/initial', $config);
    rc11_res_assert($status === 0, 'descubrimiento: ' . $log);
    $fingerprints = [];
    foreach (rc11_res_csv($temp . '/initial/identity_conflicts.csv') as $row) {
        if (in_array($row['type'], ['DUPLICATE_STRONG_IDENTITY_SAME_SOURCE',
                'EMAIL_COLLISION_DIFFERENT_STRONG_IDENTITY'], true)) {
            $fingerprints[$row['type']] = $row['conflict_fingerprint'];
        } elseif ($row['type'] === 'MISSING_STRONG_IDENTITY') {
            $fingerprints[$row['type']][$row['source_accounts']] = $row['conflict_fingerprint'];
        }
    }
    rc11_res_assert(count($fingerprints) === 3 &&
        count($fingerprints['MISSING_STRONG_IDENTITY']) === 3,
        'fingerprints disponibles y obligatorios');
    $headers = array_map('trim', explode(',', trim((string)file_get_contents($config))));
    $file = $temp . '/resolutions.csv';
    $handle = fopen($file, 'wb');
    fputcsv($handle, $headers);
    $append = static function (string $id, string $fp, string $action,
            int $user, string $group, string $email) use ($handle, $headers): void {
        $record = array_fill_keys($headers, '');
        $record = array_replace($record, [
            'resolution_id' => $id, 'conflict_fingerprints' => $fp,
            'action' => $action, 'source_account' => 'campus:' . $user,
            'target_group' => $group, 'selected_email' => $email,
            'approved_by' => 'Auditoría', 'approved_at_utc' => '2026-09-20T00:00:00Z',
            'evidence_reference' => 'fixture', 'justification' => 'regresión', 'active' => '1',
        ]);
        fputcsv($handle, array_values($record));
    };
    foreach ([1, 2] as $user) {
        $append('RES-MERGE', $fingerprints['DUPLICATE_STRONG_IDENTITY_SAME_SOURCE'],
            'merge', $user, 'combined', 'merge@example.com');
    }
    foreach ([3, 4] as $user) {
        $append('RES-KEEP', $fingerprints['EMAIL_COLLISION_DIFFERENT_STRONG_IDENTITY'],
            'keep_separate', $user, 'account-' . $user, 'safe-' . $user . '@example.com');
    }
    $missing = $fingerprints['MISSING_STRONG_IDENTITY'];
    // Las otras dos cuentas sin clave fuerte permanecen pending_relink;
    // una resolución aprobada nunca fabrica una llave OAuth para fusionarlas.
    $append('RES-EXCLUDE', $missing['campus:7'], 'exclude', 7, '', '');
    fclose($handle);
    [$status, $log] = $run($temp . '/resolved', $file);
    rc11_res_assert($status === 0, 'merge/keep_separate/exclude: ' . $log);
    $rows = rc11_res_csv($temp . '/resolved/canonical_users.csv');
    $decisions = array_count_values(array_column($rows, 'decision'));
    rc11_res_assert(($decisions['resolved_merge'] ?? 0) === 1 &&
        ($decisions['resolved_keep_separate'] ?? 0) === 2 &&
        ($decisions['excluded'] ?? 0) === 1,
        'las tres resoluciones conservan su semántica');
    rc11_res_assert(count(rc11_res_csv($temp . '/resolved/identity_resolution_audit.csv')) === 5 &&
        hash_file('sha256', $identity) === $originalhash,
        'auditoría manual y paquete original');
    $bad = $temp . '/bad.csv';
    file_put_contents($bad, str_replace($fingerprints['DUPLICATE_STRONG_IDENTITY_SAME_SOURCE'],
        'CFP-0000000000000000', (string)file_get_contents($file)));
    [$status, $log] = $run($temp . '/bad', $bad);
    rc11_res_assert($status !== 0 && str_contains($log, 'no existe en los conflictos'),
        'fingerprint arbitrario bloqueado');
    $empty = $temp . '/empty.csv';
    file_put_contents($empty, str_replace($fingerprints['EMAIL_COLLISION_DIFFERENT_STRONG_IDENTITY'],
        '', (string)file_get_contents($file)));
    [$status, $log] = $run($temp . '/empty', $empty);
    rc11_res_assert($status !== 0 && str_contains($log, 'conflict_fingerprints está vacío'),
        'fingerprint vacío bloqueado');
    echo "RC11_RESOLUTION_OK merge=1 keep_separate=2 exclude=1 fingerprints=required\n";
} finally {
    exec('rm -rf -- ' . escapeshellarg($temp));
}
