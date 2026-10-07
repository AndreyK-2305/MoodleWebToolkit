<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
    public static function strlen(string $value): int { return mb_strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');

function rc16_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException('RC16_FILE_CONTEXT_TEST_FAILED ' . $message); }
}
function rc16_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) { rc16_remove($item->getPathname()); }
    rmdir($path);
}
function rc16_copy_tree(string $source, string $target): void {
    mkdir($target, 0770, true);
    foreach (new FilesystemIterator($source) as $item) {
        $destination = $target . '/' . $item->getFilename();
        if ($item->isDir()) { rc16_copy_tree($item->getPathname(), $destination); }
        else { copy($item->getPathname(), $destination); }
    }
}
function rc16_tree_hash(string $directory): string {
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
function rc16_xml(string $path, string $contents): void {
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0770, true); }
    file_put_contents($path, $contents);
}
function rc16_file_xml(array $file): string {
    return '<file id="' . $file['id'] . '"><contextid>' . $file['context_id'] .
        '</contextid><userid>' . $file['source_user_id'] . '</userid><component>' .
        $file['component'] . '</component><filearea>' . $file['filearea'] .
        '</filearea><filename>' . $file['filename'] . '</filename><filesize>' .
        $file['filesize'] . '</filesize></file>';
}
function rc16_expect_block(callable $action, string $needle, string $message): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc16_check(str_contains($error->getMessage(), $needle),
            $message . ': ' . $error->getMessage());
    }
}

$fixture = json_decode((string)file_get_contents(
    __DIR__ . '/fixtures/rc16-file-contexts.json'
), true, 512, JSON_THROW_ON_ERROR);
$inventory = [
    'course' => ['source_course_id' => $fixture['source_course_id']],
    'counts' => ['module_files' => 2],
    'modules' => [],
    'relations' => ['files' => []],
];
foreach ($fixture['modules'] as $module) {
    $module['module_key'] = p5_module_key($module['modname'], $module['idnumber'], $module['name']);
    $module['completion_mode'] = 1;
    $inventory['modules'][] = $module;
}
foreach ($fixture['module_files'] as $file) {
    $inventory['relations']['files'][] = [
        'source_user_id' => $file['source_user_id'],
        'activity_key' => 'legacy|' . $file['context_id'],
        'component' => $file['component'],
        'filearea' => $file['filearea'],
        'filename' => $file['filename'],
    ];
}
$plan = p5_prepare_module_identities(
    $inventory, $fixture['source_id'], (int)$fixture['source_course_id']
);
$root = sys_get_temp_dir() . '/consolidador-rc16-' . bin2hex(random_bytes(6));
$raw = $root . '/raw';
mkdir($raw . '/activities', 0770, true);
try {
    foreach ($fixture['modules'] as $module) {
        $directory = $raw . '/activities/' . $module['modname'] . '_' . $module['source_module_id'];
        rc16_xml($directory . '/module.xml', '<module id="' . $module['source_module_id'] . '">' .
            '<modulename>' . $module['modname'] . '</modulename><idnumber></idnumber></module>');
        rc16_xml($directory . '/' . $module['modname'] . '.xml',
            '<activity moduleid="' . $module['source_module_id'] . '" modulename="' .
            $module['modname'] . '" contextid="' . $module['context_id'] . '"><' .
            $module['modname'] . '/></activity>');
    }
    rc16_xml($raw . '/activities/hvp_201/inforef.xml',
        '<inforef><fileref>' .
        '<file><id>601</id></file><file><id>700</id></file>' .
        '<file><id>704</id></file><file><id>701</id></file>' .
        '</fileref></inforef>');
    $allfiles = array_merge($fixture['non_module_files'], $fixture['module_files']);
    $filesxml = '<files>';
    foreach ($allfiles as $file) { $filesxml .= rc16_file_xml($file); }
    // filename="." se excluye por el contrato histórico, no por filesize.
    $filesxml .= '<file id="800"><contextid>9201</contextid><userid>0</userid>' .
        '<component>mod_hvp</component><filearea>content</filearea>' .
        '<filename>.</filename><filesize>0</filesize></file></files>';
    rc16_xml($raw . '/files.xml', $filesxml);

    $rawhash = rc16_tree_hash($raw);
    $rawfileshash = hash_file('sha256', $raw . '/files.xml');
    $work = $root . '/work';
    rc16_copy_tree($raw, $work);
    $activities = p5_rewrite_backup_module_idnumbers(
        $work, $plan['modules_by_source_id']
    );
    $report = null;
    $effective = p5_effective_inventory_from_backup(
        $plan['inventory'], $plan, $work, $activities, $report
    );
    $metrics = $report['files'];
    rc16_check($report['status'] === 'passed', 'la población válida no reconcilió');
    rc16_check($metrics['files_xml_rows_total'] === 8 &&
        $metrics['files_module_context_candidates'] === 2 &&
        $metrics['files_backup_candidates'] === 2 &&
        $metrics['files_matched_by_context'] === 1 &&
        $metrics['files_confirmed_by_inforef'] === 1 &&
        $metrics['files_matched_by_file_id'] === 1 &&
        $metrics['files_inforef_only_excluded'] === 3 &&
        $metrics['files_non_module_context_excluded'] === 5 &&
        $metrics['files_unattributed_nonmodule_excluded'] === 2 &&
        $metrics['files_field_contract_excluded'] === 1 &&
        $metrics['files_context_inforef_conflicts'] === 0 &&
        $metrics['files_unresolved'] === 0 &&
        $metrics['files_ambiguous'] === 0 &&
        $metrics['files_multiset_difference'] === 0,
        'métricas de contexto incorrectas');
    rc16_check(count($effective['relations']['files']) === 2 &&
        $effective['counts']['module_files'] === 2,
        'la población efectiva no coincide con CONTEXT_MODULE');
    $effectivebyfilename = [];
    foreach ($effective['relations']['files'] as $row) {
        $effectivebyfilename[$row['filename']] = $row;
    }
    rc16_check(isset($effectivebyfilename['actividad.h5p']) &&
        isset($effectivebyfilename['guia.pdf']),
        'faltó un archivo directo de módulo');
    rc16_check(!isset($effectivebyfilename['library.js']) &&
        !isset($effectivebyfilename['library.css']) &&
        !isset($effectivebyfilename['f1.png']) &&
        !isset($effectivebyfilename['section.png']) &&
        !isset($effectivebyfilename['overview.png']),
        'un archivo de otro contexto amplió relations.files');
    rc16_check($effectivebyfilename['actividad.h5p']['activity_key'] ===
        $plan['modules_by_source_id'][201]['module_key'] &&
        $effectivebyfilename['guia.pdf']['activity_key'] ===
        $plan['modules_by_source_id'][202]['module_key'],
        'contextid no determinó el módulo efectivo');
    rc16_check(rc16_tree_hash($raw) === $rawhash &&
        hash_file('sha256', $work . '/files.xml') === $rawfileshash,
        'files.xml o el árbol original fueron modificados');
    $sample = $metrics['excluded_file_evidence'];
    rc16_check(count($sample) === 5, 'la muestra excluida no tiene la población esperada');
    $samplejson = array_map(static fn(array $row): string => json_encode(
        $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ), $sample);
    $sortedsample = $samplejson;
    sort($sortedsample, SORT_STRING);
    rc16_check($samplejson === $sortedsample, 'la muestra no es determinista');
    $reasons = array_count_values(array_column($sample, 'reason'));
    rc16_check(($reasons['inforef_reference_non_module_context'] ?? 0) === 3 &&
        ($reasons['non_module_context'] ?? 0) === 2,
        'el diagnóstico no distingue exclusiones');

    $report2 = null;
    $effective2 = p5_effective_inventory_from_backup(
        $plan['inventory'], $plan, $work, $activities, $report2
    );
    rc16_check($effective === $effective2 && $report === $report2,
        'la reconciliación no es idempotente');

    $different = $plan['inventory'];
    $different['relations']['files'][] = [
        'source_user_id' => 0,
        'activity_key' => 'legacy|ghost',
        'component' => 'mod_assign',
        'filearea' => 'intro',
        'filename' => 'missing.pdf',
    ];
    $differenceReport = null;
    rc16_expect_block(function () use ($different, $plan, $work, $activities, &$differenceReport): void {
        p5_effective_inventory_from_backup($different, $plan, $work, $activities, $differenceReport);
    }, 'MODULE_RELATION_MULTISET_INCONSISTENT', 'una diferencia real no bloqueó');
    rc16_check($differenceReport['files']['files_unresolved'] === 1 &&
        $differenceReport['files']['files_multiset_difference'] === 1,
        'la diferencia real no quedó cuantificada');

    $conflict = $root . '/conflict';
    rc16_copy_tree($raw, $conflict);
    rc16_xml($conflict . '/activities/hvp_201/inforef.xml',
        '<inforef><fileref><file><id>601</id></file><file><id>602</id></file></fileref></inforef>');
    $conflictactivities = p5_rewrite_backup_module_idnumbers(
        $conflict, $plan['modules_by_source_id']
    );
    $conflictReport = null;
    rc16_expect_block(function () use ($plan, $conflict, $conflictactivities, &$conflictReport): void {
        p5_effective_inventory_from_backup($plan['inventory'], $plan, $conflict, $conflictactivities, $conflictReport);
    }, 'MODULE_FILE_EVIDENCE_CONFLICT', 'contexto e inforef contradictorios no bloquearon');
    rc16_check($conflictReport['files']['files_context_inforef_conflicts'] === 1 &&
        $conflictReport['files']['files_ambiguous'] === 1 &&
        $conflictReport['files']['excluded_file_evidence'][0]['reason'] ===
            'context_inforef_conflict',
        'el conflicto no quedó diagnosticado');

    $duplicate = $root . '/duplicate';
    rc16_copy_tree($raw, $duplicate);
    rc16_xml($duplicate . '/activities/assign_202/inforef.xml',
        '<inforef><fileref><file><id>700</id></file></fileref></inforef>');
    $duplicateactivities = p5_rewrite_backup_module_idnumbers(
        $duplicate, $plan['modules_by_source_id']
    );
    $duplicateReport = null;
    rc16_expect_block(function () use ($plan, $duplicate, $duplicateactivities, &$duplicateReport): void {
        p5_effective_inventory_from_backup($plan['inventory'], $plan, $duplicate, $duplicateactivities, $duplicateReport);
    }, 'MODULE_FILE_ID_AMBIGUOUS', 'file_id duplicado en inforef no bloqueó');
    rc16_check($duplicateReport['files']['files_ambiguous'] === 1 &&
        $duplicateReport['files']['excluded_file_evidence'][0]['reason'] ===
            'duplicate_inforef_assignment',
        'el inforef duplicado no quedó diagnosticado');

    echo "RC16_FILE_CONTEXT_OK xml=8 module=2 confirmed=1 context=1 inforef_only=3 nonmodule=5 zero_size=included conflict=blocked duplicate=blocked immutable=yes\n";
} finally {
    rc16_remove($root);
}
