<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$GLOBALS['rc5pc_log'] = [];
$GLOBALS['rc5pc_user_issues'] = ['errors' => [], 'warnings' => ['user-warning']];
$GLOBALS['rc5pc_role_issues'] = ['errors' => [], 'warnings' => ['role-warning']];
$GLOBALS['rc5pc_question_issues'] = ['errors' => [], 'warnings' => ['question-warning']];
$GLOBALS['rc5pc_role_mapping_mutated'] = false;

class restore_controller_dbops {
    public static function create_restore_temp_tables(string $restoreid): void {
        $GLOBALS['rc5pc_log'][] = 'create:' . $restoreid;
    }
    public static function drop_restore_temp_tables(string $restoreid): void {
        $GLOBALS['rc5pc_log'][] = 'drop:' . $restoreid;
    }
}
class restore_dbops {
    public static function reset_backup_ids_cached(): void {
        $GLOBALS['rc5pc_log'][] = 'reset';
    }
    public static function load_inforef_to_tempids(
        string $restoreid, string $path, object $progress
    ): void {
        $relative = str_contains($path, '/activities/')
            ? 'activities/inforef.xml' : 'inforef.xml';
        $GLOBALS['rc5pc_log'][] = 'inforef:' . $relative;
    }
    public static function load_users_to_tempids(
        string $restoreid, string $path, object $progress
    ): void {
        $GLOBALS['rc5pc_log'][] = 'users';
    }
    public static function precheck_included_users(
        string $restoreid, int $courseid, int $userid, bool $samesite,
        object $progress
    ): array {
        $GLOBALS['rc5pc_log'][] = 'users_precheck';
        return $GLOBALS['rc5pc_user_issues'];
    }
    public static function load_roles_to_tempids(string $restoreid, string $path): void {
        $GLOBALS['rc5pc_log'][] = 'roles';
    }
    public static function precheck_included_roles(
        string $restoreid, int $courseid, int $userid, bool $samesite,
        object $mappings
    ): array {
        if (!property_exists($mappings, 'modified') ||
                !property_exists($mappings, 'mappings') ||
                !is_array($mappings->mappings)) {
            throw new RuntimeException('mock_role_mappings_invalid');
        }
        $mappings->mappings['editingteacher'] = 'editingteacher';
        $GLOBALS['rc5pc_role_mapping_mutated'] =
            ($mappings->mappings['editingteacher'] ?? null) === 'editingteacher';
        $GLOBALS['rc5pc_log'][] = 'roles_precheck';
        return $GLOBALS['rc5pc_role_issues'];
    }
    public static function load_questionbanks_to_tempids(
        string $restoreid, string $activitiespath
    ): void {
        $GLOBALS['rc5pc_log'][] = 'question_banks';
    }
    public static function load_categories_and_questions_to_tempids(
        string $restoreid, string $questionspath
    ): void {
        $GLOBALS['rc5pc_log'][] = 'questions';
    }
    public static function precheck_categories_and_questions(
        string $restoreid, int $courseid, int $userid, bool $samesite
    ): array {
        $GLOBALS['rc5pc_log'][] = 'questions_precheck';
        return $GLOBALS['rc5pc_question_issues'];
    }
}
class backup_general_helper {
    public static function backup_is_samesite(object $info): bool { return true; }
}
class rc5pc_manager {
    public function table_exists(string $table): bool { return false; }
}
class rc5pc_db {
    public function get_manager(): object { return new rc5pc_manager(); }
}
class rc5pc_plan {
    public function __construct(private string $basepath) {}
    public function get_basepath(): string { return $this->basepath; }
}
class rc5pc_controller {
    public function __construct(private string $basepath) {}
    public function get_restoreid(): string { return 'restore-rc5'; }
    public function get_plan(): object { return new rc5pc_plan($this->basepath); }
    public function get_progress(): object { return (object)[]; }
    public function get_info(): object {
        return (object)[
            'role_mappings' => (object)[
                'modified' => false,
                'mappings' => [],
            ],
        ];
    }
}

$DB = new rc5pc_db();
require_once(__DIR__ . '/../scripts/phase6-restore-precheck.php');

function rc5pc_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('V8_RC5_PRECHECK_FAILED ' . $message);
    }
}
function rc5pc_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) {
        rc5pc_remove($item->getPathname());
    }
    rmdir($path);
}

// A-C. Clasificación exacta de precheck normal, warnings y errores.
rc5pc_check(
    p6_restore_precheck_decision(true, ['errors' => [], 'warnings' => []])['mode'] ===
        'ready',
    'precheck OK no quedó ready'
);
$warningdecision = p6_restore_precheck_decision(false, [
    'errors' => [], 'warnings' => ['warning'],
]);
rc5pc_check($warningdecision['mode'] === 'rehydrate' &&
    $warningdecision['errors'] === 0 && $warningdecision['warnings'] === 1,
    'warnings sin errores no activaron rehidratación');
rc5pc_check(
    p6_restore_precheck_decision(false, [
        'errors' => ['fatal'], 'warnings' => ['warning'],
    ])['mode'] === 'block',
    'un error real no bloqueó'
);

$root = sys_get_temp_dir() . '/consolidador-v8-rc5-precheck-' .
    bin2hex(random_bytes(6));
mkdir($root . '/activities/quiz_1', 0770, true);
file_put_contents($root . '/inforef.xml', '<inforef/>');
file_put_contents($root . '/activities/quiz_1/inforef.xml', '<inforef/>');
file_put_contents($root . '/users.xml', '<users/>');
file_put_contents($root . '/roles.xml', '<roles/>');
file_put_contents($root . '/questions.xml', '<question_categories/>');

try {
    $GLOBALS['rc5pc_log'] = [];
    $result = p6_restore_rehydrate_tempids(
        new rc5pc_controller($root),
        55,
        2,
        static function(string $restoreid): void {
            $GLOBALS['rc5pc_log'][] = 'historical_mapping:' . $restoreid;
        }
    );
    $expected = [
        'create:restore-rc5',
        'reset',
        'inforef:activities/inforef.xml',
        'inforef:inforef.xml',
        'users',
        'users_precheck',
        'historical_mapping:restore-rc5',
        'roles',
        'roles_precheck',
        'question_banks',
        'questions',
        'questions_precheck',
    ];
    rc5pc_check($GLOBALS['rc5pc_log'] === $expected,
        'secuencia de rehidratación distinta: ' . implode(',', $GLOBALS['rc5pc_log']));
    rc5pc_check($result['inforef_files'] === 2 &&
        $result['user_warnings'] === 1 &&
        $result['role_warnings'] === 1 &&
        $result['question_warnings'] === 1,
        'no conservó las métricas de warnings');
    rc5pc_check($GLOBALS['rc5pc_role_mapping_mutated'] === true,
        'role_mappings no conservó el contrato mutable stdClass de Moodle');

    // Un contrato role_mappings distinto al esperado debe bloquear fail-closed.
    $invalidcontroller = new class($root) extends rc5pc_controller {
        public function get_info(): object {
            return (object)['role_mappings' => []];
        }
    };
    try {
        p6_restore_rehydrate_tempids(
            $invalidcontroller, 55, 2, static function(): void {}
        );
        throw new RuntimeException('expected_role_mapping_contract_block_missing');
    } catch (RuntimeException $error) {
        rc5pc_check(str_contains(
            $error->getMessage(),
            'MOODLE_PRECHECK_REHYDRATION_ROLE_MAPPINGS_INVALID'
        ), 'role_mappings inválido produjo: ' . $error->getMessage());
    }

    // D. Una excepción posterior provoca cleanup y reset de caché.
    try {
        throw new RuntimeException('fallo posterior controlado');
    } catch (RuntimeException $error) {
        p6_restore_drop_rehydrated_tempids('restore-rc5');
    }
    rc5pc_check(array_slice($GLOBALS['rc5pc_log'], -2) === [
        'drop:restore-rc5', 'reset',
    ], 'cleanup no fue exception-safe');

    // Los errores que aparecen durante la rehidratación siguen bloqueando.
    $GLOBALS['rc5pc_user_issues'] = ['errors' => ['user-error'], 'warnings' => []];
    try {
        p6_restore_rehydrate_tempids(
            new rc5pc_controller($root), 55, 2, static function(): void {}
        );
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc5pc_check(str_contains(
            $error->getMessage(),
            'MOODLE_PRECHECK_REHYDRATED_USER_ERRORS'
        ), 'error rehidratado produjo: ' . $error->getMessage());
    }

    // Caso B post-execute_plan: no puede reaparecer backup_ids_temp.
    $apply = (string)file_get_contents(__DIR__ . '/../scripts/phase6-apply-course.php');
    $execute = strpos($apply, '$controller->execute_plan();');
    rc5pc_check($execute !== false, 'execute_plan no fue encontrado');
    $afterexecute = substr($apply, $execute);
    rc5pc_check(!str_contains($afterexecute, 'get_backup_ids_record(') &&
        str_contains($afterexecute, '$persistHistoricalAudit(') &&
        str_contains($apply, 'MOODLE_PRECHECK_REHYDRATED'),
        'se consulta mapping temporal después de execute_plan o falta auditoría persistente');

    echo "V8_RC5_PRECHECK_OK ready=1 warnings=rehydrated errors=blocked cleanup=1 post_execute=persistent\n";
} finally {
    rc5pc_remove($root);
}
