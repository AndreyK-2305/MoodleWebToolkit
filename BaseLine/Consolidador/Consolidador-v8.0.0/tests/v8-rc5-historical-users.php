<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
define('IGNORE_MISSING', 0);

class rc5hu_manager {
    public function table_exists(string $table): bool {
        return $table === 'auth_oauth2_linked_login';
    }
}
class rc5hu_db {
    public array $users = [];
    public bool $linked = false;
    public string $googleSub = '';
    public string $migrationStatus = '';
    public array $updates = [];

    public function __construct(array $users) {
        foreach ($users as $user) {
            $this->users[(int)$user->id] = clone $user;
        }
    }
    public function get_manager(): object { return new rc5hu_manager(); }
    public function get_records(
        string $table, array $conditions, string $sort, string $fields
    ): array {
        if ($table !== 'user') { return []; }
        $matches = [];
        foreach ($this->users as $id => $record) {
            foreach ($conditions as $field => $value) {
                if (($record->{$field} ?? null) != $value) { continue 2; }
            }
            $matches[$id] = clone $record;
        }
        ksort($matches, SORT_NUMERIC);
        return $matches;
    }
    public function get_record(
        string $table, array $conditions, string $fields = '*', int $strictness = 0
    ): object|false {
        if ($table === 'user') {
            $record = $this->users[(int)($conditions['id'] ?? 0)] ?? null;
            return $record ? clone $record : false;
        }
        if ($table === 'user_info_field') {
            return match ((string)($conditions['shortname'] ?? '')) {
                'google_sub' => (object)['id' => 11],
                'migration_identity_status' => (object)['id' => 12],
                default => false,
            };
        }
        return false;
    }
    public function record_exists(string $table, array $conditions): bool {
        return $table === 'auth_oauth2_linked_login' && $this->linked;
    }
    public function get_field(string $table, string $field, array $conditions): mixed {
        if ($table !== 'user_info_data') { return false; }
        return match ((int)($conditions['fieldid'] ?? 0)) {
            11 => $this->googleSub,
            12 => $this->migrationStatus,
            default => false,
        };
    }
    public function update_record(string $table, object $record): void {
        if ($table !== 'user' || !isset($this->users[(int)$record->id])) {
            throw new RuntimeException('unexpected_update');
        }
        foreach (get_object_vars($record) as $field => $value) {
            $this->users[(int)$record->id]->{$field} = $value;
        }
        $this->updates[] = clone $record;
    }
}

require_once(__DIR__ . '/../scripts/phase6-historical-users.php');

function rc5hu_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('V8_RC5_HISTORICAL_FAILED ' . $message);
    }
}
function rc5hu_block(callable $action, string $needle): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc5hu_check(str_contains($error->getMessage(), $needle),
            'bloqueo inesperado: ' . $error->getMessage());
    }
}
function rc5hu_user(
    int $id = 7947,
    string $username = 'deleted-28',
    string $email = 'deleted-28@example.org',
    int $deleted = 1,
    string $auth = 'manual',
    int $mnethostid = 1
): object {
    return (object)compact('id', 'username', 'email', 'deleted', 'auth', 'mnethostid');
}
function rc5hu_classification(): array {
    return [
        'source' => 'posgrados-2025-05-02-directo',
        'users' => [[
            'source_user_id' => 28,
            'source_username' => 'deleted-28',
            'source_email' => 'deleted-28@example.org',
            'deleted' => true,
            'classification' => 'historical_deleted',
            'target_user_id' => null,
            'canonical_id' => '',
        ]],
    ];
}
function rc5hu_resolve(rc5hu_db $db, array $globalmap = [], int $expected = 7947): array {
    return p6_historical_resolve_persistent_target(
        $db,
        (object)['mnet_localhost_id' => 1],
        rc5hu_classification(),
        'posgrados-2025-05-02-directo',
        28,
        $globalmap,
        $expected,
        true
    );
}

// E/F. Resolución persistente y adopción exacta sin backup_ids_temp.
$db = new rc5hu_db([rc5hu_user()]);
$resolved = rc5hu_resolve($db);
rc5hu_check($resolved['target_user_id'] === 7947 &&
    $resolved['resolution'] === 'persistent_deleted_user_fingerprint' &&
    $resolved['auth_normalized'] === false,
    'no adoptó el histórico exacto');

// G-I. El fingerprint incompleto o una cuenta activa nunca se adopta.
foreach ([
    rc5hu_user(7947, 'other-user'),
    rc5hu_user(7947, 'deleted-28', 'other@example.org'),
    rc5hu_user(7947, 'deleted-28', 'deleted-28@example.org', 0),
] as $invalid) {
    rc5hu_block(
        static fn() => rc5hu_resolve(new rc5hu_db([$invalid])),
        'historical_user_duplicated'
    );
}

// J-L. OAuth linked login, google_sub y pending_relink permanecen bloqueantes.
$db = new rc5hu_db([rc5hu_user()]);
$db->linked = true;
rc5hu_block(static fn() => rc5hu_resolve($db), 'historical_user_reactivated');
$db = new rc5hu_db([rc5hu_user()]);
$db->googleSub = 'google-sub-forbidden';
rc5hu_block(static fn() => rc5hu_resolve($db), 'historical_user_reactivated');
$db = new rc5hu_db([rc5hu_user()]);
$db->migrationStatus = 'pending_relink';
rc5hu_block(static fn() => rc5hu_resolve($db), 'historical_user_reactivated');

// M. Una identidad Phase4 incompatible nunca puede fusionarse.
$db = new rc5hu_db([rc5hu_user()]);
rc5hu_block(static fn() => rc5hu_resolve($db, [
    'posgrados-2025-05-02-directo:28' => ['target_user_id' => 7947],
]), 'conflicto con Phase4');

// N. oauth2 solo se normaliza después del fingerprint estricto deleted/local.
$db = new rc5hu_db([rc5hu_user(7947, 'deleted-28',
    'deleted-28@example.org', 1, 'oauth2')]);
$resolved = rc5hu_resolve($db);
rc5hu_check($resolved['auth_normalized'] === true &&
    $resolved['target_user']['auth'] === 'manual' &&
    count($db->updates) === 1 && $db->updates[0]->auth === 'manual',
    'oauth2 histórico no fue normalizado de forma controlada');

// O. Recovery finalizing_interrupted reconstruye registry y auditoría validada.
$registry = p6_historical_registry_update(
    null,
    'posgrados-2025-05-02-directo',
    28,
    7947,
    'COURSE-POSGRADOS-ABCDEF123456',
    'persistent_deleted_user_fingerprint'
);
$audit = p6_historical_validated_audit(
    'COURSE-POSGRADOS-ABCDEF123456',
    'posgrados-2025-05-02-directo',
    321,
    [[
        'source_user_id' => 28,
        'target_user_id' => 7947,
        'resolution' => 'persistent_deleted_user_fingerprint',
    ]],
    true
);
rc5hu_check($registry['target_user_id'] === 7947 &&
    $audit['resolution_state'] === 'validated' &&
    $audit['recovered_after_interruption'] === true,
    'recovery no produjo evidencia persistente validada');
$apply = (string)file_get_contents(__DIR__ . '/../scripts/phase6-apply-course.php');
rc5hu_check(str_contains($apply, "stage = 'finalizing_interrupted'") &&
    str_contains($apply, 'HISTORICAL_AUDIT_RECOVERED') &&
    str_contains($apply, '$persistHistoricalAudit('),
    'la ruta finalizing_interrupted no enlaza la recuperación histórica');

// P. Recuperación ambigua: fingerprint o auditoría duplicados bloquean.
rc5hu_block(static fn() => rc5hu_resolve(new rc5hu_db([
    rc5hu_user(7947), rc5hu_user(7948),
]), [], 0), 'fingerprint persistente no es único');
rc5hu_block(static fn() => p6_historical_validated_audit(
    'COURSE-POSGRADOS-ABCDEF123456',
    'posgrados-2025-05-02-directo',
    321,
    [
        ['source_user_id' => 28, 'target_user_id' => 7947],
        ['source_user_id' => 29, 'target_user_id' => 7947],
    ],
    true
), 'resolución histórica ambigua');

echo "V8_RC5_HISTORICAL_USERS_OK persistent=1 adoption=strict oauth2=manual recovery=validated ambiguity=blocked\n";
