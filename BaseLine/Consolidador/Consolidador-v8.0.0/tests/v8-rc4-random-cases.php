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

function rc4random_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('V8_RC4_RANDOM_CASES_FAILED ' . $message);
    }
}
function rc4random_remove(string $path): void {
    if (!file_exists($path) && !is_link($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) {
        rc4random_remove($item->getPathname());
    }
    rmdir($path);
}
function rc4random_fixture(
    string $root,
    string $name,
    int $count,
    int $category,
    int $firstquestion,
    int $firstentry,
    bool $withreferences
): string {
    $directory = $root . '/' . $name;
    mkdir($directory . '/activities/quiz_1', 0770, true);
    $entries = [];
    $references = [];
    for ($index = 0; $index < $count; $index++) {
        $question = $firstquestion + $index;
        $entry = $firstentry + $index;
        $entries[] = '<question_bank_entry id="' . $entry . '">' .
            '<question_versions><question_version id="' . (90000 + $entry) . '">' .
            '<question id="' . $question . '"><qtype>random</qtype>' .
            '<stamp>RC4-STAMP-' . $question . '</stamp><questiontext>0</questiontext>' .
            '</question></question_version></question_versions>' .
            '</question_bank_entry>';
        if ($withreferences) {
            $filter = json_encode(['filter' => ['category' => [
                'jointype' => 1,
                'values' => [$category],
                'filteroptions' => ['includesubcategories' => '0'],
            ]]], JSON_THROW_ON_ERROR);
            $references[] = '<question_set_reference id="' . (80000 + $index) . '">' .
                '<filtercondition>' . htmlspecialchars(
                    $filter,
                    ENT_XML1 | ENT_QUOTES,
                    'UTF-8'
                ) . '</filtercondition></question_set_reference>';
        }
    }
    file_put_contents($directory . '/questions.xml',
        '<?xml version="1.0" encoding="UTF-8"?>' .
        '<question_categories><question_category id="' . $category . '">' .
        '<parent>1</parent><name>Random legacy</name><idnumber></idnumber>' .
        '<question_bank_entries>' . implode('', $entries) .
        '</question_bank_entries></question_category></question_categories>');
    file_put_contents($directory . '/activities/quiz_1/quiz.xml',
        '<?xml version="1.0" encoding="UTF-8"?>' .
        '<activity><quiz><question_set_references>' . implode('', $references) .
        '</question_set_references></quiz></activity>');
    return $directory;
}

$root = sys_get_temp_dir() . '/consolidador-v8-rc4-random-' .
    bin2hex(random_bytes(6));
mkdir($root, 0770, true);
try {
    // El supuesto MBZ fuente está fuera de la extracción y debe conservar bytes.
    $sealed = $root . '/course-posgrados-sealed.mbz';
    file_put_contents($sealed, random_bytes(1024));
    $sealedbefore = hash_file('sha256', $sealed);

    $case1 = rc4random_fixture(
        $root,
        'COURSE-POSGRADOS-2025-05-02-DIRECTO-3A40238CEAF4',
        1,
        86,
        121,
        104,
        false
    );
    $case1result = p6_normalize_legacy_random_questions($case1);
    rc4random_check(
        $case1result['legacy_random_detected'] === 1 &&
        $case1result['legacy_random_removed'] === 1 &&
        $case1result['legacy_random_question_ids'] === [121] &&
        $case1result['legacy_random_entry_ids'] === [104] &&
        $case1result['legacy_random_category_ids'] === [86] &&
        $case1result['legacy_random_orphan_physical_rows'] === 1 &&
        $case1result['legacy_random_set_references_verified'] === 0 &&
        $case1result['legacy_random_normalization_status'] === 'normalized',
        'el caso real 3A40238CEAF4 no quedó como huérfano seguro'
    );

    $case20 = rc4random_fixture(
        $root,
        'COURSE-POSGRADOS-2025-05-02-DIRECTO-1DDB71B67038',
        20,
        866,
        2000,
        3000,
        false
    );
    $case20result = p6_normalize_legacy_random_questions($case20);
    rc4random_check(
        $case20result['legacy_random_detected'] === 20 &&
        $case20result['legacy_random_removed'] === 20 &&
        $case20result['legacy_random_orphan_entries'] === 20,
        'el caso de 20 residuos no clasificó todos'
    );

    $case80 = rc4random_fixture(
        $root,
        'COURSE-POSGRADOS-2025-05-02-DIRECTO-B8E8D8E3AFDF',
        80,
        986,
        4000,
        5000,
        true
    );
    $referencesbefore = hash_file(
        'sha256',
        $case80 . '/activities/quiz_1/quiz.xml'
    );
    $case80result = p6_normalize_legacy_random_questions($case80);
    rc4random_check(
        $case80result['legacy_random_detected'] === 80 &&
        $case80result['legacy_random_removed'] === 80 &&
        $case80result['legacy_random_set_references_verified'] === 80 &&
        count($case80result['legacy_random_set_reference_cardinality_by_quiz']) === 1 &&
        hash_file('sha256', $case80 . '/activities/quiz_1/quiz.xml') ===
            $referencesbefore,
        'el caso de escala 80 no conservó cardinalidad/referencias'
    );

    rc4random_check(hash_file('sha256', $sealed) === $sealedbefore,
        'la normalización modificó el MBZ sellado');

    $runtime = (string)file_get_contents(__DIR__ . '/../scripts/phase6-lib.php');
    rc4random_check(
        str_contains($runtime, 'p6_normalize_legacy_random_questions($directory)') &&
        !str_contains($runtime, 'DELEGATED_TO_MOODLE_NATIVE_RESTORE'),
        'la ruta productiva todavía delega qtype=random a Moodle'
    );

    echo "V8_RC4_RANDOM_CASES_OK case1=1 case20=20 case80=80 sealed_mbz=unchanged\n";
} finally {
    rc4random_remove($root);
}
