<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/v8-plugin-catalog.php';
require_once __DIR__ . '/../scripts/v8-preparation.php';
require_once __DIR__ . '/../scripts/v8-identity-review.php';

function v8_test(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('V8_CONTRACT_FAILED: ' . $message);
    }
}

$catalogPath = __DIR__ . '/../config/plugin-compatibility-catalog.json';
$catalog = v8c_validate_catalog(v8c_read_json($catalogPath));
v8_test(count($catalog['entries']) === 19, 'semilla de 19 plugins');
v8_test(isset($catalog['entries']['theme_academi'],
    $catalog['entries']['theme_almondb'], $catalog['entries']['mod_hvp']),
    'themes y HVP en catálogo');
v8_test(count($catalog['entries']['mod_hvp']['submodules']) === 3,
    'submodules recursivos HVP pinneados');

$lock = v8c_resolve($catalog, ['original_compatibility_needs'=>[[
    'source_id'=>'a', 'component'=>'customcertelement_daterange',
    'source_version'=>'1', 'observed_state'=>'missing', 'used_activity'=>true,
]]], hash_file('sha256', $catalogPath));
$selected = array_column($lock['plugins'], null, 'component');
v8_test(isset($selected['customcertelement_daterange'], $selected['mod_customcert']),
    'expansión determinista de dependencias');
v8_test($lock['unknown_plugins'] === [] && !$lock['plugin_lock_valid'],
    'catálogo no equivale a lock aprobado');

$unknown = v8c_resolve($catalog, ['original_compatibility_needs'=>[[
    'source_id'=>'a', 'component'=>'mod_desconocido',
    'source_version'=>'1', 'observed_state'=>'missing', 'used_activity'=>true,
]]], hash_file('sha256', $catalogPath));
v8_test($unknown['lock_status'] === 'DISCOVERY_REQUIRED' &&
    ($unknown['unknown_plugins'][0]['state'] ?? '') === 'PLUGIN_UNKNOWN',
    'plugin desconocido requiere discovery');

$bad = $catalog;
$bad['entries']['mod_hvp']['commit'] = 'latest';
try {
    v8c_validate_catalog($bad);
    v8_test(false, 'el catálogo aceptó latest');
} catch (V8CatalogException) {
    // Esperado: los registros permanecen pinneados.
}

$promotionEntry = $catalog['entries']['mod_kanban'];
$promotionEntry['path'] = 'public/mod/promotedfixture';
$promoted = v8c_promote($catalog, [
    'component'=>'mod_promotedfixture', 'entry'=>$promotionEntry,
    'approval'=>['approved_by'=>'Test institucional', 'approved_at'=>'2026-09-26',
        'evidence'=>'staging fixture passed'],
]);
v8_test(isset($promoted['entries']['mod_promotedfixture']) &&
    $promoted['last_promotion']['approved_by'] === 'Test institucional',
    'promoción explícita y auditada');
try {
    v8c_promote($catalog, ['component'=>'mod_invalido', 'entry'=>$promotionEntry]);
    v8_test(false, 'promoción sin aprobación aceptada');
} catch (V8CatalogException) {
    // Esperado.
}

$identities = v8p_read_json(__DIR__ . '/fixtures/v8-identities.json');
$distinctMap = [
    'origen_a:10'=>'CAN-AAAAAAAAAAAA',
    'origen_b:20'=>'CAN-BBBBBBBBBBBB',
    'origen_b:21'=>'CAN-CCCCCCCCCCCC',
];
$candidates = v8p_identity_candidates($identities['documents'], $distinctMap);
v8_test(count($candidates) === 1, 'un único candidato fuzzy esperado');
v8_test($candidates[0]['other_evidence'] === 'google_sub=different' &&
    $candidates[0]['candidate_type'] === 'POSSIBLE_IDENTITY_MATCH' &&
    $candidates[0]['resolution'] === '' &&
    !array_key_exists('google_sub', $candidates[0]),
    'google_sub distinto no fusiona ni se expone');
v8_test($candidates === v8p_identity_candidates($identities['documents'], $distinctMap),
    'matching determinista');
$resolvedMap = [
    'origen_a:10'=>'CAN-AAAAAAAAAAAA',
    'origen_b:20'=>'CAN-AAAAAAAAAAAA',
    'origen_b:21'=>'CAN-BBBBBBBBBBBB',
];
v8_test(v8p_identity_candidates($identities['documents'], $resolvedMap) === [],
    'un candidato ya reconciliado no reaparece');

$review = $candidates;
$review[0]['resolution'] = 'MERGE';
$review[0]['canonical_target'] = 'origen_a:10';
$review[0]['canonical_email'] = 'kevin@ejemplo.edu';
$review[0]['canonical_username'] = 'kevin.canonico';
$review[0]['oauth_policy'] = 'canonical_account';
$review[0]['operator'] = 'Operador de prueba';
$review[0]['decision_timestamp_utc'] = '2026-09-26T18:30:00Z';
$review[0]['evidence_reference'] = 'ticket-42';
$review[0]['justification'] = 'Validación institucional explícita';
$imported = v8ir_import($candidates, $review);
v8_test(count($imported) === 2 && $imported[0]['decision'] === 'MERGE' &&
    $imported[0]['algorithm_version'] === 'v8-blocked-2' &&
    $imported[0]['previous_canonical_ids'] !== '',
    'MERGE fuzzy produce resolución auditada para Fase 3');
v8_test(str_contains($candidates[0]['other_evidence'], 'google_sub=different') &&
    $candidates[0]['resolution'] === '',
    'google_sub distinto nunca produce auto-merge');
$kept = $candidates;
$kept[0]['resolution'] = 'KEEP_SEPARATE';
$kept[0]['operator'] = 'Operador de prueba';
$kept[0]['decision_timestamp_utc'] = '2026-09-26T18:31:00Z';
$kept[0]['evidence_reference'] = 'ticket-43';
$kept[0]['justification'] = 'Personas distintas confirmadas';
$keptRows = v8ir_import($candidates, $kept);
v8_test(count($keptRows) === 2 && $keptRows[0]['decision'] === 'KEEP_SEPARATE',
    'KEEP_SEPARATE queda operativo y auditado');

$themes = v8p_read_json(__DIR__ . '/fixtures/v8-themes.json');
$themePlan = v8p_theme_plan($themes['sources'], $themes['policy'], $themes['lock'],
    $themes['target_inventory'], $themes['managed']);
$rows = $themePlan['course_rows']['origen_a'];
v8_test($themePlan['assignment_policy'] === 'preserve_course_assignments' &&
    $themePlan['global_theme'] === 'boost' && count($themePlan['warnings']) === 1,
    'política y warning de themes');
v8_test(in_array('classic', $themePlan['compatible_installed_themes'], true) &&
    $themePlan['global_theme_selection_explicit'] === true,
    'themes core reales del destino y selección explícita');
v8_test($rows[0]['resolution_state'] === 'NO_EXPLICIT_THEME' &&
    $rows[1]['resolution_state'] === 'PENDING_POST_RESTORE_VERIFICATION' &&
    $rows[2]['resolution_state'] === 'FALLBACK_TO_GLOBAL' &&
    $rows[2]['transport_state'] === 'NOT_TRANSPORTABLE' &&
    $rows[2]['warning_code'] === 'WARNING_COURSE_THEME_NOT_APPLIED',
    'transporte de asignaciones por curso');
v8_test(count($themePlan['user_assignments_raw']['origen_a']) === 1 &&
    count($themePlan['category_assignments_raw']['origen_a']) === 1,
    'usuario y categoría solo inventariados');

$verified = v8p_verify_course_themes($rows, ['origen_a:2'=>'academi']);
v8_test($verified['status'] === 'passed_with_warnings' &&
    $verified['counts']['courses_without_explicit_theme'] === 1 &&
    $verified['counts']['preserved_by_restore'] === 1 &&
    $verified['counts']['fallback_to_global'] === 1 &&
    $verified['counts']['failed_functional'] === 0,
    'verificación posterior de themes');
$failed = v8p_verify_course_themes([$rows[1]], ['origen_a:2'=>'boost']);
v8_test($failed['status'] === 'failed' &&
    $failed['counts']['failed_functional'] === 1,
    'theme transportable perdido exige reaplicación');
$reapplied = v8p_verify_course_themes([$rows[1]], [
    'origen_a:2'=>['before'=>'boost', 'after'=>'academi'],
]);
v8_test($reapplied['status'] === 'passed' &&
    $reapplied['counts']['reapplied'] === 1,
    'theme transportable se reaplica y verifica');
$clean = v8p_verify_course_themes([$rows[1]], ['origen_a:2'=>'academi']);
v8_test($clean['status'] === 'passed' && $clean['counts']['warnings'] === 0,
    'verificación sin warnings devuelve passed');
$missingSources = $themes['sources'];
$missingSources['origen_a']['inventory_complete'] = false;
$missingSources['origen_a']['inventory_state'] = 'inventory_missing';
$missing = v8p_theme_plan($missingSources, $themes['policy'], $themes['lock'],
    $themes['target_inventory'], $themes['managed']);
v8_test($missing['theme_inventory_complete'] === false &&
    $missing['theme_assignment_policy_sealed'] === false,
    'inventario faltante no se declara completo');
$rawProfiles = [
    'academi'=>['component'=>'theme_academi', 'state'=>'complete',
        'settings'=>['preset'=>['state'=>'config_value','value'=>'a.scss']],
        'settings_count'=>1],
    'almondb'=>['component'=>'theme_almondb', 'state'=>'empty',
        'settings'=>[], 'settings_count'=>0],
    'boost'=>['component'=>'theme_boost', 'state'=>'empty',
        'settings'=>[], 'settings_count'=>0],
];
$rawAssignments = [
    'schema_version'=>'1.0', 'inventory_complete'=>true,
    'courses'=>[['source_course_id'=>2, 'theme'=>'almondb']],
    'users'=>[], 'categories'=>[], 'cohorts'=>[],
    'states'=>['courses'=>'complete', 'users'=>'not_supported',
        'categories'=>'not_supported', 'cohorts'=>'not_supported'],
];
$rawThemes = [
    'schema_version'=>'1.0', 'inventory_complete'=>true,
    'global_theme'=>'academi', 'global_theme_state'=>'complete',
    'site'=>['global_theme'=>'academi', 'global_theme_state'=>'complete',
        'global_theme_source'=>'core_config'],
    'policies'=>[
        'allow_course_themes'=>['state'=>'complete','value'=>true],
        'allow_user_themes'=>['state'=>'complete','value'=>false],
        'allow_category_themes'=>['state'=>'not_supported'],
    ],
    'profiles'=>$rawProfiles,
    'states'=>['global_theme'=>'complete','course_assignments'=>'complete',
        'profiles'=>'complete','installed_themes'=>'complete'],
];
$rawThemes['fingerprint_sha256'] = v8p_theme_value_sha256([
    'site'=>$rawThemes['site'], 'policies'=>$rawThemes['policies'],
    'profiles'=>$rawProfiles, 'assignments'=>$rawAssignments,
]);
$rawManifest = [
    'source_id'=>'origen_742', 'collector_version'=>'7.4.2-linux',
    'capabilities'=>['theme_inventory'=>'1.0'],
    'entries'=>[
        ['source_course_id'=>1,'source_shortname'=>'SIN'],
        ['source_course_id'=>2,'source_shortname'=>'CON'],
    ],
];
$rawInventory = [
    'themes'=>$rawThemes, 'theme_assignments'=>$rawAssignments,
    'courses'=>[
        ['source_course_id'=>1,'shortname'=>'SIN','theme'=>''],
        ['source_course_id'=>2,'shortname'=>'CON','theme'=>'almondb'],
    ],
];
$rawPlugins = ['plugins'=>[
    ['type'=>'theme','component'=>'theme_boost'],
    ['type'=>'theme','component'=>'theme_academi'],
    ['type'=>'theme','component'=>'theme_almondb'],
]];
$emptyThemeMetadata = ['schema_version'=>'1.0', 'state'=>'empty',
    'name'=>'', 'source'=>'course.theme'];
$almondThemeMetadata = ['schema_version'=>'1.0', 'state'=>'complete',
    'name'=>'almondb', 'source'=>'course.theme'];
$rawDetails = [
    '1'=>['theme'=>'', 'theme_metadata'=>$emptyThemeMetadata,
        'theme_metadata_sha256'=>v8p_theme_value_sha256($emptyThemeMetadata),
        'inventory'=>['course'=>['theme'=>'']]],
    '2'=>['theme'=>'almondb',
        'theme_metadata'=>$almondThemeMetadata,
        'theme_metadata_sha256'=>v8p_theme_value_sha256($almondThemeMetadata),
        'inventory'=>['course'=>['theme'=>'almondb']]],
];
$normalized742 = v8p_theme_data($rawManifest, $rawInventory, $rawPlugins, $rawDetails);
v8_test($normalized742['inventory_complete'] === true &&
    $normalized742['inventory_state'] === 'complete' &&
    $normalized742['legacy'] === false &&
    $normalized742['courses'][0]['metadata_state'] === 'empty' &&
    $normalized742['assignment_states']['users'] === 'not_supported',
    'contrato Recolector 7.4.2 completo y ámbitos opcionales');
$mismatchDetails = $rawDetails;
$mismatchDetails['2']['theme'] = 'academi';
try {
    v8p_theme_data($rawManifest, $rawInventory, $rawPlugins, $mismatchDetails);
    v8_test(false, 'se aceptó contradicción de theme por curso');
} catch (V8PreparationException $error) {
    v8_test(str_contains($error->getMessage(), 'SOURCE_THEME_INVENTORY_MISMATCH'),
        'contradicción usa error específico');
}
$invalid742 = $rawInventory;
unset($invalid742['themes']['profiles']);
try {
    v8p_theme_data($rawManifest, $invalid742, $rawPlugins, $rawDetails);
    v8_test(false, 'capability 7.4.2 corrupta cayó a legacy');
} catch (V8PreparationException $error) {
    v8_test(str_contains($error->getMessage(), 'INVALID_THEME_INVENTORY'),
        '7.4.2 corrupto bloquea sin fallback legacy');
}
$legacyManifest = ['source_id'=>'origen_741','collector_version'=>'7.4.1-linux',
    'entries'=>[['source_course_id'=>7,'source_shortname'=>'LEGACY']]];
$legacy = v8p_theme_data($legacyManifest, [], $rawPlugins, []);
v8_test($legacy['inventory_complete'] === false &&
    $legacy['inventory_state'] === 'legacy_not_available' &&
    $legacy['courses'][0]['metadata_state'] === 'not_available',
    'paquete 7.4.1 se clasifica como legacy accionable');
$legacySources = ['origen_741'=>$legacy];
$legacyPolicy = $themes['policy'];
$legacyPolicy['theme_inventory_legacy_accepted'] = true;
$legacyPlan = v8p_theme_plan($legacySources, $legacyPolicy, $themes['lock'],
    $themes['target_inventory'], $themes['managed']);
v8_test($legacyPlan['theme_inventory_complete'] === false &&
    $legacyPlan['theme_inventory_legacy_accepted'] === true &&
    $legacyPlan['theme_inventory_ready'] === true &&
    $legacyPlan['course_rows']['origen_741'][0]['resolution_state'] ===
        'THEME_METADATA_NOT_AVAILABLE' &&
    in_array('WARNING_LEGACY_THEME_METADATA_UNAVAILABLE',
        array_column($legacyPlan['warnings'], 'code'), true),
    'aceptación legacy permite READY con warning sin inventar asignaciones');
$legacyPolicy['theme_inventory_legacy_accepted'] = false;
$legacyBlocked = v8p_theme_plan($legacySources, $legacyPolicy, $themes['lock'],
    $themes['target_inventory'], $themes['managed']);
v8_test($legacyBlocked['theme_inventory_ready'] === false &&
    $legacyBlocked['theme_assignment_policy_sealed'] === false,
    'legacy exige aceptación explícita');

$collisionA = $normalized742;
$collisionB = $normalized742;
$collisionB['profiles']['academi']['settings']['preset']['value'] = 'b.scss';
$collisionB['available']['academi']['configuration'] =
    $collisionB['profiles']['academi'];
$collisionPlan = v8p_theme_plan(['a'=>$collisionA, 'b'=>$collisionB],
    $themes['policy'], $themes['lock'], $themes['target_inventory'],
    $themes['managed']);
v8_test(in_array('WARNING_THEME_PROFILE_COLLISION',
    array_column($collisionPlan['warnings'], 'code'), true),
    'colisión de perfiles preserva fuentes y produce warning');

$academiPolicy = $themes['policy'];
$academiPolicy['global_theme_selection'] = 'academi';
$academiPolicy['selection_source'] = 'operator';
$academiPlan = v8p_theme_plan($themes['sources'], $academiPolicy, $themes['lock'],
    $themes['target_inventory'], ['settings'=>['theme'=>'academi',
        'allowcoursethemes'=>1]]);
v8_test($academiPlan['target_theme_policy_verified'] === true &&
    $academiPlan['theme_assignment_policy_sealed'] === true,
    'Academi externo se aplica y sella');
$classicPolicy = $themes['policy'];
$classicPolicy['global_theme_selection'] = 'classic';
$classicPolicy['selection_source'] = 'operator';
$classicPlan = v8p_theme_plan($themes['sources'], $classicPolicy, $themes['lock'],
    $themes['target_inventory'], ['settings'=>['theme'=>'classic',
        'allowcoursethemes'=>1]]);
v8_test($classicPlan['target_theme_policy_verified'] === true,
    'Classic core se selecciona sin plugin-lock');
$operatorBoost = $themes['policy'];
$operatorBoost['selection_source'] = 'operator';
$boostPlan = v8p_theme_plan($themes['sources'], $operatorBoost, $themes['lock'],
    $themes['target_inventory'], $themes['managed']);
v8_test($boostPlan['selection_source'] === 'operator' &&
    $boostPlan['target_theme_policy_verified'] === true,
    'Boost explícito conserva decisión aunque coincida con default');
$contract742Plan = v8p_theme_plan(['origen_742'=>$normalized742],
    $themes['policy'], $themes['lock'], $themes['target_inventory'],
    $themes['managed']);
$unselectedPolicy = $themes['policy'];
$unselectedPolicy['global_theme_selection'] = '';
$unselectedPolicy['selection_source'] = '';
$unselected = v8p_theme_plan($themes['sources'], $unselectedPolicy,
    $themes['lock'], $themes['target_inventory'], $themes['managed']);
v8_test($unselected['theme_assignment_policy_sealed'] === false,
    'boost predeterminado no equivale a selección explícita');

$readinessInputs = [
    'lock'=>['plugin_catalog_checked'=>true, 'plugin_lock_valid'=>true,
        'lock_status'=>'SEALED'],
    'phase3'=>['identity_conflicts_unresolved'=>0,
        'phase4_expected'=>['identity_review_pending'=>0]],
    'phase4'=>['blocking_conflicts'=>0, 'identity_review_pending'=>0],
    'oauth'=>['status'=>'ready', 'validation'=>'passed'],
    'theme'=>$contract742Plan,
    'identity'=>['generation_status'=>'generated', 'review_status'=>'skipped'],
];
$ready = v8p_readiness($readinessInputs,
    ['fixture_sha256'=>str_repeat('a', 64)]);
v8_test($ready['status'] === 'READY_TO_RUN' && $ready['ready_to_run'] &&
    $ready['checks']['identity_candidates_reviewed'],
    'contrato 7.4.2 y skip fuzzy son compatibles con READY_TO_RUN');
$readinessInputs['theme'] = $legacyPlan;
$legacyReady = v8p_readiness($readinessInputs,
    ['fixture_sha256'=>str_repeat('b', 64)]);
v8_test($legacyReady['ready_to_run'] === true &&
    $legacyReady['warnings_nonblocking'] > 0,
    'readiness comprende legacy explícitamente aceptado');
$ready['checks']['plugin_lock_valid'] = false;
v8_test(!in_array(false, array_diff_key($ready['checks'], ['plugin_lock_valid'=>true]), true),
    'fixture de readiness aislado');

echo "V8_CONTRACTS_OK catalog=19 dependencies=1 unknown=1 identity=1 themes=3 readiness=1\n";
