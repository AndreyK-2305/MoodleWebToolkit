<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
}
class core_user {
    public static function clean_field(string $value, string $field): string { return $value; }
}
function validate_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
require_once __DIR__ . '/../scripts/phase4-lib.php';

function rc11_assert(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException('RC11_EMAIL_FAILED: ' . $message); }
}
function rc11_user(string $source, int $id, string $username, string $email,
        string $auth = 'manual', int $modified = 1): array {
    return [
        'source' => $source, 'source_user_id' => $id, 'username' => $username,
        'auth' => $auth, 'email' => $email, 'firstname' => 'Persona',
        'lastname' => (string)$id, 'timemodified' => $modified,
        'google_issuer' => '', 'google_sub' => '', 'google_sub_verified' => false,
        'oauth_identifier_kind' => 'unknown', 'oauth_linked_username' => '',
        'oauth_links' => [], 'is_site_admin' => $id === 1,
    ];
}

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/rc11-emails.json'),
    true, 512, JSON_THROW_ON_ERROR);
$temp = sys_get_temp_dir() . '/rc11-email-' . bin2hex(random_bytes(6));
mkdir($temp . '/input', 0770, true);
try {
    $alpha = [];
    $beta = [];
    $expected = [];
    $id = 1;
    foreach ($fixture['benchmark'] as $username => [$original, $normalized]) {
        $alpha[] = rc11_user('alpha', $id++, (string)$username, $original);
        $expected[(string)$username] = $normalized;
    }
    $alpha[] = rc11_user('alpha', $id++, 'valid', $fixture['valid']);
    $alpha[] = rc11_user('alpha', $id++, 'outer_spaces', $fixture['outer_spaces']);
    $alpha[] = rc11_user('alpha', $id++, 'impossible', $fixture['unrecoverable']);
    $alpha[] = rc11_user('alpha', $id++, 'accent_collision', $fixture['collision'][0]);
    $alpha[] = rc11_user('alpha', $id++, 'valid_collision', $fixture['collision'][1]);
    $alpha[] = rc11_user('alpha', $id++, 'oauth_pending', $fixture['pending_relink'][0], 'oauth2');
    $alpha[] = rc11_user('alpha', $id++, 'shared_a', $fixture['shared']);
    $alpha[] = rc11_user('alpha', $id++, 'shared_b', $fixture['shared']);
    $alpha[] = rc11_user('alpha', $id++, 'multi_source', 'earlier@example.com', 'manual', 10);
    $beta[] = rc11_user('beta', 1, 'multi_source', 'más.tarde@example.com', 'manual', 30);
    $roles = [[
        'source' => 'alpha', 'source_user_id' => 1, 'context_level' => 'course',
        'context_key' => 'curso', 'context_name' => 'Historia académica',
        'source_role_id' => 5, 'role_shortname' => 'student',
        'role_name' => 'Estudiante', 'role_archetype' => 'student',
    ]];
    foreach (['alpha' => [$alpha, $roles], 'beta' => [$beta, []]] as $source => [$users, $sourceRoles]) {
        file_put_contents($temp . '/input/identity-' . $source . '.json', json_encode([
            'metadata' => ['source' => $source, 'schema_version' => '1.2',
                'google_sub_policy' => 'verified_only'],
            'users' => $users, 'roles' => $sourceRoles, 'enrolments' => [],
            'role_catalog' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    $sourceHashes = [];
    foreach (['alpha', 'beta'] as $source) {
        $sourceHashes[$source] = hash_file('sha256', $temp . '/input/identity-' . $source . '.json');
    }
    $configsha = str_repeat('a', 64);
    $outputs = [];
    for ($run = 1; $run <= 2; $run++) {
        $out = $temp . '/output' . $run;
        mkdir($out);
        foreach (['alpha', 'beta'] as $source) {
            copy($temp . '/input/identity-' . $source . '.json',
                $out . '/identity-' . $source . '.json');
        }
        $command = 'php ' .
            escapeshellarg(__DIR__ . '/../scripts/reconcile-identities.php') .
            ' --input=' . escapeshellarg($temp . '/input') .
            ' --output=' . escapeshellarg($out) .
            ' --sources=alpha,beta --targetid=destino --targetname=Destino' .
            ' --confighash=' . $configsha .
            ' --resolutions=' . escapeshellarg(__DIR__ . '/../config/identity_resolutions.csv') .
            ' --identitypolicy=' . escapeshellarg(__DIR__ . '/../config/identity-policy.json');
        exec($command . ' 2>&1', $log, $status);
        rc11_assert($status === 0, 'Fase 3: ' . implode("\n", $log));
        rc11_assert(str_contains(implode("\n", $log), 'EMAIL_NORMALIZED_WARNING'),
            'advertencia de normalización visible');
        $outputs[] = $out;
    }
    foreach (['canonical_users.csv', 'source_user_map.csv', 'identity_conflicts.csv',
              'email_normalization_audit.csv'] as $name) {
        rc11_assert(hash_file('sha256', $outputs[0] . '/' . $name) ===
            hash_file('sha256', $outputs[1] . '/' . $name), 'reintento cambia ' . $name);
    }
    $phase3 = p4_load_phase3($outputs[0], $configsha, 'destino', false);
    $summary = $phase3['summary'];
    $audit = p4_read_csv($outputs[0] . '/email_normalization_audit.csv');
    $canonical = $phase3['canonical'];
    $maps = array_column($phase3['source_map'], null, 'source_username');
    $byid = array_column($canonical, null, 'canonical_id');
    rc11_assert(count($canonical) === 14 && (int)$summary['email_normalized'] === 8 &&
        (int)$summary['email_normalization_blocked'] === 3 &&
        count($audit) === 10, 'conteos canónicos, warnings y bloqueos');
    foreach ($expected as $username => $email) {
        $person = $byid[$maps[$username]['canonical_id']];
        $unchangedid = 'CAN-' . strtoupper(substr(hash(
            'sha256', 'manual-username|' . $username
        ), 0, 12));
        $matching = array_values(array_filter($audit, static fn(array $row): bool =>
            $row['canonical_id'] === $person['canonical_id']));
        rc11_assert($person['canonical_id'] === $unchangedid &&
            $person['proposed_email'] === $email &&
            $person['approved_for_apply'] === '1' &&
            count($matching) === 1 && $matching[0]['status'] === 'warning' &&
            $matching[0]['source_email'] === $fixture['benchmark'][$username][0] &&
            $matching[0]['normalized_email'] === $email,
            'cinco direcciones reales, conservación y auditoría: ' . $username);
        $planned = p4_plan_identity($person, $phase3, [], p4_inventory_indexes([]));
        rc11_assert($planned['action'] === 'create' &&
            $planned['target_email'] === $email, 'Fase 4 saneada: ' . $username);
        $corrupt = $person;
        $corrupt['proposed_email'] = 'invalid..address@example.com';
        rc11_assert(p4_plan_identity($corrupt, $phase3, [], p4_inventory_indexes([]))['action'] ===
            'conflict_invalid_email', 'Fase 4 rechaza artefacto corrupto');
    }
    rc11_assert($byid[$maps['valid']['canonical_id']]['proposed_email'] === $fixture['valid'] &&
        count(array_filter($audit, static fn(array $row): bool =>
            $row['canonical_id'] === $maps['valid']['canonical_id'])) === 0,
        'correo válido intacto y sin auditoría');
    $trimmed = array_values(array_filter($audit, static fn(array $row): bool =>
        $row['canonical_id'] === $maps['outer_spaces']['canonical_id']));
    rc11_assert(count($trimmed) === 1 &&
        $trimmed[0]['normalization_rule'] === 'trim' &&
        $trimmed[0]['normalized_email'] === 'spaces@example.com',
        'espacios externos quedan advertidos');
    foreach (['impossible', 'accent_collision', 'valid_collision'] as $username) {
        $person = $byid[$maps[$username]['canonical_id']];
        rc11_assert($person['decision'] === 'manual_review' &&
            $person['approved_for_apply'] === '0' &&
            p4_plan_identity($person, $phase3, [], p4_inventory_indexes([]))['action'] ===
                'skip_identity_review', 'correo irrecuperable o colisión: ' . $username);
    }
    foreach ($canonical as $person) {
        if ($person['approved_for_apply'] === '1') {
            rc11_assert(p4_plan_identity($person, $phase3, [], p4_inventory_indexes([]))['action'] !==
                'conflict_invalid_email', 'ninguna identidad aprobada entrega correo inválido');
        }
    }
    rc11_assert($maps['accent_collision']['canonical_id'] !==
        $maps['valid_collision']['canonical_id'] &&
        count(array_filter($audit, static fn(array $row): bool =>
            $row['reason'] === 'normalization_email_collision')) === 1,
        'colisión no fusiona identidades');
    $pending = $byid[$maps['oauth_pending']['canonical_id']];
    rc11_assert($pending['proposed_email'] === $fixture['pending_relink'][1] &&
        $pending['identity_method'] === 'pending_relink' &&
        $pending['google_sub'] === '' && $pending['oauth_linked_username'] === '' &&
        $pending['approved_for_apply'] === '1', 'pending_relink no adquiere OAuth');
    rc11_assert($maps['shared_a']['canonical_id'] !== $maps['shared_b']['canonical_id'] &&
        $byid[$maps['shared_a']['canonical_id']]['approved_for_apply'] === '1' &&
        $byid[$maps['shared_b']['canonical_id']]['approved_for_apply'] === '1',
        'correo compartido no identifica ni fusiona');
    $multi = $byid[$maps['multi_source']['canonical_id']];
    rc11_assert($multi['source_account_count'] === '2' &&
        $multi['proposed_email'] === 'mas.tarde@example.com' &&
        $multi['approved_for_apply'] === '1', 'fallback de varias cuentas saneado');
    rc11_assert(count(array_filter($phase3['source_map'], static fn(array $row): bool =>
        $row['canonical_id'] === $multi['canonical_id'])) === 2,
        'las dos cuentas permanecen mapeadas');
    rc11_assert(count($phase3['accounts']) === 15 &&
        hash_file('sha256', $temp . '/input/identity-alpha.json') === $sourceHashes['alpha'] &&
        hash_file('sha256', $temp . '/input/identity-beta.json') === $sourceHashes['beta'],
        'no se alteran inventarios de origen');
    // Un segundo inventario con los correos ya saneados debe producir el
    // mismo valor técnico sin normalizaciones adicionales para esas cuentas.
    $again = $temp . '/already-normalized';
    mkdir($again);
    foreach (['alpha', 'beta'] as $source) {
        $data = json_decode((string)file_get_contents(
            $temp . '/input/identity-' . $source . '.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['users'] as &$user) {
            if (isset($expected[$user['username']])) {
                $user['email'] = $expected[$user['username']];
            } else if ($user['username'] === 'outer_spaces') {
                $user['email'] = 'spaces@example.com';
            } else if ($user['username'] === 'oauth_pending') {
                $user['email'] = $fixture['pending_relink'][1];
            } else if ($user['username'] === 'multi_source' && $source === 'beta') {
                $user['email'] = 'mas.tarde@example.com';
            }
        }
        unset($user);
        file_put_contents($again . '/identity-' . $source . '.json',
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    $command = 'php ' . escapeshellarg(__DIR__ . '/../scripts/reconcile-identities.php') .
        ' --input=' . escapeshellarg($again) . ' --output=' . escapeshellarg($again) .
        ' --sources=alpha,beta --targetid=destino --targetname=Destino' .
        ' --confighash=' . $configsha .
        ' --resolutions=' . escapeshellarg(__DIR__ . '/../config/identity_resolutions.csv') .
        ' --identitypolicy=' . escapeshellarg(__DIR__ . '/../config/identity-policy.json');
    $log = [];
    exec($command . ' 2>&1', $log, $status);
    rc11_assert($status === 0, 'segunda aplicación del saneamiento');
    $secondsummary = json_decode((string)file_get_contents($again . '/summary.json'), true);
    rc11_assert((int)$secondsummary['email_normalized'] === 0 &&
        count(rc11_audit_warnings($again . '/email_normalization_audit.csv')) === 0,
        'normalización idempotente');
    $secondcanon = array_column(p4_read_csv($again . '/canonical_users.csv'), null, 'canonical_id');
    $secondphase3 = p4_load_phase3($again, $configsha, 'destino', false);
    foreach ($canonical as $firstrow) {
        $other = $secondcanon[$firstrow['canonical_id']] ?? null;
        rc11_assert($other !== null &&
            p4_desired_identity($other, $secondphase3)['email'] ===
            p4_desired_identity($firstrow, $phase3)['email'],
            'idempotencia del correo seleccionado y canonical_id');
    }
    $role = p4_read_csv($outputs[0] . '/role_assignments.csv');
    rc11_assert($byid[$maps['1090226722']['canonical_id']]['siteadmin_required'] === '1' &&
        count($role) === 1 && $role[0]['approved_for_apply'] === '1',
        'siteadmin y rol académico conservados');
    $auditpath = $outputs[0] . '/email_normalization_audit.csv';
    file_put_contents($auditpath, 'alterado', FILE_APPEND);
    try {
        p4_load_phase3($outputs[0], $configsha, 'destino', false);
        throw new RuntimeException('RC11_EMAIL_FAILED: auditoría alterada aceptada');
    } catch (RuntimeException $e) {
        rc11_assert(str_contains($e->getMessage(), 'EMAIL_NORMALIZATION_AUDIT_INVALID'),
            'sello de auditoría');
    }
    echo "RC11_EMAIL_OK benchmark=5 normalized=8 blocked=3 collision=1 pending=1 retry=stable\n";
} finally {
    exec('rm -rf -- ' . escapeshellarg($temp));
}

function rc11_audit_warnings(string $path): array {
    return array_filter(p4_read_csv($path), static fn(array $row): bool =>
        $row['status'] === 'warning');
}
