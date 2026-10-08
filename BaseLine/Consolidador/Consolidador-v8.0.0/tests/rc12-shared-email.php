<?php
declare(strict_types=1);

namespace core\oauth2 {
    class issuer {}
}

namespace {
    define('MOODLE_INTERNAL', true);
    define('IGNORE_MISSING', 0);
    define('MUST_EXIST', 1);
    define('FORMAT_PLAIN', 0);
    $CFG = (object)['libdir' => __DIR__ . '/fixtures',
        'allowaccountssameemail' => 1, 'mnet_localhost_id' => 1];
    class core_text {
        public static function strtolower(string $value): string { return mb_strtolower($value); }
    }
    class core_user {
        public static function clean_field(string $value, string $field): string { return $value; }
    }
    function validate_email(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    function current_language(): string { return 'es'; }
    function random_string(int $length): string { return str_repeat('x', $length); }
    function set_user_preference(string $key, int $value, int $id): void {}
    function user_create_user(object $user, bool $password, bool $triggers): int {
        global $DB, $CFG;
        if ($CFG->allowaccountssameemail !== 1) {
            throw new \RuntimeException('Moodle rechazó correo duplicado');
        }
        return $DB->insert_record('user', $user);
    }
    function user_update_user(object $user, bool $password, bool $triggers): void {
        global $DB;
        $DB->update_record('user', $user);
    }
    require_once __DIR__ . '/../scripts/phase4-lib.php';

    class Rc12Db {
        public array $users = [];
        public array $profiles = [];
        public int $creates = 0;
        private int $next = 1000;
        public function get_records(string $table, array $criteria, string $sort = '', string $fields = '*'): array {
            $records = $table === 'user' ? $this->users : $this->profiles;
            return array_filter($records, static function (object $record) use ($criteria): bool {
                foreach ($criteria as $key => $value) {
                    if (($record->$key ?? null) != $value) { return false; }
                }
                return true;
            });
        }
        public function get_record(string $table, array $criteria, string $fields = '*', int $mode = 0): ?object {
            $rows = $this->get_records($table, $criteria);
            $record = $rows ? reset($rows) : null;
            if ($record === null && $mode === MUST_EXIST) {
                throw new \RuntimeException('Registro ausente');
            }
            return $record;
        }
        public function get_record_select(string $table, string $where, array $params,
                string $fields = '*', int $mode = 0): ?object {
            foreach ($this->users as $record) {
                if ($record->deleted == 0 && $record->email === $params['email'] &&
                        $record->id !== $params['userid']) { return $record; }
            }
            return null;
        }
        public function insert_record(string $table, object $record): int {
            $record = clone $record;
            $record->id = ++$this->next;
            if ($table === 'user') {
                $record->deleted = 0;
                $this->users[$record->id] = $record;
                $this->creates++;
            } else {
                $this->profiles[$record->id] = $record;
            }
            return $record->id;
        }
        public function update_record(string $table, object $update): void {
            $target = $table === 'user' ? $this->users[$update->id] : $this->profiles[$update->id];
            foreach (get_object_vars($update) as $key => $value) { $target->$key = $value; }
        }
        public function inventory(): array {
            $result = [];
            foreach ($this->users as $user) {
                $profile = [];
                foreach ($this->profiles as $item) {
                    if ($item->userid === $user->id) { $profile[$item->fieldid] = $item->data; }
                }
                $result[$user->id] = [
                    'id' => $user->id, 'username' => $user->username,
                    'email' => $user->email, 'firstname' => $user->firstname,
                    'lastname' => $user->lastname, 'idnumber' => $user->idnumber,
                    'auth' => $user->auth, 'canonical_id' => $profile[1] ?? '',
                    'google_issuer' => '', 'google_sub' => '',
                    'oauth_linked_username' => '', 'oauth_identifier_kind' => '',
                    'program_codes' => '',
                ];
            }
            return $result;
        }
    }
    function check12(bool $ok, string $label): void {
        if (!$ok) { throw new \RuntimeException('RC12_SHARED_EMAIL_FAILED ' . $label); }
    }
    $fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/rc12-shared-emails.json'),
        true, 512, JSON_THROW_ON_ERROR);
    $temp = sys_get_temp_dir() . '/rc12-shared-' . bin2hex(random_bytes(6));
    mkdir($temp);
    try {
        $users = [];
        for ($group = 1; $group <= $fixture['shared_email_values']; $group++) {
            for ($position = 1; $position <= $fixture['identities_per_value']; $position++) {
                $id = ($group - 1) * 2 + $position;
                $pending = $group === $fixture['pending_relink_pair'] && $position === 2;
                $oauth = $group === $fixture['oauth_manual_pair'] && $position === 1;
                $email = $group === 1 ? $fixture['real_case']['email'] :
                    'shared-' . $group . '@example.com';
                $username = $group === 1
                    ? $fixture['real_case']['identities'][$position - 1]['username']
                    : 'account.' . $id;
                $users[] = [
                    'source' => 'campus', 'source_user_id' => $id,
                    'username' => $username,
                    'email' => $email,
                    'auth' => $pending || $oauth ? 'oauth2' : 'manual',
                    'firstname' => 'Persona', 'lastname' => (string)$id,
                    'google_issuer' => $oauth ? 'https://accounts.google.com' : '',
                    'google_sub' => $oauth ? 'stable-sub-7' : '',
                    'google_sub_verified' => $oauth, 'oauth_identifier_kind' =>
                        $oauth ? 'sub' : 'unknown',
                    'oauth_linked_username' => $oauth ? 'stable-sub-7' : '',
                    'oauth_links' => [],
                ];
            }
        }
        $input = $temp . '/identity-campus.json';
        file_put_contents($input, json_encode(['metadata' => [
            'source' => 'campus', 'schema_version' => '1.2',
            'google_sub_policy' => 'verified_only'], 'users' => $users,
            'roles' => [], 'enrolments' => [], 'role_catalog' => []], JSON_THROW_ON_ERROR));
        $command = 'php ' . escapeshellarg(__DIR__ . '/../scripts/reconcile-identities.php') .
            ' --input=' . escapeshellarg($temp) . ' --output=' . escapeshellarg($temp) .
            ' --sources=campus --targetid=destino --targetname=Destino' .
            ' --confighash=' . str_repeat('a', 64) .
            ' --resolutions=' . escapeshellarg(__DIR__ . '/../config/identity_resolutions.csv') .
            ' --identitypolicy=' . escapeshellarg(__DIR__ . '/../config/identity-policy.json');
        $log = [];
        exec($command . ' 2>&1', $log, $exit);
        check12($exit === 0, 'fase3 ' . implode(' | ', $log));
        $phase3 = p4_load_phase3($temp, str_repeat('a', 64), 'destino', false);
        $summary = $phase3['summary'];
        $audit = p4_read_csv($temp . '/shared_email_audit.csv');
        check12(count($phase3['canonical']) === 154 &&
            (int)$summary['shared_email_values'] === 77 &&
            (int)$summary['shared_email_identities'] === 154 &&
            count($audit) === 154 &&
            (int)$summary['phase4_expected']['applicable_identities'] === 154,
            '77 direcciones, 154 identidades independientes');
        $ids = array_column($phase3['source_map'], 'canonical_id', 'source_user_id');
        check12($ids[1] !== $ids[2] && $ids[3] !== $ids[4],
            'no fusiona correo compartido del mismo origen');
        check12($ids[1] === $fixture['real_case']['identities'][0]['canonical_id'] &&
            $ids[2] === $fixture['real_case']['identities'][1]['canonical_id'] &&
            $ids[7] !== $ids[8], 'caso real y OAuth + manual son independientes');
        $pending = array_values(array_filter($phase3['canonical'], static fn(array $row): bool =>
            $row['identity_method'] === 'pending_relink'));
        check12(count($pending) === 1 && $pending[0]['google_sub'] === '' &&
            $pending[0]['proposed_email'] === 'shared-2@example.com',
            'OAuth sin sub permanece cuenta manual pending_relink con correo real');

        $DB = new Rc12Db();
        $existing = (object)['username' => 'legacy.other', 'email' => $fixture['existing_target_email'],
            'firstname' => 'Previo', 'lastname' => 'Destino', 'idnumber' => '',
            'auth' => 'manual', 'mnethostid' => 1];
        $legacyId = $DB->insert_record('user', $existing);
        foreach (['legacy.shared.one', 'legacy.shared.two'] as $username) {
            $duplicate = clone $existing;
            $duplicate->username = $username;
            $duplicate->email = 'older-shared@example.com';
            $DB->insert_record('user', $duplicate);
        }
        $DB->creates = 0;
        $initial = $DB->inventory();
        $plan = p4_build_plan($phase3, $initial);
        $stats = p4_plan_group_stats($plan);
        check12($stats === ['duplicate_username_values' => 0, 'shared_email_values' => 77,
                'shared_email_identities' => 154] &&
            count(array_filter($plan, static fn(array $row): bool => $row['action'] === 'create')) === 154,
            'plan crea 154 sin adoptar cuenta existente por correo');
        $preexisting = p4_target_shared_email_audit($initial);
        check12(count($preexisting) === 2 &&
            $preexisting[0]['email'] === 'older-shared@example.com',
            'auditoría de duplicados ya existentes en destino');
        $bundle = ['summary' => ['target_allows_shared_email' => true,
            'shared_email_values' => 77, 'shared_email_identities' => 154],
            'phase3' => $phase3, 'rows' => $plan];
        p4_preflight_plan($bundle, $initial);
        $CFG->allowaccountssameemail = 0;
        try {
            p4_preflight_plan($bundle, $initial);
            throw new \RuntimeException('Política desactivada aceptada');
        } catch (\RuntimeException $error) {
            check12(str_contains($error->getMessage(), 'SHARED_EMAIL_TARGET_POLICY_BLOCKED') &&
                $DB->creates === 0, 'cambio de política bloquea antes de crear usuarios');
        }
        $CFG->allowaccountssameemail = 1;
        $pair = array_values(array_filter($plan, static fn(array $row): bool =>
            $row['target_email'] === 'josefranciscocc@ufps.edu.co'));
        check12(count($pair) === 2, 'dos canonical IDs en el mismo correo');
        $fields = ['migration_canonical_id' => 1, 'migration_identity_status' => 2,
            'google_issuer' => 3, 'google_sub' => 4,
            'oauth_linked_username' => 5, 'oauth_identifier_kind' => 6, 'program_codes' => 7];
        $issuer = new \core\oauth2\issuer();
        $first = p4_apply_plan_row($pair[0], $fields, $issuer);
        check12($first['apply_status'] === 'created' && $DB->creates === 1,
            'interrupción después de la primera cuenta');
        p4_preflight_plan($bundle, $DB->inventory());
        $second = p4_apply_plan_row($pair[1], $fields, $issuer);
        $retry = p4_apply_plan_row($pair[0], $fields, $issuer);
        p4_preflight_plan($bundle, $DB->inventory());
        check12($second['apply_status'] === 'created' &&
            $retry['apply_status'] === 'already_applied' && $DB->creates === 2 &&
            $first['target_user_id'] !== $second['target_user_id'] &&
            $second['target_user_id'] !== $legacyId &&
            $first['target_username'] !== $second['target_username'] &&
            count(p4_target_shared_email_audit($DB->inventory())) === 5,
            'reintento preserva dos usuarios distintos y el anterior');
        $duplicate = $phase3;
        $duplicate['canonical'][1]['canonical_username'] =
            $duplicate['canonical'][0]['canonical_username'];
        $badPlan = p4_build_plan($duplicate, $initial);
        check12(p4_plan_group_stats($badPlan)['duplicate_username_values'] === 1 &&
            count(array_filter($badPlan, static fn(array $row): bool =>
                $row['action'] === 'conflict_duplicate_planned_username')) === 2,
            'username duplicado bloquea antes de Apply');
        $CFG->allowaccountssameemail = 0;
        try {
            p4_assert_shared_email_policy(['target_allows_shared_email' => true,
                'shared_email_values' => 0, 'shared_email_identities' => 0], 'empty_lot');
            throw new \RuntimeException('Lote vacío aceptó política incompatible');
        } catch (\RuntimeException $error) {
            check12(str_contains($error->getMessage(), 'SHARED_EMAIL_TARGET_POLICY_BLOCKED'),
                'política obligatoria aun sin emails compartidos');
        }
        $disabled = p4_build_plan($phase3, $initial);
        check12(count(array_filter($disabled, static fn(array $row): bool =>
            $row['action'] === 'conflict_email_collision')) >= 2,
            'plan sin política bloquea correo previo');
        echo "RC12_SHARED_EMAIL_OK values=77 identities=154 partial_retry=2 policy_flip=blocked\n";
    } finally {
        exec('rm -rf -- ' . escapeshellarg($temp));
    }
}
