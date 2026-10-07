<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
    public static function strlen(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-effective-inventory.php');

function rc24_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('RC24_PHASE13_FAILED ' . $message);
    }
}
function rc24_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) { rc24_remove($item->getPathname()); }
    rmdir($path);
}
function rc24_write(string $path, string $contents): void {
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0770, true); }
    file_put_contents($path, $contents);
}
function rc24_block(callable $action, string $needle): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc24_check(str_contains($error->getMessage(), $needle),
            $needle . ' produjo: ' . $error->getMessage());
    }
}

$root = sys_get_temp_dir() . '/consolidador-rc24-phase13-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
try {
    // 1) Relaciones históricas homónimas: el MBZ redistribuye por módulo solo
    // cuando el multiconjunto semántico coincide exactamente.
    $modules = [
        101 => ['module_key' => 'label|code-a', 'modname' => 'label'],
        102 => ['module_key' => 'label|code-b', 'modname' => 'label'],
    ];
    $historical = [
        ['activity_key' => 'label|etiqueta', 'source_user_id' => 7, 'completion_state' => 1],
        ['activity_key' => 'label|etiqueta', 'source_user_id' => 8, 'completion_state' => 2],
    ];
    $structural = [
        ['_source_module_id' => 101, 'activity_key' => 'label|code-a', 'source_user_id' => 7, 'completion_state' => 1],
        ['_source_module_id' => 102, 'activity_key' => 'label|code-b', 'source_user_id' => 8, 'completion_state' => 2],
    ];
    $evidence = [
        'structural_projection' => 0,
        'structural_projection_relations' => [],
        'aliases_blocked' => 0,
        'blocked' => [],
    ];
    $projected = p6_structural_relation_projection(
        $historical, 'activity_completions', $structural, $modules, $evidence
    );
    $keys = array_column($projected, 'activity_key');
    sort($keys, SORT_STRING);
    rc24_check($keys === ['label|code-a', 'label|code-b'] &&
        $evidence['structural_projection'] === 2 &&
        $evidence['structural_projection_relations'][0]['status'] === 'matched',
        'la proyección estructural no resolvió homónimos demostrados');

    $badstructural = $structural;
    $badstructural[1]['completion_state'] = 3;
    rc24_block(static function() use ($historical, $badstructural, $modules): void {
        $evidence = [
            'structural_projection' => 0,
            'structural_projection_relations' => [],
            'aliases_blocked' => 0,
            'blocked' => [],
        ];
        p6_structural_relation_projection(
            $historical, 'activity_completions', $badstructural, $modules, $evidence
        );
    }, 'MODULE_RELATION_STRUCTURAL_MISMATCH');

    // 2) mod_customcert/image usa contexto de curso y puede ser anotado por
    // varias actividades sin que file_id pertenezca a un único módulo.
    $shared = $root . '/customcert-shared';
    $activities = [];
    foreach ([1538, 846, 847] as $id) {
        $path = $shared . '/activities/customcert_' . $id;
        rc24_write($path . '/module.xml', '<module id="' . $id . '"><modulename>customcert</modulename><idnumber></idnumber></module>');
        rc24_write($path . '/inforef.xml', '<inforef><fileref><file><id>5697</id></file><file><id>5703</id></file></fileref></inforef>');
        $activities[$id] = [
            'path' => $path,
            'context_id' => 0,
            'module' => [
                'modname' => 'customcert',
                'module_key' => 'customcert|cert-' . $id,
                'instance' => $id + 1000,
            ],
        ];
    }
    rc24_write($shared . '/moodle_backup.xml', '<moodle_backup><information>' .
        '<original_course_contextid>81</original_course_contextid>' .
        '</information></moodle_backup>');
    rc24_write($shared . '/files.xml', '<files>' .
        '<file id="5697"><contextid>81</contextid><component>mod_customcert</component><filearea>image</filearea><itemid>0</itemid><userid>0</userid><filename>.</filename></file>' .
        '<file id="5703"><contextid>81</contextid><component>mod_customcert</component><filearea>image</filearea><itemid>0</itemid><userid>0</userid><filename>logo_ufps.jpe</filename></file>' .
        '</files>');
    $fileevidence = null;
    $candidates = p5_backup_relation_candidates($shared, $activities, $fileevidence);
    rc24_check($fileevidence['files_shared_inforef_accepted'] === 2 &&
        $fileevidence['files_ambiguous'] === 0 &&
        count($candidates['files']) === 0,
        'customcert/image compartido no siguió el contrato de contexto de curso');

    // El permiso es semántico, no general: otra duplicidad continúa bloqueada.
    $badshared = $root . '/bad-shared';
    rc24_write($badshared . '/activities/assign_1/inforef.xml', '<inforef><fileref><file><id>9</id></file></fileref></inforef>');
    rc24_write($badshared . '/activities/assign_2/inforef.xml', '<inforef><fileref><file><id>9</id></file></fileref></inforef>');
    rc24_write($badshared . '/files.xml', '<files><file id="9"><contextid>50</contextid><component>mod_assign</component><filearea>intro</filearea><itemid>0</itemid><userid>0</userid><filename>x.pdf</filename></file></files>');
    $badactivities = [
        1 => ['path' => $badshared . '/activities/assign_1', 'context_id' => 0,
            'module' => ['modname' => 'assign', 'module_key' => 'assign|a', 'instance' => 1]],
        2 => ['path' => $badshared . '/activities/assign_2', 'context_id' => 0,
            'module' => ['modname' => 'assign', 'module_key' => 'assign|b', 'instance' => 2]],
    ];
    rc24_block(static function() use ($badshared, $badactivities): void {
        $evidence = null;
        p5_backup_relation_candidates($badshared, $badactivities, $evidence);
    }, 'MODULE_FILE_ID_AMBIGUOUS');

    // 3) Una top moderna con entradas directas sigue siendo bloqueante, con
    // diagnóstico preciso; una top vacía es válida.
    $questions = $root . '/questions.xml';
    rc24_write($questions, '<question_categories>' .
        '<question_category id="270"><parent>0</parent><contextid>6851</contextid><name>top</name><idnumber></idnumber>' .
        '<question_bank_entries><question_bank_entry id="1"/></question_bank_entries></question_category>' .
        '</question_categories>');
    rc24_block(static fn() => p5_validate_backup_question_hierarchy($questions),
        'QUESTION_TOP_CATEGORY_DIRECT_ENTRIES_UNRESTORABLE');
    rc24_write($questions, '<question_categories>' .
        '<question_category id="270"><parent>0</parent><contextid>6851</contextid><name>top</name><idnumber></idnumber>' .
        '<question_bank_entries></question_bank_entries></question_category>' .
        '</question_categories>');
    rc24_check(p5_validate_backup_question_hierarchy($questions)['categories_checked'] === 1,
        'top vacía fue bloqueada');

    // 4) files.xml global: user/icon ausente se audita como warning; cualquier
    // payload académico interno ausente sigue bloqueando.
    $icons = $root . '/icons';
    rc24_write($icons . '/files.xml', '<files><file id="115106">' .
        '<contextid>4302</contextid><component>user</component><filearea>icon</filearea>' .
        '<itemid>0</itemid><filename>f1.jpg</filename><filesize>123</filesize>' .
        '<contenthash>' . str_repeat('a', 40) . '</contenthash></file></files>');
    $payload = p6_audit_backup_file_payload_presence($icons);
    rc24_check($payload['missing_user_icon_payloads_warning'] === 1 &&
        $payload['missing_user_icon_contenthashes'] === [str_repeat('a', 40)],
        'user/icon faltante no quedó como warning auditable');

    $missing = $root . '/academic-missing';
    rc24_write($missing . '/files.xml', '<files><file id="99">' .
        '<contextid>9001</contextid><component>mod_assign</component><filearea>intro</filearea>' .
        '<itemid>0</itemid><filename>guia.pdf</filename><filesize>123</filesize>' .
        '<contenthash>' . str_repeat('b', 40) . '</contenthash></file></files>');
    rc24_block(static fn() => p6_audit_backup_file_payload_presence($missing),
        'MODULE_FILE_PAYLOAD_MISSING');

    echo "RC24_PHASE13_CONTRACTS_OK relations=structural customcert=shared top=blocked user_icon=warning academic_payload=blocked\n";
} finally {
    rc24_remove($root);
}
