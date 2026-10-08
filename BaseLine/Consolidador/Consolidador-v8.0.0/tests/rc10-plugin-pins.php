<?php
declare(strict_types=1);
require_once __DIR__ . '/../docker/verify-plugin-pins.php';

function rc10_assert(bool $condition, string $reason): void {
    if (!$condition) { throw new RuntimeException('RC10_PINS_FAILED ' . $reason); }
}
function rc10_git(string $path, array $args): string {
    $command = 'git -C ' . escapeshellarg($path);
    foreach ($args as $arg) { $command .= ' ' . escapeshellarg($arg); }
    exec($command . ' 2>&1', $out, $status);
    rc10_assert($status === 0, 'git ' . implode(' ', $args) . ' ' . implode(' ', $out));
    return trim(implode("\n", $out));
}
function rc10_reject(callable $action, string $reason): void {
    try {
        $action();
    } catch (RuntimeException $e) {
        rc10_assert(!str_contains($e->getMessage(), 'aceptó'), $reason);
        return;
    }
    throw new RuntimeException('RC10_PINS_FAILED aceptó ' . $reason);
}
$temp = sys_get_temp_dir() . '/rc10-pins-' . bin2hex(random_bytes(6));
$root = $temp . '/custom-plugins';
$parent = $root . '/public/mod/customcert';
$child = $parent . '/element/daterange';
$evoting = $root . '/public/mod/evoting';
mkdir($parent, 0770, true);
mkdir($evoting, 0770, true);
try {
    rc10_git($parent, ['init', '-q']);
    file_put_contents($parent . '/version.php', "<?php\n\$plugin->component = 'mod_customcert';\n\$plugin->version = 2026042013;\n\$plugin->release = '5.2.8';\n");
    rc10_git($parent, ['add', '.']);
    rc10_git($parent, ['-c', 'user.name=Test', '-c', 'user.email=test@example.edu',
        'commit', '-qm', 'parent']);
    $parentHash = pin_tree($parent);
    mkdir($child, 0770, true);
    rc10_git($child, ['init', '-q']);
    file_put_contents($child . '/version.php', "<?php\n\$plugin->component = 'customcertelement_daterange';\n\$plugin->version = 2026042000;\n\$plugin->requires = 2026042000;\n");
    rc10_git($child, ['add', '.']);
    rc10_git($child, ['-c', 'user.name=Test', '-c', 'user.email=test@example.edu',
        'commit', '-qm', 'child']);
    rc10_assert(pin_tree($parent, ['element/daterange']) === $parentHash,
        'hash padre excluye solo al hijo declarado');
    rc10_git($evoting, ['init', '-q']);
    file_put_contents($evoting . '/version.php', "<?php\n\$plugin->component = 'mod_evoting';\n\$plugin->version = 2024042302;\n\$plugin->release = 'v4.0';\n");
    rc10_git($evoting, ['add', '.']);
    rc10_git($evoting, ['-c', 'user.name=Test', '-c', 'user.email=test@example.edu',
        'commit', '-qm', 'institutional']);
    rc10_git($evoting, ['remote', 'add', 'origin', '/institucion/evoting.git']);
    $manifest = ['schema_version' => '1.0', 'plugins' => [[
        'component' => 'mod_customcert', 'path' => 'public/mod/customcert',
        'version' => 2026042013, 'release' => '5.2.8',
        'commit' => rc10_git($parent, ['rev-parse', 'HEAD']),
        'tree_sha256' => $parentHash, 'submodules' => [],
    ], [
        'component' => 'customcertelement_daterange',
        'path' => 'public/mod/customcert/element/daterange',
        'version' => 2026042000, 'release' => null,
        'commit' => rc10_git($child, ['rev-parse', 'HEAD']),
        'tree_sha256' => pin_tree($child), 'submodules' => [],
    ], [
        'component' => 'mod_evoting', 'path' => 'public/mod/evoting',
        'version' => 2024042302, 'release' => 'v4.0',
        'commit' => rc10_git($evoting, ['rev-parse', 'HEAD']),
        'tree_sha256' => pin_tree($evoting), 'submodules' => [],
        'provenance' => ['kind' => 'institutional_git',
            'source' => 'custodia institucional expediente 123',
            'origin' => '/institucion/evoting.git',
            'responsible' => 'Equipo Moodle', 'reviewed_at' => '2026-09-20'],
    ]]];
    rc10_assert(count(pin_verify($root, $manifest)) === 3,
        'padre e hijo Git independientes, release null, evoting institucional');
    $releaseOmitted = $manifest;
    unset($releaseOmitted['plugins'][1]['release']);
    rc10_assert(count(pin_verify($root, $releaseOmitted)) === 3,
        'pin sin release explícito también es válido');
    rc10_reject(function () use ($root, $manifest): void {
        $invalid = $manifest;
        $invalid['plugins'][1]['release'] = 'inventado';
        pin_verify($root, $invalid);
    }, 'release inventado');
    rc10_reject(function () use ($root, $manifest): void {
        $invalid = $manifest;
        $invalid['plugins'][0]['release'] = null;
        pin_verify($root, $invalid);
    }, 'release padre omitido');
    rc10_reject(function () use ($root, $manifest): void {
        $invalid = $manifest;
        $invalid['plugins'][1]['commit'] = str_repeat('0', 40);
        pin_verify($root, $invalid);
    }, 'commit hijo alterado');
    rc10_reject(function () use ($root, $manifest): void {
        $invalid = $manifest;
        $invalid['plugins'][1]['tree_sha256'] = str_repeat('0', 64);
        pin_verify($root, $invalid);
    }, 'árbol hijo alterado');
    rc10_reject(function () use ($root, $manifest): void {
        $invalid = $manifest;
        $invalid['plugins'][] = $invalid['plugins'][1];
        pin_verify($root, $invalid);
    }, 'path solapado');
    rc10_reject(function () use ($root, $manifest): void {
        $invalid = $manifest;
        unset($invalid['plugins'][2]['provenance']);
        pin_verify($root, $invalid);
    }, 'evoting sin procedencia');
    rc10_reject(function () use ($root, $manifest): void {
        $invalid = $manifest;
        $invalid['plugins'][2]['provenance']['origin'] = '/otra/institucion.git';
        pin_verify($root, $invalid);
    }, 'origen Git no corresponde');
    $copied = $temp . '/moodle';
    mkdir($copied);
    exec('rsync -a --exclude=.git ' . escapeshellarg($root . '/') . ' ' .
        escapeshellarg($copied . '/'), $lines, $status);
    rc10_assert($status === 0 && is_file($copied . '/public/mod/customcert/element/daterange/version.php') &&
        !file_exists($copied . '/public/mod/customcert/.git') &&
        !file_exists($copied . '/public/mod/customcert/element/daterange/.git'),
        'copia padre/hijo sin .git');
    rc10_assert(pin_tree($copied . '/public/mod/customcert',
        pin_child_paths($manifest['plugins'], 'public/mod/customcert')) === $parentHash &&
        pin_tree($copied . '/public/mod/customcert/element/daterange') ===
            $manifest['plugins'][1]['tree_sha256'],
        'hashes del inventario de destino coinciden después de copiar');
    file_put_contents($parent . '/element/otro.txt', 'sin pin');
    rc10_reject(static fn() => pin_verify($root, $manifest), 'archivo arbitrario en padre');
    unlink($parent . '/element/otro.txt');
    file_put_contents($child . '/extra.txt', 'sin pin');
    rc10_reject(static fn() => pin_verify($root, $manifest), 'archivo arbitrario en hijo');
    echo "RC10_PINS_OK parent_child=git release=optional git_origin=local copy=no_git tampering=blocked\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
    rmdir($temp);
}
