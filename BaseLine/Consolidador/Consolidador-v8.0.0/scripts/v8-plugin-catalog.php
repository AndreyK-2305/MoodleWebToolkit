<?php
declare(strict_types=1);

final class V8CatalogException extends RuntimeException {}

function v8c_read_json(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        throw new V8CatalogException("V8_CATALOG_FILE_MISSING: $path");
    }
    try {
        $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new V8CatalogException("V8_CATALOG_JSON_INVALID: $path: " . $error->getMessage());
    }
    if (!is_array($value)) {
        throw new V8CatalogException("V8_CATALOG_JSON_INVALID: $path no contiene un objeto.");
    }
    return $value;
}

function v8c_atomic_json(string $path, array $value): void {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new V8CatalogException("V8_CATALOG_WRITE_FAILED: $directory");
    }
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
    if (file_put_contents($temporary, $json, LOCK_EX) === false ||
            !rename($temporary, $path)) {
        @unlink($temporary);
        throw new V8CatalogException("V8_CATALOG_WRITE_FAILED: $path");
    }
}

function v8c_sha256(string $path): string {
    $hash = is_file($path) ? hash_file('sha256', $path) : false;
    if (!is_string($hash)) {
        throw new V8CatalogException("V8_CATALOG_HASH_FAILED: $path");
    }
    return $hash;
}

function v8c_is_list(array $value): bool {
    return array_is_list($value);
}

function v8c_validate_catalog(array $catalog): array {
    if (($catalog['schema_version'] ?? '') !== '1.0' ||
            ($catalog['catalog_id'] ?? '') !== 'catalog-seed-ufps-2026' ||
            ($catalog['target_moodle_branch'] ?? '') !== '5.2' ||
            !is_array($catalog['entries'] ?? null) ||
            v8c_is_list($catalog['entries'])) {
        throw new V8CatalogException('V8_CATALOG_INVALID: cabecera o entries inválidos.');
    }
    $paths = [];
    foreach ($catalog['entries'] as $component => $entry) {
        if (!is_string($component) ||
                !preg_match('/^[a-z][a-z0-9]*_[a-z][a-z0-9_]*$/', $component) ||
                !is_array($entry) || ($entry['status'] ?? '') !== 'known_good_candidate' ||
                !is_string($entry['repository'] ?? null) ||
                !preg_match('~^https://(?:github\.com|bitbucket\.org)/[^\s]+\.git$~',
                    $entry['repository']) ||
                !preg_match('/^[a-f0-9]{40}$/', (string)($entry['commit'] ?? '')) ||
                !preg_match('/^[a-f0-9]{64}$/', (string)($entry['tree_sha256'] ?? '')) ||
                !is_int($entry['version'] ?? null) || $entry['version'] < 1 ||
                !is_int($entry['requires'] ?? null) || $entry['requires'] < 1 ||
                !is_string($entry['path'] ?? null) ||
                !preg_match('~^(?:public/)?[a-z]+/[a-z][a-z0-9_]*(?:/[a-z0-9_-]+)*$~',
                    $entry['path']) || isset($paths[$entry['path']]) ||
                !is_array($entry['dependencies'] ?? null) ||
                !is_array($entry['submodules'] ?? null) ||
                !is_array($entry['provenance'] ?? null) ||
                trim((string)($entry['provenance']['context'] ?? '')) === '' ||
                trim((string)($entry['provenance']['reviewed_by'] ?? '')) === '' ||
                !preg_match('/^\d{4}-\d{2}-\d{2}$/',
                    (string)($entry['validated_at'] ?? '')) ||
                !in_array('5.2', $entry['supported'] ?? [], true) ||
                ($entry['validated_target'] ?? '') !== '5.2.1' ||
                ($entry['validation_method'] ?? '') !== 'staging_upgrade') {
            throw new V8CatalogException("V8_CATALOG_INVALID: entrada incompleta: $component");
        }
        if ($entry['release'] !== null && !is_string($entry['release'])) {
            throw new V8CatalogException("V8_CATALOG_INVALID: release inválido: $component");
        }
        if (($entry['type'] ?? '') === 'theme' && !str_starts_with($component, 'theme_')) {
            throw new V8CatalogException("V8_CATALOG_INVALID: theme inconsistente: $component");
        }
        foreach ($entry['dependencies'] as $dependency => $minimum) {
            if (!is_string($dependency) ||
                    !preg_match('/^[a-z][a-z0-9]*_[a-z][a-z0-9_]*$/', $dependency) ||
                    !is_int($minimum) || $minimum < 1) {
                throw new V8CatalogException(
                    "V8_CATALOG_INVALID: dependencia inválida: $component");
            }
        }
        $seenSubmodules = [];
        foreach ($entry['submodules'] as $submodule) {
            $subpath = (string)($submodule['path'] ?? '');
            if (!is_array($submodule) ||
                    !preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~', $subpath) ||
                    isset($seenSubmodules[$subpath]) ||
                    !preg_match('/^[a-f0-9]{40}$/', (string)($submodule['commit'] ?? '')) ||
                    !preg_match('/^[a-f0-9]{64}$/', (string)($submodule['tree_sha256'] ?? ''))) {
                throw new V8CatalogException("V8_CATALOG_INVALID: submodule inválido: $component");
            }
            $seenSubmodules[$subpath] = true;
        }
        $paths[$entry['path']] = true;
    }
    ksort($catalog['entries'], SORT_STRING);
    return $catalog;
}

function v8c_promote(array $catalog, array $proposal): array {
    $catalog = v8c_validate_catalog($catalog);
    $component = (string)($proposal['component'] ?? '');
    $entry = $proposal['entry'] ?? null;
    $approval = $proposal['approval'] ?? null;
    if (!preg_match('/^[a-z][a-z0-9]*_[a-z][a-z0-9_]*$/', $component) ||
            !is_array($entry) || !is_array($approval) ||
            trim((string)($approval['approved_by'] ?? '')) === '' ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/',
                (string)($approval['approved_at'] ?? '')) ||
            trim((string)($approval['evidence'] ?? '')) === '') {
        throw new V8CatalogException('V8_CATALOG_PROMOTION_INVALID');
    }
    if (isset($catalog['entries'][$component])) {
        throw new V8CatalogException("V8_CATALOG_PROMOTION_EXISTS: $component");
    }
    $entry['status'] = 'known_good_candidate';
    $entry['validated_target'] = '5.2.1';
    $entry['validation_method'] = 'staging_upgrade';
    $entry['validated_at'] = (string)$approval['approved_at'];
    $entry['provenance'] = is_array($entry['provenance'] ?? null) ?
        $entry['provenance'] : [];
    $entry['provenance']['context'] = (string)($entry['provenance']['context'] ??
        'explicit_promotion');
    $entry['provenance']['reviewed_by'] = (string)$approval['approved_by'];
    $entry['provenance']['promotion_evidence'] = (string)$approval['evidence'];
    $catalog['entries'][$component] = $entry;
    $catalog = v8c_validate_catalog($catalog);
    $catalog['last_promotion'] = [
        'component'=>$component, 'approved_by'=>(string)$approval['approved_by'],
        'approved_at'=>(string)$approval['approved_at'],
        'evidence'=>(string)$approval['evidence'],
    ];
    return $catalog;
}

function v8c_requirements(array $needs): array {
    $rows = $needs['original_compatibility_needs'] ?? $needs;
    if (!is_array($rows)) {
        throw new V8CatalogException('V8_PLUGIN_NEEDS_INVALID: necesidades ilegibles.');
    }
    $requirements = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new V8CatalogException('V8_PLUGIN_NEEDS_INVALID: fila no estructurada.');
        }
        $component = (string)($row['component'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9]*_[a-z][a-z0-9_]*$/', $component)) {
            throw new V8CatalogException("V8_PLUGIN_NEEDS_INVALID: component=$component");
        }
        if (!isset($requirements[$component])) {
            $requirements[$component] = [];
        }
        $requirements[$component][] = [
            'source_id' => (string)($row['source_id'] ?? ''),
            'source_version' => (string)($row['source_version'] ?? ''),
            'observed_state' => (string)($row['observed_state'] ?? ''),
            'used_activity' => (bool)($row['used_activity'] ?? false),
        ];
    }
    ksort($requirements, SORT_STRING);
    return $requirements;
}

function v8c_resolve(array $catalog, array $needs, string $catalogHash,
        string $needsHash = ''): array {
    $catalog = v8c_validate_catalog($catalog);
    $requirements = v8c_requirements($needs);
    $plugins = [];
    $unknown = [];
    $requiredComponents = array_fill_keys(array_keys($requirements), true);
    // Dependencias conocidas también forman parte del lock de esta ejecución.
    $queue = array_keys($requirements);
    while ($queue) {
        $component = array_shift($queue);
        $entry = $catalog['entries'][$component] ?? null;
        if (!is_array($entry)) {
            $unknown[$component] = [
                'component' => $component,
                'state' => 'PLUGIN_UNKNOWN',
                'required_by' => $requirements[$component] ?? [],
            ];
            continue;
        }
        foreach ($entry['dependencies'] as $dependency => $minimum) {
            if (!isset($requiredComponents[$dependency])) {
                $requiredComponents[$dependency] = true;
                $requirements[$dependency] = [[
                    'source_id' => 'catalog_dependency',
                    'source_version' => (string)$minimum,
                    'observed_state' => 'dependency',
                    'used_activity' => false,
                ]];
                $queue[] = $dependency;
            }
        }
    }
    foreach (array_keys($requiredComponents) as $component) {
        if (!isset($catalog['entries'][$component])) {
            continue;
        }
        $entry = $catalog['entries'][$component];
        $plugins[] = [
            'component' => $component,
            'selection_source' => 'compatibility_catalog',
            'selection_state' => 'known_good_candidate',
            'repository' => $entry['repository'],
            'path' => $entry['path'],
            'commit' => $entry['commit'],
            'version' => $entry['version'],
            'release' => $entry['release'],
            'tree_sha256' => $entry['tree_sha256'],
            'submodules' => $entry['submodules'],
            'provenance' => $entry['provenance'],
            'required_by' => $requirements[$component],
            'artifact_verification' => 'pending',
            'staging_validation' => 'pending',
        ];
    }
    usort($plugins, static fn(array $a, array $b): int =>
        (substr_count($a['path'], '/') <=> substr_count($b['path'], '/')) ?:
        strcmp($a['component'], $b['component']));
    ksort($unknown, SORT_STRING);
    return [
        'schema_version' => '1.0',
        'lock_status' => $unknown ? 'DISCOVERY_REQUIRED' : 'CATALOG_CANDIDATES_SELECTED',
        'target_moodle_branch' => '5.2',
        'target_moodle_release' => '5.2.1',
        'catalog_id' => $catalog['catalog_id'],
        'catalog_sha256' => $catalogHash,
        'compatibility_needs_sha256' => $needsHash,
        'plugins' => $plugins,
        'unknown_plugins' => array_values($unknown),
        'plugin_catalog_checked' => true,
        'plugin_lock_valid' => false,
    ];
}

function v8c_normalize_plugin_path(string $path): string {
    $path = str_replace('\\', '/', trim($path));
    foreach (['/var/www/html/', '/opt/moodle/'] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
            break;
        }
    }
    return trim($path, '/');
}

function v8c_inventory_plugin_ready(array $plugin): bool {
    $disk = (int)($plugin['version_disk'] ?? 0);
    $db = (int)($plugin['version_db'] ?? 0);
    $status = (string)($plugin['installation_status'] ?? '');

    return $disk > 0 &&
        $db > 0 &&
        $disk === $db &&
        in_array($status, ['uptodate', 'installed', 'disabled'], true);
}

function v8c_required_by_used_activity(array $requiredBy): bool {
    foreach ($requiredBy as $requirement) {
        if (is_array($requirement) && ($requirement['used_activity'] ?? false) === true) {
            return true;
        }
    }
    return false;
}

function v8c_runtime_usable(array $plugin, array $requiredBy): bool {
    if (!v8c_required_by_used_activity($requiredBy)) {
        return true;
    }
    if ((string)($plugin['installation_status'] ?? '') === 'disabled') {
        return false;
    }
    if (array_key_exists('enabled', $plugin) && $plugin['enabled'] === false) {
        return false;
    }
    return true;
}

function v8c_adopt_manual(array $lock, array $inventory): array {
    $live = [];
    foreach ($inventory['plugins'] ?? [] as $plugin) {
        if (is_array($plugin)) {
            $live[(string)($plugin['component'] ?? '')] = $plugin;
        }
    }

    $pins = [];
    foreach ($inventory['approved_plugins'] ?? [] as $pin) {
        if (is_array($pin)) {
            $pins[(string)($pin['component'] ?? '')] = $pin;
        }
    }

    $satisfied = is_array($lock['satisfied_plugins'] ?? null)
        ? $lock['satisfied_plugins']
        : [];

    foreach ($lock['unknown_plugins'] ?? [] as $unknown) {
        $component = (string)($unknown['component'] ?? '');
        $plugin = $live[$component] ?? null;
        $pin = $pins[$component] ?? null;
        $requiredBy = is_array($unknown['required_by'] ?? null)
            ? $unknown['required_by'] : [];

        /*
         * Los componentes standard/core del destino no necesitan un pin Git,
         * pero un módulo realmente utilizado debe estar habilitado en runtime.
         */
        if (is_array($plugin) &&
                v8c_inventory_plugin_ready($plugin) &&
                v8c_runtime_usable($plugin, $requiredBy) &&
                in_array((string)($plugin['source'] ?? ''),
                    ['standard', 'core'], true)) {
            $satisfied[] = [
                'component' => $component,
                'resolution' => 'satisfied_by_target',
                'target_source' => (string)($plugin['source'] ?? ''),
                'root_path' => (string)($plugin['root_path'] ?? ''),
                'version' => (int)($plugin['version_disk'] ?? 0),
                'installation_status' =>
                    (string)($plugin['installation_status'] ?? ''),
                'enabled' => $plugin['enabled'] ?? null,
                'required_by' => $requiredBy,
            ];
            continue;
        }

        /*
         * Subplugins incluidos físicamente dentro de un plugin padre pinneado
         * quedan cubiertos por el árbol SHA-256 del padre si el inventario AFTER
         * demuestra que el padre exacto es el seleccionado para esta ejecución.
         */
        $coveringParent = null;
        $childPath = is_array($plugin)
            ? v8c_normalize_plugin_path((string)($plugin['root_path'] ?? ''))
            : '';

        if (is_array($plugin) &&
                v8c_inventory_plugin_ready($plugin) &&
                $childPath !== '') {
            $bestLength = -1;

            foreach ($lock['plugins'] ?? [] as $selection) {
                if (!is_array($selection)) {
                    continue;
                }

                $parentComponent = (string)($selection['component'] ?? '');
                $parentPath = v8c_normalize_plugin_path(
                    (string)($selection['path'] ?? ''));

                if ($parentComponent === '' ||
                        $parentComponent === $component ||
                        $parentPath === '' ||
                        !str_starts_with($childPath, $parentPath . '/')) {
                    continue;
                }

                $parentLive = $live[$parentComponent] ?? null;
                if (!is_array($parentLive) ||
                        !v8c_inventory_plugin_ready($parentLive) ||
                        (string)($parentLive['version_disk'] ?? '') !==
                            (string)($selection['version'] ?? '') ||
                        (string)($parentLive['tree_sha256'] ?? '') !==
                            (string)($selection['tree_sha256'] ?? '') ||
                        (string)($parentLive['approved_commit'] ?? '') !==
                            (string)($selection['commit'] ?? '')) {
                    continue;
                }

                if (strlen($parentPath) > $bestLength) {
                    $coveringParent = [$parentComponent, $parentPath];
                    $bestLength = strlen($parentPath);
                }
            }
        }

        if ($coveringParent !== null) {
            [$parentComponent, $parentPath] = $coveringParent;
            $satisfied[] = [
                'component' => $component,
                'resolution' => 'satisfied_by_parent',
                'parent_component' => $parentComponent,
                'parent_path' => $parentPath,
                'root_path' => (string)($plugin['root_path'] ?? ''),
                'version' => (int)($plugin['version_disk'] ?? 0),
                'installation_status' =>
                    (string)($plugin['installation_status'] ?? ''),
                'required_by' => $requiredBy,
            ];
            continue;
        }

        /* Un plugin adicional independiente continúa exigiendo un pin exacto. */
        if (!is_array($plugin) || !is_array($pin) ||
                !preg_match('/^[a-f0-9]{40}$/', (string)($pin['commit'] ?? '')) ||
                !preg_match('/^[a-f0-9]{64}$/',
                    (string)($pin['tree_sha256'] ?? '')) ||
                (string)($plugin['approved_commit'] ?? '') !==
                    (string)$pin['commit'] ||
                (string)($plugin['tree_sha256'] ?? '') !==
                    (string)$pin['tree_sha256'] ||
                (string)($plugin['version_disk'] ?? '') !==
                    (string)($pin['version'] ?? '')) {
            throw new V8CatalogException(
                "V8_PLUGIN_UNKNOWN_NOT_PINNED: $component requiere pin manual exacto.");
        }

        $lock['plugins'][] = [
            'component' => $component,
            'selection_source' => 'manual_approved',
            'selection_state' => 'explicitly_resolved',
            'repository' => (string)($pin['provenance']['origin'] ?? ''),
            'path' => (string)($pin['path'] ?? ''),
            'commit' => (string)$pin['commit'],
            'version' => (int)$pin['version'],
            'release' => $pin['release'] ?? null,
            'tree_sha256' => (string)$pin['tree_sha256'],
            'submodules' => $pin['submodules'] ?? [],
            'provenance' => $pin['provenance'] ?? [],
            'required_by' => $requiredBy,
            'artifact_verification' => 'passed',
            'staging_validation' => 'passed',
        ];
    }

    usort($lock['plugins'], static fn(array $a, array $b): int =>
        strcmp((string)$a['component'], (string)$b['component']));
    usort($satisfied, static fn(array $a, array $b): int =>
        strcmp((string)$a['component'], (string)$b['component']));

    $lock['satisfied_plugins'] = $satisfied;
    $lock['unknown_plugins'] = [];
    $lock['lock_status'] = 'ARTIFACTS_VERIFIED_PENDING_STAGING';
    return $lock;
}

function v8c_tree_sha256(string $directory, array $excludedChildren = []): string {
    if (!is_dir($directory)) {
        throw new V8CatalogException("V8_PLUGIN_ARTIFACT_MISSING: $directory");
    }
    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($directory) + 1));
        if (preg_match('~(^|/)\.git(/|$)~', $relative)) {
            continue;
        }
        foreach ($excludedChildren as $child) {
            if ($relative === $child || str_starts_with($relative, $child . '/')) {
                continue 2;
            }
        }
        if ($item->isLink() || !$item->isFile()) {
            throw new V8CatalogException("V8_PLUGIN_ARTIFACT_UNSAFE: $relative");
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

function v8c_process(array $command, ?string $cwd = null): string {
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new V8CatalogException('V8_PLUGIN_PROCESS_FAILED: ' . $command[0]);
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new V8CatalogException('V8_PLUGIN_PROCESS_FAILED: ' . implode(' ', $command) .
            ': ' . trim((string)$stderr));
    }
    return trim((string)$stdout);
}

function v8c_verify_checkout(string $directory, string $component, array $entry,
        array $childPaths = []): void {
    if (v8c_process(['git', '-C', $directory, 'rev-parse', 'HEAD']) !== $entry['commit']) {
        throw new V8CatalogException("V8_PLUGIN_COMMIT_MISMATCH: $component");
    }
    $origin = v8c_process(['git', '-C', $directory, 'remote', 'get-url', 'origin']);
    if ($origin !== $entry['repository']) {
        throw new V8CatalogException("V8_PLUGIN_PROVENANCE_MISMATCH: $component");
    }
    $versionPath = $directory . '/version.php';
    $versionText = is_file($versionPath) ? (string)file_get_contents($versionPath) : '';
    if (!preg_match('/\$plugin->component\s*=\s*([\'\"])([^\'\"]+)\1\s*;/', $versionText, $cm) ||
            $cm[2] !== $component ||
            !preg_match('/\$plugin->version\s*=\s*([0-9]+)\s*;/', $versionText, $vm) ||
            (int)$vm[1] !== $entry['version']) {
        throw new V8CatalogException("V8_PLUGIN_VERSION_MISMATCH: $component");
    }
    $hasRelease = $entry['release'] !== null;
    $declaresRelease = preg_match('/\$plugin->release\s*=\s*([\'\"])([^\'\"]*)\1\s*;/',
        $versionText, $rm) === 1;
    if ($declaresRelease !== $hasRelease || ($hasRelease && $rm[2] !== $entry['release'])) {
        throw new V8CatalogException("V8_PLUGIN_RELEASE_MISMATCH: $component");
    }
    if (v8c_tree_sha256($directory, $childPaths) !== $entry['tree_sha256']) {
        throw new V8CatalogException("V8_PLUGIN_TREE_MISMATCH: $component");
    }
    foreach ($entry['submodules'] as $submodule) {
        $subdir = $directory . '/' . $submodule['path'];
        if (v8c_process(['git', '-C', $subdir, 'rev-parse', 'HEAD']) !== $submodule['commit'] ||
                v8c_tree_sha256($subdir) !== $submodule['tree_sha256']) {
            throw new V8CatalogException("V8_PLUGIN_SUBMODULE_MISMATCH: $component/" .
                $submodule['path']);
        }
    }
}

function v8c_delete_tree(string $path): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_link($path) || !is_dir($path)) {
        throw new V8CatalogException("V8_PLUGIN_DELETE_REFUSED: $path");
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw new V8CatalogException("V8_PLUGIN_DELETE_REFUSED: " . $item->getPathname());
        }
        $ok = $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        if (!$ok) {
            throw new V8CatalogException("V8_PLUGIN_DELETE_FAILED: " . $item->getPathname());
        }
    }
    if (!rmdir($path)) {
        throw new V8CatalogException("V8_PLUGIN_DELETE_FAILED: $path");
    }
}

function v8c_copy_tree(string $source, string $destination): void {
    if (is_link($source) || !is_dir($source)) {
        throw new V8CatalogException("V8_PLUGIN_COPY_SOURCE_INVALID: $source");
    }
    if (!is_dir($destination) && !mkdir($destination, 0770, true) && !is_dir($destination)) {
        throw new V8CatalogException("V8_PLUGIN_COPY_FAILED: $destination");
    }
    $sourceMode = fileperms($source);
    if ($sourceMode !== false && !chmod($destination, $sourceMode & 0777)) {
        throw new V8CatalogException("V8_PLUGIN_COPY_MODE_FAILED: $destination");
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $target = $destination . '/' . $relative;
        if ($item->isLink()) {
            throw new V8CatalogException("V8_PLUGIN_COPY_SYMLINK: $relative");
        }
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) {
                throw new V8CatalogException("V8_PLUGIN_COPY_FAILED: $target");
            }
        } elseif (!copy($item->getPathname(), $target)) {
            throw new V8CatalogException("V8_PLUGIN_COPY_FAILED: $target");
        }
        $mode = fileperms($item->getPathname());
        if ($mode !== false && !chmod($target, $mode & 0777)) {
            throw new V8CatalogException("V8_PLUGIN_COPY_MODE_FAILED: $target");
        }
    }
}

function v8c_checkout_candidate(string $cacheDirectory, string $component, array $entry,
        array $childPaths = []): string {
    $final = $cacheDirectory . '/' . $component . '/' . $entry['commit'];
    if (is_dir($final)) {
        try {
            v8c_verify_checkout($final, $component, $entry, $childPaths);
            return $final;
        } catch (Throwable) {
            v8c_delete_tree($final);
        }
    }
    $parent = dirname($final);
    if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
        throw new V8CatalogException("V8_PLUGIN_CACHE_FAILED: $parent");
    }
    $temporary = $parent . '/.checkout-' . bin2hex(random_bytes(8));
    try {
        v8c_process(['git', 'clone', '--no-checkout', $entry['repository'], $temporary]);
        v8c_process(['git', '-C', $temporary, 'checkout', '--detach', $entry['commit']]);
        if ($entry['submodules']) {
            v8c_process(['git', '-C', $temporary, 'submodule', 'update', '--init', '--recursive']);
            foreach ($entry['submodules'] as $submodule) {
                v8c_process(['git', '-C', $temporary . '/' . $submodule['path'],
                    'checkout', '--detach', $submodule['commit']]);
            }
        }
        v8c_verify_checkout($temporary, $component, $entry, $childPaths);
        if (!rename($temporary, $final)) {
            throw new V8CatalogException("V8_PLUGIN_CACHE_FAILED: $final");
        }
    } catch (Throwable $error) {
        if (is_dir($temporary)) {
            v8c_delete_tree($temporary);
        }
        throw $error;
    }
    return $final;
}

function v8c_pin(string $component, array $entry): array {
    $pin = [
        'component' => $component,
        'path' => $entry['path'],
        'version' => $entry['version'],
        'release' => $entry['release'],
        'commit' => $entry['commit'],
        'tree_sha256' => $entry['tree_sha256'],
        'submodules' => $entry['submodules'],
    ];
    if (($entry['provenance']['kind'] ?? '') === 'institutional_git') {
        $pin['provenance'] = $entry['provenance'];
    }
    return $pin;
}

function v8c_install(array $catalog, array $lock, string $projectRoot): array {
    $catalog = v8c_validate_catalog($catalog);
    if (($lock['schema_version'] ?? '') !== '1.0' || !is_array($lock['plugins'] ?? null)) {
        throw new V8CatalogException('V8_PLUGIN_LOCK_INVALID: lock ilegible.');
    }
    $cache = $projectRoot . '/cache/plugins';
    $custom = $projectRoot . '/docker/custom-plugins';
    $manifestPath = $custom . '/approved-plugins.json';
    $manifest = is_file($manifestPath) ? v8c_read_json($manifestPath) :
        ['schema_version' => '1.0', 'plugins' => []];
    $pins = [];
    foreach (($manifest['plugins'] ?? []) as $pin) {
        if (is_array($pin) && isset($pin['component'])) {
            $pins[(string)$pin['component']] = $pin;
        }
    }
    $catalogChildren = [];
    foreach ($catalog['entries'] as $parentComponent => $parent) {
        foreach ($catalog['entries'] as $child) {
            if (str_starts_with($child['path'], $parent['path'] . '/')) {
                $catalogChildren[$parentComponent][] = substr(
                    $child['path'], strlen($parent['path']) + 1);
            }
        }
    }
    $installed = [];
    foreach ($lock['plugins'] as &$selection) {
        $component = (string)($selection['component'] ?? '');
        $entry = $catalog['entries'][$component] ?? null;
        if (!is_array($entry)) {
            throw new V8CatalogException("V8_PLUGIN_LOCK_INVALID: $component no existe en catálogo.");
        }
        $checkout = v8c_checkout_candidate($cache, $component, $entry,
            $catalogChildren[$component] ?? []);
        $destination = $custom . '/' . $entry['path'];
        if (is_dir($destination)) {
            v8c_delete_tree($destination);
        }
        v8c_copy_tree($checkout, $destination);
        v8c_verify_checkout($destination, $component, $entry,
            $catalogChildren[$component] ?? []);
        $pins[$component] = v8c_pin($component, $entry);
        $selection['artifact_verification'] = 'passed';
        $selection['cache_path'] = 'cache/plugins/' . $component . '/' . $entry['commit'];
        $installed[] = $component;
    }
    unset($selection);
    ksort($pins, SORT_STRING);
    v8c_atomic_json($manifestPath, [
        'schema_version' => '1.0',
        'plugins' => array_values($pins),
    ]);
    $lock['approved_plugins_sha256'] = v8c_sha256($manifestPath);
    $lock['lock_status'] = ($lock['unknown_plugins'] ?? []) ?
        'DISCOVERY_REQUIRED' : 'ARTIFACTS_VERIFIED_PENDING_STAGING';
    $lock['plugin_lock_valid'] = false;
    return [$lock, [
        'schema_version' => '1.0',
        'installed_count' => count($installed),
        'installed_components' => $installed,
        'unknown_count' => count($lock['unknown_plugins'] ?? []),
        'approved_plugins_sha256' => $lock['approved_plugins_sha256'],
    ]];
}

function v8c_seal(array $catalog, array $lock, array $technical, array $inventory,
        string $technicalHash, string $inventoryHash): array {
    $catalog = v8c_validate_catalog($catalog);
    if (($technical['status'] ?? '') !== 'passed' || !($technical['passed'] ?? false)) {
        throw new V8CatalogException('V8_PLUGIN_STAGING_FAILED: validación técnica no aprobada.');
    }
    $live = [];
    foreach (($inventory['plugins'] ?? []) as $plugin) {
        if (is_array($plugin)) {
            $live[(string)($plugin['component'] ?? '')] = $plugin;
        }
    }

    if (!is_array($lock['plugins'] ?? null)) {
        throw new V8CatalogException(
            'V8_PLUGIN_LOCK_INVALID: plugins no es un arreglo.');
    }
    foreach ($lock['plugins'] as &$selection) {
        $component = (string)($selection['component'] ?? '');
        $entry = $catalog['entries'][$component] ?? null;
        $plugin = $live[$component] ?? null;
        $expected = is_array($entry) ? $entry : $selection;
        if (!is_array($plugin) ||
                (string)($plugin['version_disk'] ?? '') !== (string)($expected['version'] ?? '') ||
                (string)($plugin['tree_sha256'] ?? '') !==
                    (string)($expected['tree_sha256'] ?? '') ||
                (string)($plugin['approved_commit'] ?? '') !==
                    (string)($expected['commit'] ?? '')) {
            throw new V8CatalogException("V8_PLUGIN_STAGING_MISMATCH: $component");
        }
        $selection['staging_validation'] = 'passed';
    }
    unset($selection);

    foreach ($lock['satisfied_plugins'] ?? [] as $selection) {
        if (!is_array($selection)) {
            throw new V8CatalogException('V8_PLUGIN_LOCK_INVALID: satisfied_plugins inválido.');
        }
        $component = (string)($selection['component'] ?? '');
        $plugin = $live[$component] ?? null;
        $resolution = (string)($selection['resolution'] ?? '');
        if (!is_array($plugin) || !v8c_inventory_plugin_ready($plugin)) {
            throw new V8CatalogException("V8_PLUGIN_SATISFIED_MISMATCH: $component");
        }
        if ($resolution === 'satisfied_by_target' &&
                !v8c_runtime_usable($plugin,
                    is_array($selection['required_by'] ?? null)
                        ? $selection['required_by'] : [])) {
            throw new V8CatalogException("V8_PLUGIN_REQUIRED_DISABLED: $component");
        }
        if ($resolution === 'satisfied_by_parent') {
            $parentComponent = (string)($selection['parent_component'] ?? '');
            $parent = $live[$parentComponent] ?? null;
            $parentSelection = null;
            foreach ($lock['plugins'] as $candidate) {
                if (is_array($candidate) &&
                        (string)($candidate['component'] ?? '') === $parentComponent) {
                    $parentSelection = $candidate;
                    break;
                }
            }
            if (!is_array($parent) || !is_array($parentSelection) ||
                    (string)($parent['version_disk'] ?? '') !==
                        (string)($parentSelection['version'] ?? '') ||
                    (string)($parent['tree_sha256'] ?? '') !==
                        (string)($parentSelection['tree_sha256'] ?? '') ||
                    (string)($parent['approved_commit'] ?? '') !==
                        (string)($parentSelection['commit'] ?? '')) {
                throw new V8CatalogException(
                    "V8_PLUGIN_PARENT_MISMATCH: $component -> $parentComponent");
            }
        }
    }

    if ($lock['unknown_plugins'] ?? []) {
        throw new V8CatalogException('V8_PLUGIN_UNKNOWN: quedan componentes sin resolución.');
    }
    $lock['technical_validation_sha256'] = $technicalHash;
    $lock['inventory_after_sha256'] = $inventoryHash;
    $lock['lock_status'] = 'SEALED';
    $lock['plugin_lock_valid'] = true;
    return $lock;
}

function v8c_options(array $argv): array {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new V8CatalogException("V8_ARGUMENT_INVALID: $argument");
        }
        [$key, $value] = explode('=', substr($argument, 2), 2);
        $options[$key] = $value;
    }
    return $options;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = v8c_options($argv);
        $mode = $options['mode'] ?? 'validate';
        $catalogPath = $options['catalog'] ?? '';
        $catalog = v8c_validate_catalog(v8c_read_json($catalogPath));
        if ($mode === 'validate') {
            echo 'V8_PLUGIN_CATALOG_OK entries=' . count($catalog['entries']) . PHP_EOL;
            exit(0);
        }
        if ($mode === 'promote') {
            $proposalPath = $options['proposal'] ?? '';
            $catalog = v8c_promote($catalog, v8c_read_json($proposalPath));
            v8c_atomic_json($catalogPath, $catalog);
            echo 'V8_CATALOG_PROMOTION_OK component=' .
                (string)(v8c_read_json($proposalPath)['component'] ?? '') . PHP_EOL;
            exit(0);
        }
        $lockPath = $options['lock'] ?? '';
        if ($mode === 'resolve') {
            $needsPath = $options['needs'] ?? '';
            $lock = v8c_resolve($catalog, v8c_read_json($needsPath),
                v8c_sha256($catalogPath), v8c_sha256($needsPath));
            v8c_atomic_json($lockPath, $lock);
            echo 'V8_PLUGIN_RESOLVE_OK selected=' . count($lock['plugins']) .
                ' unknown=' . count($lock['unknown_plugins']) . PHP_EOL;
            exit(0);
        }
        if ($mode === 'adopt') {
            $inventoryPath = $options['inventory'] ?? '';
            $lock = v8c_adopt_manual(v8c_read_json($lockPath),
                v8c_read_json($inventoryPath));
            v8c_atomic_json($lockPath, $lock);
            echo 'V8_PLUGIN_ADOPT_OK manual=' . count(array_filter($lock['plugins'],
                static fn(array $plugin): bool =>
                    ($plugin['selection_source'] ?? '') === 'manual_approved')) . PHP_EOL;
            exit(0);
        }
        if ($mode === 'install') {
            [$lock, $summary] = v8c_install($catalog, v8c_read_json($lockPath),
                rtrim($options['projectroot'] ?? '', '/'));
            v8c_atomic_json($lockPath, $lock);
            v8c_atomic_json($options['summary'] ?? '', $summary);
            echo 'V8_PLUGIN_INSTALL_OK installed=' . $summary['installed_count'] .
                ' unknown=' . $summary['unknown_count'] . PHP_EOL;
            exit(0);
        }
        if ($mode === 'seal') {
            $technicalPath = $options['technical'] ?? '';
            $inventoryPath = $options['inventory'] ?? '';
            $lock = v8c_seal($catalog, v8c_read_json($lockPath),
                v8c_read_json($technicalPath), v8c_read_json($inventoryPath),
                v8c_sha256($technicalPath), v8c_sha256($inventoryPath));
            v8c_atomic_json($lockPath, $lock);
            echo 'V8_PLUGIN_LOCK_OK status=SEALED' . PHP_EOL;
            exit(0);
        }
        throw new V8CatalogException("V8_MODE_INVALID: $mode");
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
