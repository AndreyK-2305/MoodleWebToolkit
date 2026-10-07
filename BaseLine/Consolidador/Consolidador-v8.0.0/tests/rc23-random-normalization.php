<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
    public static function strlen(string $value): int { return mb_strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-random-normalization.php');

function rc23r_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException('RC23_RANDOM_FAILED ' . $message); }
}
function rc23r_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) { rc23r_remove($item->getPathname()); }
    rmdir($path);
}
function rc23r_question(int $id, string $stamp, bool $include = false): string {
    return '<question id="' . $id . '"><qtype>random</qtype><stamp>' .
        htmlspecialchars($stamp, ENT_XML1) . '</stamp><questiontext>' .
        ($include ? '1' : '0') . '</questiontext></question>';
}
function rc23r_entry(int $id, string $question): string {
    return '<question_bank_entry id="' . $id . '"><question_versions>' .
        '<question_version id="' . ($id + 10000) . '">' . $question .
        '</question_version></question_versions></question_bank_entry>';
}
function rc23r_filter(int $category, bool $include = false, bool $legacy = false): string {
    $value = $legacy
        ? ['questioncategoryid' => (string)$category,
            'includingsubcategories' => $include ? '1' : '0']
        : ['filter' => ['category' => ['jointype' => 1, 'values' => [$category],
            'filteroptions' => ['includesubcategories' => $include ? '1' : '0']]]];
    return htmlspecialchars(json_encode($value, JSON_THROW_ON_ERROR), ENT_XML1 | ENT_QUOTES);
}
function rc23r_quiz(string $path, int $category, int $count, bool $legacy = false): void {
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0770, true); }
    $rows = [];
    for ($index = 1; $index <= $count; $index++) {
        $rows[] = '<question_set_reference id="' . $index . '"><filtercondition>' .
            rc23r_filter($category, false, $legacy) .
            '</filtercondition></question_set_reference>';
    }
    file_put_contents($path, '<activity><quiz><question_set_references>' .
        implode('', $rows) . '</question_set_references></quiz></activity>');
}
function rc23r_fixture(string $root, string $name, int $physical, int $logical): string {
    $path = $root . '/' . $name;
    mkdir($path, 0770, true);
    $entries = [];
    for ($index = 0; $index < $physical; $index++) {
        $entries[] = rc23r_entry(2000 + $index,
            rc23r_question(3000 + $index, 'STAMP-' . ($index % $logical)));
    }
    file_put_contents($path . '/questions.xml', '<question_categories>' .
        '<question_category id="866"><parent>1</parent><name>Random</name>' .
        '<idnumber></idnumber><question_bank_entries>' . implode('', $entries) .
        '</question_bank_entries></question_category></question_categories>');
    return $path;
}
function rc23r_block(callable $callable, string $reason): void {
    try {
        $callable();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc23r_check(str_contains($error->getMessage(), 'reason=' . $reason),
            $reason . ' produjo: ' . $error->getMessage());
    }
}

$root = sys_get_temp_dir() . '/consolidador-rc23-random-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
try {
    $duplicated = rc23r_fixture($root, 'physical-20-logical-10', 20, 10);
    rc23r_quiz($duplicated . '/activities/quiz_1/quiz.xml', 866, 10);
    $result = p6_normalize_legacy_random_questions($duplicated);
    rc23r_check($result['legacy_random_physical_rows'] === 20 &&
        $result['legacy_random_logical_stamps'] === 10 &&
        $result['legacy_random_removed'] === 20 &&
        $result['legacy_random_set_references_verified'] === 10,
        '20 filas físicas/10 stamps no se normalizaron');

    $twoquizzes = rc23r_fixture($root, 'two-quizzes', 10, 10);
    rc23r_quiz($twoquizzes . '/activities/quiz_1/quiz.xml', 866, 10);
    rc23r_quiz($twoquizzes . '/activities/quiz_2/quiz.xml', 866, 10);
    $result = p6_normalize_legacy_random_questions($twoquizzes);
    rc23r_check($result['legacy_random_set_references_verified'] === 20 &&
        count($result['legacy_random_set_reference_cardinality_by_quiz']) === 2,
        'la cardinalidad válida no se evaluó por quiz.xml');

    $mismatch = rc23r_fixture($root, 'quiz-mismatch', 10, 10);
    rc23r_quiz($mismatch . '/activities/quiz_1/quiz.xml', 866, 10);
    rc23r_quiz($mismatch . '/activities/quiz_2/quiz.xml', 866, 9);
    rc23r_block(static fn() => p6_normalize_legacy_random_questions($mismatch),
        'set_reference_cardinality_mismatch');

    $legacy = rc23r_fixture($root, 'legacy-filter', 1, 1);
    rc23r_quiz($legacy . '/activities/quiz_1/quiz.xml', 866, 1, true);
    $result = p6_normalize_legacy_random_questions($legacy);
    rc23r_check($result['legacy_random_filter_formats'] ===
        ['legacy_questioncategoryid'], 'filter legacy no fue reconocido exactamente');

    $unknown = rc23r_fixture($root, 'unknown-filter', 1, 1);
    rc23r_quiz($unknown . '/activities/quiz_1/quiz.xml', 866, 1, true);
    $quizpath = $unknown . '/activities/quiz_1/quiz.xml';
    $contents = (string)file_get_contents($quizpath);
    $contents = str_replace(
        rc23r_filter(866, false, true),
        htmlspecialchars(json_encode(['questioncategoryid' => '866',
            'includingsubcategories' => '0', 'unknown' => true], JSON_THROW_ON_ERROR),
            ENT_XML1 | ENT_QUOTES),
        $contents
    );
    file_put_contents($quizpath, $contents);
    rc23r_block(static fn() => p6_normalize_legacy_random_questions($unknown),
        'invalid_filtercondition');

    $conflict = $root . '/stamp-conflict';
    mkdir($conflict, 0770, true);
    file_put_contents($conflict . '/questions.xml', '<question_categories>' .
        '<question_category id="866"><parent>1</parent><name>A</name><idnumber></idnumber>' .
        '<question_bank_entries>' . rc23r_entry(1, rc23r_question(11, 'SAME')) .
        '</question_bank_entries></question_category>' .
        '<question_category id="867"><parent>1</parent><name>B</name><idnumber></idnumber>' .
        '<question_bank_entries>' . rc23r_entry(2, rc23r_question(12, 'SAME')) .
        '</question_bank_entries></question_category></question_categories>');
    rc23r_quiz($conflict . '/activities/quiz_1/quiz.xml', 866, 1);
    rc23r_quiz($conflict . '/activities/quiz_2/quiz.xml', 867, 1);
    rc23r_block(static fn() => p6_normalize_legacy_random_questions($conflict),
        'random_stamp_semantic_conflict');

    echo "RC23_RANDOM_NORMALIZATION_OK physical=20 logical=10 per_quiz=yes legacy=yes fail_closed=yes\n";
} finally {
    rc23r_remove($root);
}
