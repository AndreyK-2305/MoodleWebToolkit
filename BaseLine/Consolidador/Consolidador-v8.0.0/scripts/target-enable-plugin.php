<?php
// Habilita un módulo de actividad standard/core ya instalado en el destino.
declare(strict_types=1);

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once('/usr/local/bin/verify-plugin-pins.php');
require_once(__DIR__ . '/target-plugin-pin.php');

[$options, $unrecognized] = cli_get_params(
    ['component' => null, 'pin-verified' => 0, 'help' => false],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln("Uso: php target-enable-plugin.php --component=mod_x [--pin-verified=1]\n");
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $component = core_text::strtolower(trim((string)$options['component']));
    $callerPinClaim = (int)$options['pin-verified'] === 1;
    if (!preg_match('/^mod_[a-z][a-z0-9_]*$/', $component)) {
        throw new RuntimeException('Solo se habilitan módulos mod_* requeridos por cursos.');
    }
    [, $name] = explode('_', $component, 2);
    $manager = core_plugin_manager::instance();
    $plugins = $manager->get_plugins_of_type('mod');
    if (!isset($plugins[$name])) {
        throw new RuntimeException("Módulo no instalado: $component");
    }
    $plugin = $plugins[$name];
    $additional = !(method_exists($plugin, 'is_standard') && $plugin->is_standard());
    $pin = null;
    if ($additional) {
        // --pin-verified se conserva por compatibilidad, pero nunca autoriza.
        // La comprobación usa el manifest horneado en la imagen y el código
        // realmente instalado en el volumen persistente de Moodle.
        $pin = target_plugin_verify_additional_pin(
            $component,
            (string)($plugin->rootdir ?? ''),
            (string)$CFG->dirroot,
            '/opt/approved-plugins.json',
            $callerPinClaim
        );
    }
    $class = core_plugin_manager::resolve_plugininfo_class('mod');
    if (!is_callable([$class, 'enable_plugin'])) {
        throw new RuntimeException("Moodle no expone enable_plugin para $component");
    }
    $before = (int)$DB->get_field('modules', 'visible', ['name' => $name], MUST_EXIST);
    $changed = $class::enable_plugin($name, 1);
    purge_all_caches();
    $after = (int)$DB->get_field('modules', 'visible', ['name' => $name], MUST_EXIST);
    if ($after !== 1) {
        throw new RuntimeException("No fue posible habilitar $component");
    }
    cli_writeln(
        ($additional ? 'TARGET_PLUGIN_PIN_VERIFIED ' : '') .
        'TARGET_PLUGIN_ENABLED component=' . $component .
        ($additional ? ' tree_sha256=' . $pin['tree_sha256'] : '') .
        ' before=' . $before .
        ' after=' . $after .
        ' changed=' . ($changed ? '1' : '0')
    );
} catch (Throwable $error) {
    cli_error('TARGET_PLUGIN_ENABLE_ERROR ' . $error->getMessage());
}
