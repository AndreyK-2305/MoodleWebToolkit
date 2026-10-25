<?php
// 8.1.0: contrato del mapa de categorías con el caso del laboratorio sintético
// (pregrado y posgrado: Ingeniería en ambos, «Ciencias Básicas»/«Ciencias
// basicas» con subcategorías y una categoría exclusiva por origen).
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value, 'UTF-8'); }
    public static function strlen(string $value): int { return mb_strlen($value, 'UTF-8'); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-backup-users.php');
require_once(__DIR__ . '/../scripts/phase6-effective-inventory.php');
require_once(__DIR__ . '/../scripts/phase6-degradation.php');
require_once(__DIR__ . '/../scripts/phase6-lib.php');
require_once(__DIR__ . '/../scripts/v8-category-map.php');

function cm_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('V8_810_CATEGORY_MAP_FAILED ' . $message);
    }
}

function cm_inventory(string $source, string $name, array $categories, array $courses): array {
    $rows = [];
    foreach ($categories as [$id, $parent, $label, $depth]) {
        $rows[] = ['source_category_id' => $id, 'source_parent_id' => $parent, 'name' => $label,
            'idnumber' => '', 'depth' => $depth];
    }
    $list = [];
    foreach ($courses as [$id, $category, $shortname]) {
        $list[] = ['source_course_id' => $id, 'source_category_id' => $category,
            'shortname' => $shortname, 'fullname' => $shortname . ' completo'];
    }
    return ['source_id' => $source, 'source_name' => $name, 'write_performed' => false,
        'categories' => $rows, 'courses' => $list];
}

$pre = 'pregrado-2026-10-24-directo';
$pos = 'posgrado-2026-10-24-directo';
$inventories = [
    $pre => cm_inventory($pre, 'Pregrado', [
        [1, 0, 'Category 1', 1], [2, 0, 'Ingeniería', 1], [3, 0, 'Ciencias Básicas', 1],
        [4, 3, 'Matemáticas', 2], [5, 0, 'Humanidades', 1],
    ], [[11, 2, 'ING-101'], [12, 2, 'ING-201'], [13, 4, 'MAT-101'], [14, 4, 'MAT-102'],
        [15, 5, 'HUM-101'], [16, 5, 'HUM-102']]),
    $pos => cm_inventory($pos, 'Posgrado', [
        [1, 0, 'Category 1', 1], [7, 0, 'Ingeniería', 1], [8, 0, 'Ciencias basicas', 1],
        [9, 8, 'Estadística', 2], [10, 0, 'Educación', 1],
    ], [[21, 7, 'ING-101'], [22, 7, 'ING-501'], [23, 9, 'EST-501'], [24, 9, 'EST-502'],
        [25, 10, 'EDU-501'], [26, 10, 'EDU-502']]),
];
$aliases = [$pre => 'PRE', $pos => 'POS'];
$existing = [['id' => 1, 'parent_id' => 0, 'name' => 'Categoría 1', 'idnumber' => '', 'depth' => 1, 'course_count' => 0]];
$pilot = ['source_id' => $pos, 'source_course_id' => 25];
$configsha = str_repeat('a', 64);

cm_check(v8cm_normalize('Ciencias Básicas') === v8cm_normalize('  ciencias   basicas '), 'normalización sin tildes');
cm_check(v8cm_normalize('Ingeniería') === 'ingenieria', 'normalización de Ingeniería');
cm_check(v8cm_similarity('Matemáticas', 'Matematica') >= V8CM_SIMILARITY, 'similitud de nombres parecidos');
cm_check(v8cm_similarity('Humanidades', 'Educación') < V8CM_SIMILARITY, 'nombres distintos no se sugieren');

$proposal = v8cm_build_proposal($inventories, $aliases, $existing, $pilot, $configsha);
$proposalagain = v8cm_build_proposal($inventories, $aliases, $existing, $pilot, $configsha);
cm_check(v8cm_encode($proposal) === v8cm_encode($proposalagain), 'la propuesta es determinista');
cm_check(count($proposal['default_mapping']['nodes']) === 10, 'un nodo por categoría de origen');
cm_check($proposal['pilot']['source_category_id'] === 10, 'el piloto se ubica en Educación');
$kinds = [];
foreach ($proposal['suggestions'] as $suggestion) {
    $kinds[$suggestion['a']['name'] . '+' . $suggestion['b']['name']] = $suggestion['kind'];
    $kinds[$suggestion['b']['name'] . '+' . $suggestion['a']['name']] = $suggestion['kind'];
}
cm_check(($kinds['Ingeniería+Ingeniería'] ?? '') === 'same', 'sugiere unir Ingeniería');
cm_check(($kinds['Ciencias Básicas+Ciencias basicas'] ?? '') === 'same', 'sugiere unir Ciencias Básicas');
cm_check(!isset($kinds['Humanidades+Educación']), 'no sugiere Humanidades con Educación');
foreach ($proposal['suggestions'] as $suggestion) {
    cm_check($suggestion['a']['source'] !== $suggestion['b']['source'], 'solo entre orígenes distintos');
}
$proposalsha = hash('sha256', v8cm_encode($proposal));
$audit = ['operator' => 'admin@lab', 'decision_timestamp_utc' => '2026-10-25T12:00:00Z',
    'evidence_reference' => 'mwt:execution:1'];

// La propuesta sin cambios es inválida: dos «Ingeniería» y dos «Category 1»
// quedan hermanas en la raíz.
$default = ['schema_version' => '1.0', 'proposal_sha256' => $proposalsha] + $audit + $proposal['default_mapping'];
$result = v8cm_validate($proposal, $proposalsha, $default);
$codes = array_column($result['issues'], 'code');
cm_check(in_array('SIBLING_DUPLICATE', $codes, true), 'detecta hermanos duplicados');

// El mapa del laboratorio: Ingeniería y Ciencias Básicas unidas, Category 1 de
// ambos orígenes unida a la «Categoría 1» existente, Humanidades y Educación propias.
$map = ['schema_version' => '1.0', 'proposal_sha256' => $proposalsha] + $audit + [
    'nodes' => [
        ['key' => 'E-1', 'existing_category_id' => 1],
        ['key' => 'ING', 'name' => 'Ingeniería', 'parent' => '@top'],
        ['key' => 'CB', 'name' => 'Ciencias Básicas', 'parent' => '@top'],
        ['key' => 'MAT', 'name' => 'Matemáticas', 'parent' => 'CB'],
        ['key' => 'EST', 'name' => 'Estadística', 'parent' => 'CB'],
        ['key' => 'HUM', 'name' => 'Humanidades', 'parent' => '@top'],
        ['key' => 'EDU', 'name' => 'Educación', 'parent' => '@top'],
    ],
    'assignments' => [
        ['source' => $pre, 'source_category_id' => 1, 'node' => 'E-1'],
        ['source' => $pos, 'source_category_id' => 1, 'node' => 'E-1'],
        ['source' => $pre, 'source_category_id' => 2, 'node' => 'ING'],
        ['source' => $pos, 'source_category_id' => 7, 'node' => 'ING'],
        ['source' => $pre, 'source_category_id' => 3, 'node' => 'CB'],
        ['source' => $pos, 'source_category_id' => 8, 'node' => 'CB'],
        ['source' => $pre, 'source_category_id' => 4, 'node' => 'MAT'],
        ['source' => $pos, 'source_category_id' => 9, 'node' => 'EST'],
        ['source' => $pre, 'source_category_id' => 5, 'node' => 'HUM'],
        ['source' => $pos, 'source_category_id' => 10, 'node' => 'EDU'],
    ],
];
$result = v8cm_validate($proposal, $proposalsha, $map);
cm_check($result['issues'] === [], 'el mapa del laboratorio es válido: ' . json_encode($result['issues'], JSON_UNESCAPED_UNICODE));
$nodes = array_column($result['nodes'], null, 'key');
cm_check($nodes['ING']['members'] === [$pos . '|7', $pre . '|2'], 'Ingeniería une ambos orígenes');
cm_check(preg_match('/^MAP-[A-F0-9]{20}$/', $nodes['ING']['marker']) === 1, 'marcador derivado');
cm_check($nodes['MAT']['parent_node'] === 'CB' && $nodes['MAT']['depth'] === 2, 'Matemáticas dentro de Ciencias Básicas');
cm_check($nodes['ING']['parent_category_id'] === 0, 'Ingeniería en la raíz de Moodle');
cm_check($nodes['E-1']['existing_category_id'] === 1 && $nodes['E-1']['marker'] === '', 'la existente conserva su idnumber');
cm_check($result['pilot']['chain'] === ['EDU'] && $result['pilot']['node'] === 'EDU', 'cadena del piloto');

// El marcador depende de la ruta normalizada, no de la clave elegida en la web.
$renamedkeys = $map;
$renamedkeys['nodes'][1]['key'] = 'W-9';
foreach ($renamedkeys['assignments'] as &$assignment) {
    if ($assignment['node'] === 'ING') { $assignment['node'] = 'W-9'; }
}
unset($assignment);
$again = array_column(v8cm_validate($proposal, $proposalsha, $renamedkeys)['nodes'], null, 'key');
cm_check($again['W-9']['marker'] === $nodes['ING']['marker'], 'marcador estable ante claves distintas');

// Errores: ciclo, nombre vacío, sin mapear, asignación duplicada, propuesta cambiada,
// homónima existente sin marcador y padre inexistente.
$broken = $map;
$broken['nodes'][2]['parent'] = 'MAT';
cm_check(in_array('CYCLE', array_column(v8cm_validate($proposal, $proposalsha, $broken)['issues'], 'code'), true), 'ciclo');
$broken = $map;
$broken['nodes'][5]['name'] = '   ';
cm_check(in_array('NAME_EMPTY', array_column(v8cm_validate($proposal, $proposalsha, $broken)['issues'], 'code'), true), 'nombre vacío');
$broken = $map;
array_pop($broken['assignments']);
cm_check(in_array('UNMAPPED', array_column(v8cm_validate($proposal, $proposalsha, $broken)['issues'], 'code'), true), 'sin mapear');
$broken = $map;
$broken['assignments'][] = ['source' => $pre, 'source_category_id' => 2, 'node' => 'HUM'];
cm_check(in_array('ASSIGNMENT_DUPLICATE', array_column(v8cm_validate($proposal, $proposalsha, $broken)['issues'], 'code'), true), 'duplicada');
cm_check(in_array('PROPOSAL_CHANGED', array_column(v8cm_validate($proposal, str_repeat('b', 64), $map)['issues'], 'code'), true), 'propuesta cambiada');
$broken = $map;
$broken['nodes'][0] = ['key' => 'E-1', 'name' => 'Categoría 1', 'parent' => '@top'];
cm_check(in_array('EXISTING_HOMONYM', array_column(v8cm_validate($proposal, $proposalsha, $broken)['issues'], 'code'), true), 'homónima existente');
$broken = $map;
$broken['nodes'][3]['parent'] = 'NADA';
cm_check(in_array('PARENT_MISSING', array_column(v8cm_validate($proposal, $proposalsha, $broken)['issues'], 'code'), true), 'padre inexistente');
$broken = $map;
unset($broken['operator']);
cm_check(in_array('AUDIT_MISSING', array_column(v8cm_validate($proposal, $proposalsha, $broken)['issues'], 'code'), true), 'auditoría');

// Validación sellada y filas de fase 6: una fila por nodo, cursos de ambos
// orígenes en el mismo nodo y ninguna raíz por origen.
$directory = sys_get_temp_dir() . '/v8-810-' . bin2hex(random_bytes(4));
mkdir($directory);
$proposalpath = $directory . '/proposal.json';
$mappath = $directory . '/category-map.json';
v8cm_write_json($proposalpath, $proposal);
$map['proposal_sha256'] = (string)hash_file('sha256', $proposalpath);
v8cm_write_json($mappath, $map);
$validationpath = $directory . '/validation.json';
cm_check(v8cm_main(['mode' => 'validate', 'proposal' => $proposalpath, 'mapping' => $mappath,
    'output' => $validationpath]) === 0, 'validación por CLI');
$validation = v8cm_load_validation($validationpath, $configsha);
cm_check($validation['category_map_sha256'] === hash_file('sha256', $mappath), 'hash del mapa sellado');
$tampered = $validation;
$tampered['nodes'][array_search('ING', array_column($tampered['nodes'], 'key'), true)]['marker'] = 'POS-ROOT';
v8cm_write_json($directory . '/tampered.json', $tampered);
try {
    v8cm_load_validation($directory . '/tampered.json', $configsha);
    cm_check(false, 'una validación alterada se rechaza');
} catch (V8CategoryMapException) {
}

$sourcecategorymaps = [];
foreach ($inventories as $source => $inventory) {
    foreach ($inventory['categories'] as $category) {
        $sourcecategorymaps[$source][(int)$category['source_category_id']] = $category;
    }
}
$targetbyid = [1 => ['target_category_id' => 1, 'target_parent_id' => 0, 'name' => 'Categoría 1', 'idnumber' => '']];
$edu = array_column($validation['nodes'], null, 'key')['EDU'];
// El piloto ya creó Educación con su marcador en 07b: fase 6 la reutiliza.
$targetbymarker = [$edu['marker'] => [['target_category_id' => 40, 'target_parent_id' => 0, 'name' => 'Educación', 'idnumber' => $edu['marker']]]];
$targetbyparentname = ['0|categoría 1' => [$targetbyid[1]], '0|educación' => $targetbymarker[$edu['marker']]];
[$rows, $rowbykey, $categorykeybysourceid] = p6_mapped_category_rows($validation, $sourcecategorymaps,
    $targetbyid, $targetbymarker, $targetbyparentname);
$rowsbykey = array_column($rows, null, 'category_key');
cm_check(count($rows) === 7, 'una fila por nodo');
cm_check($categorykeybysourceid[$pre . '|2'] === 'NODE-ING' && $categorykeybysourceid[$pos . '|7'] === 'NODE-ING', 'cursos de Ingeniería al mismo nodo');
cm_check($rowsbykey['NODE-EDU']['action'] === 'reuse' && (int)$rowsbykey['NODE-EDU']['matched_target_category_id'] === 40, 'Educación del piloto se reutiliza');
cm_check($rowsbykey['NODE-ING']['action'] === 'create' && $rowsbykey['NODE-ING']['target_parent_category_id'] === 0, 'Ingeniería se crea en la raíz');
cm_check($rowsbykey['NODE-MAT']['parent_category_key'] === 'NODE-CB', 'Matemáticas cuelga de Ciencias Básicas');
cm_check($rowsbykey['NODE-E-1']['action'] === 'reuse' && $rowsbykey['NODE-E-1']['node_kind'] === 'existing', 'Categoría 1 existente se reutiliza');
foreach ($rows as $row) {
    cm_check($row['action'] !== 'blocked', 'ninguna fila bloqueada: ' . $row['category_key'] . ' ' . $row['blocking_reason']);
    cm_check(!str_ends_with((string)$row['target_category_marker'], '-ROOT'), 'sin raíces por origen');
}
// Una homónima sin marcador en la raíz bloquea la creación.
[$blockedrows] = p6_mapped_category_rows($validation, $sourcecategorymaps, $targetbyid, [],
    $targetbyparentname + ['0|ingeniería' => [['target_category_id' => 50, 'target_parent_id' => 0, 'name' => 'Ingeniería', 'idnumber' => '']]]);
cm_check(array_column($blockedrows, null, 'category_key')['NODE-ING']['action'] === 'blocked', 'homónima sin marcador bloquea');

// Sin mapa, 8.0.0 intacto: la etapa y el plan dependen del archivo o de la variable.
$wizard = (string)file_get_contents(__DIR__ . '/../scripts/consolidation-wizard.ps1');
cm_check(str_contains($wizard, '(Test-CategoryMapActive) -and -not (Test-CategoryMap)'), 'etapa 05a condicionada');
cm_check(str_contains($wizard, "if ((Test-CategoryMapActive) -and -not (Test-Phase5Plan)"), 'etapa 07b condicionada');
$plan = (string)file_get_contents(__DIR__ . '/../scripts/phase6-plan.php');
cm_check(substr_count($plan, "if (\$categorymapsha !== '') {") === 2 && str_contains($plan, "'categorymap' => '',"), 'plan 8.0.0 por defecto');
$packageplan = (string)file_get_contents(__DIR__ . '/../scripts/phase6-package-plan.ps1');
cm_check(str_contains($packageplan, '$categoryMapArguments = @()'), 'sin argumento de mapa por defecto');

foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
rmdir($directory);
echo "V8_810_CATEGORY_MAP_OK\n";
