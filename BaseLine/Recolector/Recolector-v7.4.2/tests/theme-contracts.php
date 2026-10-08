<?php

declare(strict_types=1);

require_once(__DIR__ . '/../scripts/theme-contract.php');
define('COLLECTOR_VALIDATE_PACKAGE_LIBRARY_ONLY', true);
require_once(__DIR__ . '/../scripts/validate-package.php');

function test_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$profile = collector_theme_profile('theme_academi', (object)[
    'preset' => 'campus.scss',
    'logo' => 'logo.png',
    'api_token' => 'NO-DEBE-SALIR',
    'privatekey' => 'NO-DEBE-SALIR',
]);
test_assert($profile['settings']['preset']['state'] === 'config_value', 'TEST E: config normal');
test_assert($profile['settings']['logo']['state'] === 'file_reference', 'TEST E: archivo');
test_assert($profile['settings']['api_token'] === ['state' => 'redacted'], 'TEST F: token');
test_assert($profile['settings']['privatekey'] === ['state' => 'redacted'], 'TEST F: clave');
test_assert(!str_contains(json_encode($profile), 'NO-DEBE-SALIR'), 'TEST F: secreto expuesto');

$empty = collector_theme_course_metadata('');
$assigned = collector_theme_course_metadata('theme_almondb');
test_assert($empty['state'] === 'empty' && $empty['name'] === '', 'TEST C: sin theme');
test_assert($assigned['state'] === 'complete' && $assigned['name'] === 'almondb', 'TEST D');

$academic = [
    'course' => ['source_course_id' => 2, 'shortname' => 'X', 'theme' => 'academi'],
    'counts' => ['activities' => 3],
];
$firststate = collector_theme_sha256(collector_theme_academic_course_inventory($academic));
$academic['course']['theme'] = 'almondb';
$secondstate = collector_theme_sha256(collector_theme_academic_course_inventory($academic));
test_assert($firststate === $secondstate, 'TEST J: theme invalidó estado académico');
test_assert(
    collector_theme_course_metadata_sha256('academi') !==
        collector_theme_course_metadata_sha256('almondb'),
    'La huella visual no distingue themes.'
);

$fixture = json_decode(
    (string)file_get_contents(__DIR__ . '/fixtures/theme-inventory.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$fixture['themes']['fingerprint_sha256'] = collector_theme_sha256([
    'site' => $fixture['themes']['site'],
    'policies' => $fixture['themes']['policies'],
    'profiles' => $fixture['themes']['profiles'],
    'assignments' => $fixture['theme_assignments'],
]);
$report = ['failures' => [], 'warnings' => []];
audit_validate_theme_inventory(
    $report,
    [
        'themes' => $fixture['themes'],
        'theme_assignments' => $fixture['theme_assignments'],
        'courses' => $fixture['courses'],
    ],
    $fixture['plugins']
);
test_assert($report['failures'] === [], 'TEST A-D/H: contrato válido rechazado');
test_assert($report['warnings'] === [], 'Inventario válido produjo warnings');

$unsupported = $fixture['theme_assignments'];
$unsupported['states']['users'] = 'not_supported';
$unsupported['states']['categories'] = 'not_supported';
test_assert(
    $unsupported['states']['users'] === 'not_supported' &&
        $unsupported['states']['categories'] === 'not_supported',
    'TEST G: estados opcionales'
);

$legacy = $fixture;
$legacy['courses'][1]['theme'] = 'legacytheme';
$legacy['theme_assignments']['courses'][0]['theme'] = 'legacytheme';
$legacy['themes']['fingerprint_sha256'] = collector_theme_sha256([
    'site' => $legacy['themes']['site'],
    'policies' => $legacy['themes']['policies'],
    'profiles' => $legacy['themes']['profiles'],
    'assignments' => $legacy['theme_assignments'],
]);
$report = ['failures' => [], 'warnings' => []];
audit_validate_theme_inventory(
    $report,
    [
        'themes' => $legacy['themes'],
        'theme_assignments' => $legacy['theme_assignments'],
        'courses' => $legacy['courses'],
    ],
    $legacy['plugins']
);
test_assert($report['failures'] === [], 'Theme legado ausente debe ser warning');
test_assert(count($report['warnings']) === 1, 'Falta warning para theme legado');

$changed = $fixture['themes'];
$changed['site']['global_theme'] = 'boost';
test_assert(
    collector_theme_sha256([
        'site' => $changed['site'],
        'policies' => $changed['policies'],
        'profiles' => $changed['profiles'],
        'assignments' => $fixture['theme_assignments'],
    ]) !== $fixture['themes']['fingerprint_sha256'],
    'TEST K: fingerprint no es determinista/sensible'
);

fwrite(STDOUT, "THEME_CONTRACTS_OK\n");
