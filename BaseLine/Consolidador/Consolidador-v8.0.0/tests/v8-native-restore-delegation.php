<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return strtolower($value); }
    public static function strlen(string $value): int { return strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-random-normalization.php');
require_once(__DIR__ . '/../scripts/phase6-degradation.php');

function v8native_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('V8_NATIVE_RESTORE_FAILED: ' . $message);
    }
}

$root = sys_get_temp_dir() . '/v8-native-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
try {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<question_categories>
  <question_category id="70">
    <parent>1</parent><name>Random legacy</name><idnumber></idnumber>
    <question_bank_entries>
      <question_bank_entry id="377">
        <question_versions><question_version id="9001">
          <question id="487"><qtype>random</qtype><stamp>V8-NATIVE-487</stamp><questiontext>0</questiontext></question>
        </question_version></question_versions>
      </question_bank_entry>
    </question_bank_entries>
  </question_category>
</question_categories>
XML;
    file_put_contents($root . '/questions.xml', $xml);
    $before = hash_file('sha256', $root . '/questions.xml');
    $audit = p6_normalize_legacy_random_questions($root);
    $after = hash_file('sha256', $root . '/questions.xml');
    v8native_check(
        $audit['legacy_random_detected'] === 1 &&
        $audit['legacy_random_removed'] === 1 &&
        $audit['legacy_random_orphan_physical_rows'] === 1 &&
        $audit['legacy_random_normalization_status'] === 'normalized' &&
        $before !== $after &&
        !str_contains((string)file_get_contents($root . '/questions.xml'),
            '<qtype>random</qtype>'),
        'la random huérfana no se normalizó antes del restore'
    );

    foreach (['forum_discussions', 'forum_posts'] as $relation) {
        $plan = ['items' => [[
            'type' => 'structural_mismatch',
            'event' => 'STRUCTURAL_OBSERVATION',
            'relation' => $relation,
            'action' => 'delegate_to_moodle_native_restore',
            'blocking' => false,
        ]]];
        v8native_check(
            p6_degradation_allows_structural($plan, $relation),
            $relation . ' no quedó como observación delegada'
        );
    }

    echo "RANDOM_NORMALIZATION_OK detected=1 removed=1 orphan=1\n";
    echo "FORUM_NATIVE_RESTORE_OK relations=2 blocking=false delegated=1\n";
} finally {
    @unlink($root . '/questions.xml');
    @rmdir($root);
}
