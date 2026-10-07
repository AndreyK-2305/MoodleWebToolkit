<?php
// Preparacion V8: candidatos no autoritativos, temas y contrato READY_TO_RUN.
declare(strict_types=1);

final class V8PreparationException extends RuntimeException {}

function v8p_read_json(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        throw new V8PreparationException("V8_PREPARATION_FILE_MISSING: $path");
    }
    try {
        $value = json_decode((string)file_get_contents($path), true, 512,
            JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new V8PreparationException(
            "V8_PREPARATION_JSON_INVALID: $path: " . $error->getMessage());
    }
    if (!is_array($value)) {
        throw new V8PreparationException("V8_PREPARATION_JSON_INVALID: $path");
    }
    return $value;
}

function v8p_atomic_text(string $path, string $contents): void {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) &&
            !is_dir($directory)) {
        throw new V8PreparationException("V8_PREPARATION_WRITE_FAILED: $directory");
    }
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
    if (file_put_contents($temporary, $contents, LOCK_EX) === false ||
            !rename($temporary, $path)) {
        @unlink($temporary);
        throw new V8PreparationException("V8_PREPARATION_WRITE_FAILED: $path");
    }
}

function v8p_atomic_json(string $path, array $value): void {
    v8p_atomic_text($path, json_encode($value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
        JSON_THROW_ON_ERROR) . PHP_EOL);
}

function v8p_sha256(string $path): string {
    $hash = is_file($path) ? hash_file('sha256', $path) : false;
    if (!is_string($hash)) {
        throw new V8PreparationException("V8_PREPARATION_HASH_FAILED: $path");
    }
    return $hash;
}

function v8p_read_csv(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        throw new V8PreparationException("V8_PREPARATION_FILE_MISSING: $path");
    }
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new V8PreparationException("V8_PREPARATION_CSV_INVALID: $path");
    }
    $headers = fgetcsv($stream, 0, ',', '"', '');
    if (!is_array($headers) || $headers === []) {
        fclose($stream);
        throw new V8PreparationException("V8_PREPARATION_CSV_INVALID: $path");
    }
    if (isset($headers[0])) {
        $headers[0] = preg_replace(
            '/^\xEF\xBB\xBF/',
            '',
            (string)$headers[0]
        ) ?? (string)$headers[0];
    }
    $rows = [];
    while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        if ($values === [null] || $values === []) {
            continue;
        }
        if (count($values) !== count($headers)) {
            fclose($stream);
            throw new V8PreparationException("V8_PREPARATION_CSV_INVALID: $path");
        }
        $rows[] = array_combine($headers, $values);
    }
    fclose($stream);
    return ['headers'=>$headers, 'rows'=>$rows];
}

function v8p_canonical_map(array $rows): array {
    $map = [];
    foreach ($rows as $row) {
        $key = trim((string)($row['source'] ?? '')) . ':' .
            trim((string)($row['source_user_id'] ?? ''));
        $canonical = trim((string)($row['canonical_id'] ?? ''));
        if ($key === ':' || $canonical === '' || isset($map[$key])) {
            throw new V8PreparationException('V8_IDENTITY_SOURCE_MAP_INVALID');
        }
        $map[$key] = $canonical;
    }
    ksort($map, SORT_STRING);
    return $map;
}

function v8p_norm(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    if (class_exists('Normalizer')) {
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);
        if (is_string($decomposed)) {
            $value = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $decomposed;
        }
    } else {
        $value = strtr($value, [
            'á'=>'a', 'à'=>'a', 'ä'=>'a', 'â'=>'a', 'é'=>'e', 'è'=>'e',
            'ë'=>'e', 'ê'=>'e', 'í'=>'i', 'ì'=>'i', 'ï'=>'i', 'î'=>'i',
            'ó'=>'o', 'ò'=>'o', 'ö'=>'o', 'ô'=>'o', 'ú'=>'u', 'ù'=>'u',
            'ü'=>'u', 'û'=>'u', 'ñ'=>'n', 'ç'=>'c',
        ]);
    }
    return trim(preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value);
}

function v8p_similarity(string $left, string $right): float {
    if ($left === '' || $right === '') {
        return 0.0;
    }
    if ($left === $right) {
        return 1.0;
    }
    $length = max(strlen($left), strlen($right));
    return $length === 0 ? 1.0 : max(0.0, 1.0 - levenshtein($left, $right) / $length);
}

function v8p_google_state(array $left, array $right): string {
    $a = trim((string)($left['google_sub'] ?? ''));
    $b = trim((string)($right['google_sub'] ?? ''));
    $av = ($left['google_sub_verified'] ?? false) === true && $a !== '';
    $bv = ($right['google_sub_verified'] ?? false) === true && $b !== '';
    if (!$av || !$bv) {
        return !$av && !$bv ? 'missing' : 'unknown';
    }
    return hash_equals($a, $b) ? 'same' : 'different';
}

/**
 * Genera candidatos mediante bloques. Nunca cambia identidades ni google_sub.
 */
function v8p_identity_candidates(array $documents, array $canonicalMap = [],
        array $reviewedGroups = []): array {
    $accounts = [];
    foreach ($documents as $document) {
        if (!is_array($document) || !is_array($document['users'] ?? null)) {
            throw new V8PreparationException('V8_IDENTITY_INPUT_INVALID');
        }
        foreach ($document['users'] as $row) {
            if (!is_array($row) || (int)($row['source_user_id'] ?? 0) < 1) {
                throw new V8PreparationException('V8_IDENTITY_INPUT_INVALID');
            }
            if (($row['historical_deleted'] ?? false) === true ||
                    (int)($row['deleted'] ?? 0) === 1) {
                continue;
            }
            $row['source'] = (string)($row['source'] ??
                ($document['metadata']['source'] ?? ''));
            $row['_name'] = v8p_norm(trim((string)($row['firstname'] ?? '')) . ' ' .
                trim((string)($row['lastname'] ?? '')));
            $row['_first'] = v8p_norm((string)($row['firstname'] ?? ''));
            $row['_last'] = v8p_norm((string)($row['lastname'] ?? ''));
            $email = mb_strtolower(trim((string)($row['email'] ?? '')), 'UTF-8');
            $row['_email'] = $email;
            $row['_local'] = str_contains($email, '@') ? explode('@', $email, 2)[0] : '';
            $row['_document'] = v8p_norm((string)($row['idnumber'] ?? ''));
            $key = $row['source'] . ':' . (int)$row['source_user_id'];
            if ($row['source'] === '' || isset($accounts[$key])) {
                throw new V8PreparationException("V8_IDENTITY_INPUT_DUPLICATE: $key");
            }
            $accounts[$key] = $row;
        }
    }
    ksort($accounts, SORT_STRING);
    $canonicalMembers = [];
    foreach ($canonicalMap as $accountkey => $canonical) {
        $canonicalMembers[$canonical][] = $accountkey;
    }
    foreach ($canonicalMembers as &$members) {
        sort($members, SORT_STRING);
    }
    unset($members);
    $blocks = [];
    foreach ($accounts as $key => $row) {
        $tokens = array_values(array_filter(explode(' ', $row['_name'])));
        $blockKeys = [];
        if ($row['_name'] !== '') {
            $blockKeys[] = 'n:' . $row['_name'];
        }
        if ($row['_last'] !== '' && $row['_first'] !== '') {
            $blockKeys[] = 'f:' . substr($row['_last'], 0, 8) . ':' .
                substr($row['_first'], 0, 3);
        }
        if (count($tokens) > 1) {
            sort($tokens, SORT_STRING);
            $blockKeys[] = 't:' . implode(':', array_slice($tokens, 0, 3));
        }
        if ($row['_document'] !== '') {
            $blockKeys[] = 'd:' . $row['_document'];
        }
        if (strlen($row['_local']) >= 5) {
            $blockKeys[] = 'e:' . substr(v8p_norm($row['_local']), 0, 10);
        }
        foreach (array_unique($blockKeys) as $block) {
            $blocks[$block][] = $key;
        }
    }
    ksort($blocks, SORT_STRING);
    $pairs = [];
    foreach ($blocks as $keys) {
        sort($keys, SORT_STRING);
        // Los bloques muy amplios se subdividen de manera determinista.
        if (count($keys) > 500) {
            $subblocks = [];
            foreach ($keys as $key) {
                $row = $accounts[$key];
                $subblocks[substr($row['_name'], 0, 16)][] = $key;
            }
        } else {
            $subblocks = [$keys];
        }
        foreach ($subblocks as $subset) {
            $count = count($subset);
            if ($count > 500) {
                usort($subset, static fn(string $a, string $b): int =>
                    strcmp((string)$accounts[$a]['_local'] . '|' . $a,
                        (string)$accounts[$b]['_local'] . '|' . $b));
            }
            for ($i = 0; $i < $count; $i++) {
                // Un bloque patológicamente común usa una ventana acotada.
                // Así la preparación sigue siendo O(n*k), nunca todos-contra-todos.
                $limit = $count > 500 ? min($count, $i + 33) : $count;
                for ($j = $i + 1; $j < $limit; $j++) {
                    $a = $accounts[$subset[$i]];
                    $b = $accounts[$subset[$j]];
                    if ($a['source'] === $b['source']) {
                        continue;
                    }
                    $pair = $subset[$i] . '|' . $subset[$j];
                    $pairs[$pair] = [$subset[$i], $subset[$j]];
                }
            }
        }
    }
    ksort($pairs, SORT_STRING);
    $rows = [];
    foreach ($pairs as [$leftKey, $rightKey]) {
        $left = $accounts[$leftKey];
        $right = $accounts[$rightKey];
        $leftCanonical = (string)($canonicalMap[$leftKey] ?? '');
        $rightCanonical = (string)($canonicalMap[$rightKey] ?? '');
        if ($leftCanonical !== '' && $leftCanonical === $rightCanonical) {
            continue;
        }
        $nameScore = v8p_similarity($left['_name'], $right['_name']);
        $emailScore = v8p_similarity(v8p_norm($left['_local']), v8p_norm($right['_local']));
        $documentMatch = $left['_document'] !== '' &&
            $left['_document'] === $right['_document'];
        if (!$documentMatch && $nameScore < 0.84 &&
                !($nameScore >= 0.72 && $emailScore >= 0.76)) {
            continue;
        }
        $confidence = $documentMatch || ($nameScore >= 0.94 && $emailScore >= 0.82)
            ? 'HIGH' : (($nameScore >= 0.86 || $emailScore >= 0.86) ? 'MEDIUM' : 'LOW');
        $candidateGroup = 'CAND-' . strtoupper(substr(hash('sha256',
            $leftKey . '|' . $rightKey), 0, 16));
        if (isset($reviewedGroups[$candidateGroup])) {
            continue;
        }
        $rows[] = [
            'candidate_group' => $candidateGroup,
            'candidate_type' => 'POSSIBLE_IDENTITY_MATCH',
            'source_a' => $left['source'],
            'source_user_id_a' => (string)$left['source_user_id'],
            'canonical_id_a' => $leftCanonical,
            'canonical_members_a' => implode('|', $canonicalMembers[$leftCanonical] ?? [$leftKey]),
            'name_a' => trim((string)($left['firstname'] ?? '') . ' ' .
                (string)($left['lastname'] ?? '')),
            'email_a' => (string)($left['email'] ?? ''),
            'google_identity_state_a' =>
                (($left['google_sub_verified'] ?? false) === true ? 'verified' : 'missing'),
            'source_b' => $right['source'],
            'source_user_id_b' => (string)$right['source_user_id'],
            'canonical_id_b' => $rightCanonical,
            'canonical_members_b' => implode('|', $canonicalMembers[$rightCanonical] ?? [$rightKey]),
            'name_b' => trim((string)($right['firstname'] ?? '') . ' ' .
                (string)($right['lastname'] ?? '')),
            'email_b' => (string)($right['email'] ?? ''),
            'google_identity_state_b' =>
                (($right['google_sub_verified'] ?? false) === true ? 'verified' : 'missing'),
            'name_similarity' => number_format($nameScore, 4, '.', ''),
            'email_similarity' => number_format($emailScore, 4, '.', ''),
            'institutional_identifier_match' => $documentMatch ? 'true' : 'false',
            'other_evidence' => 'google_sub=' . v8p_google_state($left, $right),
            'confidence_band' => $confidence,
            'resolution' => '',
            'canonical_target' => '',
            'canonical_email' => '',
            'canonical_username' => '',
            'oauth_policy' => '',
            'operator' => '',
            'decision_timestamp_utc' => '',
            'evidence_reference' => '',
            'justification' => '',
        ];
    }
    usort($rows, static fn(array $a, array $b): int =>
        ['HIGH'=>0, 'MEDIUM'=>1, 'LOW'=>2][$a['confidence_band']] <=>
            ['HIGH'=>0, 'MEDIUM'=>1, 'LOW'=>2][$b['confidence_band']] ?:
        strcmp($a['candidate_group'], $b['candidate_group']));
    return $rows;
}

function v8p_write_csv(string $path, array $headers, array $rows): void {
    $stream = fopen('php://temp', 'w+');
    if ($stream === false) {
        throw new V8PreparationException('V8_PREPARATION_CSV_FAILED');
    }
    fputcsv($stream, $headers, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($stream, array_map(static fn(string $header): string =>
            (string)($row[$header] ?? ''), $headers), ',', '"', '');
    }
    rewind($stream);
    $contents = stream_get_contents($stream);
    fclose($stream);
    v8p_atomic_text($path, (string)$contents);
}

function v8p_theme_name(string $component): string {
    $name = strtolower(trim($component));
    if (str_starts_with($name, 'theme_')) {
        $name = substr($name, 6);
    }
    return preg_match('/^[a-z][a-z0-9_]*$/', $name) === 1 ? $name : '';
}

function v8p_theme_canonicalize(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('v8p_theme_canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = v8p_theme_canonicalize($item);
    }
    return $value;
}

function v8p_theme_value_sha256(mixed $value): string {
    return hash('sha256', json_encode(v8p_theme_canonicalize($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
        JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
}

function v8p_theme_invalid(string $source, string $path, string $message): never {
    throw new V8PreparationException(
        "INVALID_THEME_INVENTORY: source=$source path=$path: $message");
}

function v8p_theme_data(array $manifest, array $inventory, array $plugins,
        array $courseDetails = []): array {
    $source = trim((string)($manifest['source_id'] ?? ''));
    if ($source === '') {
        throw new V8PreparationException('V8_THEME_SOURCE_INVALID');
    }
    $capabilities = $manifest['capabilities'] ?? [];
    if (!is_array($capabilities)) {
        v8p_theme_invalid($source, 'manifest.json:capabilities',
            'capabilities debe ser un objeto.');
    }
    $hasCapability = array_key_exists('theme_inventory', $capabilities);
    if ($hasCapability && ($capabilities['theme_inventory'] ?? null) !== '1.0') {
        v8p_theme_invalid($source, 'manifest.json:capabilities.theme_inventory',
            'versión de capability no soportada.');
    }

    $available = [];
    foreach ($plugins['plugins'] ?? [] as $plugin) {
        if (!is_array($plugin)) {
            continue;
        }
        $component = (string)($plugin['component'] ?? '');
        if (str_starts_with($component, 'theme_')) {
            $name = v8p_theme_name($component);
            if ($name === '') {
                v8p_theme_invalid($source, 'plugins.json',
                    "componente de theme inválido: $component");
            }
            $available[$name] = [
                'component' => $component,
                'version' => (string)($plugin['version_disk'] ?? ''),
                'release' => $plugin['release'] ?? null,
                'dependencies' => $plugin['dependencies'] ?? [],
                'configuration' => [],
            ];
        }
    }
    ksort($available, SORT_STRING);

    if (!$hasCapability) {
        $courseRows = [];
        foreach ($manifest['entries'] ?? [] as $entry) {
            $courseRows[] = [
                'source_course_id'=>(string)($entry['source_course_id'] ?? ''),
                'course_shortname'=>(string)($entry['source_shortname'] ?? ''),
                'original_theme'=>'', 'metadata_state'=>'not_available',
            ];
        }
        return [
            'global_theme'=>'', 'allow_course_themes'=>null,
            'available'=>$available, 'profiles'=>[], 'courses'=>$courseRows,
            'users'=>[], 'categories'=>[],
            'assignment_states'=>[
                'courses'=>'not_available', 'users'=>'not_available',
                'categories'=>'not_available', 'cohorts'=>'not_available',
            ],
            'inventory_complete'=>false,
            'inventory_state'=>'legacy_not_available', 'legacy'=>true,
            'collector_version'=>(string)($manifest['collector_version'] ?? ''),
            'inventory_evidence'=>[
                'capability'=>'absent',
                'action'=>'recollect_with_7.4.2_or_accept_legacy',
            ],
        ];
    }

    $themeData = $inventory['themes'] ?? null;
    $assignments = $inventory['theme_assignments'] ?? null;
    if (!is_array($themeData) || !is_array($assignments) ||
            ($themeData['schema_version'] ?? '') !== '1.0' ||
            ($assignments['schema_version'] ?? '') !== '1.0' ||
            ($themeData['inventory_complete'] ?? null) !== true ||
            ($assignments['inventory_complete'] ?? null) !== true) {
        v8p_theme_invalid($source, 'inventario-origen.json',
            'la capability theme_inventory exige metadata completa 1.0.');
    }
    $global = v8p_theme_name((string)($themeData['global_theme'] ?? ''));
    $siteGlobal = v8p_theme_name((string)($themeData['site']['global_theme'] ?? ''));
    if (($themeData['global_theme_state'] ?? '') !== 'complete' ||
            $global === '' || $siteGlobal !== $global) {
        v8p_theme_invalid($source, 'inventario-origen.json:themes.global_theme',
            'el theme global no está determinado o es inconsistente.');
    }
    $profiles = $themeData['profiles'] ?? null;
    if (!is_array($profiles) || ($profiles !== [] && array_is_list($profiles))) {
        v8p_theme_invalid($source, 'inventario-origen.json:themes.profiles',
            'profiles debe ser un objeto por nombre de theme.');
    }
    foreach ($profiles as $name => $profile) {
        $normalized = v8p_theme_name((string)$name);
        if ($normalized === '' || !is_array($profile) ||
                ($profile['component'] ?? '') !== 'theme_' . $normalized ||
                !in_array($profile['state'] ?? '', ['complete','empty'], true) ||
                !is_array($profile['settings'] ?? null) ||
                (int)($profile['settings_count'] ?? -1) !==
                    count($profile['settings'] ?? [])) {
            v8p_theme_invalid($source,
                'inventario-origen.json:themes.profiles.' . (string)$name,
                'perfil de theme inválido.');
        }
        foreach ($profile['settings'] as $setting => $value) {
            if (!is_array($value) || !in_array($value['state'] ?? '',
                    ['config_value','file_reference','redacted','not_available'], true)) {
                v8p_theme_invalid($source, "theme_$normalized:$setting",
                    'setting de theme con estado inválido.');
            }
            if (preg_match('/(?:pass(?:word|wd)?|secret|token|api[_-]?key|' .
                    'private[_-]?key|client[_-]?secret|credential|' .
                    'signing[_-]?key|access[_-]?key)/i', (string)$setting) === 1 &&
                    (($value['state'] ?? '') !== 'redacted' ||
                        array_key_exists('value', $value))) {
                v8p_theme_invalid($source, "theme_$normalized:$setting",
                    'setting sensible no redactado.');
            }
        }
        if (!isset($available[$normalized])) {
            v8p_theme_invalid($source, 'plugins.json',
                "el perfil theme_$normalized no corresponde a un plugin inventariado.");
        }
        $available[$normalized]['configuration'] = $profile;
    }
    foreach (array_keys($available) as $name) {
        if (!isset($profiles[$name])) {
            v8p_theme_invalid($source, 'inventario-origen.json:themes.profiles',
                "falta el perfil del plugin theme_$name.");
        }
    }
    foreach (['allow_course_themes','allow_user_themes','allow_category_themes'] as $name) {
        $policy = $themeData['policies'][$name] ?? null;
        $state = is_array($policy) ? (string)($policy['state'] ?? '') : '';
        if (!in_array($state, ['complete','not_supported','not_available'], true) ||
                ($state === 'complete' && !is_bool($policy['value'] ?? null))) {
            v8p_theme_invalid($source, "inventario-origen.json:themes.policies.$name",
                'política de theme inválida.');
        }
    }
    foreach (['courses','users','categories','cohorts'] as $scope) {
        if (!is_array($assignments[$scope] ?? null) ||
                !array_is_list($assignments[$scope]) ||
                !in_array($assignments['states'][$scope] ?? '',
                    ['complete','empty','not_supported','not_available','error'], true)) {
            v8p_theme_invalid($source,
                "inventario-origen.json:theme_assignments.$scope",
                'lista o estado de asignaciones inválido.');
        }
    }
    if (!in_array($assignments['states']['courses'] ?? '',
            ['complete','empty'], true)) {
        v8p_theme_invalid($source,
            'inventario-origen.json:theme_assignments.courses',
            'las asignaciones críticas por curso están incompletas.');
    }
    $fingerprint = v8p_theme_value_sha256([
        'site'=>$themeData['site'] ?? null,
        'policies'=>$themeData['policies'] ?? null,
        'profiles'=>$profiles,
        'assignments'=>$assignments,
    ]);
    if (!hash_equals((string)($themeData['fingerprint_sha256'] ?? ''), $fingerprint)) {
        v8p_theme_invalid($source,
            'inventario-origen.json:themes.fingerprint_sha256',
            'la huella semántica no coincide.');
    }

    $summaryCourses = [];
    foreach ($inventory['courses'] ?? [] as $row) {
        if (!is_array($row) || (int)($row['source_course_id'] ?? 0) < 1 ||
                !array_key_exists('theme', $row) || !is_string($row['theme'])) {
            v8p_theme_invalid($source, 'inventario-origen.json:courses',
                'curso sin source_course_id/theme válido.');
        }
        $id = (string)(int)$row['source_course_id'];
        if (isset($summaryCourses[$id])) {
            v8p_theme_invalid($source, 'inventario-origen.json:courses',
                "curso repetido: $id");
        }
        $theme = v8p_theme_name($row['theme']);
        if (trim($row['theme']) !== '' && $theme === '') {
            v8p_theme_invalid($source, "course:$id", 'nombre de theme inválido.');
        }
        $summaryCourses[$id] = ['theme'=>$theme,
            'shortname'=>(string)($row['shortname'] ?? '')];
    }
    $courseAssignments = [];
    foreach ($assignments['courses'] as $row) {
        $id = is_array($row) ? (int)($row['source_course_id'] ?? 0) : 0;
        $theme = is_array($row) ? v8p_theme_name((string)($row['theme'] ?? '')) : '';
        if ($id < 1 || $theme === '' || isset($courseAssignments[(string)$id])) {
            v8p_theme_invalid($source,
                'inventario-origen.json:theme_assignments.courses',
                'asignación explícita inválida o repetida.');
        }
        $courseAssignments[(string)$id] = $theme;
    }
    foreach ($courseAssignments as $id => $_theme) {
        if (!isset($summaryCourses[$id])) {
            v8p_theme_invalid($source,
                'inventario-origen.json:theme_assignments.courses',
                "la asignación referencia un curso ausente: $id");
        }
    }
    $courseRows = [];
    $seenManifestCourses = [];
    foreach ($manifest['entries'] ?? [] as $entry) {
        $id = (string)(int)($entry['source_course_id'] ?? 0);
        if ($id === '0' || isset($seenManifestCourses[$id]) ||
                !isset($summaryCourses[$id]) ||
                !isset($courseDetails[$id])) {
            v8p_theme_invalid($source, "course:$id",
                'curso repetido o faltan el resumen/inventario individual.');
        }
        $seenManifestCourses[$id] = true;
        $summaryTheme = $summaryCourses[$id]['theme'];
        $assignedTheme = $courseAssignments[$id] ?? '';
        $detail = $courseDetails[$id] ?? [];
        $detailValues = [
            'theme'=>$detail['theme'] ?? null,
            'inventory.course.theme'=>$detail['inventory']['course']['theme'] ?? null,
            'theme_metadata.name'=>$detail['theme_metadata']['name'] ?? null,
        ];
        foreach ($detailValues as $field => $value) {
            if (!is_string($value)) {
                v8p_theme_invalid($source, "course:$id:$field",
                    'metadata individual ausente.');
            }
            $normalized = v8p_theme_name($value);
            if (($value !== '' && $normalized === '') || $normalized !== $summaryTheme) {
                throw new V8PreparationException(
                    "SOURCE_THEME_INVENTORY_MISMATCH: source=$source " .
                    "course=$id field=$field expected=$summaryTheme actual=$normalized");
            }
        }
        if ($assignedTheme !== $summaryTheme) {
            throw new V8PreparationException(
                "SOURCE_THEME_INVENTORY_MISMATCH: source=$source course=$id " .
                "field=theme_assignments expected=$summaryTheme actual=$assignedTheme");
        }
        $expectedState = $summaryTheme === '' ? 'empty' : 'complete';
        $expectedMetadata = [
            'schema_version'=>'1.0', 'state'=>$expectedState,
            'name'=>$summaryTheme, 'source'=>'course.theme',
        ];
        if (($detail['theme_metadata'] ?? null) !== $expectedMetadata ||
                !hash_equals(v8p_theme_value_sha256($expectedMetadata),
                    (string)($detail['theme_metadata_sha256'] ?? ''))) {
            throw new V8PreparationException(
                "SOURCE_THEME_INVENTORY_MISMATCH: source=$source course=$id " .
                'field=theme_metadata');
        }
        $courseRows[] = [
            'source_course_id' => $id,
            'course_shortname' => (string)($entry['source_shortname'] ??
                $summaryCourses[$id]['shortname']),
            'original_theme' => $summaryTheme,
            'metadata_state' => $expectedState,
        ];
    }
    if (count($seenManifestCourses) !== count($summaryCourses)) {
        v8p_theme_invalid($source, 'inventario-origen.json:courses',
            'el resumen contiene cursos que no pertenecen al manifiesto.');
    }
    return [
        'global_theme' => $global,
        'allow_course_themes' => ($themeData['policies']['allow_course_themes']['state'] ?? '')
            === 'complete'
                ? (bool)$themeData['policies']['allow_course_themes']['value'] : null,
        'available' => $available,
        'profiles' => $profiles,
        'courses' => $courseRows,
        'users' => $assignments['users'],
        'categories' => $assignments['categories'],
        'assignment_states' => $assignments['states'],
        'inventory_complete' => true,
        'inventory_state' => 'complete', 'legacy'=>false,
        'collector_version'=>(string)($manifest['collector_version'] ?? ''),
        'inventory_evidence' => [
            'capability'=>'theme_inventory=1.0',
            'fingerprint_sha256'=>$fingerprint,
            'global_theme_known'=>true, 'profiles_inventoried'=>true,
            'course_assignments_inventoried'=>true,
            'user_assignments_state'=>$assignments['states']['users'],
            'category_assignments_state'=>$assignments['states']['categories'],
        ],
    ];
}

function v8p_theme_plan(array $sources, array $policy, array $lock,
        array $targetInventory = [], array $managed = []): array {
    if (($policy['schema_version'] ?? '') !== '1.0' ||
            ($policy['assignment_policy'] ?? '') !== 'preserve_course_assignments') {
        throw new V8PreparationException('V8_THEME_POLICY_INVALID');
    }
    $compatible = [];
    foreach ($targetInventory['plugins'] ?? [] as $plugin) {
        $component = (string)($plugin['component'] ?? '');
        if (str_starts_with($component, 'theme_') &&
                ($plugin['installation_status'] ?? '') !== 'missing') {
            $compatible[v8p_theme_name($component)] = true;
        }
    }
    foreach ($lock['plugins'] ?? [] as $plugin) {
        if (str_starts_with((string)($plugin['component'] ?? ''), 'theme_') &&
                ($plugin['staging_validation'] ?? '') === 'passed') {
            $compatible[v8p_theme_name((string)$plugin['component'])] = true;
        }
    }
    ksort($compatible, SORT_STRING);
    $global = trim((string)($policy['global_theme_selection'] ?? ''));
    $selectionSource = trim((string)($policy['selection_source'] ?? ''));
    $explicitSelection = $global !== '' &&
        in_array($selectionSource, ['operator', 'config'], true);
    if ($global !== '' && !isset($compatible[$global])) {
        throw new V8PreparationException("V8_THEME_GLOBAL_NOT_AVAILABLE: $global");
    }
    $fallback = trim((string)($policy['safe_fallback_theme'] ?? ''));
    if ($fallback === '' || !isset($compatible[$fallback])) {
        throw new V8PreparationException("V8_THEME_FALLBACK_NOT_AVAILABLE: $fallback");
    }
    $courseRows = [];
    $warnings = [];
    $profiles = [];
    $rawUsers = [];
    $rawCategories = [];
    $siteThemes = [];
    $inventoryStates = [];
    $inventoryComplete = true;
    $inventoryReady = true;
    $legacyAccepted = ($policy['theme_inventory_legacy_accepted'] ?? false) === true;
    $profileVariants = [];
    $expectedAllowCourseThemes = ($policy['apply_course_themes'] ?? true) === true ? 1 : 0;
    $targetPolicyVerified = $explicitSelection &&
        (string)($managed['settings']['theme'] ?? '') === $global &&
        (int)($managed['settings']['allowcoursethemes'] ?? -1) ===
            $expectedAllowCourseThemes;
    foreach ($sources as $source => $data) {
        $inventoryStates[$source] = [
            'state'=>$data['inventory_state'] ?? 'inventory_missing',
            'evidence'=>$data['inventory_evidence'] ?? [],
        ];
        if (($data['inventory_complete'] ?? false) !== true) {
            $inventoryComplete = false;
            if (($data['inventory_state'] ?? '') !== 'legacy_not_available') {
                $inventoryReady = false;
            }
        }
        if (($data['inventory_state'] ?? '') === 'legacy_not_available') {
            if (!$legacyAccepted) {
                $inventoryReady = false;
            } else {
                $warnings[] = [
                    'code'=>'WARNING_LEGACY_THEME_METADATA_UNAVAILABLE',
                    'source'=>$source,
                    'collector_version'=>$data['collector_version'] ?? '',
                    'action'=>'recollect_with_recolector_7.4.2_for_full_theme_fidelity',
                ];
            }
        }
        $siteThemes[$source] = [
            'source' => $source,
            'original_global_theme' => $data['global_theme'],
            'selected_target_global_theme' => $global,
        ];
        $profiles[$source] = $data['available'];
        foreach ($data['profiles'] ?? [] as $themeName => $profile) {
            $profileVariants[$themeName][v8p_theme_value_sha256($profile)][] = $source;
        }
        $rawUsers[$source] = is_array($data['users']) ? $data['users'] : [];
        $rawCategories[$source] = is_array($data['categories']) ?
            $data['categories'] : [];
        foreach ($data['courses'] as $course) {
            $original = (string)$course['original_theme'];
            if (($course['metadata_state'] ?? '') === 'not_available') {
                $state = 'THEME_METADATA_NOT_AVAILABLE';
                $resolved = '';
                $warning = 'WARNING_LEGACY_THEME_METADATA_UNAVAILABLE';
            } elseif ($original === '') {
                $state = 'NO_EXPLICIT_THEME';
                $resolved = '';
                $warning = '';
            } elseif (isset($compatible[$original])) {
                $state = 'PENDING_POST_RESTORE_VERIFICATION';
                $resolved = $original;
                $warning = '';
            } else {
                $state = 'FALLBACK_TO_GLOBAL';
                $resolved = $global !== '' ? $global : $fallback;
                $warning = 'WARNING_COURSE_THEME_NOT_APPLIED';
                $warnings[] = [
                    'code' => $warning, 'source' => $source,
                    'source_course_id' => $course['source_course_id'],
                    'original_theme' => $original, 'fallback' => $global,
                ];
            }
            $courseRows[$source][] = [
                'source' => $source,
                'source_course_id' => $course['source_course_id'],
                'course_shortname' => $course['course_shortname'],
                'original_theme' => $original,
                'transport_state' => ($course['metadata_state'] ?? '') === 'not_available'
                    ? 'NOT_AVAILABLE'
                    : ($original !== '' && !isset($compatible[$original])
                        ? 'NOT_TRANSPORTABLE' : 'TRANSPORTABLE'),
                'resolved_target_theme' => $resolved,
                'resolution_state' => $state,
                'verification_state' => 'pending',
                'warning_code' => $warning,
            ];
        }
    }
    foreach ($profileVariants as $themeName => $variants) {
        if (count($variants) > 1) {
            $variantSources = [];
            foreach ($variants as $variant => $variantSourceList) {
                $variantSources[] = [
                    'profile_sha256'=>$variant,
                    'sources'=>array_values($variantSourceList),
                ];
            }
            $warnings[] = [
                'code'=>'WARNING_THEME_PROFILE_COLLISION',
                'theme'=>$themeName, 'variants'=>$variantSources,
                'impact'=>'destination_uses_one_effective_global_configuration',
            ];
        }
    }
    ksort($siteThemes, SORT_STRING);
    ksort($courseRows, SORT_STRING);
    return [
        'schema_version' => '1.0',
        'assignment_policy' => 'preserve_course_assignments',
        'global_theme' => $global,
        'global_theme_selection' => $global,
        'selection_source' => $selectionSource,
        'global_theme_selection_explicit' => $explicitSelection,
        'safe_fallback_theme' => $fallback,
        'compatible_installed_themes' => array_keys($compatible),
        'target_theme_inventory' => array_values(array_filter(
            $targetInventory['plugins'] ?? [],
            static fn(array $plugin): bool =>
                str_starts_with((string)($plugin['component'] ?? ''), 'theme_')
        )),
        'source_theme_inventory' => $inventoryStates,
        'site_themes' => $siteThemes,
        'profiles' => $profiles,
        'course_rows' => $courseRows,
        'user_assignments_raw' => $rawUsers,
        'category_assignments_raw' => $rawCategories,
        'warnings' => $warnings,
        'theme_inventory_complete' => $inventoryComplete,
        'theme_inventory_legacy_accepted' => !$inventoryComplete && $legacyAccepted,
        'theme_inventory_ready' => $inventoryReady,
        'theme_profiles_complete' => $inventoryComplete,
        'theme_profiles_sealed' => $inventoryReady,
        'target_theme_policy_verified' => $targetPolicyVerified,
        'expected_allowcoursethemes' => $expectedAllowCourseThemes,
        'theme_assignment_policy_sealed' => $inventoryReady && $explicitSelection &&
            $targetPolicyVerified,
        'course_theme_transport_plan_ready' => $inventoryReady && $explicitSelection &&
            $targetPolicyVerified,
    ];
}

function v8p_write_theme_artifacts(string $exports, array $plan): array {
    $headers = ['source', 'source_course_id', 'course_shortname', 'original_theme',
        'transport_state',
        'resolved_target_theme', 'resolution_state', 'verification_state', 'warning_code'];
    $profileHashes = [];
    $assignmentHashes = [];
    foreach ($plan['site_themes'] as $source => $site) {
        $profileDir = "$exports/theme-profiles/$source";
        foreach ($plan['profiles'][$source] ?? [] as $name => $profile) {
            $path = "$profileDir/$name.json";
            v8p_atomic_json($path, ['schema_version'=>'1.0', 'source'=>$source,
                'theme'=>$name, 'profile'=>$profile]);
            $profileHashes["$source/$name"] = v8p_sha256($path);
        }
        $assignmentDir = "$exports/theme-assignments/$source";
        v8p_atomic_json("$assignmentDir/site-theme.json", $site);
        v8p_write_csv("$assignmentDir/course-themes.csv", $headers,
            $plan['course_rows'][$source] ?? []);
        foreach (['user', 'category'] as $kind) {
            $rows = $kind === 'user' ? $plan['user_assignments_raw'][$source] ?? [] :
                $plan['category_assignments_raw'][$source] ?? [];
            v8p_atomic_json("$assignmentDir/{$kind}-themes.json", [
                'schema_version'=>'1.0', 'source'=>$source,
                'application_policy'=>'inventory_only', 'assignments'=>$rows]);
        }
        foreach (['site-theme.json', 'course-themes.csv', 'user-themes.json',
                'category-themes.json'] as $file) {
            $assignmentHashes["$source/$file"] = v8p_sha256("$assignmentDir/$file");
        }
    }
    ksort($profileHashes, SORT_STRING);
    ksort($assignmentHashes, SORT_STRING);
    $summary = $plan;
    unset($summary['profiles'], $summary['course_rows'],
        $summary['user_assignments_raw'], $summary['category_assignments_raw']);
    $summary['theme_profile_hashes'] = $profileHashes;
    $summary['theme_assignment_hashes'] = $assignmentHashes;
    v8p_atomic_json("$exports/theme-plan.json", $summary);
    return $summary;
}

function v8p_verify_course_themes(array $plannedRows, array $actual): array {
    $counts = [
        'courses_without_explicit_theme'=>0, 'expected_explicit_course_themes'=>0,
        'preserved_by_restore'=>0, 'reapplied'=>0, 'fallback_to_global'=>0,
        'metadata_not_available'=>0, 'not_transportable'=>0,
        'warnings'=>0, 'failed_functional'=>0,
    ];
    $results = [];
    foreach ($plannedRows as $row) {
        $key = (string)$row['source'] . ':' . (string)$row['source_course_id'];
        $original = (string)$row['original_theme'];
        $actualTheme = is_array($actual[$key] ?? null)
            ? (string)(($actual[$key]['before'] ?? ''))
            : (string)($actual[$key] ?? '');
        if (($row['resolution_state'] ?? '') === 'THEME_METADATA_NOT_AVAILABLE') {
            $counts['metadata_not_available']++;
            $counts['warnings']++;
            $state = 'THEME_METADATA_NOT_AVAILABLE';
        } elseif ($original === '') {
            $counts['courses_without_explicit_theme']++;
            $state = 'NO_EXPLICIT_THEME';
        } elseif (($row['resolution_state'] ?? '') === 'FALLBACK_TO_GLOBAL') {
            $counts['expected_explicit_course_themes']++;
            $counts['fallback_to_global']++;
            $counts['not_transportable']++;
            $counts['warnings']++;
            $state = 'FALLBACK_TO_GLOBAL';
        } else {
            $counts['expected_explicit_course_themes']++;
            $observation = $actual[$key] ?? '';
            $actualTheme = is_array($observation)
                ? (string)($observation['before'] ?? '') : (string)$observation;
            $afterReapply = is_array($observation)
                ? (string)($observation['after'] ?? '') : '';
            if ($actualTheme === (string)$row['resolved_target_theme']) {
                $counts['preserved_by_restore']++;
                $state = 'PRESERVED_BY_RESTORE';
            } elseif ($afterReapply === (string)$row['resolved_target_theme']) {
                $state = 'REAPPLIED';
                $counts['reapplied']++;
            } else {
                $state = 'REAPPLY_REQUIRED';
                $counts['failed_functional']++;
            }
        }
        $results[] = $row + ['verification_state'=>$state,
            'actual_theme'=>$actualTheme];
    }
    $status = $counts['failed_functional'] > 0 ? 'failed' :
        ($counts['warnings'] > 0 ? 'passed_with_warnings' : 'passed');
    return ['schema_version'=>'1.0', 'status'=>$status,
        'counts'=>$counts, 'results'=>$results];
}

function v8p_load_theme_sources(string $packages): array {
    $sources = [];
    foreach (glob(rtrim($packages, '/') . '/*/manifest.json') ?: [] as $manifestPath) {
        $base = dirname($manifestPath);
        $manifest = v8p_read_json($manifestPath);
        $source = (string)($manifest['source_id'] ?? '');
        if ($source === '' || isset($sources[$source])) {
            throw new V8PreparationException('V8_THEME_SOURCE_INVALID');
        }
        $details = [];
        foreach ($manifest['entries'] ?? [] as $entry) {
            $courseId = (string)(int)($entry['source_course_id'] ?? 0);
            $inventoryFile = (string)($entry['inventory_file'] ?? '');
            if ($courseId !== '0' && $inventoryFile !== '') {
                $details[$courseId] = v8p_read_json($base . '/' . $inventoryFile);
            }
        }
        $sources[$source] = v8p_theme_data($manifest,
            v8p_read_json($base . '/inventario-origen.json'),
            v8p_read_json($base . '/plugins.json'), $details);
    }
    if ($sources === []) {
        throw new V8PreparationException(
            'V8_THEME_SOURCE_EMPTY: no existen paquetes importados para normalizar.');
    }
    ksort($sources, SORT_STRING);
    return $sources;
}

function v8p_theme_status(array $sources): array {
    $complete = [];
    $legacy = [];
    foreach ($sources as $source => $data) {
        if (($data['inventory_state'] ?? '') === 'legacy_not_available') {
            $legacy[] = [
                'source'=>$source,
                'collector_version'=>$data['collector_version'] ?? '',
                'state'=>'legacy_not_available',
                'action'=>'recollect_with_7.4.2_or_accept_legacy',
            ];
        } else {
            $complete[] = [
                'source'=>$source,
                'collector_version'=>$data['collector_version'] ?? '',
                'state'=>$data['inventory_state'] ?? 'invalid',
            ];
        }
    }
    return [
        'schema_version'=>'1.0', 'status'=>'valid',
        'complete_sources'=>$complete, 'legacy_sources'=>$legacy,
        'legacy_decision_required'=>$legacy !== [],
    ];
}

function v8p_readiness(array $inputs, array $hashes): array {
    $lock = $inputs['lock'];
    $phase3 = $inputs['phase3'];
    $phase4 = $inputs['phase4'];
    $oauth = $inputs['oauth'];
    $theme = $inputs['theme'];
    $identity = $inputs['identity'];
    $flags = [
        'plugin_catalog_checked' => ($lock['plugin_catalog_checked'] ?? false) === true,
        'plugin_lock_valid' => ($lock['plugin_lock_valid'] ?? false) === true &&
            ($lock['lock_status'] ?? '') === 'SEALED',
        'deterministic_identity_conflicts_resolved' =>
            (int)($phase3['identity_conflicts_unresolved'] ?? -1) === 0 &&
            (int)($phase3['phase4_expected']['identity_review_pending'] ?? -1) === 0,
        'phase4_plan_applicable' => (int)($phase4['blocking_conflicts'] ?? -1) === 0 &&
            (int)($phase4['identity_review_pending'] ?? -1) === 0,
        'oauth_proxy_live_valid' => ($oauth['status'] ?? '') === 'ready' &&
            ($oauth['validation'] ?? '') === 'passed',
        'theme_inventory_ready' => ($theme['theme_inventory_ready'] ?? false) === true,
        'theme_profiles_sealed' => ($theme['theme_profiles_sealed'] ?? false) === true,
        'theme_assignment_policy_sealed' =>
            ($theme['theme_assignment_policy_sealed'] ?? false) === true,
        'target_theme_policy_verified' =>
            ($theme['target_theme_policy_verified'] ?? false) === true,
        'course_theme_transport_plan_ready' =>
            ($theme['course_theme_transport_plan_ready'] ?? false) === true,
        'identity_candidates_generated' =>
            in_array($identity['generation_status'] ?? '', ['generated', 'skipped'], true),
        'identity_candidates_reviewed' =>
            in_array($identity['review_status'] ?? '',
                ['reviewed', 'skipped', 'not_required'], true),
    ];
    $ready = !in_array(false, $flags, true);
    return [
        'schema_version'=>'1.0', 'release'=>'8.0.0-linux-rc12',
        'status'=>$ready ? 'READY_TO_RUN' : 'NOT_READY',
        'ready_to_run'=>$ready, 'checks'=>$flags, 'sealed_inputs'=>$hashes,
        'warnings_nonblocking'=>count($theme['warnings'] ?? []),
        'destination_write_performed'=>false,
    ];
}

function v8p_options(array $argv): array {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new V8PreparationException("V8_ARGUMENT_INVALID: $argument");
        }
        [$key, $value] = explode('=', substr($argument, 2), 2);
        $options[$key] = $value;
    }
    return $options;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = v8p_options($argv);
        $mode = $options['mode'] ?? '';
        if ($mode === 'identities') {
            $input = rtrim($options['input'] ?? '', '/');
            $documents = [];
            foreach (glob($input . '/identity-*.json') ?: [] as $path) {
                $documents[] = v8p_read_json($path);
            }
            $auditRows = [];
            if (($options['fuzzy-audit'] ?? '') !== '' &&
                    is_file((string)$options['fuzzy-audit'])) {
                $auditRows = v8p_read_csv((string)$options['fuzzy-audit'])['rows'];
            }
            $reviewedGroups = [];
            foreach ($auditRows as $auditRow) {
                $reviewedGroups[(string)($auditRow['candidate_group'] ?? '')] = true;
            }
            unset($reviewedGroups['']);
            $canonicalMap = v8p_canonical_map(v8p_read_csv(
                $options['source-map'] ?? '')['rows']);
            $rows = v8p_identity_candidates($documents, $canonicalMap, $reviewedGroups);
            $headers = ['candidate_group','candidate_type','source_a','source_user_id_a',
                'canonical_id_a','canonical_members_a','name_a','email_a',
                'google_identity_state_a','source_b','source_user_id_b','canonical_id_b',
                'canonical_members_b',
                'name_b','email_b',
                'google_identity_state_b','name_similarity','email_similarity',
                'institutional_identifier_match','other_evidence','confidence_band',
                'resolution','canonical_target','canonical_email','canonical_username',
                'oauth_policy','operator','decision_timestamp_utc','evidence_reference',
                'justification'];
            $output = $options['output'] ?? '';
            v8p_write_csv($output, $headers, $rows);
            $requestedReview = strtolower(trim((string)($options['review'] ?? 'pending')));
            if (!in_array($requestedReview, ['pending','skipped','reviewed'], true)) {
                throw new V8PreparationException('V8_IDENTITY_REVIEW_STATUS_INVALID');
            }
            $reviewStatus = !$rows
                ? ($auditRows ? 'reviewed' : 'not_required')
                : $requestedReview;
            $summary = ['schema_version'=>'1.1',
                'identity_match_algorithm_version'=>'v8-blocked-2',
                'generation_status'=>'generated', 'review_status'=>$reviewStatus,
                'candidate_count'=>count($rows),
                'identity_candidates_sha256'=>v8p_sha256($output),
                'source_user_map_sha256'=>v8p_sha256($options['source-map'] ?? ''),
                'fuzzy_resolution_audit_sha256'=>($options['fuzzy-audit'] ?? '') !== '' &&
                    is_file((string)$options['fuzzy-audit'])
                    ? v8p_sha256((string)$options['fuzzy-audit']) : '',
                'fuzzy_resolution_audit_rows'=>count($auditRows),
                'authoritative'=>false, 'automatic_merge_performed'=>false,
                'manual_merge_performed'=>count(array_filter($auditRows,
                    static fn(array $row): bool =>
                        strtoupper((string)($row['decision'] ?? '')) === 'MERGE')) > 0];
            v8p_atomic_json($options['summary'] ?? '', $summary);
            echo 'V8_IDENTITY_CANDIDATES_OK count=' . count($rows) . PHP_EOL;
            exit(0);
        }
        if ($mode === 'theme-status') {
            $status = v8p_theme_status(v8p_load_theme_sources(
                rtrim($options['packages'] ?? '', '/')));
            v8p_atomic_json($options['output'] ?? '', $status);
            echo 'V8_THEME_INVENTORY_STATUS_OK complete=' .
                count($status['complete_sources']) . ' legacy=' .
                count($status['legacy_sources']) . PHP_EOL;
            exit(0);
        }
        if ($mode === 'themes') {
            $sources = v8p_load_theme_sources(
                rtrim($options['packages'] ?? '', '/'));
            $plan = v8p_theme_plan($sources,
                v8p_read_json($options['policy'] ?? ''),
                v8p_read_json($options['lock'] ?? ''),
                v8p_read_json($options['target-inventory'] ?? ''),
                v8p_read_json($options['managed'] ?? ''));
            $summary = v8p_write_theme_artifacts(rtrim($options['exports'] ?? '', '/'), $plan);
            echo 'V8_THEME_PLAN_OK sources=' . count($sources) .
                ' warnings=' . count($summary['warnings']) . PHP_EOL;
            exit(0);
        }
        if ($mode === 'readiness') {
            $paths = [
                'lock'=>$options['lock'] ?? '', 'phase3'=>$options['phase3'] ?? '',
                'phase4'=>$options['phase4'] ?? '', 'oauth'=>$options['oauth'] ?? '',
                'theme'=>$options['theme'] ?? '', 'identity'=>$options['identity'] ?? '',
                'catalog'=>$options['catalog'] ?? '', 'policy'=>$options['policy'] ?? '',
                'config'=>$options['config'] ?? '', 'managed'=>$options['managed'] ?? '',
                'target_inventory'=>$options['target-inventory'] ?? '',
            ];
            $inputs = [];
            $hashes = [];
            foreach ($paths as $name => $path) {
                $hashes[$name . '_sha256'] = v8p_sha256($path);
                if (!in_array($name,
                        ['catalog','policy','config','managed','target_inventory'], true)) {
                    $inputs[$name] = v8p_read_json($path);
                }
            }
            $readiness = v8p_readiness($inputs, $hashes);
            v8p_atomic_json($options['output'] ?? '', $readiness);
            if (!$readiness['ready_to_run']) {
                throw new V8PreparationException('V8_NOT_READY');
            }
            echo 'READY_TO_RUN' . PHP_EOL;
            exit(0);
        }
        throw new V8PreparationException("V8_MODE_INVALID: $mode");
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
