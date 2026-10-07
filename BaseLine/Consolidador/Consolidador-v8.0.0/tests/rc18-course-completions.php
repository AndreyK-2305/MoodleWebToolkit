<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
    public static function strlen(string $value): int { return mb_strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');

function rc18_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('RC18_COURSE_COMPLETIONS_FAILED ' . $message);
    }
}

function rc18_comparable_rows(array $rows): array {
    usort($rows, static fn(array $left, array $right): int => strcmp(
        json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ));
    return $rows;
}

function rc18_relation_passes(array $expected, array $actual): bool {
    $view = p5_course_completion_comparison_view($expected, $actual);
    return rc18_comparable_rows($view['expected_rows']) ===
        rc18_comparable_rows($view['actual_rows']);
}

$tracking1 = [['source_user_id' => 1, 'completed' => false]];
$tracking2 = [['source_user_id' => 2, 'completed' => false]];
$complete1 = [['source_user_id' => 1, 'completed' => true]];

$cases = [
    'expected_tracking_actual_empty' => [$tracking1, [], true],
    'expected_empty_actual_tracking' => [[], $tracking1, true],
    'different_tracking_users' => [$tracking1, $tracking2, true],
    'same_effective_completion' => [$complete1, $complete1, true],
    'missing_effective_completion' => [$complete1, [], false],
    'effective_became_tracking' => [$complete1, $tracking1, false],
    'expected_tracking_extra' => [[
        ['source_user_id' => 1, 'completed' => true],
        ['source_user_id' => 2, 'completed' => false],
    ], $complete1, true],
    'actual_tracking_extra' => [$complete1, [
        ['source_user_id' => 1, 'completed' => true],
        ['source_user_id' => 2, 'completed' => false],
    ], true],
];

foreach ($cases as $name => [$expected, $actual, $shouldpass]) {
    rc18_check(
        rc18_relation_passes($expected, $actual) === $shouldpass,
        $name . ' produjo un resultado incorrecto'
    );
}

$benchmarkexpectedrows = [];
for ($userid = 1; $userid <= 54; $userid++) {
    $benchmarkexpectedrows[] = [
        'source_user_id' => $userid,
        'completed' => false,
    ];
}
$benchmarkactualrows = array_values(array_filter(
    $benchmarkexpectedrows,
    static fn(array $row): bool => (int)$row['source_user_id'] !== 54
));
$benchmarkview = p5_course_completion_comparison_view(
    $benchmarkexpectedrows,
    $benchmarkactualrows
);
rc18_check(
    $benchmarkview['course_completions_raw_expected'] === 54 &&
    $benchmarkview['course_completions_raw_actual'] === 53 &&
    $benchmarkview['course_completions_effective_expected'] === 0 &&
    $benchmarkview['course_completions_effective_actual'] === 0 &&
    $benchmarkview['course_completions_tracking_ignored_expected'] === 54 &&
    $benchmarkview['course_completions_tracking_ignored_actual'] === 53,
    'las métricas del benchmark 54/53 son incorrectas'
);

$comparison = p5_compare_course_inventories(
    [
        'counts' => ['course_completions' => 54],
        'modules_by_type' => [],
        'modules' => [],
        'relations' => ['course_completions' => $benchmarkexpectedrows],
    ],
    [
        'counts' => ['course_completions' => 53],
        'modules_by_type' => [],
        'modules' => [],
        'relations' => ['course_completions' => $benchmarkactualrows],
    ]
);
rc18_check($comparison['complete'],
    'el conteo comparable del benchmark no pasó');
rc18_check(
    $comparison['course_completions_comparison'] === array_diff_key(
        $benchmarkview,
        ['expected_rows' => true, 'actual_rows' => true]
    ),
    'las métricas no quedaron expuestas en la comparación'
);

// El filtro es deliberadamente estricto: solo el booleano true representa
// una finalización efectiva en el inventario JSON.
$strict = p5_course_completion_comparison_view(
    [['source_user_id' => 1, 'completed' => 1]],
    []
);
rc18_check($strict['course_completions_effective_expected'] === 0,
    'completed no booleano fue aceptado como finalización efectiva');

echo "RC18_COURSE_COMPLETIONS_OK cases=8 raw=54/53 effective=0/0 tracking=54/53 true=strict\n";
