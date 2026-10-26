<?php
declare(strict_types=1);

// 8.2.0: personalización del theme global copiada de un origen elegido.
require_once __DIR__ . '/../scripts/v8-plugin-catalog.php';
require_once __DIR__ . '/../scripts/v8-preparation.php';

function v8tp_test(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('V8_820_THEME_PROFILE_FAILED: ' . $message);
    }
}
function v8tp_throws(callable $call, string $code, string $message): void {
    try {
        $call();
    } catch (V8PreparationException $exception) {
        v8tp_test(str_starts_with($exception->getMessage(), $code), $message);
        return;
    }
    v8tp_test(false, $message);
}

$boost = ['component'=>'theme_boost', 'state'=>'complete', 'settings_count'=>7, 'settings'=>[
    'brandcolor'=>['state'=>'config_value', 'value'=>'#0f6cbf'],
    'scss'=>['state'=>'config_value', 'value'=>".navbar { background: \$brand; }\n"],
    'preset'=>['state'=>'config_value', 'value'=>'default.scss'],
    'logo'=>['state'=>'file_reference', 'value'=>'/logo.png'],
    'apikey'=>['state'=>'redacted'],
    'version'=>['state'=>'config_value', 'value'=>'2024100700'],
    'unset'=>['state'=>'config_value', 'value'=>null],
]];
$sources = [
    'pregrado'=>['profiles'=>['boost'=>$boost]],
    'posgrado'=>['profiles'=>['boost'=>['component'=>'theme_boost', 'state'=>'empty',
        'settings'=>[], 'settings_count'=>0]]],
];

$none = v8p_global_theme_profile($sources, 'boost', '');
v8tp_test($none['state'] === 'not_requested' && $none['settings'] === [],
    'sin origen elegido el destino conserva la configuración por defecto');

$chosen = v8p_global_theme_profile($sources, 'boost', 'pregrado');
v8tp_test($chosen['state'] === 'requested' && $chosen['source'] === 'pregrado',
    'el origen elegido queda sellado en el plan');
v8tp_test(array_keys($chosen['settings']) === ['brandcolor', 'preset', 'scss'] &&
    str_contains($chosen['settings']['scss'], '.navbar'),
    'solo viajan los valores de configuración, SCSS incluido');
v8tp_test($chosen['skipped'] === ['apikey'=>'redacted', 'logo'=>'file_reference',
    'unset'=>'not_available', 'version'=>'plugin_version'],
    'secretos, archivos, nulos y la versión del plugin nunca se aplican');
v8tp_test($chosen['profile_sha256'] === v8p_theme_value_sha256($boost),
    'la huella identifica el perfil copiado');

v8tp_throws(fn() => v8p_global_theme_profile($sources, 'boost', 'otro'),
    'V8_THEME_PROFILE_SOURCE_INVALID', 'un origen desconocido bloquea');
v8tp_throws(fn() => v8p_global_theme_profile($sources, 'classic', 'pregrado'),
    'V8_THEME_PROFILE_SOURCE_INVALID', 'un origen sin perfil del theme global bloquea');
v8tp_throws(fn() => v8p_global_theme_profile($sources, 'boost', '../x'),
    'V8_THEME_PROFILE_SOURCE_INVALID', 'el nombre del origen es cerrado');

echo "V8_820_THEME_PROFILE_OK\n";
