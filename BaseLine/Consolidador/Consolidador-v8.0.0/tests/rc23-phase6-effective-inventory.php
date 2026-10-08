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

function rc23e_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException('RC23_EFFECTIVE_FAILED ' . $message); }
}
function rc23e_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) { rc23e_remove($item->getPathname()); }
    rmdir($path);
}
function rc23e_write(string $path, string $contents): void {
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0770, true); }
    file_put_contents($path, $contents);
}
function rc23e_module(int $id, string $idnumber, string $name): array {
    return [
        'source_module_id' => $id,
        'modname' => 'quiz',
        'instance' => 500 + $id,
        'idnumber' => $idnumber,
        'name' => $name,
        'module_key' => p5_module_key('quiz', $idnumber, $name),
        'completion_mode' => 1,
    ];
}
function rc23e_activity(
    string $root,
    array $module,
    int $contextid,
    int $userid,
    string $state = 'finished',
    float $sumgrades = 8.25
): void {
    $id = (int)$module['source_module_id'];
    $directory = $root . '/activities/quiz_' . $id;
    rc23e_write($directory . '/module.xml', '<module id="' . $id . '">' .
        '<modulename>quiz</modulename><idnumber>' .
        htmlspecialchars((string)$module['idnumber'], ENT_XML1) .
        '</idnumber></module>');
    rc23e_write($directory . '/quiz.xml', '<activity moduleid="' . $id .
        '" modulename="quiz" contextid="' . $contextid . '"><quiz><attempts>' .
        '<attempt id="' . ($id + 9000) . '"><userid>' . $userid . '</userid>' .
        '<state>' . $state . '</state><sumgrades>' . $sumgrades . '</sumgrades>' .
        '<attempt>1</attempt><timestart>100</timestart><timefinish>200</timefinish>' .
        '<preview>0</preview></attempt></attempts></quiz></activity>');
}
function rc23e_empty_files(string $root): void {
    rc23e_write($root . '/files.xml', '<files></files>');
}
function rc23e_block(callable $action, string $needle): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc23e_check(str_contains($error->getMessage(), $needle),
            $needle . ' produjo: ' . $error->getMessage());
    }
}

$root = sys_get_temp_dir() . '/consolidador-rc23-effective-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
try {
    $unique = $root . '/unique-alias';
    $module = rc23e_module(101, '2470202B-2023-I-U1-C1', 'Cuestionario Unidad I');
    rc23e_activity($unique, $module, 9001, 7);
    $payload = 'transported-content';
    $contenthash = sha1($payload);
    rc23e_write($unique . '/files/' . substr($contenthash, 0, 2) . '/' .
        $contenthash, $payload);
    rc23e_write($unique . '/files.xml', '<files>' .
        '<file id="501"><contextid>9001</contextid><userid>0</userid>' .
        '<component>mod_quiz</component><filearea>intro</filearea>' .
        '<filename>transported.txt</filename><filesize>' . strlen($payload) .
        '</filesize><contenthash>' . $contenthash . '</contenthash></file>' .
        '<file id="502"><contextid>9001</contextid><userid>0</userid>' .
        '<component>assignfeedback_editpdf</component>' .
        '<filearea>tmp_jpg_to_pdf</filearea><filename>temporary.pdf</filename>' .
        '<filesize>123</filesize><contenthash>' . str_repeat('a', 40) .
        '</contenthash></file></files>');
    $inventory = [
        'course' => ['source_course_id' => 45],
        'counts' => ['module_files' => 2],
        'modules_by_type' => ['quiz' => 1],
        'modules' => [$module],
        'relations' => [
            'quiz_attempts' => [[
                'source_user_id' => 7,
                'activity_key' => 'quiz|cuestionario unidad i',
                'state' => 'finished',
                'sumgrades' => 8.25,
            ]],
            'files' => [[
                'source_user_id' => 0,
                'activity_key' => 'quiz|cuestionario unidad i',
                'component' => 'mod_quiz',
                'filearea' => 'intro',
                'filename' => 'database-only.txt',
            ], [
                'source_user_id' => 0,
                'activity_key' => 'quiz|cuestionario unidad i',
                'component' => 'assignfeedback_editpdf',
                'filearea' => 'tmp_jpg_to_pdf',
                'filename' => 'temporary.pdf',
            ]],
        ],
    ];
    $audit = null;
    $effective = p6_effective_restore_inventory(
        $inventory, 'posgrados', 45, $unique, $audit
    );
    $effectivekey = 'quiz|2470202b-2023-i-u1-c1';
    rc23e_check($effective['relations']['quiz_attempts'][0]['activity_key'] ===
        $effectivekey &&
        $audit['activity_aliases']['historical_name_alias'] === 1,
        'el alias histórico único no resolvió al idnumber efectivo');
    $modulexml = new DOMDocument();
    $modulexml->load($unique . '/activities/quiz_101/module.xml');
    rc23e_check(p5_dom_text($modulexml->documentElement, 'idnumber') ===
        '2470202B-2023-I-U1-C1' &&
        $effective['modules'][0]['module_key'] === $effectivekey,
        'module.xml e inventario no comparten el effective_idnumber');
    rc23e_check(count($effective['relations']['files']) === 1 &&
        $effective['relations']['files'][0]['filename'] === 'transported.txt' &&
        $effective['counts']['module_files'] === 1 &&
        $audit['files_transport']['files_source_database_rows'] === 2 &&
        $audit['files_transport']['files_xml_transport_rows'] === 2 &&
        $audit['files_transport']['files_regenerable_excluded_transport'] === 1 &&
        $audit['files_transport']['files_payloads_checked'] === 1,
        'files.xml no definió exactamente la población transportable');

    $generated = $root . '/generated-idnumber';
    $generatedmodule = rc23e_module(102, '', 'Quiz sin idnumber');
    rc23e_activity($generated, $generatedmodule, 9002, 8);
    rc23e_empty_files($generated);
    $generatedinventory = $inventory;
    $generatedinventory['course']['source_course_id'] = 46;
    $generatedinventory['modules'] = [$generatedmodule];
    $generatedinventory['relations'] = ['quiz_attempts' => [[
        'source_user_id' => 8, 'activity_key' => 'quiz|quiz sin idnumber',
        'state' => 'finished', 'sumgrades' => 8.25,
    ]], 'files' => []];
    $generatedaudit = null;
    $generatedeffective = p6_effective_restore_inventory(
        $generatedinventory, 'posgrados', 46, $generated, $generatedaudit
    );
    $generatedid = $generatedeffective['modules'][0]['effective_idnumber'];
    $generatedxml = new DOMDocument();
    $generatedxml->load($generated . '/activities/quiz_102/module.xml');
    rc23e_check(preg_match('/^MIG-P5-MOD-[A-F0-9]{32}$/', $generatedid) === 1 &&
        p5_dom_text($generatedxml->documentElement, 'idnumber') === $generatedid,
        'el idnumber generado no viajó realmente en module.xml');

    $fallback = $root . '/structural-fallback';
    $modulea = rc23e_module(201, 'CODE-A', 'Nombre Repetido');
    $moduleb = rc23e_module(202, 'CODE-B', 'Nombre Repetido');
    rc23e_activity($fallback, $modulea, 9201, 7);
    rc23e_activity($fallback, $moduleb, 9202, 99);
    // El segundo módulo existe y conserva el mismo nombre, pero no transporta
    // intentos. Así el fallback prueba la identidad estructural sin introducir
    // una fila MBZ inexplicable para el contrato estricto de quiz_attempts.
    rc23e_write(
        $fallback . '/activities/quiz_202/quiz.xml',
        '<activity moduleid="202" modulename="quiz" contextid="9202">' .
        '<quiz><attempts></attempts></quiz></activity>'
    );
    rc23e_empty_files($fallback);
    $fallbackinventory = $inventory;
    $fallbackinventory['modules'] = [$modulea, $moduleb];
    $fallbackinventory['relations'] = ['quiz_attempts' => [[
        'source_user_id' => 7, 'activity_key' => 'quiz|nombre repetido',
        'state' => 'finished', 'sumgrades' => 8.25,
    ]], 'files' => []];
    $fallbackaudit = null;
    $fallbackeffective = p6_effective_restore_inventory(
        $fallbackinventory, 'posgrados', 47, $fallback, $fallbackaudit
    );
    rc23e_check($fallbackeffective['relations']['quiz_attempts'][0]['activity_key'] ===
        'quiz|code-a' && $fallbackaudit['activity_aliases']['structural_fallback'] === 1,
        'el alias ambiguo no utilizó evidencia estructural suficiente');

    $ambiguous = $root . '/ambiguous-alias';
    rc23e_activity($ambiguous, $modulea, 9301, 7);
    rc23e_activity($ambiguous, $moduleb, 9302, 7);
    rc23e_empty_files($ambiguous);
    rc23e_block(static function() use ($fallbackinventory, $ambiguous): void {
        $audit = null;
        p6_effective_restore_inventory(
            $fallbackinventory, 'posgrados', 47, $ambiguous, $audit
        );
    }, 'MODULE_ACTIVITY_ALIAS_UNRESOLVED');

    $missingpayload = $root . '/missing-payload';
    rc23e_activity($missingpayload, $module, 9401, 7);
    rc23e_write($missingpayload . '/files.xml', '<files><file id="601">' .
        '<contextid>9401</contextid><userid>0</userid><component>mod_quiz</component>' .
        '<filearea>intro</filearea><filename>lost.txt</filename><filesize>4</filesize>' .
        '<contenthash>' . str_repeat('b', 40) . '</contenthash></file></files>');
    rc23e_block(static function() use ($inventory, $missingpayload): void {
        $audit = null;
        p6_effective_restore_inventory(
            $inventory, 'posgrados', 45, $missingpayload, $audit
        );
    }, 'MODULE_FILE_PAYLOAD_MISSING');

    $expectedcomparison = [
        'counts' => ['activities' => 1, 'module_files' => 1],
        'modules_by_type' => ['quiz' => 1],
        'modules' => [['modname' => 'quiz', 'module_key' => 'quiz|academic']],
        'relations' => ['course_completions' => [], 'files' => [[
            'source_user_id' => 0, 'activity_key' => 'quiz|academic',
            'component' => 'mod_quiz', 'filearea' => 'intro',
            'filename' => 'academic.txt',
        ]]],
    ];
    $actualcomparison = $expectedcomparison;
    $actualcomparison['counts'] = ['activities' => 2, 'module_files' => 2];
    $actualcomparison['modules_by_type']['qbank'] = 1;
    $actualcomparison['modules'][] = [
        'modname' => 'qbank', 'module_key' => 'qbank|generated',
    ];
    $actualcomparison['relations']['files'][] = [
        'source_user_id' => 0, 'activity_key' => 'qbank|generated',
        'component' => 'mod_qbank', 'filearea' => 'intro',
        'filename' => 'technical.txt',
    ];
    $qbankcomparison = p5_compare_course_inventories(
        $expectedcomparison,
        $actualcomparison
    );
    rc23e_check($qbankcomparison['complete'] &&
        $qbankcomparison['compatibility_adjustments']['ignored_target_qbank_modules'] === 1 &&
        $qbankcomparison['compatibility_adjustments']['ignored_target_qbank_files'] === 1,
        'el archivo qbank técnico no siguió la condición del módulo ignorado');

    echo "RC23_PHASE6_EFFECTIVE_INVENTORY_OK idnumber=written alias=unique fallback=structural ambiguity=blocked files=transport tmp=ignored qbank=conditional payload=verified\n";
} finally {
    rc23e_remove($root);
}
