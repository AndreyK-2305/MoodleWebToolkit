<?php
// Ejecutar dentro del contenedor Moodle después del restore del piloto.
// 8.2.0: copia en el destino la personalización del theme global (SCSS,
// colores, presets) del origen elegido en theme-policy.json. Escribe en
// config_plugins como lo haría la página del theme, de modo que el
// administrador puede cambiarla después desde Moodle sin reinstalar nada.
declare(strict_types=1);

define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

global $CFG;
require_once $CFG->libdir . '/clilib.php';

[$options] = cli_get_params([
    'plan'=>'/exports/theme-plan.json',
    'output'=>'/exports/theme-profile-application.json',
], ['h'=>'help']);

function v8tp_fail(string $message): never {
    fwrite(STDERR, 'V8_THEME_PROFILE_ERROR ' . $message . PHP_EOL);
    exit(1);
}
function v8tp_atomic_json(string $path, array $value): void {
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($temporary, $json, LOCK_EX) === false ||
            !rename($temporary, $path)) {
        @unlink($temporary); v8tp_fail("No se pudo escribir $path.");
    }
}

$planPath = (string)$options['plan'];
if (!is_readable($planPath)) { v8tp_fail("No se puede leer $planPath."); }
$plan = json_decode((string)file_get_contents($planPath), true);
if (!is_array($plan) || ($plan['theme_assignment_policy_sealed'] ?? false) !== true) {
    v8tp_fail('El plan de themes no está sellado.');
}
$profile = is_array($plan['global_theme_profile'] ?? null) ? $plan['global_theme_profile'] : [];
$state = (string)($profile['state'] ?? 'not_requested');
$theme = (string)($plan['global_theme'] ?? '');
$result = [
    'schema_version'=>'1.0',
    'theme_plan_sha256'=>hash_file('sha256', $planPath),
    'theme'=>$theme, 'source'=>(string)($profile['source'] ?? ''),
    'profile_sha256'=>(string)($profile['profile_sha256'] ?? ''),
    'status'=>'not_requested', 'applied'=>[], 'unchanged'=>[], 'skipped'=>[],
    'destination_write_performed'=>false,
];
if ($state === 'not_requested') {
    v8tp_atomic_json((string)$options['output'], $result);
    echo 'V8_THEME_PROFILE_OK status=not_requested' . PHP_EOL;
    exit(0);
}
if ($state !== 'requested' || ($profile['theme'] ?? '') !== $theme ||
        !is_array($profile['settings'] ?? null)) {
    v8tp_fail('El perfil del theme global del plan es inválido.');
}
if ((string)($CFG->theme ?? '') !== $theme) {
    v8tp_fail("El theme efectivo no es $theme.");
}
$component = 'theme_' . $theme;
if (!array_key_exists($theme, core_component::get_plugin_list('theme'))) {
    v8tp_fail("El theme $theme no está instalado.");
}
$failed = [];
foreach ($profile['settings'] as $name => $value) {
    $name = (string)$name;
    if (!preg_match('/^[a-z0-9_]{1,100}$/D', $name) || $name === 'version' || !is_string($value)) {
        v8tp_fail("Setting inválido en el plan: $name.");
    }
    $current = get_config($component, $name);
    if ($current !== false && (string)$current === $value) {
        $result['unchanged'][] = $name;
        continue;
    }
    set_config($name, $value, $component);
    if ((string)get_config($component, $name) !== $value) {
        $failed[] = $name;
        continue;
    }
    $result['applied'][] = $name;
}
$result['skipped'] = is_array($profile['skipped'] ?? null) ? $profile['skipped'] : [];
$result['destination_write_performed'] = $result['applied'] !== [];
if ($result['applied'] !== []) {
    // El SCSS se compila de nuevo con los valores copiados.
    theme_reset_all_caches();
}
$result['status'] = $failed !== [] ? 'failed' :
    ($result['skipped'] !== [] ? 'applied_with_skips' : 'applied');
$result['failed'] = $failed;
v8tp_atomic_json((string)$options['output'], $result);
if ($failed !== []) { v8tp_fail('No se pudieron verificar: ' . implode(',', $failed) . '.'); }
echo 'V8_THEME_PROFILE_OK status=' . $result['status'] . ' theme=' . $theme .
    ' source=' . $result['source'] . ' applied=' . count($result['applied']) .
    ' unchanged=' . count($result['unchanged']) . ' skipped=' . count($result['skipped']) . PHP_EOL;
