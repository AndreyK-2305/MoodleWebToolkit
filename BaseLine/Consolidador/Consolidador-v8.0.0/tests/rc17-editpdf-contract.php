<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
    public static function strlen(string $value): int { return mb_strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');

function rc17e_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException('RC17_EDITPDF_TEST_FAILED ' . $message); }
}
function rc17e_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) { rc17e_remove($item->getPathname()); }
    rmdir($path);
}
function rc17e_xml(string $path, string $contents): void {
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0770, true); }
    file_put_contents($path, $contents);
}

$root = sys_get_temp_dir() . '/consolidador-rc17-editpdf-' . bin2hex(random_bytes(6));
try {
    $directory = $root . '/activities/assign_321';
    rc17e_xml($directory . '/module.xml',
        '<module id="321"><modulename>assign</modulename><idnumber></idnumber></module>');
    rc17e_xml($directory . '/assign.xml',
        '<activity moduleid="321" modulename="assign" contextid="700"><assign/></activity>');
    $files = [
        [1, 'assignfeedback_editpdf', 'readonlypages', 'read.png', 0],
        [2, 'assignfeedback_editpdf', 'stamps', 'stamp.png', 10],
        [3, 'assignfeedback_editpdf', 'combined', 'combined.pdf', 10],
        [4, 'assignfeedback_editpdf', 'pages', 'page.png', 10],
        [5, 'assignfeedback_editpdf', 'partial', 'partial.pdf', 10],
        [6, 'mod_assign', 'intro', 'zero.txt', 0],
    ];
    $filesxml = '<files>';
    foreach ($files as [$id, $component, $filearea, $filename, $filesize]) {
        $filesxml .= '<file id="' . $id . '"><contextid>700</contextid><userid>0</userid>' .
            '<component>' . $component . '</component><filearea>' . $filearea .
            '</filearea><filename>' . $filename . '</filename><filesize>' .
            $filesize . '</filesize></file>';
    }
    rc17e_xml($root . '/files.xml', $filesxml . '</files>');

    $module = [
        'source_module_id' => 321,
        'modname' => 'assign',
        'instance' => 9321,
        'idnumber' => '',
        'effective_idnumber' => 'MIG-P5-MOD-00000000000000000000000000000321',
        'name' => 'Entrega',
        'module_key' => 'assign|entrega-321',
    ];
    $inventoryfiles = [];
    foreach ($files as [, $component, $filearea, $filename]) {
        $inventoryfiles[] = [
            'source_user_id' => 0,
            'activity_key' => 'legacy',
            'component' => $component,
            'filearea' => $filearea,
            'filename' => $filename,
        ];
    }
    $inventory = [
        'counts' => ['module_files' => 6],
        'relations' => ['files' => $inventoryfiles],
    ];
    $plan = ['modules_by_source_id' => [321 => $module]];
    $contextmetrics = null;
    $activities = p5_rewrite_backup_module_idnumbers($root, $plan['modules_by_source_id'], $contextmetrics);
    $report = null;
    $effective = p5_effective_inventory_from_backup(
        $inventory, $plan, $root, $activities, $report, $contextmetrics
    );
    $kept = array_column($effective['relations']['files'], 'filename');
    sort($kept, SORT_STRING);
    rc17e_check($kept === ['read.png', 'stamp.png', 'zero.txt'],
        'la lista estricta de excepciones editpdf cambió');
    rc17e_check($report['relations']['files']['inventory_rows'] === 6 &&
        $report['relations']['files']['backup_candidates'] === 6 &&
        $report['relations']['files']['comparable_inventory_rows'] === 3 &&
        $report['relations']['files']['comparable_backup_candidates'] === 3 &&
        $report['files']['files_regenerable_excluded_inventory'] === 3 &&
        $report['files']['files_regenerable_excluded_candidates'] === 3 &&
        $report['files']['files_multiset_signature_difference'] === 0 &&
        $report['files']['files_multiset_row_difference'] === 0 &&
        $effective['counts']['module_files'] === 3,
        'métricas de archivos comparables incorrectas');

    echo "RC17_EDITPDF_CONTRACT_OK combined=excluded pages=excluded partial=excluded readonlypages=strict stamps=strict zero_size=included\n";
} finally {
    rc17e_remove($root);
}
