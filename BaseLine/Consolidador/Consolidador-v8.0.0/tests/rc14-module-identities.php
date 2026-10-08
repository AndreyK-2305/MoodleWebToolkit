<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
    public static function strlen(string $value): int { return mb_strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');

function rc14_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException('RC14_MODULE_TEST_FAILED ' . $message); }
}
function rc14_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) { rc14_remove($item->getPathname()); }
    rmdir($path);
}
function rc14_copy_tree(string $source, string $target): void {
    mkdir($target, 0770, true);
    foreach (new FilesystemIterator($source) as $item) {
        $destination = $target . '/' . $item->getFilename();
        if ($item->isDir()) { rc14_copy_tree($item->getPathname(), $destination); }
        else { copy($item->getPathname(), $destination); }
    }
}
function rc14_tree_hash(string $directory): string {
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
function rc14_xml(string $path, string $contents): void {
    $directory = dirname($path);
    if (!is_dir($directory)) { mkdir($directory, 0770, true); }
    file_put_contents($path, $contents);
}

$fixture = json_decode((string)file_get_contents(
    __DIR__ . '/fixtures/rc14-repeated-modules.json'
), true, 512, JSON_THROW_ON_ERROR);
$inventory = [
    'course' => ['source_course_id' => $fixture['source_course_id']],
    'modules' => [],
    'relations' => [
        'assignment_submissions' => [
            ['source_user_id' => 21, 'activity_key' => 'assign|entrega', 'status' => 'submitted'],
            ['source_user_id' => 22, 'activity_key' => 'assign|entrega', 'status' => 'submitted'],
        ],
        'assignment_grades' => [
            ['source_user_id' => 21, 'activity_key' => 'assign|entrega', 'grade' => 4.5],
            ['source_user_id' => 22, 'activity_key' => 'assign|entrega', 'grade' => 3.8],
        ],
        'forum_discussions' => [
            ['source_user_id' => 31, 'activity_key' => 'forum|foro', 'subject' => 'Tema A'],
            ['source_user_id' => 32, 'activity_key' => 'forum|foro', 'subject' => 'Tema B'],
        ],
        'forum_posts' => [
            ['source_user_id' => 31, 'activity_key' => 'forum|foro', 'subject' => 'Mensaje A'],
            ['source_user_id' => 32, 'activity_key' => 'forum|foro', 'subject' => 'Mensaje B'],
        ],
        'quiz_attempts' => [[
            'source_user_id' => 41, 'activity_key' => 'quiz|quiz-institucional',
            'quiz_id' => 1012, 'source_module_id' => 112, 'state' => 'finished',
            'sumgrades' => 9.25, 'attempt' => 1, 'timestart' => 1700000000,
            'timefinish' => 1700000900, 'preview' => 0, 'attempt_id' => 7001,
        ]],
        'activity_completions' => [
            ['source_user_id' => 21, 'activity_key' => 'assign|entrega', 'completion_state' => 1],
            ['source_user_id' => 22, 'activity_key' => 'assign|entrega', 'completion_state' => 2],
        ],
        'course_completions' => [],
        'files' => [
            ['source_user_id' => 21, 'activity_key' => 'assign|entrega', 'component' => 'assignsubmission_file', 'filearea' => 'submission_files', 'filename' => 'a.pdf'],
            ['source_user_id' => 22, 'activity_key' => 'assign|entrega', 'component' => 'assignsubmission_file', 'filearea' => 'submission_files', 'filename' => 'b.pdf'],
        ],
    ],
];
foreach ($fixture['modules'] as $module) {
    $module['module_key'] = p5_module_key($module['modname'], $module['idnumber'], $module['name']);
    $module['completion_mode'] = 1;
    $inventory['modules'][] = $module;
}

$plan1 = p5_prepare_module_identities(
    $inventory, $fixture['source_id'], (int)$fixture['source_course_id']
);
$plan2 = p5_prepare_module_identities(
    $inventory, $fixture['source_id'], (int)$fixture['source_course_id']
);
rc14_check($plan1['audit_rows'] === $plan2['audit_rows'], 'la identidad no es idempotente');
rc14_check($plan1['metrics']['module_idnumbers_generated'] === 11 &&
    $plan1['metrics']['module_idnumbers_preserved'] === 1 &&
    $plan1['metrics']['module_keys_unique'] === 12,
    'métricas incorrectas');
$keys = array_column($plan1['inventory']['modules'], 'module_key');
rc14_check(count($keys) === count(array_unique($keys)), 'nombres repetidos colisionaron');
foreach ($plan1['inventory']['modules'] as $module) {
    rc14_check(strlen($module['effective_idnumber']) <= 100, 'idnumber demasiado largo');
    if ((int)$module['source_module_id'] === 112) {
        rc14_check($module['effective_idnumber'] === 'QUIZ-INSTITUCIONAL' &&
            $module['idnumber_origin'] === 'original', 'idnumber original alterado');
    } else {
        rc14_check(preg_match('/^MIG-P5-MOD-[A-F0-9]{32}$/',
            $module['effective_idnumber']) === 1, 'namespace técnico inválido');
    }
}

$root = sys_get_temp_dir() . '/consolidador-rc14-' . bin2hex(random_bytes(6));
$raw = $root . '/raw';
$normalized = $root . '/normalized';
mkdir($raw . '/activities', 0770, true);
try {
    foreach ($fixture['modules'] as $module) {
        $directory = $raw . '/activities/' . $module['modname'] . '_' . $module['source_module_id'];
        $contextid = match ((int)$module['source_module_id']) {
            108 => 9001,
            109 => 9002,
            default => 10000 + (int)$module['source_module_id'],
        };
        rc14_xml($directory . '/module.xml', '<module id="' . $module['source_module_id'] . '">' .
            '<modulename>' . $module['modname'] . '</modulename><idnumber>' .
            htmlspecialchars($module['idnumber'], ENT_XML1) . '</idnumber></module>');
        rc14_xml($directory . '/' . $module['modname'] . '.xml',
            '<activity moduleid="' . $module['source_module_id'] . '" modulename="' .
            $module['modname'] . '" contextid="' . $contextid . '"><' .
            $module['modname'] . '/></activity>');
    }
    rc14_xml($raw . '/activities/assign_108/assign.xml',
        '<activity moduleid="108" modulename="assign" contextid="9001"><assign id="1008"><submissions><submission id="1"><userid>21</userid><status>submitted</status><latest>1</latest></submission></submissions>' .
        '<grades><grade id="11"><userid>21</userid><grade>4.5</grade></grade></grades></assign></activity>');
    rc14_xml($raw . '/activities/assign_109/assign.xml',
        '<activity moduleid="109" modulename="assign" contextid="9002"><assign id="1009"><submissions><submission id="2"><userid>22</userid><status>submitted</status><latest>1</latest></submission></submissions>' .
        '<grades><grade id="12"><userid>22</userid><grade>3.8</grade></grade></grades></assign></activity>');
    rc14_xml($raw . '/activities/assign_108/completion.xml',
        '<course_module_completion><completions><completion id="1"><userid>21</userid><completionstate>1</completionstate></completion></completions></course_module_completion>');
    rc14_xml($raw . '/activities/assign_109/completion.xml',
        '<course_module_completion><completions><completion id="2"><userid>22</userid><completionstate>2</completionstate></completion></completions></course_module_completion>');
    rc14_xml($raw . '/activities/assign_108/inforef.xml',
        '<inforef><fileref><file><id>501</id></file></fileref></inforef>');
    rc14_xml($raw . '/activities/assign_109/inforef.xml',
        '<inforef><fileref><file><id>502</id></file></fileref></inforef>');
    rc14_xml($raw . '/activities/forum_110/forum.xml',
        '<activity moduleid="110" modulename="forum" contextid="10110"><forum id="1010"><discussions><discussion id="1"><userid>31</userid><name>Tema A</name><posts><post id="3"><userid>31</userid><subject>Mensaje A</subject></post></posts></discussion></discussions></forum></activity>');
    rc14_xml($raw . '/activities/forum_111/forum.xml',
        '<activity moduleid="111" modulename="forum" contextid="10111"><forum id="1011"><discussions><discussion id="2"><userid>32</userid><name>Tema B</name><posts><post id="4"><userid>32</userid><subject>Mensaje B</subject></post></posts></discussion></discussions></forum></activity>');
    rc14_xml($raw . '/activities/quiz_112/quiz.xml',
        '<activity moduleid="112" modulename="quiz" contextid="10112"><quiz id="1012"><attempts><attempt id="7001"><userid>41</userid><state>finished</state><sumgrades>9.25</sumgrades><attempt>1</attempt><timestart>1700000000</timestart><timefinish>1700000900</timefinish><preview>0</preview></attempt></attempts></quiz></activity>');
    rc14_xml($raw . '/files.xml', '<files>' .
        '<file id="501"><contextid>9001</contextid><userid>21</userid><component>assignsubmission_file</component><filearea>submission_files</filearea><filename>a.pdf</filename></file>' .
        '<file id="502"><contextid>9002</contextid><userid>22</userid><component>assignsubmission_file</component><filearea>submission_files</filearea><filename>b.pdf</filename></file>' .
        '</files>');
    $rawhash = rc14_tree_hash($raw);
    rc14_copy_tree($raw, $normalized);
    $activities = p5_rewrite_backup_module_idnumbers(
        $normalized, $plan1['modules_by_source_id']
    );
    $effective = p5_effective_inventory_from_backup(
        $plan1['inventory'], $plan1, $normalized, $activities
    );
    rc14_check(rc14_tree_hash($raw) === $rawhash, 'el backup original fue modificado');
    foreach ($activities as $sourceid => $activity) {
        $dom = new DOMDocument();
        $dom->load($activity['path'] . '/module.xml');
        rc14_check(p5_dom_text($dom->documentElement, 'idnumber') ===
            $plan1['modules_by_source_id'][$sourceid]['effective_idnumber'],
            'module.xml normalizado no contiene el idnumber efectivo');
    }
    foreach (['assignment_submissions', 'assignment_grades', 'forum_discussions',
        'forum_posts', 'activity_completions', 'files'] as $relation) {
        $relationkeys = array_column($effective['relations'][$relation], 'activity_key');
        rc14_check(count(array_unique($relationkeys)) === 2,
            $relation . ' no quedó asociado a los dos módulos reales');
    }
    rc14_check($effective['relations']['quiz_attempts'][0]['activity_key'] ===
        $plan1['modules_by_source_id'][112]['module_key'], 'quiz perdió su módulo');

    // Simula el inventario que Moodle 5.2 leerá después de Restore: los IDs
    // internos cambian, pero idnumber y module_key sobreviven.
    $targetkeys = [];
    foreach ($activities as $activity) {
        $module = $activity['module'];
        $targetkeys[] = p5_module_key($module['modname'], $module['effective_idnumber'], $module['name']);
    }
    sort($targetkeys, SORT_STRING);
    $sourcekeys = array_column($effective['modules'], 'module_key');
    sort($sourcekeys, SORT_STRING);
    rc14_check($sourcekeys === $targetkeys, 'source y target no producen llaves equivalentes');

    $duplicate = $inventory;
    $duplicate['modules'][0]['idnumber'] = 'DUPLICADO';
    $duplicate['modules'][1]['idnumber'] = 'duplicado';
    try {
        p5_prepare_module_identities($duplicate, 'posgrados', 77);
        throw new RuntimeException('duplicate_not_detected');
    } catch (RuntimeException $error) {
        rc14_check(str_contains($error->getMessage(), 'MODULE_IDNUMBER_DUPLICATE'),
            'idnumber no vacío duplicado no bloqueó');
    }

    $large = $inventory;
    $large['modules'] = [];
    for ($index = 1; $index <= 545; $index++) {
        $large['modules'][] = [
            'source_module_id' => 2000 + $index,
            'modname' => $index <= 182 ? 'label' : 'resource',
            'instance' => 5000 + $index,
            'idnumber' => '',
            'name' => $index <= 182 ? 'Etiqueta' : 'Plantilla de Proyecto',
            'module_key' => $index <= 182 ? 'label|etiqueta' : 'resource|plantilla de proyecto',
            'completion_mode' => 0,
        ];
    }
    $largeplan = p5_prepare_module_identities($large, 'posgrados', 77);
    rc14_check(count($largeplan['inventory']['modules']) === 545 &&
        count(array_unique(array_column($largeplan['inventory']['modules'], 'module_key'))) === 545,
        'benchmark 545 no produjo 545 llaves');
    $auditpath = $root . '/module_idnumber_normalization.csv';
    p5_write_csv($auditpath, [
        'source_module_id', 'modname', 'instance', 'name',
        'original_idnumber', 'effective_idnumber', 'reason',
    ], $plan1['audit_rows']);
    $auditpaths = ['module_idnumber_normalization.csv' => $auditpath];
    $audithashes = p5_hash_files($auditpaths);
    p5_assert_artifact_hashes($audithashes, $auditpaths);
    file_put_contents($auditpath, "alterado\n", FILE_APPEND);
    try {
        p5_assert_artifact_hashes($audithashes, $auditpaths);
        throw new RuntimeException('tampered_audit_accepted');
    } catch (RuntimeException $error) {
        rc14_check(str_contains($error->getMessage(), 'cambió después'),
            'audit manipulado no bloqueó');
    }
    echo "RC14_MODULE_IDENTITIES_OK fixture=12 benchmark=545 relations=7 tamper=blocked\n";
} finally {
    rc14_remove($root);
}
