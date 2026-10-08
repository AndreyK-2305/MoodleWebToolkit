<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$root = sys_get_temp_dir() . '/consolidador-rc22-' . bin2hex(random_bytes(6));
$CFG = (object)[
    'libdir' => __DIR__ . '/fixtures',
    'tempdir' => $root . '/temp',
];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
    public static function strlen(string $value): int { return mb_strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-lib.php');

function rc22_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('RC22_RANDOM_NORMALIZATION_FAILED ' . $message);
    }
}

function rc22_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) {
        rc22_remove($item->getPathname());
    }
    rmdir($path);
}

function rc22_expect_block(callable $action, string $reason): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc22_check(
            str_contains($error->getMessage(), 'LEGACY_RANDOM_BLOCKED reason=' . $reason),
            'diagnóstico inesperado para ' . $reason . ': ' . $error->getMessage()
        );
    }
}

function rc22_question(int $id, string $qtype, bool $include = false): string {
    return '<question id="' . $id . '"><qtype>' . $qtype . '</qtype>' .
        '<stamp>rc22-stamp-' . $id . '</stamp>' .
        '<questiontext>' . ($include ? '1' : '0') . '</questiontext>' .
        '<name>q-' . $id . '</name></question>';
}

function rc22_entry(int $id, array $questions): string {
    return '<question_bank_entry id="' . $id . '"><question_versions>' .
        '<question_version id="' . ($id + 5000) . '">' .
        implode('', $questions) . '</question_version></question_versions>' .
        '</question_bank_entry>';
}

function rc22_set_reference(
    int $id,
    int $categoryid,
    bool $include = false,
    bool $valid = true
): string {
    if (!$valid) {
        $filter = '{not-json';
    } else {
        $filter = json_encode([
            'filter' => [
                'category' => [
                    'jointype' => 1,
                    'values' => [$categoryid],
                    'filteroptions' => [
                        'includesubcategories' => $include ? '1' : '0',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
    return '<question_set_reference id="' . $id . '"><filtercondition>' .
        htmlspecialchars($filter, ENT_XML1 | ENT_QUOTES, 'UTF-8') .
        '</filtercondition></question_set_reference>';
}

function rc22_fixture(
    string $root,
    string $name,
    array $entries,
    array $references = [],
    int $categoryid = 866,
    array $extra = []
): string {
    $directory = $root . '/' . $name;
    if (!mkdir($directory . '/activities/quiz_13083', 0770, true) &&
            !is_dir($directory . '/activities/quiz_13083')) {
        throw new RuntimeException('no creó fixture');
    }
    $questions = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<question_categories><question_category id="' . $categoryid . '">' .
        '<parent>1</parent><name>Unidad II</name><idnumber></idnumber>' .
        '<question_bank_entries>' . implode('', $entries) .
        '</question_bank_entries></question_category></question_categories>';
    $quiz = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<activity><quiz><question_set_references>' .
        implode('', $references) .
        '</question_set_references></quiz></activity>';
    file_put_contents($directory . '/questions.xml', $questions);
    file_put_contents($directory . '/activities/quiz_13083/quiz.xml', $quiz);
    file_put_contents($directory . '/users.xml', '<users><user id="1001"/></users>');
    file_put_contents($directory . '/roles.xml', '<roles><role id="2872"/></roles>');
    foreach ($extra as $relative => $contents) {
        $path = $directory . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        file_put_contents($path, $contents);
    }
    return $directory;
}

mkdir($root, 0770, true);
try {
    $norandom = rc22_fixture($root, 'no-random', [
        rc22_entry(3001, [rc22_question(4001, 'multichoice')]),
    ]);
    $hash = hash_file('sha256', $norandom . '/questions.xml');
    $result = p6_normalize_legacy_random_questions($norandom);
    rc22_check($result['legacy_random_normalization_status'] === 'not_required' &&
        $result['legacy_random_detected'] === 0 &&
        $result['legacy_random_removed'] === 0 &&
        hash_file('sha256', $norandom . '/questions.xml') === $hash,
        'un backup sin random cambió');

    $entries = [];
    $references = [];
    $questionids = [];
    $entryids = [];
    for ($index = 0; $index < 10; $index++) {
        $questionid = 3284 + $index;
        $entryid = 2872 + $index;
        $questionids[] = $questionid;
        $entryids[] = $entryid;
        $entries[] = rc22_entry($entryid, [rc22_question($questionid, 'random')]);
        $references[] = rc22_set_reference(6000 + $index, 866);
    }
    $safe = rc22_fixture($root, 'safe-ten', $entries, $references, 866, [
        'activities/quiz_13083/attempts.xml' =>
            '<attempts><question_attempt id="3284"><hash>3285</hash></question_attempt></attempts>',
    ]);
    $usershash = hash_file('sha256', $safe . '/users.xml');
    $roleshash = hash_file('sha256', $safe . '/roles.xml');
    $quizhash = hash_file('sha256', $safe . '/activities/quiz_13083/quiz.xml');
    $result = p6_normalize_legacy_random_questions($safe);
    rc22_check($result['legacy_random_normalization_status'] === 'normalized' &&
        $result['legacy_random_detected'] === 10 &&
        $result['legacy_random_removed'] === 10 &&
        $result['legacy_random_entries_removed'] === 10 &&
        $result['legacy_random_set_references_verified'] === 10 &&
        $result['legacy_random_question_ids'] === $questionids &&
        $result['legacy_random_entry_ids'] === $entryids &&
        $result['legacy_random_category_ids'] === [866],
        'el caso seguro no normalizó exactamente 10 residuos');
    rc22_check(hash_file('sha256', $safe . '/users.xml') === $usershash &&
        hash_file('sha256', $safe . '/roles.xml') === $roleshash &&
        hash_file('sha256', $safe . '/activities/quiz_13083/quiz.xml') === $quizhash,
        'la normalización alteró users, roles o question_set_reference');
    rc22_check(
        p5_validate_backup_question_hierarchy($safe . '/questions.xml')['categories_checked'] === 1,
        'questions.xml dejó de validar después de normalizar'
    );
    rc22_check(!str_contains(
        (string)file_get_contents($safe . '/questions.xml'),
        '<qtype>random</qtype>'
    ), 'persistió un random retirado');

    $mixed = rc22_fixture($root, 'mixed', [
        rc22_entry(2872, [
            rc22_question(3284, 'random'),
            rc22_question(9001, 'multichoice'),
        ]),
    ], [rc22_set_reference(6001, 866)]);
    rc22_expect_block(
        static fn() => p6_normalize_legacy_random_questions($mixed),
        'mixed_question_bank_entry'
    );

    $active = rc22_fixture($root, 'active-reference', [
        rc22_entry(2872, [rc22_question(3284, 'random')]),
    ], [rc22_set_reference(6001, 866)], 866, [
        'activities/quiz_13083/attempts.xml' =>
            '<attempts><question_attempt id="77"><questionid>3284</questionid></question_attempt></attempts>',
    ]);
    rc22_expect_block(
        static fn() => p6_normalize_legacy_random_questions($active),
        'active_question_reference'
    );

    $missing = rc22_fixture($root, 'missing-reference', [
        rc22_entry(2872, [rc22_question(3284, 'random')]),
    ]);
    $result = p6_normalize_legacy_random_questions($missing);
    rc22_check($result['legacy_random_removed'] === 1 &&
        $result['legacy_random_orphan_physical_rows'] === 1 &&
        $result['legacy_random_orphan_entries'] === 1 &&
        ($result['legacy_random_orphan_semantics']['866|0'] ?? 0) === 1,
        'el residuo huérfano sin set-reference no normalizó de forma auditada');

    $cardinality = rc22_fixture($root, 'cardinality', [
        rc22_entry(2872, [rc22_question(3284, 'random')]),
        rc22_entry(2873, [rc22_question(3285, 'random')]),
    ], [rc22_set_reference(6001, 866)]);
    rc22_expect_block(
        static fn() => p6_normalize_legacy_random_questions($cardinality),
        'set_reference_cardinality_mismatch'
    );

    $invalid = rc22_fixture($root, 'invalid-filter', [
        rc22_entry(2872, [rc22_question(3284, 'random')]),
    ], [rc22_set_reference(6001, 866, false, false)]);
    rc22_expect_block(
        static fn() => p6_normalize_legacy_random_questions($invalid),
        'invalid_filtercondition'
    );

    $wrongcategory = rc22_fixture($root, 'wrong-category', [
        rc22_entry(2872, [rc22_question(3284, 'random')]),
    ], [rc22_set_reference(6001, 867)]);
    $result = p6_normalize_legacy_random_questions($wrongcategory);
    rc22_check($result['legacy_random_removed'] === 1 &&
        ($result['legacy_random_orphan_semantics']['866|0'] ?? 0) === 1,
        'una referencia a otra categoría impidió retirar un residuo no referenciado');

    $wronginclude = rc22_fixture($root, 'wrong-include', [
        rc22_entry(2872, [rc22_question(3284, 'random', false)]),
    ], [rc22_set_reference(6001, 866, true)]);
    rc22_expect_block(
        static fn() => p6_normalize_legacy_random_questions($wronginclude),
        'set_reference_semantic_conflict'
    );

    $include = rc22_fixture($root, 'matching-include', [
        rc22_entry(2872, [rc22_question(3284, 'random', true)]),
    ], [rc22_set_reference(6001, 866, true)]);
    $result = p6_normalize_legacy_random_questions($include);
    rc22_check($result['legacy_random_removed'] === 1,
        'includesubcategories compatible no normalizó');

    $entryreference = rc22_fixture($root, 'entry-reference', [
        rc22_entry(2872, [rc22_question(3284, 'random')]),
    ], [rc22_set_reference(6001, 866)], 866, [
        'activities/quiz_13083/references.xml' =>
            '<question_references><question_reference><questionbankentryid>2872</questionbankentryid></question_reference></question_references>',
    ]);
    rc22_expect_block(
        static fn() => p6_normalize_legacy_random_questions($entryreference),
        'active_question_reference'
    );

    $implementation = (string)file_get_contents(
        __DIR__ . '/../scripts/phase6-random-normalization.php'
    );
    rc22_check(!str_contains($implementation, 'copy(') &&
        !str_contains($implementation, 'PharData') &&
        !str_contains($implementation, 'source_backup_sha256'),
        'la corrección introdujo copia/reempaque/rehash del MBZ');

    echo "RC22_LEGACY_RANDOM_NORMALIZATION_OK\n";
} finally {
    rc22_remove($root);
}
