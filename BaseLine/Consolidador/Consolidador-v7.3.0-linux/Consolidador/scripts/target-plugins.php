<?php
// Inventario de plugins del Moodle destino para el preflight de paquetes.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/phase5-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'output' => null,
        'targetid' => null,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php target-plugins.php --output=RUTA --targetid=target\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $output = trim((string)$options['output']);
    $targetid = core_text::strtolower(trim((string)$options['targetid']));
    if ($output === '' ||
            !preg_match('/^[a-z][a-z0-9_-]*$/', $targetid)) {
        throw new RuntimeException('output o targetid inválido.');
    }
    if (!class_exists('core_plugin_manager')) {
        throw new RuntimeException(
            'La API core_plugin_manager no está disponible en este Moodle.'
        );
    }

    $plugins = [];
    $manager = core_plugin_manager::instance();
    foreach ($manager->get_plugins() as $type => $instances) {
        foreach ($instances as $name => $plugin) {
            $component = (string)($plugin->component ?? ($type . '_' . $name));
            $plugins[] = [
                'component' => $component,
                'type' => (string)$type,
                'name' => (string)$name,
                'version_db' => isset($plugin->versiondb)
                    ? (int)$plugin->versiondb
                    : null,
                'version_disk' => isset($plugin->versiondisk)
                    ? (int)$plugin->versiondisk
                    : null,
                'release' => isset($plugin->release)
                    ? (string)$plugin->release
                    : '',
                'source' => method_exists($plugin, 'is_standard') &&
                    $plugin->is_standard()
                        ? 'standard'
                        : 'additional',
            ];
        }
    }
    usort(
        $plugins,
        static fn(array $left, array $right): int =>
            strcmp($left['component'], $right['component'])
    );

    $modules = [];
    foreach ($DB->get_records(
        'modules',
        null,
        'name ASC',
        'id,name,visible'
    ) as $module) {
        $modules[] = [
            'name' => (string)$module->name,
            'visible' => (int)$module->visible,
        ];
    }

    p5_write_json($output, [
        'schema_version' => '1.0',
        'phase' => '2-target-plugin-inventory',
        'generated_at_utc' => gmdate('c'),
        'target_id' => $targetid,
        'target_wwwroot' => (string)$CFG->wwwroot,
        'moodle_version' => (string)get_config('moodle', 'version'),
        'moodle_release' => (string)get_config('moodle', 'release'),
        'plugins' => $plugins,
        'activity_modules' => $modules,
        'counts' => [
            'plugins' => count($plugins),
            'activity_modules' => count($modules),
        ],
        'write_performed' => false,
    ]);
    cli_writeln(
        'TARGET_PLUGINS_OK target=' . $targetid .
        ' plugins=' . count($plugins) .
        ' modules=' . count($modules) .
        ' write=0'
    );
} catch (Throwable $error) {
    cli_error('TARGET_PLUGINS_ERROR ' . $error->getMessage());
}
