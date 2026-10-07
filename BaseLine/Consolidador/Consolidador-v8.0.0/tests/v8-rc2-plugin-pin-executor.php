<?php
declare(strict_types=1);

require_once(__DIR__ . '/../docker/verify-plugin-pins.php');
require_once(__DIR__ . '/../scripts/target-plugin-pin.php');

function v8pin_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('V8_RC2_PLUGIN_PIN_EXECUTOR_FAILED ' . $message);
    }
}
function v8pin_block(callable $action, string $code): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        v8pin_check(str_contains($error->getMessage(), $code),
            $code . ' produjo: ' . $error->getMessage());
    }
}
function v8pin_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) {
        v8pin_remove($item->getPathname());
    }
    rmdir($path);
}
function v8pin_write_manifest(string $path, array $plugins): void {
    file_put_contents($path, json_encode([
        'schema_version' => '1.0', 'plugins' => $plugins,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

$root = sys_get_temp_dir() . '/v8-rc2-plugin-pin-' . bin2hex(random_bytes(6));
$moodle = $root . '/moodle';
$pluginPath = 'public/mod/pinnedfixture';
$pluginRoot = $moodle . '/' . $pluginPath;
$manifestPath = $root . '/approved-plugins.json';
mkdir($pluginRoot . '/submodule', 0770, true);
file_put_contents($pluginRoot . '/version.php', "<?php\n" .
    "\$plugin->component = 'mod_pinnedfixture';\n" .
    "\$plugin->version = 2026050100;\n" .
    "\$plugin->release = '5.2.0';\n");
file_put_contents($pluginRoot . '/lib.php', "<?php // pinned runtime fixture\n");
file_put_contents($pluginRoot . '/submodule/library.php', "<?php // pinned submodule\n");
$submoduleTree = pin_tree($pluginRoot . '/submodule');
$pin = [
    'component' => 'mod_pinnedfixture',
    'path' => $pluginPath,
    'version' => 2026050100,
    'release' => '5.2.0',
    'commit' => str_repeat('a', 40),
    'tree_sha256' => pin_tree($pluginRoot),
    'submodules' => [[
        'path' => 'submodule',
        'commit' => str_repeat('b', 40),
        'tree_sha256' => $submoduleTree,
    ]],
];
v8pin_write_manifest($manifestPath, [$pin]);

try {
    $valid = target_plugin_verify_additional_pin(
        'mod_pinnedfixture', $pluginRoot, $moodle, $manifestPath, false
    );
    v8pin_check($valid['tree_sha256'] === $pin['tree_sha256'] &&
        $valid['submodules'] === 1 && !$valid['caller_claim_ignored'],
        'pin válido no se verificó contra el código instalado');

    $fakeClaim = target_plugin_verify_additional_pin(
        'mod_pinnedfixture', $pluginRoot, $moodle, $manifestPath, true
    );
    v8pin_check($fakeClaim['caller_claim_ignored'] === true,
        'el helper no registró el claim como no autoritativo');

    v8pin_write_manifest($manifestPath, []);
    v8pin_block(static fn() => target_plugin_verify_additional_pin(
        'mod_pinnedfixture', $pluginRoot, $moodle, $manifestPath, true
    ), 'TARGET_PLUGIN_PIN_REQUIRED');
    v8pin_write_manifest($manifestPath, [$pin]);

    file_put_contents($pluginRoot . '/version.php', "<?php\n" .
        "\$plugin->component = 'mod_pinnedfixture';\n" .
        "\$plugin->version = 2026050101;\n" .
        "\$plugin->release = '5.2.0';\n");
    v8pin_block(static fn() => target_plugin_verify_additional_pin(
        'mod_pinnedfixture', $pluginRoot, $moodle, $manifestPath, true
    ), 'TARGET_PLUGIN_PIN_VERSION_MISMATCH');
    file_put_contents($pluginRoot . '/version.php', "<?php\n" .
        "\$plugin->component = 'mod_pinnedfixture';\n" .
        "\$plugin->version = 2026050100;\n" .
        "\$plugin->release = '5.2.1';\n");
    v8pin_block(static fn() => target_plugin_verify_additional_pin(
        'mod_pinnedfixture', $pluginRoot, $moodle, $manifestPath, true
    ), 'TARGET_PLUGIN_PIN_RELEASE_MISMATCH');
    file_put_contents($pluginRoot . '/version.php', "<?php\n" .
        "\$plugin->component = 'mod_pinnedfixture';\n" .
        "\$plugin->version = 2026050100;\n" .
        "\$plugin->release = '5.2.0';\n");

    file_put_contents($pluginRoot . '/lib.php', "<?php // altered runtime fixture\n");
    v8pin_block(static fn() => target_plugin_verify_additional_pin(
        'mod_pinnedfixture', $pluginRoot, $moodle, $manifestPath, true
    ), 'TARGET_PLUGIN_PIN_TREE_MISMATCH');
    $pin['tree_sha256'] = pin_tree($pluginRoot);
    v8pin_write_manifest($manifestPath, [$pin]);
    file_put_contents($pluginRoot . '/submodule/library.php', "<?php // altered submodule\n");
    $pin['tree_sha256'] = pin_tree($pluginRoot);
    v8pin_write_manifest($manifestPath, [$pin]);
    v8pin_block(static fn() => target_plugin_verify_additional_pin(
        'mod_pinnedfixture', $pluginRoot, $moodle, $manifestPath, true
    ), 'TARGET_PLUGIN_PIN_SUBMODULE_TREE_MISMATCH');

    v8pin_check(
        str_contains((string)file_get_contents(__DIR__ . '/../scripts/target-enable-plugin.php'),
            'target_plugin_verify_additional_pin('),
        'target-enable-plugin.php no invoca la verificación defensiva');
    echo "V8_RC2_PLUGIN_PIN_EXECUTOR_OK valid=1 fake_claim=blocked " .
        "version=checked release=checked runtime_tree=checked submodule=checked\n";
} finally {
    v8pin_remove($root);
}
