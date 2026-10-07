<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures', 'tempdir' => sys_get_temp_dir()];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
}
class core_user {
    public static function clean_field(string $value, string $field): string { return $value; }
}
function validate_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
require_once(__DIR__ . '/../scripts/phase4-lib.php');
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-lib.php');
require_once(__DIR__ . '/../scripts/oauth2-preflight-lib.php');
require_once(__DIR__ . '/../docker/verify-plugin-pins.php');

function rc2_check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('RC2_CONTRACT_FAILED ' . $label); }
}
function rc2_csv(string $path): array {
    $handle = fopen($path, 'rb');
    $header = fgetcsv($handle);
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($row !== [null]) { $rows[] = array_combine($header, $row); }
    }
    fclose($handle);
    return $rows;
}
function rc2_git(string $directory, array $args): string {
    $cmd = 'git -C ' . escapeshellarg($directory);
    foreach ($args as $arg) { $cmd .= ' ' . escapeshellarg($arg); }
    exec($cmd . ' 2>&1', $out, $status);
    rc2_check($status === 0, 'git: ' . implode(' ', $args) . implode(' ', $out));
    return trim(implode("\n", $out));
}

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/rc2-scenarios.json'),
    true, 512, JSON_THROW_ON_ERROR);
$temp = sys_get_temp_dir() . '/consolidador-rc2-' . bin2hex(random_bytes(6));
mkdir($temp . '/input', 0770, true);
mkdir($temp . '/output', 0770, true);
try {
    $users = [];
    $count = (int)$fixture['accounts_without_strong_identity'];
    for ($i = 1; $i <= $count; $i++) {
        $email = $i === 4 ? 'invalido' : ('usuario' . $i . '@ufps.edu.co');
        if ($i === 3) { $email = 'usuario2@ufps.edu.co'; }
        $users[] = [
            'source' => 'campus', 'source_user_id' => $i,
            'username' => 'oauth.' . $i, 'auth' => 'oauth2',
            'email' => $email, 'firstname' => 'Persona', 'lastname' => (string)$i,
            'google_issuer' => '', 'google_sub' => '',
            'google_sub_verified' => false, 'oauth_identifier_kind' => 'unknown',
            'oauth_linked_username' => '', 'oauth_links' => [],
            'is_site_admin' => $i === 5,
        ];
    }
    $roles = [[
        'source' => 'campus', 'source_user_id' => 5,
        'context_level' => 'course', 'context_key' => 'curso-1',
        'context_name' => 'Historia académica', 'source_role_id' => 5,
        'role_shortname' => 'student', 'role_name' => 'Estudiante',
        'role_archetype' => 'student',
    ]];
    $enrolments = [[
        'source' => 'campus', 'source_user_id' => 5,
        'course_key' => 'curso-1', 'enrol_method' => 'manual', 'status' => 0,
    ]];
    file_put_contents($temp . '/input/identity-campus.json', json_encode([
        'metadata' => ['source' => 'campus', 'schema_version' => '1.2',
            'google_sub_policy' => 'verified_only'],
        'users' => $users, 'roles' => $roles, 'enrolments' => $enrolments,
        'role_catalog' => [],
    ], JSON_THROW_ON_ERROR));
    $resolutions = __DIR__ . '/../config/identity_resolutions.csv';
    $policy = __DIR__ . '/../config/identity-policy.json';
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/../scripts/reconcile-identities.php') .
        ' --input=' . escapeshellarg($temp . '/input') .
        ' --output=' . escapeshellarg($temp . '/output') .
        ' --sources=campus --targetid=destino --targetname=Destino' .
        ' --confighash=' . str_repeat('a', 64) .
        ' --resolutions=' . escapeshellarg($resolutions) .
        ' --identitypolicy=' . escapeshellarg($policy);
    exec($cmd . ' 2>&1', $log, $status);
    rc2_check($status === 0, 'conciliación: ' . implode("\n", $log));
    $summary = json_decode((string)file_get_contents($temp . '/output/summary.json'), true);
    $canonical = rc2_csv($temp . '/output/canonical_users.csv');
    $source = rc2_csv($temp . '/output/source_user_map.csv');
    $role = rc2_csv($temp . '/output/role_assignments.csv');
    rc2_check(count($canonical) === $count && count($source) === $count &&
        (int)$summary['pending_relink_accounts'] === $count &&
        (int)$summary['phase4_expected']['applicable_identities'] === $count - 1 &&
        (int)$summary['phase4_expected']['identity_review_pending'] === 1 &&
        (int)$summary['identity_conflicts_unresolved'] === 1,
        '206 cuentas conservadas; correo irrecuperable pendiente en fase 3');
    $bykey = array_column($source, null, 'source_user_id');
    $bycanonical = array_column($canonical, null, 'canonical_id');
    foreach ($source as $account) {
        $person = $bycanonical[$account['canonical_id']];
        rc2_check($account['identity_method'] === 'pending_relink' &&
            $account['decision'] === ($account['source_user_id'] === '4'
                ? 'manual_review' : 'keep_pending_relink') &&
            $account['approved_for_apply'] === ($account['source_user_id'] === '4'
                ? '0' : '1') &&
            $person['google_sub'] === '' && $person['google_issuer'] === '' &&
            $person['oauth_linked_username'] === '', 'manual pending_relink');
        $planned = p4_plan_identity($person, ['accounts' => [], 'source_order' => []],
            [], p4_inventory_indexes([]));
        rc2_check($planned['action'] === ($account['source_user_id'] === '4'
                ? 'skip_identity_review' : 'create') &&
            $planned['desired_auth'] === 'manual',
            'plan materializa cuenta manual');
    }
    rc2_check($bykey[2]['canonical_id'] !== $bykey[3]['canonical_id'] &&
        $bycanonical[$bykey[2]['canonical_id']]['proposed_email'] === 'usuario2@ufps.edu.co' &&
        $bycanonical[$bykey[3]['canonical_id']]['proposed_email'] === 'usuario2@ufps.edu.co' &&
        str_ends_with($bycanonical[$bykey[4]['canonical_id']]['proposed_email'], '@example.invalid'),
        'correo compartido/inválido sin fusión');
    rc2_check($bycanonical[$bykey[5]['canonical_id']]['siteadmin_required'] === '1' &&
        count($role) === 1 && $role[0]['normalized_role'] === 'estudiante' &&
        $role[0]['approved_for_apply'] === '1', 'siteadmin y rol académico');

    $url = 'https://moodle.ufps.edu.co';
    rc2_check(oauth2_public_issues($url, $url, true, true,
        $url . '/admin/oauth2callback.php') === [], 'OAuth/proxy correcto');
    foreach ([
        ['http://moodle.ufps.edu.co', $url, true, true, $url . '/admin/oauth2callback.php'],
        [$url, 'https://otro.ufps.edu.co', true, true, $url . '/admin/oauth2callback.php'],
        [$url, $url, false, true, $url . '/admin/oauth2callback.php'],
        [$url, $url, true, false, $url . '/admin/oauth2callback.php'],
        [$url, $url, true, true, $url . '/callback-incorrecto.php'],
    ] as $bad) {
        rc2_check(oauth2_public_issues(...$bad) !== [], 'OAuth falla con proxy/URL incoherentes');
    }
    rc2_check(p6_resume_completed_restore($fixture['restore_cases']['resume']) &&
        p6_resume_completed_restore($fixture['restore_cases']['verification_failed']) &&
        !p6_resume_completed_restore('restore_failed'), 'resume/verificación fallida');
    $artifact = $temp . '/sealed.mbz';
    file_put_contents($artifact, 'contenido original');
    $hash = hash_file('sha256', $artifact);
    p6_assert_sealed_sha256($artifact, $hash);
    file_put_contents($artifact, 'contenido cambiado');
    try {
        p6_assert_sealed_sha256($artifact, $hash);
        throw new RuntimeException('RC2_CONTRACT_FAILED artefacto alterado aceptado');
    } catch (RuntimeException $e) {
        rc2_check(str_contains($e->getMessage(), 'artefacto sellado cambió'),
            'hash detecta edición con igual tamaño');
    }

    // Plugin HVP simulado con submodule H5P real: recorre commits y bytes.
    $root = $temp . '/plugins';
    $sub = $temp . '/h5p-source';
    mkdir($root . '/mod/hvp', 0770, true);
    mkdir($sub);
    rc2_git($sub, ['init', '-q']);
    file_put_contents($sub . '/library.txt', 'h5p-original');
    rc2_git($sub, ['add', '.']);
    rc2_git($sub, ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid',
        'commit', '-qm', 'h5p']);
    $hvp = $root . '/mod/hvp';
    rc2_git($hvp, ['init', '-q']);
    file_put_contents($hvp . '/version.php', '<?php $plugin->component = \'mod_hvp\'; ' .
        '$plugin->version = 2026091900; $plugin->release = \'1.0\';');
    rc2_git($hvp, ['-c', 'protocol.file.allow=always', 'submodule', 'add', '-q',
        $sub, 'h5p']);
    rc2_git($hvp, ['add', '.']);
    rc2_git($hvp, ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid',
        'commit', '-qm', 'hvp']);
    $pin = ['schema_version' => '1.0', 'plugins' => [[
        'component' => 'mod_hvp', 'path' => 'mod/hvp',
        'version' => 2026091900, 'release' => '1.0',
        'commit' => rc2_git($hvp, ['rev-parse', 'HEAD']),
        'tree_sha256' => pin_tree($hvp),
        'submodules' => [[
            'path' => 'h5p', 'commit' => rc2_git($sub, ['rev-parse', 'HEAD']),
            'tree_sha256' => pin_tree($hvp . '/h5p'),
        ]],
    ]]];
    rc2_check(count(pin_verify($root, $pin)) === 1, 'pin HVP/H5P recursivo');
    file_put_contents($hvp . '/h5p/library.txt', 'h5p-modificado');
    try {
        pin_verify($root, $pin);
        throw new RuntimeException('RC2_CONTRACT_FAILED submodule alterado aceptado');
    } catch (RuntimeException $e) {
        rc2_check(!str_contains($e->getMessage(), 'aceptado'), 'submodule alterado bloqueado');
    }
    echo "RC2_CONTRACTS_OK pending_relink=$count oauth=1 plugin_submodule=1 seal=1\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($temp);
}
