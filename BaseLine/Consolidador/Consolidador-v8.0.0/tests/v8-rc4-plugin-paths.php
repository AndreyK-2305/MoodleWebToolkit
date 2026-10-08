<?php
declare(strict_types=1);

require_once(__DIR__ . '/../docker/verify-plugin-pins.php');
require_once(__DIR__ . '/../scripts/target-plugin-pin.php');

function rc4plugin_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('V8_RC4_PLUGIN_PATHS_FAILED ' . $message);
    }
}

function rc4plugin_remove(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        rc4plugin_remove($item->getPathname());
    }
    rmdir($path);
}

$temporary = sys_get_temp_dir() . '/consolidador-v8-rc4-plugins-' .
    bin2hex(random_bytes(6));
$moodleroot = $temporary . '/moodle/public';
$locations = [
    'mod_fixture' => 'mod/fixture',
    'block_fixture' => 'blocks/fixture',
    'qtype_fixture' => 'question/type/fixture',
    'local_fixture' => 'local/fixture',
    'auth_fixture' => 'auth/fixture',
    'enrol_fixture' => 'enrol/fixture',
    'filter_fixture' => 'filter/fixture',
    'theme_fixture' => 'theme/fixture',
];

try {
    foreach ($locations as $component => $relative) {
        $pluginroot = $moodleroot . '/' . $relative;
        mkdir($pluginroot, 0770, true);
        $location = target_plugin_resolve_location(
            $component,
            $pluginroot,
            $moodleroot,
            false
        );
        rc4plugin_check(
            $location['status'] === 'installed_localizable' &&
            $location['root_path'] === realpath($pluginroot) &&
            $location['relative_path'] === $relative,
            $component . ' no resolvió su root real'
        );

        // Los pins se expresan desde el root del repositorio, mientras que
        // $CFG->dirroot puede apuntar ya al subdirectorio public/.
        rc4plugin_check(
            target_plugin_resolve_approved_path(
                $moodleroot,
                'public/' . $relative
            ) === realpath($pluginroot),
            $component . ' duplicó el segmento public/'
        );
    }

    $pinnedroot = $moodleroot . '/mod/fixture';
    file_put_contents($pinnedroot . '/version.php', "<?php\n" .
        "\$plugin->component = 'mod_fixture';\n" .
        "\$plugin->version = 2026092800;\n" .
        "\$plugin->release = '8.0.0';\n");
    file_put_contents($pinnedroot . '/lib.php', "<?php // rc4 path fixture\n");
    $manifest = $temporary . '/approved-plugins.json';
    file_put_contents($manifest, json_encode([
        'schema_version' => '1.0',
        'plugins' => [[
            'component' => 'mod_fixture',
            'path' => 'public/mod/fixture',
            'version' => 2026092800,
            'release' => '8.0.0',
            'commit' => str_repeat('a', 40),
            'tree_sha256' => pin_tree($pinnedroot),
            'submodules' => [],
        ]],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $verified = target_plugin_verify_additional_pin(
        'mod_fixture',
        $pinnedroot,
        $moodleroot,
        $manifest
    );
    rc4plugin_check($verified['path'] === 'public/mod/fixture',
        'el verificador runtime no aceptó dirroot ya ubicado en public/');

    $core = target_plugin_resolve_location(
        'mod_fixture',
        $moodleroot . '/mod/fixture',
        $moodleroot,
        true
    );
    rc4plugin_check($core['status'] === 'core_component',
        'un componente estándar no se clasificó como core');

    $missing = target_plugin_resolve_location(
        'mod_absent',
        $moodleroot . '/mod/absent',
        $moodleroot,
        false
    );
    rc4plugin_check($missing['status'] === 'declared_missing',
        'un plugin ausente no quedó fail-closed');

    $unknown = target_plugin_resolve_location(
        'not-a-component',
        $moodleroot . '/mod/fixture',
        $moodleroot,
        false
    );
    rc4plugin_check($unknown['status'] === 'unknown_component',
        'un componente desconocido fue tratado como instalado');

    $inventory = (string)file_get_contents(
        __DIR__ . '/../scripts/target-plugins.php'
    );
    rc4plugin_check(
        str_contains($inventory, 'core_plugin_manager::instance()') &&
        str_contains($inventory, '$plugin->rootdir') &&
        str_contains($inventory, 'target_plugin_resolve_location('),
        'el inventario no usa la ruta entregada por Moodle'
    );

    echo "V8_RC4_PLUGIN_PATHS_OK types=8 core=1 missing=1 unknown=1 pin_public=1\n";
} finally {
    rc4plugin_remove($temporary);
}
