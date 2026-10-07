<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
    public static function strlen(string $value): int { return mb_strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');

function rc17_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException('RC17_CONTEXT_TEST_FAILED ' . $message); }
}
function rc17_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) { rc17_remove($item->getPathname()); }
    rmdir($path);
}
function rc17_xml(string $path, string $contents): void {
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0770, true); }
    file_put_contents($path, $contents);
}
function rc17_module(int $sourceid, string $modname = 'assign'): array {
    return [
        'source_module_id' => $sourceid,
        'modname' => $modname,
        'instance' => 1000 + $sourceid,
        'idnumber' => '',
        'effective_idnumber' => 'MIG-P5-MOD-' . str_pad((string)$sourceid, 32, '0', STR_PAD_LEFT),
        'name' => 'Actividad ' . $sourceid,
        'module_key' => $modname . '|module-' . $sourceid,
    ];
}
function rc17_tree(string $root, array $primaryxmls): array {
    $modules = [];
    foreach ($primaryxmls as $sourceid => $definition) {
        $modname = $definition['modname'] ?? 'assign';
        $module = rc17_module((int)$sourceid, $modname);
        $modules[(int)$sourceid] = $module;
        $directory = $root . '/activities/' . $modname . '_' . $sourceid;
        rc17_xml($directory . '/module.xml', '<module id="' . $sourceid . '">' .
            '<modulename>' . $modname . '</modulename><idnumber></idnumber></module>');
        if (array_key_exists('xml', $definition)) {
            rc17_xml($directory . '/' . $modname . '.xml', $definition['xml']);
        }
    }
    return $modules;
}
function rc17_expect_block(callable $action, string $needle, string $message): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc17_check(str_contains($error->getMessage(), $needle),
            $message . ': ' . $error->getMessage());
    }
}

$root = sys_get_temp_dir() . '/consolidador-rc17-context-' . bin2hex(random_bytes(6));
try {
    $valid = $root . '/valid';
    $validmodules = rc17_tree($valid, [
        123 => ['xml' => '<activity moduleid="123" modulename="assign" contextid="500"><assign/></activity>'],
        124 => ['xml' => '<activity module_id="124" modname="assign" context_id="501"><assign/></activity>'],
        125 => ['xml' => '<activity><moduleid>125</moduleid><modulename>assign</modulename><contextid>502</contextid><assign/></activity>'],
        126 => ['xml' => '<activity><module_id>126</module_id><modname>assign</modname><context_id>503</context_id><assign/></activity>'],
    ]);
    $evidence1 = null;
    $activities1 = p5_rewrite_backup_module_idnumbers($valid, $validmodules, $evidence1);
    rc17_check($activities1[123]['context_id'] === 500,
        'no extrajo contextid=500 del XML primario');
    rc17_check($evidence1['module_contexts_total'] === 4 &&
        $evidence1['module_contexts_resolved'] === 4 &&
        $evidence1['module_contexts_missing'] === 0 &&
        $evidence1['module_contexts_ambiguous'] === 0 &&
        $evidence1['module_context_duplicates'] === 0,
        'métricas válidas incorrectas');
    rc17_check(is_string($evidence1['module_context_mapping_sha256']) &&
        strlen($evidence1['module_context_mapping_sha256']) === 64,
        'no generó sello determinista del mapeo');
    $evidence2 = null;
    $activities2 = p5_rewrite_backup_module_idnumbers($valid, $validmodules, $evidence2);
    rc17_check($activities1 === $activities2 && $evidence1 === $evidence2,
        'extracción repetida no es determinista');
    $report = null;
    p5_effective_inventory_from_backup(
        ['relations' => []],
        ['modules_by_source_id' => $validmodules],
        $valid,
        $activities1,
        $report,
        $evidence1
    );
    rc17_check($report['status'] === 'passed' &&
        $report['module_contexts_total'] === 4 &&
        $report['module_contexts_resolved'] === 4,
        'el reporte no expuso métricas de contexto');

    $cases = [
        'missing-primary' => [
            'definitions' => [201 => []],
            'error' => 'MODULE_CONTEXT_MISSING',
        ],
        'moduleid-mismatch' => [
            'definitions' => [202 => ['xml' => '<activity moduleid="999" modulename="assign" contextid="600"><assign/></activity>']],
            'error' => 'MODULE_PRIMARY_XML_MODULE_ID_MISMATCH',
        ],
        'modname-mismatch' => [
            'definitions' => [203 => ['xml' => '<activity moduleid="203" modulename="quiz" contextid="601"><assign/></activity>']],
            'error' => 'MODULE_PRIMARY_XML_MODNAME_MISMATCH',
        ],
        'missing-context' => [
            'definitions' => [204 => ['xml' => '<activity moduleid="204" modulename="assign"><assign/></activity>']],
            'error' => 'MODULE_CONTEXT_MISSING',
        ],
        'zero-context' => [
            'definitions' => [205 => ['xml' => '<activity moduleid="205" modulename="assign" contextid="0"><assign/></activity>']],
            'error' => 'MODULE_CONTEXT_MISSING',
        ],
        'ambiguous-context' => [
            'definitions' => [206 => ['xml' => '<activity moduleid="206" modulename="assign" contextid="602"><context_id>603</context_id><assign/></activity>']],
            'error' => 'MODULE_CONTEXT_AMBIGUOUS',
        ],
        'duplicate-context' => [
            'definitions' => [
                207 => ['xml' => '<activity moduleid="207" modulename="assign" contextid="604"><assign/></activity>'],
                208 => ['xml' => '<activity moduleid="208" modulename="assign" contextid="604"><assign/></activity>'],
            ],
            'error' => 'MODULE_CONTEXT_DUPLICATE',
        ],
    ];
    foreach ($cases as $name => $case) {
        $casepath = $root . '/' . $name;
        $modules = rc17_tree($casepath, $case['definitions']);
        $evidence = null;
        rc17_expect_block(function () use ($casepath, $modules, &$evidence): void {
            p5_rewrite_backup_module_idnumbers($casepath, $modules, $evidence);
        }, $case['error'], $name . ' no bloqueó');
        if ($case['error'] === 'MODULE_CONTEXT_AMBIGUOUS') {
            rc17_check($evidence['module_contexts_ambiguous'] === 1,
                'contexto ambiguo no quedó cuantificado');
        }
        if ($case['error'] === 'MODULE_CONTEXT_DUPLICATE') {
            rc17_check($evidence['module_context_duplicates'] === 1,
                'contexto duplicado no quedó cuantificado');
        }
    }

    echo "RC17_ACTIVITY_CONTEXTS_OK module_xml_without_context=yes primary=4 missing=blocked mismatch=blocked ambiguous=blocked duplicate=blocked deterministic=yes\n";
} finally {
    rc17_remove($root);
}
