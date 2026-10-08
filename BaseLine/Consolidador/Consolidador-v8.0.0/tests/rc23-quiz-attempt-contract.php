<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
    public static function strlen(string $value): int { return mb_strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-lib.php');

function rc23q_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException('RC23_QUIZ_FAILED ' . $message); }
}
function rc23q_source(array $changes = []): array {
    return $changes + [
        'source_user_id' => 7,
        'activity_key' => 'quiz|effective-id',
        'state' => 'finished',
        'sumgrades' => 8.25,
    ];
}
function rc23q_target(array $changes = []): array {
    return $changes + [
        'source_user_id' => 70,
        'activity_key' => 'quiz|effective-id',
        'state' => 'finished',
        'sumgrades' => 8.25,
        'attempt' => 1,
        'preview' => 0,
        'timestart' => 1700000000,
        'timefinish' => 1700000900,
        'attempt_id' => 900,
        'quiz_id' => 80,
        'source_module_id' => 81,
    ];
}

$map = ['source:7' => ['target_user_id' => 70, 'canonical_id' => 'CAN-7']];
$historical = p5_quiz_attempt_differences(
    [rc23q_source()], [rc23q_target()], $map, 'source'
);
rc23q_check(!$historical['has_differences'] &&
    $historical['comparison_contract'] === 'historical_weak' &&
    $historical['signature_fields'] ===
        ['source_user_id', 'activity_key', 'state', 'sumgrades'],
    'el contrato histórico inventó campos fuertes');
$phase6 = p6_quiz_attempt_differences(
    [rc23q_source()], [rc23q_target()], $map, 'source'
);
rc23q_check($phase6 === $historical,
    'Fase 5 y Fase 6 no usan el mismo contrato compartido');
$historicalissues = p6_historical_relation_issues(
    ['relations' => ['quiz_attempts' => [rc23q_source()]]],
    ['relations' => ['quiz_attempts' => [rc23q_target()]]],
    [7 => 70]
);
rc23q_check($historicalissues === [],
    'la segunda firma histórica volvió a exigir campos fuertes inexistentes');

foreach ([
    'sumgrades' => rc23q_target(['sumgrades' => 7.5]),
    'state' => rc23q_target(['state' => 'inprogress']),
    'user' => rc23q_target(['source_user_id' => 71]),
    'activity' => rc23q_target(['activity_key' => 'quiz|other']),
] as $name => $target) {
    $difference = p5_quiz_attempt_differences(
        [rc23q_source()], [$target], $map, 'source'
    );
    rc23q_check($difference['has_differences'],
        'la diferencia ' . $name . ' no bloqueó');
}
$historicalchanged = p5_quiz_attempt_differences(
    [rc23q_source()], [rc23q_target(['sumgrades' => 7.5])], $map, 'source'
);
rc23q_check(array_key_exists('attempt_number', $historicalchanged['changed'][0]) &&
    $historicalchanged['changed'][0]['attempt_number'] === null &&
    ($historicalchanged['changed'][0]['target_attempt_number'] ?? null) === 1 &&
    ($historicalchanged['changed'][0]['source_optional_field_presence']['attempt'] ?? '') ===
        'absent',
    'el diagnóstico no distinguió campo fuente ausente de valor destino');

$duplicates = p5_quiz_attempt_differences(
    [rc23q_source(), rc23q_source()], [rc23q_target()], $map, 'source'
);
rc23q_check($duplicates['has_differences'] &&
    count($duplicates['missing']) === 1,
    'el multiset permitió reutilizar una fila destino');

$strongsource = rc23q_source([
    'attempt' => 1,
    'preview' => 0,
    'timestart' => 1700000000,
    'timefinish' => 1700000900,
]);
$strong = p5_quiz_attempt_differences(
    [$strongsource], [rc23q_target()], $map, 'source'
);
rc23q_check(!$strong['has_differences'] &&
    $strong['comparison_contract'] === 'strong' &&
    count($strong['signature_fields']) === 8,
    'el contrato fuerte no comparó todos los campos disponibles');
$strongchanged = p5_quiz_attempt_differences(
    [$strongsource], [rc23q_target(['timefinish' => 1700001000])], $map, 'source'
);
rc23q_check($strongchanged['has_differences'] &&
    count($strongchanged['changed']) === 1 &&
    isset($strongchanged['changed'][0]['changed_fields']['timefinish']),
    'el contrato fuerte dejó de ser estricto');

$zero = p5_quiz_attempt_differences(
    [rc23q_source(['attempt' => 0])],
    [array_diff_key(rc23q_target(), ['attempt' => true])],
    $map,
    'source'
);
rc23q_check($zero['has_differences'], 'attempt=0 fue tratado como ausente');
$nullversuszero = p5_quiz_attempt_differences(
    [rc23q_source(['attempt' => null])],
    [rc23q_target(['attempt' => 0])],
    $map,
    'source'
);
rc23q_check($nullversuszero['has_differences'], 'attempt=null fue tratado como cero');

$inconsistent = p5_quiz_attempt_differences(
    [rc23q_source(['attempt' => 1]), rc23q_source()],
    [rc23q_target(), rc23q_target(['attempt_id' => 901])],
    $map,
    'source'
);
rc23q_check($inconsistent['has_differences'] &&
    $inconsistent['comparison_contract'] === 'schema_inconsistent' &&
    ($inconsistent['schema_issues'][0]['field'] ?? '') === 'attempt',
    'el esquema fuerte parcial no bloqueó controladamente');
$reportpath = sys_get_temp_dir() . '/rc23-quiz-report-' . bin2hex(random_bytes(6)) . '.json';
try {
    $reportsha = p5_write_quiz_attempt_report($reportpath, $inconsistent, [
        'source_id' => 'source', 'source_course_id' => 45, 'target_course_id' => 90,
    ]);
    $report = p5_read_json($reportpath);
    rc23q_check(is_string($reportsha) &&
        $report['comparison_contract'] === 'schema_inconsistent' &&
        $report['signature_fields'] === $inconsistent['signature_fields'] &&
        $report['schema_issues'] === $inconsistent['schema_issues'],
        'el reporte no expuso contrato, firma e inconsistencia');
} finally {
    if (is_file($reportpath)) { unlink($reportpath); }
}

echo "RC23_QUIZ_ATTEMPT_CONTRACT_OK historical=match multiset=strict strong=strict absent_not_zero=yes consumers=2\n";
