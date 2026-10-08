<?php
// Inventario de plugins del Moodle destino para el preflight de paquetes.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/phase5-lib.php');
require_once(__DIR__ . '/target-plugin-pin.php');
require_once('/usr/local/bin/verify-plugin-pins.php');

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

    $pins = json_decode((string)file_get_contents('/opt/approved-plugins.json'),
        true, 512, JSON_THROW_ON_ERROR);
    if (($pins['schema_version'] ?? '') !== '1.0') {
        throw new RuntimeException('Falta el manifest de pins aprobado.');
    }
    $pinned = [];
    foreach ($pins['plugins'] as $pin) {
        $pinned[(string)$pin['component']] = $pin;
    }
    $plugins = [];
    $manager = core_plugin_manager::instance();
    foreach ($manager->get_plugins() as $type => $instances) {
        foreach ($instances as $name => $plugin) {
            $component = (string)($plugin->component ?? ($type . '_' . $name));
            $rootdir = (string)($plugin->rootdir ?? '');
            $additional = !(method_exists($plugin, 'is_standard') &&
                $plugin->is_standard());
            $location = target_plugin_resolve_location(
                $component,
                $rootdir,
                (string)$CFG->dirroot,
                !$additional
            );
            $enabled = method_exists($plugin, 'is_enabled')
                ? $plugin->is_enabled() : null;
            $status = method_exists($plugin, 'get_status')
                ? (string)$plugin->get_status() : '';
            $pin = $pinned[$component] ?? null;
            $dependencies = [];
            foreach ((array)($plugin->dependencies ?? []) as $required => $version) {
                if (preg_match('/^[a-z][a-z0-9]*_[a-z][a-z0-9_]*$/', (string)$required)) {
                    $dependencies[(string)$required] = (int)$version;
                }
            }
            ksort($dependencies, SORT_STRING);
            $versionfile = $rootdir . '/version.php';
            $versiontext = is_readable($versionfile)
                ? (string)file_get_contents($versionfile) : '';
            $versionvalid = $versiontext !== '' &&
                preg_match('/\$plugin->version\s*=\s*[0-9]+\s*;/', $versiontext) &&
                preg_match('/\$plugin->component\s*=\s*([\'\"])([^\'\"]+)\1\s*;/',
                    $versiontext, $matches) && $matches[2] === $component;
            $childpaths = $pin !== null
                ? pin_child_paths($pins['plugins'], (string)$pin['path']) : [];
            $declaredRelease = preg_match('/\$plugin->release\s*=\s*([\'\"])([^\'\"]*)\1\s*;/',
                $versiontext, $releaseMatch) === 1;
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
                'release' => $additional && !$declaredRelease
                    ? null : (isset($plugin->release) ? (string)$plugin->release : ''),
                'source' => $additional ? 'additional' : 'standard',
                'enabled' => $enabled,
                'installation_status' => $status,
                'location_status' => (string)$location['status'],
                'root_path' => (string)$location['root_path'],
                'relative_path' => (string)$location['relative_path'],
                'tree_sha256' => $additional &&
                    $location['status'] === 'installed_localizable'
                    ? pin_tree((string)$location['root_path'], $childpaths) : '',
                'approved_commit' => (string)($pin['commit'] ?? ''),
                'approved_path' => (string)($pin['path'] ?? ''),
                'dependencies' => (object)$dependencies,
                'version_file_valid' => (bool)$versionvalid,
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

    $inventory = [
        'schema_version' => '1.1',
        'phase' => '2-target-plugin-inventory',
        'generated_at_utc' => gmdate('c'),
        'target_id' => $targetid,
        'target_wwwroot' => (string)$CFG->wwwroot,
        'moodle_version' => (string)get_config('moodle', 'version'),
        'moodle_release' => (string)get_config('moodle', 'release'),
        'plugins' => $plugins,
        'approved_plugins_sha256' => hash_file('sha256', '/opt/approved-plugins.json'),
        'approved_plugins' => $pins['plugins'],
        'activity_modules' => $modules,
        'counts' => [
            'plugins' => count($plugins),
            'activity_modules' => count($modules),
        ],
        'write_performed' => false,
    ];
    if ($output === '-') {
        echo json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    p5_write_json($output, $inventory);
    cli_writeln(
        'TARGET_PLUGINS_OK target=' . $targetid .
        ' plugins=' . count($plugins) .
        ' modules=' . count($modules) .
        ' write=0'
    );
} catch (Throwable $error) {
    cli_error('TARGET_PLUGINS_ERROR ' . $error->getMessage());
}
