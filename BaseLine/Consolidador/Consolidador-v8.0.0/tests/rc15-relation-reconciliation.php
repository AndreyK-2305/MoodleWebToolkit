<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
    public static function strlen(string $value): int { return mb_strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');

function rc15_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException('RC15_RELATION_TEST_FAILED ' . $message); }
}
function rc15_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) { rc15_remove($item->getPathname()); }
    rmdir($path);
}
function rc15_copy_tree(string $source, string $target): void {
    mkdir($target, 0770, true);
    foreach (new FilesystemIterator($source) as $item) {
        $destination = $target . '/' . $item->getFilename();
        if ($item->isDir()) { rc15_copy_tree($item->getPathname(), $destination); }
        else { copy($item->getPathname(), $destination); }
    }
}
function rc15_tree_hash(string $directory): string {
    $rows = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $directory, FilesystemIterator::SKIP_DOTS
    ));
    foreach ($iterator as $item) {
        if (!$item->isFile()) { continue; }
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($directory) + 1));
        $rows[] = $relative . ':' . hash_file('sha256', $item->getPathname());
    }
    sort($rows, SORT_STRING);
    return hash('sha256', implode("\n", $rows));
}
function rc15_xml(string $path, string $contents): void {
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0770, true); }
    file_put_contents($path, $contents);
}
function rc15_prepare(string $tree, array $plan): array {
    $activities = p5_rewrite_backup_module_idnumbers(
        $tree, $plan['modules_by_source_id']
    );
    return [$activities, $plan];
}
function rc15_expect_block(callable $action, string $needle, string $message): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc15_check(str_contains($error->getMessage(), $needle), $message . ': ' . $error->getMessage());
    }
}

$fixture = json_decode((string)file_get_contents(
    __DIR__ . '/fixtures/rc15-file-relations.json'
), true, 512, JSON_THROW_ON_ERROR);
$inventory = [
    'course' => ['source_course_id' => $fixture['source_course_id']],
    'counts' => ['module_files' => 4],
    'modules' => [],
    'relations' => [
        // El orden heredado es deliberadamente opuesto al orden de los XML.
        'assignment_submissions' => [
            ['source_user_id' => 1, 'activity_key' => 'assign|entrega', 'status' => 'submitted'],
            ['source_user_id' => 2, 'activity_key' => 'assign|entrega', 'status' => 'submitted'],
        ],
        'files' => [
            ['source_user_id' => 0, 'activity_key' => 'assign|entrega', 'component' => 'mod_assign', 'filearea' => 'intro', 'filename' => 'guia.pdf'],
            ['source_user_id' => 0, 'activity_key' => 'assign|entrega', 'component' => 'mod_assign', 'filearea' => 'intro', 'filename' => 'guia.pdf'],
            ['source_user_id' => 0, 'activity_key' => 'assign|entrega', 'component' => 'mod_assign', 'filearea' => 'intro', 'filename' => 'rubrica.pdf'],
            // Derivado regenerable aprobado: se excluye con el mismo predicado del postrestore.
            ['source_user_id' => 0, 'activity_key' => 'assign|entrega', 'component' => 'assignfeedback_editpdf', 'filearea' => 'pages', 'filename' => 'page1.png'],
        ],
    ],
];
foreach ($fixture['modules'] as $module) {
    $module['module_key'] = p5_module_key($module['modname'], $module['idnumber'], $module['name']);
    $module['completion_mode'] = 1;
    $inventory['modules'][] = $module;
}
$plan = p5_prepare_module_identities(
    $inventory, $fixture['source_id'], (int)$fixture['source_course_id']
);
$root = sys_get_temp_dir() . '/consolidador-rc15-' . bin2hex(random_bytes(6));
$raw = $root . '/raw';
mkdir($raw . '/activities', 0770, true);
try {
    foreach ($fixture['modules'] as $module) {
        $directory = $raw . '/activities/assign_' . $module['source_module_id'];
        rc15_xml($directory . '/module.xml', '<module id="' . $module['source_module_id'] . '">' .
            '<modulename>assign</modulename><idnumber></idnumber></module>');
    }
    // Candidatos XML: user 2 está en module 101 y user 1 en module 102.
    rc15_xml($raw . '/activities/assign_101/assign.xml',
        '<activity moduleid="101" modulename="assign" contextid="9001"><assign><submissions><submission id="2"><userid>2</userid><status>submitted</status><latest>1</latest></submission></submissions></assign></activity>');
    rc15_xml($raw . '/activities/assign_102/assign.xml',
        '<activity moduleid="102" modulename="assign" contextid="9002"><assign><submissions><submission id="1"><userid>1</userid><status>submitted</status><latest>1</latest></submission></submissions></assign></activity>');
    rc15_xml($raw . '/activities/assign_103/assign.xml',
        '<activity moduleid="103" modulename="assign" contextid="9003"><assign/></activity>');
    rc15_xml($raw . '/activities/assign_101/inforef.xml',
        '<inforef><fileref><file><id>501</id></file></fileref></inforef>');
    rc15_xml($raw . '/activities/assign_102/inforef.xml',
        '<inforef><fileref><file><id>502</id></file></fileref></inforef>');
    // 503 no está en inforef: debe resolverse por contextid.
    rc15_xml($raw . '/files.xml', '<files>' .
        '<file id="502"><contextid>9002</contextid><userid>0</userid><component>mod_assign</component><filearea>intro</filearea><filename>guia.pdf</filename></file>' .
        '<file id="503"><contextid>9003</contextid><userid>0</userid><component>mod_assign</component><filearea>intro</filearea><filename>rubrica.pdf</filename></file>' .
        '<file id="501"><contextid>9001</contextid><userid>0</userid><component>mod_assign</component><filearea>intro</filearea><filename>guia.pdf</filename></file>' .
        '</files>');

    $rawhash = rc15_tree_hash($raw);
    $work = $root . '/work';
    rc15_copy_tree($raw, $work);
    [$activities] = rc15_prepare($work, $plan);
    $report1 = null;
    $effective1 = p5_effective_inventory_from_backup(
        $plan['inventory'], $plan, $work, $activities, $report1
    );
    rc15_check(rc15_tree_hash($raw) === $rawhash, 'el árbol original cambió');
    rc15_check($report1['status'] === 'passed', 'la reconciliación válida no pasó');
    rc15_check($report1['files']['files_inventory_rows'] === 4 &&
        $report1['files']['files_backup_candidates'] === 3 &&
        $report1['files']['files_matched_by_file_id'] === 2 &&
        $report1['files']['files_matched_by_context'] === 1 &&
        $report1['files']['files_unresolved'] === 0 &&
        $report1['files']['files_ambiguous'] === 0 &&
        $report1['files']['files_multiset_difference'] === 0 &&
        $report1['files']['files_regenerable_excluded_inventory'] === 1,
        'métricas de archivos incorrectas');
    rc15_check(count($effective1['relations']['files']) === 3 &&
        $effective1['counts']['module_files'] === 3,
        'el derivado editpdf no se filtró de forma compartida');
    $submissionmodules = [];
    foreach ($effective1['relations']['assignment_submissions'] as $row) {
        $submissionmodules[(int)$row['source_user_id']] = $row['activity_key'];
    }
    rc15_check($submissionmodules[2] === $plan['modules_by_source_id'][101]['module_key'] &&
        $submissionmodules[1] === $plan['modules_by_source_id'][102]['module_key'],
        'se reconstruyó por posición en lugar de evidencia estructural');
    $filekeys = array_column($effective1['relations']['files'], 'activity_key');
    rc15_check(count(array_unique($filekeys)) === 3,
        'archivos con nombre/componente/filearea compartidos perdieron su módulo real');

    $report2 = null;
    $effective2 = p5_effective_inventory_from_backup(
        $plan['inventory'], $plan, $work, $activities, $report2
    );
    rc15_check($effective1 === $effective2 && $report1 === $report2,
        'la reconciliación no es idempotente');

    $different = $plan['inventory'];
    $different['relations']['assignment_submissions'][] = [
        'source_user_id' => 99, 'activity_key' => 'assign|entrega', 'status' => 'submitted',
    ];
    $differenceReport = null;
    rc15_expect_block(function () use ($different, $plan, $work, $activities, &$differenceReport): void {
        p5_effective_inventory_from_backup($different, $plan, $work, $activities, $differenceReport);
    }, 'MODULE_RELATION_MULTISET_INCONSISTENT', 'una diferencia real no bloqueó');
    rc15_check($differenceReport['relations']['assignment_submissions']['multiset_difference'] === 1,
        'la diferencia real no quedó diagnosticada');

    $duplicateFile = $root . '/duplicate-file';
    rc15_copy_tree($raw, $duplicateFile);
    rc15_xml($duplicateFile . '/activities/assign_102/inforef.xml',
        '<inforef><fileref><file><id>501</id></file><file><id>502</id></file></fileref></inforef>');
    [$duplicateFileActivities] = rc15_prepare($duplicateFile, $plan);
    $duplicateFileReport = null;
    rc15_expect_block(function () use ($plan, $duplicateFile, $duplicateFileActivities, &$duplicateFileReport): void {
        p5_effective_inventory_from_backup($plan['inventory'], $plan, $duplicateFile, $duplicateFileActivities, $duplicateFileReport);
    }, 'MODULE_FILE_ID_AMBIGUOUS', 'file_id duplicado entre módulos no bloqueó');
    rc15_check($duplicateFileReport['files']['files_ambiguous'] === 1,
        'file_id ambiguo no incrementó la métrica');

    $duplicateContext = $root . '/duplicate-context';
    rc15_copy_tree($raw, $duplicateContext);
    $primary102 = $duplicateContext . '/activities/assign_102/assign.xml';
    rc15_xml($primary102, str_replace('contextid="9002"',
        'contextid="9001"', (string)file_get_contents($primary102)));
    $duplicateContextEvidence = null;
    rc15_expect_block(function () use ($plan, $duplicateContext, &$duplicateContextEvidence): void {
        p5_rewrite_backup_module_idnumbers(
            $duplicateContext, $plan['modules_by_source_id'], $duplicateContextEvidence
        );
    }, 'MODULE_CONTEXT_DUPLICATE', 'contextid duplicado no bloqueó');
    rc15_check($duplicateContextEvidence['module_context_duplicates'] === 1,
        'contextid duplicado no incrementó la métrica');

    echo "RC15_RELATION_RECONCILIATION_OK order=semantic file_id=2 context=1 ambiguous=blocked difference=blocked editpdf=shared immutable=yes idempotent=yes\n";
} finally {
    rc15_remove($root);
}
