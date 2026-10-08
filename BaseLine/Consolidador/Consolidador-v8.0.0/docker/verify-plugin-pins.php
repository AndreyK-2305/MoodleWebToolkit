<?php
// Falla la construcción si un plugin o submodule no coincide con su pin.
declare(strict_types=1);

function pin_tree(string $directory, array $excludedChildren = []): string {
    if (!is_dir($directory)) {
        throw new RuntimeException('Falta el directorio ' . $directory);
    }
    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($directory) + 1));
        foreach ($excludedChildren as $child) {
            if ($relative === $child || str_starts_with($relative, $child . '/')) {
                continue 2;
            }
        }
        if (preg_match('~(^|/)\.git(/|$)~', $relative)) {
            continue;
        }
        if ($item->isLink() || !$item->isFile()) {
            throw new RuntimeException('Entrada no regular: ' . $relative);
        }
        $entries[$relative] = hash_file('sha256', $item->getPathname());
    }
    ksort($entries, SORT_STRING);
    $input = '';
    foreach ($entries as $name => $hash) {
        $input .= $name . "\0" . $hash . "\0";
    }
    return hash('sha256', $input);
}

function pin_git(string $directory, array $arguments): string {
    $cmd = 'git -C ' . escapeshellarg($directory);
    foreach ($arguments as $argument) {
        $cmd .= ' ' . escapeshellarg($argument);
    }
    exec($cmd . ' 2>/dev/null', $lines, $status);
    if ($status !== 0) {
        throw new RuntimeException('No se puede comprobar Git en ' . $directory);
    }
    return rtrim(implode("\n", $lines), "\r\n");
}

function pin_child_paths(array $pins, string $parentPath): array {
    $children = [];
    foreach ($pins as $pin) {
        $path = (string)($pin['path'] ?? '');
        if (str_starts_with($path, $parentPath . '/')) {
            $children[] = substr($path, strlen($parentPath) + 1);
        }
    }
    sort($children, SORT_STRING);
    return $children;
}

function pin_verify(string $root, array $manifest): array {
    if (($manifest['schema_version'] ?? '') !== '1.0' ||
            !is_array($manifest['plugins'] ?? null)) {
        throw new RuntimeException('Manifest de plugins inválido.');
    }
    $approved = [];
    $paths = [];
    foreach ($manifest['plugins'] as $pin) {
        if (!is_array($pin) || !is_string($pin['path'] ?? null) ||
                isset($paths[$pin['path']])) {
            throw new RuntimeException('Paths de plugins repetidos o inválidos.');
        }
        $paths[$pin['path']] = true;
    }
    foreach ($manifest['plugins'] as $pin) {
        $path = (string)($pin['path'] ?? '');
        $component = (string)($pin['component'] ?? '');
        if (!preg_match('~^(?:public/)?[a-z]+/[a-z][a-z0-9_]*(?:/[a-z0-9_-]+)*$~', $path) ||
                !preg_match('/^[a-z][a-z0-9]*_[a-z][a-z0-9_]*$/', $component) ||
                isset($approved[$component]) ||
                !preg_match('/^[a-f0-9]{40}$/', (string)($pin['commit'] ?? '')) ||
                !preg_match('/^[a-f0-9]{64}$/', (string)($pin['tree_sha256'] ?? '')) ||
                (int)($pin['version'] ?? 0) < 1 ||
                !is_array($pin['submodules'] ?? null)) {
            throw new RuntimeException('Pin incompleto o repetido: ' . $component);
        }
        $hasRelease = array_key_exists('release', $pin) && $pin['release'] !== null;
        if ($hasRelease && !is_string($pin['release'])) {
            throw new RuntimeException('Release no es texto: ' . $component);
        }
        $provenance = $pin['provenance'] ?? null;
        if ($component === 'mod_evoting' || $provenance !== null) {
            if (!is_array($provenance) ||
                    ($provenance['kind'] ?? '') !== 'institutional_git' ||
                    trim((string)($provenance['source'] ?? '')) === '' ||
                    trim((string)($provenance['responsible'] ?? '')) === '' ||
                    !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($provenance['reviewed_at'] ?? '')) ||
                    trim((string)($provenance['origin'] ?? '')) === '') {
                throw new RuntimeException('Procedencia Git institucional incompleta: ' . $component);
            }
        }
        $children = pin_child_paths($manifest['plugins'], $path);
        $directory = $root . '/' . $path;
        $segmentPath = $root;
        foreach (explode('/', $path) as $segment) {
            $segmentPath .= '/' . $segment;
            if (is_link($segmentPath)) {
                throw new RuntimeException('Path de plugin es symlink: ' . $path);
            }
        }
        $versionfile = $directory . '/version.php';
        $versiontext = is_readable($versionfile)
            ? (string)file_get_contents($versionfile) : '';
        $releaseDeclared = preg_match('/\$plugin->release\s*=\s*([\'\"])([^\'\"]*)\1\s*;/',
            $versiontext, $rm) === 1;
        if (!preg_match('/\$plugin->version\s*=\s*([0-9]+)\s*;/', $versiontext, $vm) ||
                (int)$vm[1] !== (int)$pin['version'] ||
                ($releaseDeclared ? (!$hasRelease || $rm[2] !== $pin['release']) :
                    ($hasRelease || preg_match('/\$plugin->release\s*=/', $versiontext))) ||
                !preg_match('/\$plugin->component\s*=\s*([\'\"])([^\'\"]+)\1\s*;/',
                    $versiontext, $cm) || $cm[2] !== $component) {
            throw new RuntimeException('version.php no coincide con el pin: ' . $component);
        }
        if ($provenance !== null &&
                pin_git($directory, ['config', '--get', 'remote.origin.url']) !==
                    $provenance['origin']) {
            throw new RuntimeException('Origin no coincide con procedencia: ' . $component);
        }
        foreach ($children as $child) {
            if (pin_git($directory, ['ls-files', '--', $child]) !== '') {
                throw new RuntimeException('El padre rastrea archivos del hijo: ' . $component);
            }
        }
        $gitStatusArgs = ['status', '--porcelain', '--untracked-files=all'];
        if ($children) {
            $gitStatusArgs = array_merge($gitStatusArgs, ['--', '.'],
                array_map(static fn(string $child): string =>
                    ':(exclude)' . $child, $children));
        }
        if (pin_git($directory, ['rev-parse', 'HEAD']) !== $pin['commit'] ||
                pin_git($directory, $gitStatusArgs) !== '' ||
                pin_tree($directory, $children) !== $pin['tree_sha256']) {
            throw new RuntimeException('Commit, árbol o worktree distinto: ' . $component);
        }
        $declared = [];
        foreach ($pin['submodules'] as $subpin) {
            $subpath = (string)($subpin['path'] ?? '');
            if (!preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~', $subpath) ||
                    isset($declared[$subpath]) ||
                    count(array_filter($children, static fn(string $child): bool =>
                        $subpath === $child || str_starts_with($subpath, $child . '/') ||
                        str_starts_with($child, $subpath . '/'))) > 0 ||
                    !preg_match('/^[a-f0-9]{40}$/', (string)($subpin['commit'] ?? '')) ||
                    !preg_match('/^[a-f0-9]{64}$/', (string)($subpin['tree_sha256'] ?? ''))) {
                throw new RuntimeException('Submodule sin pin completo: ' . $component);
            }
            $subdir = $directory . '/' . $subpath;
            if (pin_git($subdir, ['rev-parse', 'HEAD']) !== $subpin['commit'] ||
                    pin_tree($subdir) !== $subpin['tree_sha256']) {
                throw new RuntimeException('Submodule distinto: ' . $subpath);
            }
            $declared[$subpath] = true;
        }
        $actual = pin_git($directory, ['submodule', 'status', '--recursive']);
        $found = [];
        foreach (explode("\n", $actual) as $line) {
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^ ([a-f0-9]{40}) ([^ ]+)(?: \(.*\))?$/', $line, $m) ||
                    !isset($declared[$m[2]])) {
                throw new RuntimeException('Submodule no inicializado o sin pin: ' . $line);
            }
            $found[$m[2]] = true;
        }
        ksort($found, SORT_STRING);
        ksort($declared, SORT_STRING);
        if ($found !== $declared) {
            throw new RuntimeException('Submodules declarados no coinciden: ' . $component);
        }
        $approved[$component] = $pin;
    }
    // No permitir que COPY introduzca un plugin adicional sin manifest.
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (in_array($relative, ['README-PLUGINS.txt', 'approved-plugins.json'], true)) {
            continue;
        }
        $covered = array_filter($manifest['plugins'], static fn(array $p): bool =>
            str_starts_with($relative, $p['path'] . '/'));
        if (!$covered) {
            throw new RuntimeException('Archivo empaquetado sin pin: ' . $relative);
        }
    }
    return $approved;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $root = rtrim((string)($argv[1] ?? ''), '/');
        $manifest = json_decode((string)file_get_contents((string)($argv[2] ?? '')),
            true, 512, JSON_THROW_ON_ERROR);
        $pins = pin_verify($root, $manifest);
        echo 'PLUGIN_PINS_OK count=' . count($pins) . PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, 'PLUGIN_PINS_ERROR ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
