<?php
declare(strict_types=1);

function vf_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException('V8_FUZZY_FAILED: ' . $message); }
}
function vf_csv(string $path): array {
    $stream = fopen($path, 'rb');
    $headers = fgetcsv($stream, 0, ',', '"', '');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    $rows = [];
    while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        if ($values !== [null]) { $rows[] = array_combine($headers, $values); }
    }
    fclose($stream);
    return $rows;
}
function vf_run(string $input, string $output, string $fuzzy): void {
    $command = 'php ' . escapeshellarg(__DIR__ . '/../scripts/reconcile-identities.php') .
        ' --input=' . escapeshellarg($input) .
        ' --output=' . escapeshellarg($output) .
        ' --sources=origen_a,origen_b --targetid=destino --targetname=Destino' .
        ' --confighash=' . str_repeat('b', 64) .
        ' --resolutions=' . escapeshellarg(__DIR__ . '/../config/identity_resolutions.csv') .
        ' --fuzzyresolutions=' . escapeshellarg($fuzzy) .
        ' --identitypolicy=' . escapeshellarg(__DIR__ . '/../config/identity-policy.json');
    exec($command . ' 2>&1', $log, $status);
    vf_check($status === 0, implode("\n", $log));
}

$temp = sys_get_temp_dir() . '/consolidador-v8-fuzzy-' . bin2hex(random_bytes(5));
mkdir($temp . '/input', 0770, true);
mkdir($temp . '/preliminary', 0770, true);
mkdir($temp . '/final', 0770, true);
$headers = 'resolution_id,candidate_group,decision,source_account,canonical_account,' .
    'canonical_email,canonical_username,oauth_policy,approved_by,approved_at_utc,' .
    'evidence_reference,justification,algorithm_version,previous_canonical_ids,active';
$fuzzy = $temp . '/fuzzy.csv';
file_put_contents($fuzzy, $headers . "\n");
try {
    foreach ([
        'origen_a'=>[10, 'kevin.a', 'kevinandreyjc@example.edu', 'sub-a'],
        'origen_b'=>[20, 'kevin.b', 'kevinandreyjaimes1@example.net', 'sub-b'],
    ] as $source => [$id, $username, $email, $sub]) {
        file_put_contents($temp . "/input/identity-$source.json", json_encode([
            'metadata'=>['source'=>$source, 'schema_version'=>'1.2',
                'google_sub_policy'=>'verified_only'],
            'users'=>[['source'=>$source, 'source_user_id'=>$id,
                'username'=>$username, 'auth'=>'oauth2', 'email'=>$email,
                'firstname'=>'Kevin Andrey', 'lastname'=>'Jaimes Cristancho',
                'idnumber'=>'', 'google_issuer'=>'https://accounts.google.com',
                'google_sub'=>$sub, 'google_sub_verified'=>true,
                'oauth_identifier_kind'=>'sub', 'oauth_linked_username'=>$sub,
                'oauth_links'=>[]]],
            'roles'=>[['source'=>$source, 'source_user_id'=>$id,
                'context_level'=>'course', 'context_key'=>'curso-1',
                'context_name'=>'Curso', 'source_role_id'=>5,
                'role_shortname'=>'student', 'role_name'=>'Estudiante',
                'role_archetype'=>'student']],
            'enrolments'=>[], 'role_catalog'=>[],
        ], JSON_THROW_ON_ERROR));
    }
    vf_run($temp . '/input', $temp . '/preliminary', $fuzzy);
    $preMap = vf_csv($temp . '/preliminary/source_user_map.csv');
    vf_check(count(array_unique(array_column($preMap, 'canonical_id'))) === 2,
        'preliminar debe conservar dos canonicales');
    $previous = array_column($preMap, 'canonical_id');
    sort($previous, SORT_STRING);
    $group = 'CAND-' . strtoupper(substr(hash('sha256',
        'origen_a:10|origen_b:20'), 0, 16));
    $common = [
        'FUZ-' . substr($group, 5), $group, 'MERGE', '', 'origen_a:10',
        'kevin.canonico@example.edu', 'kevin.canonico', 'canonical_account',
        'Operador QA', '2026-09-26T18:30:00Z', 'ticket-v8-42',
        'Titularidad validada manualmente', 'v8-blocked-2',
        implode('|', $previous), 'true',
    ];
    $stream = fopen($fuzzy, 'wb');
    fputcsv($stream, explode(',', $headers), ',', '"', '');
    foreach (['origen_a:10','origen_b:20'] as $account) {
        $row = $common;
        $row[3] = $account;
        fputcsv($stream, $row, ',', '"', '');
    }
    fclose($stream);
    vf_run($temp . '/input', $temp . '/final', $fuzzy);
    $map = vf_csv($temp . '/final/source_user_map.csv');
    $canonical = vf_csv($temp . '/final/canonical_users.csv');
    $roles = vf_csv($temp . '/final/role_assignments.csv');
    $audit = vf_csv($temp . '/final/fuzzy_identity_resolution_audit.csv');
    $summary = json_decode((string)file_get_contents($temp . '/final/summary.json'),
        true, 512, JSON_THROW_ON_ERROR);
    vf_check(count($canonical) === 1 && count($map) === 2 &&
        count(array_unique(array_column($map, 'canonical_id'))) === 1,
        'MERGE debe reconstruir un único canonical y source_user_map');
    vf_check(count($roles) >= 1 &&
        count(array_unique(array_column($roles, 'canonical_id'))) === 1,
        'roles deben regenerarse contra el canonical fusionado');
    vf_check($canonical[0]['google_sub'] === 'sub-a' &&
        $canonical[0]['canonical_username'] === 'kevin.canonico' &&
        !str_contains($canonical[0]['google_sub'], 'sub-b'),
        'solo la identidad OAuth canónica puede materializarse');
    vf_check(count($audit) === 2 &&
        count(array_unique(array_column($audit, 'resulting_canonical_id'))) === 1 &&
        (int)$summary['fuzzy_identity_merges_applied'] === 1,
        'auditoría fuzzy debe registrar origen, decisión y canonical resultante');
    echo "V8_FUZZY_RECONCILIATION_OK canonical=1 accounts=2 roles=" .
        count($roles) . " oauth=sub-a\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isDir()) { rmdir($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($temp);
}
