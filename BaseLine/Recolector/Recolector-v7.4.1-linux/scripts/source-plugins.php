<?php
// Inventario de plugins y módulos instalados en el Moodle de origen.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/bootstrap.php');
require(collector_moodle_config_path());
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/phase5-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'config' => null,
        'output' => null,
        'sourceid' => null,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php source-plugins.php --output=RUTA --sourceid=virtual " .
        "--config=/ruta/config.php\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $output = trim((string)$options['output']);
    $sourceid = core_text::strtolower(trim((string)$options['sourceid']));
    if ($output === '' || !preg_match('/^[a-z][a-z0-9_-]*$/', $sourceid)) {
        throw new RuntimeException('output o sourceid inválido.');
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

    $usedmodules = [];
    foreach ($DB->get_records_sql(
        'SELECT DISTINCT m.name
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
          WHERE cm.deletioninprogress = 0
       ORDER BY m.name'
    ) as $module) {
        $usedmodules[] = (string)$module->name;
    }

    p5_write_json($output, [
        'schema_version' => '1.0',
        'package_type' => 'moodle-consolidation-plugin-inventory',
        'generated_at_utc' => gmdate('c'),
        'source_id' => $sourceid,
        'moodle_version' => (string)get_config('moodle', 'version'),
        'moodle_release' => (string)get_config('moodle', 'release'),
        'plugins' => $plugins,
        'used_activity_modules' => $usedmodules,
        'counts' => [
            'plugins' => count($plugins),
            'used_activity_modules' => count($usedmodules),
        ],
        'write_performed' => false,
    ]);
    cli_writeln(
        'SOURCE_PLUGINS_OK source=' . $sourceid .
        ' plugins=' . count($plugins) .
        ' modules=' . count($usedmodules)
    );
} catch (Throwable $error) {
    cli_error('SOURCE_PLUGINS_ERROR ' . $error->getMessage());
}
