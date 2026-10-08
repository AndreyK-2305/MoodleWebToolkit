<?php
// Revalida pins adicionales dentro del proceso que habilita el módulo.
declare(strict_types=1);

/** Resuelve el root entregado por Moodle sin inferir carpetas por tipo. */
function target_plugin_resolve_location(
    string $component,
    string $pluginRoot,
    string $moodleRoot,
    bool $isStandard
): array {
    if (!preg_match('/^[a-z][a-z0-9]*_[a-z][a-z0-9_]*$/', $component)) {
        return ['status' => 'unknown_component', 'root_path' => '',
            'relative_path' => ''];
    }
    $root = realpath($moodleRoot);
    $actual = realpath($pluginRoot);
    if ($root === false || $actual === false || !is_dir($actual)) {
        return ['status' => 'declared_missing', 'root_path' => '',
            'relative_path' => ''];
    }
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $actual = rtrim(str_replace('\\', '/', $actual), '/');
    if ($actual === $root || !str_starts_with($actual, $root . '/')) {
        return ['status' => 'unknown_component', 'root_path' => $actual,
            'relative_path' => ''];
    }
    return [
        'status' => $isStandard ? 'core_component' : 'installed_localizable',
        'root_path' => $actual,
        'relative_path' => substr($actual, strlen($root) + 1),
    ];
}

/** Evita duplicar public/ cuando $CFG->dirroot ya apunta a ese directorio. */
function target_plugin_resolve_approved_path(
    string $moodleRoot,
    string $approvedPath
): string|false {
    $root = realpath($moodleRoot);
    $segments = explode('/', trim(str_replace('\\', '/', $approvedPath), '/'));
    if ($root === false || !$segments || in_array('', $segments, true) ||
            in_array('.', $segments, true) || in_array('..', $segments, true)) {
        return false;
    }
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (count($segments) > 1 && $segments[0] === basename($root)) {
        array_shift($segments);
    }
    $candidate = realpath($root . '/' . implode('/', $segments));
    if ($candidate === false) {
        return false;
    }
    $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
    return str_starts_with($candidate, $root . '/') ? $candidate : false;
}

function target_plugin_approved_relative_path(
    string $moodleRoot,
    string $approvedPath
): string {
    $root = realpath($moodleRoot);
    $segments = explode('/', trim(str_replace('\\', '/', $approvedPath), '/'));
    if ($root !== false && count($segments) > 1 &&
            $segments[0] === basename(str_replace('\\', '/', $root))) {
        array_shift($segments);
    }
    return implode('/', $segments);
}

function target_plugin_verify_additional_pin(
    string $component,
    string $pluginRoot,
    string $moodleRoot,
    string $manifestPath,
    bool $callerClaim = false
): array {
    if (!function_exists('pin_tree') || !function_exists('pin_child_paths')) {
        throw new RuntimeException('TARGET_PLUGIN_PIN_VERIFIER_UNAVAILABLE');
    }
    if (!is_readable($manifestPath)) {
        throw new RuntimeException('TARGET_PLUGIN_PIN_MANIFEST_MISSING');
    }
    try {
        $manifest = json_decode(
            (string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR
        );
    } catch (Throwable $error) {
        throw new RuntimeException('TARGET_PLUGIN_PIN_MANIFEST_INVALID', 0, $error);
    }
    if (!is_array($manifest) || ($manifest['schema_version'] ?? '') !== '1.0' ||
            !is_array($manifest['plugins'] ?? null)) {
        throw new RuntimeException('TARGET_PLUGIN_PIN_MANIFEST_INVALID');
    }

    $pins = [];
    foreach ($manifest['plugins'] as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException('TARGET_PLUGIN_PIN_MANIFEST_INVALID');
        }
        $entryComponent = (string)($entry['component'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9]*_[a-z][a-z0-9_]*$/', $entryComponent) ||
                isset($pins[$entryComponent])) {
            throw new RuntimeException('TARGET_PLUGIN_PIN_MANIFEST_INVALID');
        }
        $pins[$entryComponent] = $entry;
    }
    $pin = $pins[$component] ?? null;
    if (!is_array($pin)) {
        throw new RuntimeException('TARGET_PLUGIN_PIN_REQUIRED: ' . $component);
    }
    $path = (string)($pin['path'] ?? '');
    $version = $pin['version'] ?? null;
    $release = $pin['release'] ?? null;
    $commit = (string)($pin['commit'] ?? '');
    $tree = (string)($pin['tree_sha256'] ?? '');
    $submodules = $pin['submodules'] ?? null;
    if (!preg_match('~^(?:public/)?[a-z]+/[a-z][a-z0-9_]*(?:/[a-z0-9_-]+)*$~', $path) ||
        (!is_int($version) && !(is_string($version) && ctype_digit($version))) ||
            (int)$version < 1 ||
            ($release !== null && !is_string($release)) ||
            !preg_match('/^[a-f0-9]{40}$/', $commit) ||
            !preg_match('/^[a-f0-9]{64}$/', $tree) || !is_array($submodules)) {
        throw new RuntimeException('TARGET_PLUGIN_PIN_INVALID: ' . $component);
    }

    $root = realpath($moodleRoot);
    $location = target_plugin_resolve_location(
        $component,
        $pluginRoot,
        $moodleRoot,
        false
    );
    $expectedRoot = target_plugin_resolve_approved_path($moodleRoot, $path);
    $actualRoot = (string)($location['root_path'] ?? '');
    if ($root === false || $expectedRoot === false ||
            ($location['status'] ?? '') !== 'installed_localizable' ||
            $expectedRoot !== $actualRoot || !is_dir($actualRoot)) {
        throw new RuntimeException('TARGET_PLUGIN_PIN_PATH_MISMATCH: ' . $component);
    }
    $cursor = $root;
    $relativePath = target_plugin_approved_relative_path($moodleRoot, $path);
    foreach (explode('/', $relativePath) as $segment) {
        $cursor .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($cursor)) {
            throw new RuntimeException('TARGET_PLUGIN_PIN_PATH_UNSAFE: ' . $component);
        }
    }

    $versionFile = $actualRoot . '/version.php';
    $versionText = is_readable($versionFile) && !is_link($versionFile)
        ? (string)file_get_contents($versionFile) : '';
    if (!preg_match('/\$plugin->component\s*=\s*([\'"])([^\'"]+)\1\s*;/',
            $versionText, $componentMatch) || $componentMatch[2] !== $component ||
            !preg_match('/\$plugin->version\s*=\s*([0-9]+)\s*;/',
                $versionText, $versionMatch) || (int)$versionMatch[1] !== $version) {
        throw new RuntimeException('TARGET_PLUGIN_PIN_VERSION_MISMATCH: ' . $component);
    }
    $releaseDeclared = preg_match(
        '/\$plugin->release\s*=\s*([\'"])([^\'"]*)\1\s*;/',
        $versionText,
        $releaseMatch
    ) === 1;
    if ($releaseDeclared !== ($release !== null) ||
            ($releaseDeclared && $releaseMatch[2] !== $release)) {
        throw new RuntimeException('TARGET_PLUGIN_PIN_RELEASE_MISMATCH: ' . $component);
    }

    $children = pin_child_paths($manifest['plugins'], $path);
    try {
        $actualTree = pin_tree($actualRoot, $children);
    } catch (Throwable $error) {
        throw new RuntimeException('TARGET_PLUGIN_PIN_TREE_UNSAFE: ' . $component, 0, $error);
    }
    if (!hash_equals($tree, $actualTree)) {
        throw new RuntimeException('TARGET_PLUGIN_PIN_TREE_MISMATCH: ' . $component);
    }

    foreach ($submodules as $submodule) {
        if (!is_array($submodule)) {
            throw new RuntimeException('TARGET_PLUGIN_PIN_SUBMODULE_INVALID: ' . $component);
        }
        $subpath = (string)($submodule['path'] ?? '');
        $subcommit = (string)($submodule['commit'] ?? '');
        $subtree = (string)($submodule['tree_sha256'] ?? '');
        if (!preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~', $subpath) ||
                !preg_match('/^[a-f0-9]{40}$/', $subcommit) ||
                !preg_match('/^[a-f0-9]{64}$/', $subtree)) {
            throw new RuntimeException('TARGET_PLUGIN_PIN_SUBMODULE_INVALID: ' . $component);
        }
        $subroot = $actualRoot . '/' . $subpath;
        if (is_link($subroot) || !is_dir($subroot)) {
            throw new RuntimeException('TARGET_PLUGIN_PIN_SUBMODULE_MISSING: ' . $component . '/' . $subpath);
        }
        try {
            $subtreeActual = pin_tree($subroot);
        } catch (Throwable $error) {
            throw new RuntimeException(
                'TARGET_PLUGIN_PIN_SUBMODULE_UNSAFE: ' . $component . '/' . $subpath,
                0,
                $error
            );
        }
        if (!hash_equals($subtree, $subtreeActual)) {
            throw new RuntimeException('TARGET_PLUGIN_PIN_SUBMODULE_TREE_MISMATCH: ' .
                $component . '/' . $subpath);
        }
    }

    return [
        'component' => $component,
        'path' => $path,
        'version' => $version,
        'release' => $release,
        'commit' => $commit,
        'tree_sha256' => $tree,
        'submodules' => count($submodules),
        // El claim se expone solo como dato de auditoría; nunca altera el resultado.
        'caller_claim_ignored' => $callerClaim,
    ];
}
