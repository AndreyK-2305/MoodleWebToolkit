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

function rc5ro_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('V8_RC5_RANDOM_ORDER_FAILED ' . $message);
    }
}
function rc5ro_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) {
        rc5ro_remove($item->getPathname());
    }
    rmdir($path);
}
function rc5ro_block(callable $action, string $needle): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc5ro_check(str_contains($error->getMessage(), $needle),
            'bloqueo inesperado: ' . $error->getMessage());
    }
}
function rc5ro_entry(int $entryid, int $questionid, string $qtype): string {
    return '<question_bank_entry id="' . $entryid . '"><question_versions>' .
        '<question_version id="' . ($entryid + 1000) . '"><question id="' .
        $questionid . '"><qtype>' . $qtype . '</qtype><stamp>stamp-' .
        $questionid . '</stamp><questiontext>0</questiontext><name>q-' .
        $questionid . '</name></question></question_version>' .
        '</question_versions></question_bank_entry>';
}
function rc5ro_fixture(
    string $root,
    string $name,
    string $categoryname,
    int $parent,
    string $entries,
    bool $setreference = false,
    string $extra = ''
): string {
    $directory = $root . '/' . $name;
    mkdir($directory . '/activities/quiz_1', 0770, true);
    file_put_contents($directory . '/questions.xml',
        '<?xml version="1.0" encoding="UTF-8"?>' .
        '<question_categories><question_category id="86"><parent>' . $parent .
        '</parent><name>' . $categoryname . '</name><idnumber></idnumber>' .
        '<question_bank_entries>' . $entries . '</question_bank_entries>' .
        '</question_category></question_categories>');
    $references = '';
    if ($setreference) {
        $filter = json_encode(['filter' => ['category' => [
            'jointype' => 1,
            'values' => [86],
            'filteroptions' => ['includesubcategories' => '0'],
        ]]], JSON_THROW_ON_ERROR);
        $references = '<question_set_reference id="700"><filtercondition>' .
            htmlspecialchars($filter, ENT_XML1 | ENT_QUOTES, 'UTF-8') .
            '</filtercondition></question_set_reference>';
    }
    file_put_contents($directory . '/activities/quiz_1/quiz.xml',
        '<activity><quiz><question_set_references>' . $references .
        '</question_set_references></quiz></activity>');
    if ($extra !== '') {
        file_put_contents($directory . '/activities/quiz_1/attempts.xml', $extra);
    }
    return $directory;
}

$root = sys_get_temp_dir() . '/consolidador-v8-rc5-random-order-' .
    bin2hex(random_bytes(6));
mkdir($root, 0770, true);
try {
    // A. top vacío es válido y no se modifica.
    $empty = rc5ro_fixture($root, 'top-empty', 'top', 0, '');
    $before = hash_file('sha256', $empty . '/questions.xml');
    $result = p6_normalize_legacy_random_questions($empty);
    rc5ro_check($result['legacy_random_normalization_status'] === 'not_required' &&
        hash_file('sha256', $empty . '/questions.xml') === $before,
        'top vacío fue alterado');

    // B. top con solo residuos seguros se normaliza antes de validar jerarquía.
    $safe = rc5ro_fixture(
        $root, 'top-random', 'top', 0, rc5ro_entry(104, 121, 'random'), true
    );
    $referencesbefore = p6_question_set_reference_snapshot($safe);
    $before = hash_file('sha256', $safe . '/questions.xml');
    $result = p6_normalize_legacy_random_questions($safe);
    rc5ro_check($result['legacy_random_normalization_status'] === 'normalized' &&
        $result['legacy_random_removed'] === 1 &&
        $result['legacy_random_entries_removed'] === 1 &&
        $result['legacy_random_set_references_verified'] === 1 &&
        hash_file('sha256', $safe . '/questions.xml') !== $before &&
        p6_question_set_reference_snapshot($safe) === $referencesbefore &&
        p5_validate_backup_question_hierarchy(
            $safe . '/questions.xml'
        )['categories_with_questions'] === 0,
        'top con residuo seguro no quedó normalizado y válido');

    // C. top con contenido académico real continúa bloqueando.
    $academic = rc5ro_fixture(
        $root, 'top-academic', 'top', 0, rc5ro_entry(105, 122, 'multichoice')
    );
    rc5ro_block(
        static fn() => p6_normalize_legacy_random_questions($academic),
        'QUESTION_TOP_CATEGORY_DIRECT_ENTRIES_UNRESTORABLE'
    );

    // D/G. Sin random, una categoría ordinaria parent=0 bloquea de inmediato.
    $ordinary = rc5ro_fixture(
        $root, 'ordinary-parent-zero', 'Unidad inválida', 0,
        rc5ro_entry(106, 123, 'multichoice')
    );
    rc5ro_block(
        static fn() => p6_normalize_legacy_random_questions($ordinary),
        'QUESTION_CATEGORY_PARENT_ZERO_INVALID'
    );

    // E. Una referencia activa al random sigue siendo incompatible.
    $active = rc5ro_fixture(
        $root, 'top-random-active', 'top', 0,
        rc5ro_entry(107, 124, 'random'), true,
        '<attempts><question_attempt><questionid>124</questionid></question_attempt></attempts>'
    );
    rc5ro_block(
        static fn() => p6_normalize_legacy_random_questions($active),
        'LEGACY_RANDOM_BLOCKED reason=active_question_reference'
    );

    // F. La invariancia semántica de question_set_reference es fail-closed.
    rc5ro_block(
        static fn() => p6_assert_question_set_reference_invariant(
            [['path' => 'quiz.xml', 'category_id' => 86]],
            [['path' => 'quiz.xml', 'category_id' => 87]]
        ),
        'LEGACY_RANDOM_BLOCKED reason=question_set_reference_changed'
    );

    echo "V8_RC5_RANDOM_ORDER_OK top_empty=valid top_random=normalized academic=blocked invariant=sealed\n";
} finally {
    rc5ro_remove($root);
}
