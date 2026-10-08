<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/v8-plugin-catalog.php';
require_once __DIR__ . '/../scripts/v8-preparation.php';

function rc1_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('RC1_ASSERT_FAILED: ' . $message);
    }
}

$usedBy = [[
    'source_id' => 'source-a',
    'source_version' => '2024100700',
    'observed_state' => 'installed_disabled',
    'used_activity' => true,
]];

$baseLock = [
    'plugins' => [],
    'satisfied_plugins' => [],
    'unknown_plugins' => [[
        'component' => 'mod_bigbluebuttonbn',
        'required_by' => $usedBy,
    ]],
];

$enabledInventory = [
    'plugins' => [[
        'component' => 'mod_bigbluebuttonbn',
        'source' => 'standard',
        'version_disk' => 2024100700,
        'version_db' => 2024100700,
        'installation_status' => 'uptodate',
        'enabled' => true,
        'root_path' => '/var/www/html/public/mod/bigbluebuttonbn',
    ]],
    'approved_plugins' => [],
];

$adopted = v8c_adopt_manual($baseLock, $enabledInventory);
rc1_assert(count($adopted['unknown_plugins']) === 0,
    'standard enabled should be resolved');
rc1_assert(($adopted['satisfied_plugins'][0]['resolution'] ?? '') ===
    'satisfied_by_target', 'standard enabled resolution');

$disabledInventory = $enabledInventory;
$disabledInventory['plugins'][0]['installation_status'] = 'disabled';
$disabledInventory['plugins'][0]['enabled'] = false;
$blocked = false;
try {
    v8c_adopt_manual($baseLock, $disabledInventory);
} catch (V8CatalogException $error) {
    $blocked = str_contains($error->getMessage(), 'V8_PLUGIN_UNKNOWN_NOT_PINNED');
}
rc1_assert($blocked, 'used disabled module must not be satisfied_by_target');

$parentLock = [
    'plugins' => [[
        'component' => 'mod_customcert',
        'path' => 'public/mod/customcert',
        'version' => 2026042001,
        'commit' => str_repeat('a', 40),
        'tree_sha256' => str_repeat('b', 64),
    ]],
    'satisfied_plugins' => [],
    'unknown_plugins' => [[
        'component' => 'customcertelement_code',
        'required_by' => [],
    ]],
];
$parentInventory = [
    'plugins' => [
        [
            'component' => 'mod_customcert',
            'source' => 'additional',
            'version_disk' => 2026042001,
            'version_db' => 2026042001,
            'installation_status' => 'uptodate',
            'enabled' => true,
            'root_path' => '/var/www/html/public/mod/customcert',
            'approved_commit' => str_repeat('a', 40),
            'tree_sha256' => str_repeat('b', 64),
        ],
        [
            'component' => 'customcertelement_code',
            'source' => 'additional',
            'version_disk' => 2026042001,
            'version_db' => 2026042001,
            'installation_status' => 'uptodate',
            'enabled' => null,
            'root_path' => '/var/www/html/public/mod/customcert/element/code',
        ],
    ],
    'approved_plugins' => [],
];
$nested = v8c_adopt_manual($parentLock, $parentInventory);
rc1_assert(($nested['satisfied_plugins'][0]['resolution'] ?? '') ===
    'satisfied_by_parent', 'nested plugin should be covered by pinned parent');
rc1_assert(($nested['satisfied_plugins'][0]['parent_component'] ?? '') ===
    'mod_customcert', 'nested parent component');

$tmp = sys_get_temp_dir() . '/v8rc1-' . bin2hex(random_bytes(5));
mkdir($tmp, 0770, true);
$csv = $tmp . '/bom.csv';
file_put_contents($csv, "\xEF\xBB\xBFsource,user_id\nsource-a,1\n");
$parsed = v8p_read_csv($csv);
rc1_assert(($parsed['headers'][0] ?? '') === 'source', 'UTF-8 BOM must be stripped');

$src = $tmp . '/src';
$dst = $tmp . '/dst';
mkdir($src, 0770, true);
file_put_contents($src . '/run.sh', "#!/bin/sh\nexit 0\n");
chmod($src . '/run.sh', 0755);
v8c_copy_tree($src, $dst);
rc1_assert((fileperms($dst . '/run.sh') & 0777) === 0755,
    'plugin copy must preserve executable bit');

@unlink($dst . '/run.sh');
@rmdir($dst);
@unlink($src . '/run.sh');
@rmdir($src);
@unlink($csv);
@rmdir($tmp);

echo "V8_RC1_REGRESSIONS_OK\n";
