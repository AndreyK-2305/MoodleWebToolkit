<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
    public static function strlen(string $value): int { return mb_strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-effective-inventory.php');

function rc5qa_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('V8_RC5_QUIZ_ATTEMPTS_FAILED ' . $message);
    }
}

function rc5qa_evidence(): array {
    return [
        'primary_legacy_key' => 0,
        'historical_name_alias' => 0,
        'structural_fallback' => 0,
        'structural_projection' => 0,
        'structural_projection_relations' => [],
        'aliases_blocked' => 0,
        'resolutions' => [],
        'blocked' => [],
    ];
}

function rc5qa_row(
    int $userid,
    string $activitykey,
    string $state = 'finished',
    ?float $sumgrades = 8.25,
    int $attemptid = 9001,
    int $preview = 0
): array {
    return [
        'source_user_id' => $userid,
        'activity_key' => $activitykey,
        'quiz_id' => 501,
        'source_module_id' => 101,
        'state' => $state,
        'sumgrades' => $sumgrades,
        'attempt' => 1,
        'timestart' => 100,
        'timefinish' => 200,
        'preview' => $preview,
        'attempt_id' => $attemptid,
    ];
}

function rc5qa_candidate(
    int $userid,
    int $moduleid = 101,
    string $activitykey = 'quiz|quiz-a',
    string $state = 'finished',
    ?float $sumgrades = 8.25,
    int $attemptid = 9001,
    int $preview = 0
): array {
    return [
        '_source_module_id' => $moduleid,
        'source_user_id' => $userid,
        'activity_key' => $activitykey,
        'quiz_id' => 500 + $moduleid,
        'source_module_id' => $moduleid,
        'state' => $state,
        'sumgrades' => $sumgrades,
        'attempt' => 1,
        'timestart' => 100,
        'timefinish' => 200,
        'preview' => $preview,
        'attempt_id' => $attemptid,
    ];
}

function rc5qa_project(array $rows, array $candidates, ?array &$evidence = null): array {
    $modules = [
        101 => ['module_key' => 'quiz|quiz-a', 'modname' => 'quiz'],
        102 => ['module_key' => 'quiz|quiz-b', 'modname' => 'quiz'],
    ];
    $primary = [
        'quiz|quiz-a' => [101],
        'quiz|quiz-b' => [102],
    ];
    $names = $primary;
    $evidence = rc5qa_evidence();
    return p6_quiz_attempt_legacy_projection(
        $rows,
        $candidates,
        $modules,
        $primary,
        $names,
        $evidence
    );
}

function rc5qa_block(callable $action, string $needle): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc5qa_check(
            str_contains($error->getMessage(), $needle),
            'bloqueo inesperado: ' . $error->getMessage()
        );
    }
}

function rc5qa_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) {
        rc5qa_remove($item->getPathname());
    }
    rmdir($path);
}

// A. Inventario y MBZ idénticos.
$evidence = null;
$result = rc5qa_project(
    [rc5qa_row(7, 'quiz|quiz-a')],
    [rc5qa_candidate(7)],
    $evidence
);
rc5qa_check(count($result) === 1 &&
    $evidence['quiz_attempt_projection']['status'] === 'matched' &&
    $evidence['quiz_attempt_projection']['excluded_legacy_rows'] === 0,
    'la población idéntica no quedó matched');

// B. Una fila legacy adicional no transportada, incluido el 1 -> 0 real.
$evidence = null;
$result = rc5qa_project(
    [rc5qa_row(246, 'quiz|quiz-a', 'inprogress', null, 9100, 1)],
    [],
    $evidence
);
rc5qa_check($result === [] &&
    $evidence['quiz_attempt_projection']['inventory_rows'] === 1 &&
    $evidence['quiz_attempt_projection']['backup_rows'] === 0 &&
    $evidence['quiz_attempt_projection']['effective_rows'] === 0 &&
    $evidence['quiz_attempt_projection']['excluded_legacy_rows'] === 1 &&
    $evidence['quiz_attempt_projection']['status'] === 'projected_from_transport',
    'el caso legacy 1 -> 0 no fue proyectado');

// C. Varias filas adicionales: se conserva exactamente el multiconjunto MBZ.
$evidence = null;
$result = rc5qa_project([
    rc5qa_row(7, 'quiz|quiz-a', 'finished', 8.25, 9001),
    rc5qa_row(7, 'quiz|quiz-a', 'finished', 8.25, 9002, 1),
    rc5qa_row(8, 'quiz|quiz-a', 'inprogress', null, 9003, 1),
], [
    rc5qa_candidate(7, 101, 'quiz|quiz-a', 'finished', 8.25, 9001),
], $evidence);
rc5qa_check(count($result) === 1 &&
    $evidence['quiz_attempt_projection']['excluded_legacy_rows'] === 2,
    'no excluyó únicamente las filas legacy no transportadas');

// D. El MBZ nunca puede contener más filas que el inventario explique.
rc5qa_block(static function(): void {
    $evidence = null;
    rc5qa_project(
        [rc5qa_row(7, 'quiz|quiz-a')],
        [rc5qa_candidate(7), rc5qa_candidate(7, 101, 'quiz|quiz-a',
            'finished', 8.25, 9002)],
        $evidence
    );
}, 'QUIZ_ATTEMPT_TRANSPORT_UNEXPLAINED');

// E. Un activity_key distinto forma otra firma y debe bloquear.
rc5qa_block(static function(): void {
    $evidence = null;
    rc5qa_project(
        [rc5qa_row(7, 'quiz|quiz-a')],
        [rc5qa_candidate(7, 102, 'quiz|quiz-b')],
        $evidence
    );
}, 'QUIZ_ATTEMPT_TRANSPORT_UNEXPLAINED');

// F. Moodle no debe transportar previews como candidatos académicos.
rc5qa_block(static function(): void {
    $evidence = null;
    rc5qa_project(
        [rc5qa_row(7, 'quiz|quiz-a')],
        [rc5qa_candidate(7, 101, 'quiz|quiz-a', 'finished', 8.25, 9001, 1)],
        $evidence
    );
}, 'QUIZ_ATTEMPT_TRANSPORT_PREVIEW_UNEXPECTED');

// G. El productor nuevo excluye previews en la consulta SQL.
$producer = (string)file_get_contents(__DIR__ . '/../scripts/phase5-lib.php');
rc5qa_check(
    preg_match('/WHERE\s+q\.course\s*=\s*:courseid\s+AND\s+qa\.preview\s*=\s*0/s',
        $producer) === 1,
    'el productor no filtra qa.preview = 0'
);

// Integración mínima: relation y count se actualizan juntos desde el MBZ.
$root = sys_get_temp_dir() . '/consolidador-v8-rc5-quiz-' .
    bin2hex(random_bytes(6));
mkdir($root . '/activities/quiz_101', 0770, true);
try {
    $module = [
        'source_module_id' => 101,
        'modname' => 'quiz',
        'instance' => 601,
        'idnumber' => 'QUIZ-A',
        'name' => 'Quiz A',
        'module_key' => p5_module_key('quiz', 'QUIZ-A', 'Quiz A'),
        'completion_mode' => 1,
    ];
    file_put_contents($root . '/activities/quiz_101/module.xml',
        '<module id="101"><modulename>quiz</modulename>' .
        '<idnumber>QUIZ-A</idnumber></module>');
    file_put_contents($root . '/activities/quiz_101/quiz.xml',
        '<activity moduleid="101" modulename="quiz" contextid="9001">' .
        '<quiz><attempts></attempts></quiz></activity>');
    file_put_contents($root . '/files.xml', '<files></files>');
    $raw = [
        'course' => ['source_course_id' => 45],
        'counts' => ['quiz_attempts' => 1, 'module_files' => 0],
        'modules_by_type' => ['quiz' => 1],
        'modules' => [$module],
        'relations' => [
            'quiz_attempts' => [rc5qa_row(
                246,
                $module['module_key'],
                'inprogress',
                null,
                9100,
                1
            )],
            'files' => [],
        ],
    ];
    $audit = null;
    $effective = p6_effective_restore_inventory(
        $raw,
        'posgrados-2025-05-02-directo',
        45,
        $root,
        $audit
    );
    rc5qa_check($effective['relations']['quiz_attempts'] === [] &&
        $effective['counts']['quiz_attempts'] === 0 &&
        $audit['activity_aliases']['quiz_attempt_projection']['status'] ===
            'projected_from_transport',
        'la vista efectiva no actualizó relation y count de forma atómica');
} finally {
    rc5qa_remove($root);
}

echo "V8_RC5_QUIZ_ATTEMPTS_OK matched=1 projected=legacy fail_closed=backup_preview count=effective\n";
