<?php
// 8.1.0: mapa de categorías destino declarado antes de escribir el destino.
//
// Propuesta (solo lectura) a partir de los inventarios de origen y de las
// categorías actuales del destino, y validación del mapeo que decide el
// operador (config/category-map.json). El mapeo nunca trae marcadores: se
// derivan aquí de la ruta normalizada de cada nodo, así que dos orígenes
// unidos en un mismo nodo comparten marcador y fase 6 reutiliza la categoría.
declare(strict_types=1);

final class V8CategoryMapException extends RuntimeException {}

const V8CM_SCHEMA = '1.0';
const V8CM_TOP = '@top';
const V8CM_MAX_COURSES_LISTED = 50;
const V8CM_SIMILARITY = 0.85;

function v8cm_read_json(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        throw new V8CategoryMapException("V8_CATEGORY_MAP_FILE_MISSING: $path");
    }
    try {
        $raw = (string)file_get_contents($path);
        $raw = (string)preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new V8CategoryMapException(
            "V8_CATEGORY_MAP_JSON_INVALID: $path: " . $error->getMessage());
    }
    if (!is_array($value)) {
        throw new V8CategoryMapException("V8_CATEGORY_MAP_JSON_INVALID: $path");
    }
    return $value;
}

function v8cm_encode(array $value): string {
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
}

function v8cm_write_json(string $path, array $value): void {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) &&
            !is_dir($directory)) {
        throw new V8CategoryMapException("V8_CATEGORY_MAP_WRITE_FAILED: $directory");
    }
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
    if (file_put_contents($temporary, v8cm_encode($value), LOCK_EX) === false ||
            !rename($temporary, $path)) {
        @unlink($temporary);
        throw new V8CategoryMapException("V8_CATEGORY_MAP_WRITE_FAILED: $path");
    }
}

/** Como p5_norm: lo que Moodle compara al buscar una categoría homónima. */
function v8cm_lower(string $value): string {
    return mb_strtolower(trim($value), 'UTF-8');
}

/**
 * Nombre comparable: minúsculas, sin tildes ni puntuación y con los espacios
 * colapsados. «Ciencias Básicas» y «Ciencias basicas» coinciden.
 */
function v8cm_normalize(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    if (class_exists('Normalizer')) {
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);
        if (is_string($decomposed)) {
            $value = (string)preg_replace('/\p{Mn}+/u', '', $decomposed);
        }
    } else {
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
    }
    $value = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    return trim((string)preg_replace('/\s+/u', ' ', $value));
}

/** Similitud 0..1 por distancia de edición sobre los nombres normalizados. */
function v8cm_similarity(string $left, string $right): float {
    $left = v8cm_normalize($left);
    $right = v8cm_normalize($right);
    if ($left === '' || $right === '') {
        return 0.0;
    }
    if ($left === $right) {
        return 1.0;
    }
    $a = preg_split('//u', $left, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $b = preg_split('//u', $right, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $previous = range(0, count($b));
    foreach ($a as $i => $charA) {
        $current = [$i + 1];
        foreach ($b as $j => $charB) {
            $current[] = min($previous[$j + 1] + 1, $current[$j] + 1,
                $previous[$j] + ($charA === $charB ? 0 : 1));
        }
        $previous = $current;
    }
    $longest = max(count($a), count($b));
    return 1.0 - ($previous[count($b)] / $longest);
}

function v8cm_member(string $source, int $categoryid): string {
    return $source . '|' . $categoryid;
}

function v8cm_default_node_key(string $source, int $categoryid): string {
    return 'N-' . strtoupper(substr(hash('sha256', 'node|' . v8cm_member($source, $categoryid)), 0, 12));
}

/**
 * Marcador destino de un nodo: deriva de su ancla (la raíz de Moodle o una
 * categoría existente) y de la ruta de nombres normalizados. Es estable entre
 * ejecuciones y no depende de claves elegidas en la web.
 */
function v8cm_marker(string $anchor, array $normalizedpath): string {
    return 'MAP-' . strtoupper(substr(hash('sha256',
        'mwt-category-map|' . $anchor . '|' . implode('/', $normalizedpath)), 0, 20));
}

/**
 * @param array<string, array> $inventories source_id => source-inventory JSON
 * @param array<string, string> $aliases source_id => alias de tres caracteres
 * @param list<array> $targetcategories categorías actuales del destino
 */
function v8cm_build_proposal(array $inventories, array $aliases, array $targetcategories,
        ?array $pilot, string $configsha): array {
    ksort($inventories, SORT_STRING);
    $sources = [];
    $nodes = [];
    $assignments = [];
    $flat = [];
    foreach ($inventories as $sourceid => $inventory) {
        $sourceid = (string)$sourceid;
        if (($inventory['source_id'] ?? '') !== $sourceid ||
                ($inventory['write_performed'] ?? null) !== false) {
            throw new V8CategoryMapException(
                "V8_CATEGORY_MAP_INVENTORY_INVALID: $sourceid");
        }
        $coursesbycategory = [];
        foreach ($inventory['courses'] ?? [] as $course) {
            $categoryid = (int)($course['source_category_id'] ?? 0);
            $coursesbycategory[$categoryid][] = [
                'id' => (int)($course['source_course_id'] ?? 0),
                'shortname' => mb_substr((string)($course['shortname'] ?? ''), 0, 255),
                'fullname' => mb_substr((string)($course['fullname'] ?? ''), 0, 255),
            ];
        }
        $categories = [];
        $seen = [];
        $rows = $inventory['categories'] ?? [];
        usort($rows, static fn(array $a, array $b): int =>
            [(int)($a['depth'] ?? 0), (int)($a['source_category_id'] ?? 0)] <=>
            [(int)($b['depth'] ?? 0), (int)($b['source_category_id'] ?? 0)]);
        foreach ($rows as $category) {
            $id = (int)($category['source_category_id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                throw new V8CategoryMapException(
                    "V8_CATEGORY_MAP_INVENTORY_INVALID: $sourceid categoría $id");
            }
            $seen[$id] = true;
            $courses = $coursesbycategory[$id] ?? [];
            usort($courses, static fn(array $a, array $b): int =>
                [$a['shortname'], $a['id']] <=> [$b['shortname'], $b['id']]);
            $categories[] = [
                'id' => $id,
                'parent_id' => (int)($category['source_parent_id'] ?? 0),
                'name' => (string)($category['name'] ?? ''),
                'idnumber' => (string)($category['idnumber'] ?? ''),
                'depth' => (int)($category['depth'] ?? 0),
                'course_count' => count($courses),
                'courses' => array_slice($courses, 0, V8CM_MAX_COURSES_LISTED),
            ];
        }
        foreach ($categories as $category) {
            if ($category['parent_id'] !== 0 && !isset($seen[$category['parent_id']])) {
                throw new V8CategoryMapException(
                    "V8_CATEGORY_MAP_INVENTORY_INVALID: $sourceid padre {$category['parent_id']}");
            }
            // Árbol unificado por defecto: las categorías superiores de cada
            // origen quedan juntas en la raíz de Moodle, sin raíz por origen.
            $key = v8cm_default_node_key($sourceid, $category['id']);
            $nodes[] = [
                'key' => $key,
                'name' => $category['name'],
                'parent' => $category['parent_id'] === 0
                    ? V8CM_TOP
                    : v8cm_default_node_key($sourceid, $category['parent_id']),
            ];
            $assignments[] = ['source' => $sourceid,
                'source_category_id' => $category['id'], 'node' => $key];
            $flat[] = ['source' => $sourceid, 'id' => $category['id'],
                'parent_id' => $category['parent_id'], 'name' => $category['name']];
        }
        $sources[] = [
            'source' => $sourceid,
            'alias' => (string)($aliases[$sourceid] ?? ''),
            'name' => (string)($inventory['source_name'] ?? $sourceid),
            'categories' => $categories,
        ];
    }

    $existing = [];
    foreach ($targetcategories as $category) {
        $existing[] = [
            'id' => (int)($category['id'] ?? 0),
            'parent_id' => (int)($category['parent_id'] ?? 0),
            'name' => (string)($category['name'] ?? ''),
            'idnumber' => (string)($category['idnumber'] ?? ''),
            'depth' => (int)($category['depth'] ?? 0),
            'course_count' => (int)($category['course_count'] ?? 0),
        ];
    }
    usort($existing, static fn(array $a, array $b): int =>
        [$a['depth'], $a['id']] <=> [$b['depth'], $b['id']]);

    $pilotplacement = null;
    if ($pilot !== null) {
        $pilotsource = (string)($pilot['source_id'] ?? '');
        $pilotcourse = (int)($pilot['source_course_id'] ?? 0);
        foreach ($inventories[$pilotsource]['courses'] ?? [] as $course) {
            if ((int)($course['source_course_id'] ?? 0) === $pilotcourse) {
                $pilotplacement = ['source' => $pilotsource, 'source_course_id' => $pilotcourse,
                    'shortname' => (string)($course['shortname'] ?? ''),
                    'source_category_id' => (int)($course['source_category_id'] ?? 0)];
            }
        }
        if ($pilotplacement === null) {
            throw new V8CategoryMapException('V8_CATEGORY_MAP_PILOT_NOT_IN_INVENTORY');
        }
    }

    return [
        'schema_version' => V8CM_SCHEMA,
        'phase' => 'v8-category-map-proposal',
        'config_sha256' => $configsha,
        'sources' => $sources,
        'existing' => $existing,
        'pilot' => $pilotplacement,
        'default_mapping' => ['nodes' => $nodes, 'assignments' => $assignments],
        'suggestions' => v8cm_suggestions($flat),
        'destination_write_performed' => false,
    ];
}

/**
 * Pares de categorías de orígenes distintos que conviene unir. Solo se
 * sugieren; el operador decide. Se comparan categorías de la misma
 * profundidad relativa a la raíz del origen.
 */
function v8cm_suggestions(array $flat): array {
    $depth = [];
    $byid = [];
    foreach ($flat as $category) {
        $byid[v8cm_member($category['source'], $category['id'])] = $category;
    }
    $depthof = static function (array $category) use (&$depthof, &$depth, $byid): int {
        $key = v8cm_member($category['source'], $category['id']);
        if (!isset($depth[$key])) {
            $parent = $byid[v8cm_member($category['source'], $category['parent_id'])] ?? null;
            $depth[$key] = $parent === null ? 1 : $depthof($parent) + 1;
        }
        return $depth[$key];
    };
    $suggestions = [];
    $count = count($flat);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $a = $flat[$i];
            $b = $flat[$j];
            if ($a['source'] === $b['source'] || $depthof($a) !== $depthof($b)) {
                continue;
            }
            $same = v8cm_normalize($a['name']) === v8cm_normalize($b['name']);
            $score = $same ? 1.0 : v8cm_similarity($a['name'], $b['name']);
            if (!$same && $score < V8CM_SIMILARITY) {
                continue;
            }
            $suggestions[] = [
                'kind' => $same ? 'same' : 'similar',
                'score' => round($score, 3),
                'a' => ['source' => $a['source'], 'source_category_id' => $a['id'], 'name' => $a['name']],
                'b' => ['source' => $b['source'], 'source_category_id' => $b['id'], 'name' => $b['name']],
            ];
        }
    }
    return array_slice($suggestions, 0, 500);
}

/**
 * Valida el mapeo contra la propuesta. Devuelve los problemas (vacío si es
 * válido) y, si es válido, los nodos resueltos con su profundidad, ancla,
 * marcador derivado y miembros, más la cadena del piloto.
 *
 * @return array{issues: list<array{code: string, node: ?string, message: string}>, nodes: list<array>, pilot: ?array}
 */
function v8cm_validate(array $proposal, string $proposalsha, array $mapping): array {
    $issues = [];
    $issue = static function (string $code, ?string $node, string $message) use (&$issues): void {
        if (count($issues) < 200) {
            $issues[] = ['code' => $code, 'node' => $node, 'message' => $message];
        }
    };
    if (($mapping['schema_version'] ?? '') !== V8CM_SCHEMA) {
        $issue('SCHEMA', null, 'El mapa no usa el formato 1.0.');
    }
    if (($mapping['proposal_sha256'] ?? '') !== $proposalsha) {
        $issue('PROPOSAL_CHANGED', null,
            'El mapa se hizo sobre otra propuesta; los orígenes o el destino cambiaron.');
    }
    foreach (['operator', 'decision_timestamp_utc', 'evidence_reference'] as $field) {
        if (!is_string($mapping[$field] ?? null) || trim((string)$mapping[$field]) === '') {
            $issue('AUDIT_MISSING', null, "Falta $field en el mapa.");
        }
    }
    $existing = [];
    $existingbyparentname = [];
    $existingbymarker = [];
    foreach ($proposal['existing'] ?? [] as $category) {
        $existing[(int)$category['id']] = $category;
        $existingbyparentname[(int)$category['parent_id'] . '|' .
            v8cm_lower((string)$category['name'])][] = $category;
        if ((string)$category['idnumber'] !== '') {
            $existingbymarker[(string)$category['idnumber']][] = $category;
        }
    }

    // Un nodo es una categoría nueva o una categoría que ya existe en el
    // destino (existing_category_id). Las existentes conservan nombre y padre.
    $nodes = [];
    $existingnodes = [];
    foreach (is_array($mapping['nodes'] ?? null) ? $mapping['nodes'] : [] as $node) {
        $key = is_array($node) ? (string)($node['key'] ?? '') : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key)) {
            $issue('NODE_KEY_INVALID', null, 'Un nodo tiene una clave inválida.');
            continue;
        }
        if (isset($nodes[$key])) {
            $issue('NODE_DUPLICATE', $key, 'La clave del nodo se repite.');
            continue;
        }
        $existingid = $node['existing_category_id'] ?? null;
        if ($existingid !== null) {
            if (!is_int($existingid) || !isset($existing[$existingid])) {
                $issue('EXISTING_MISSING', $key, 'La categoría existente ya no está en el destino.');
                continue;
            }
            if (isset($existingnodes[$existingid])) {
                $issue('EXISTING_DUPLICATE', $key, 'La misma categoría existente aparece dos veces.');
                continue;
            }
            $existingnodes[$existingid] = $key;
            $nodes[$key] = ['key' => $key, 'name' => (string)$existing[$existingid]['name'],
                'parent' => '', 'existing' => $existingid, 'members' => []];
            continue;
        }
        $name = trim((string)preg_replace('/\s+/u', ' ', (string)($node['name'] ?? '')));
        if ($name === '') {
            $issue('NAME_EMPTY', $key, 'La categoría no tiene nombre.');
        } else if (mb_strlen($name) > 255) {
            $issue('NAME_TOO_LONG', $key, 'El nombre supera los 255 caracteres.');
        }
        $nodes[$key] = ['key' => $key, 'name' => $name, 'parent' => (string)($node['parent'] ?? ''),
            'existing' => null, 'members' => []];
    }
    if ($nodes === []) {
        $issue('NODES_EMPTY', null, 'El mapa no contiene categorías.');
    }
    foreach ($nodes as $key => $node) {
        if ($node['existing'] === null && $node['parent'] !== V8CM_TOP && !isset($nodes[$node['parent']])) {
            $issue('PARENT_MISSING', $key, 'La categoría padre no existe en el mapa.');
        }
    }

    // Ciclos: se sigue la cadena de padres de cada nodo nuevo.
    foreach (array_keys($nodes) as $key) {
        $seen = [];
        $current = $key;
        while (isset($nodes[$current]) && $nodes[$current]['existing'] === null) {
            if (isset($seen[$current])) {
                $issue('CYCLE', $key, 'La categoría queda dentro de sí misma.');
                break;
            }
            $seen[$current] = true;
            $current = $nodes[$current]['parent'];
        }
    }

    // Cada categoría de origen, asignada exactamente una vez.
    $expected = [];
    foreach ($proposal['sources'] ?? [] as $source) {
        foreach ($source['categories'] ?? [] as $category) {
            $expected[v8cm_member((string)$source['source'], (int)$category['id'])] = [
                'source' => (string)$source['source'], 'category' => $category];
        }
    }
    $assigned = [];
    foreach (is_array($mapping['assignments'] ?? null) ? $mapping['assignments'] : [] as $assignment) {
        $member = is_array($assignment)
            ? v8cm_member((string)($assignment['source'] ?? ''), (int)($assignment['source_category_id'] ?? 0))
            : '';
        $node = is_array($assignment) ? (string)($assignment['node'] ?? '') : '';
        if (!isset($expected[$member])) {
            $issue('ASSIGNMENT_UNKNOWN', null, 'Una asignación nombra una categoría de origen que no existe.');
            continue;
        }
        if (isset($assigned[$member])) {
            $issue('ASSIGNMENT_DUPLICATE', $node, 'Una categoría de origen está asignada dos veces.');
            continue;
        }
        if (!isset($nodes[$node])) {
            $issue('ASSIGNMENT_NODE_MISSING', null, 'Una asignación apunta a un nodo inexistente.');
            continue;
        }
        $assigned[$member] = $node;
        $nodes[$node]['members'][] = $member;
    }
    foreach ($expected as $member => $item) {
        if (!isset($assigned[$member])) {
            $issue('UNMAPPED', null, 'La categoría «' . $item['category']['name'] . '» de ' .
                $item['source'] . ' no tiene destino.');
        }
    }

    // Hermanos nuevos con el mismo nombre normalizado bajo el mismo padre.
    $siblings = [];
    foreach ($nodes as $key => $node) {
        if ($node['existing'] === null && $node['name'] !== '') {
            $siblings[$node['parent'] . '|' . v8cm_normalize($node['name'])][] = $key;
        }
    }
    foreach ($siblings as $keys) {
        if (count($keys) > 1) {
            foreach ($keys as $key) {
                $issue('SIBLING_DUPLICATE', $key,
                    'Hay otra categoría con el mismo nombre en el mismo lugar; únalas o cambie el nombre.');
            }
        }
    }

    $resolved = [];
    $pilot = null;
    if ($issues === []) {
        $resolve = static function (string $key) use (&$resolve, &$resolved, $nodes, $existing): array {
            if (isset($resolved[$key])) {
                return $resolved[$key];
            }
            $node = $nodes[$key];
            $members = $node['members'];
            sort($members, SORT_STRING);
            if ($node['existing'] !== null) {
                $category = $existing[$node['existing']];
                return $resolved[$key] = [
                    'key' => $key, 'name' => $node['name'], 'parent_node' => '',
                    'parent_category_id' => (int)$category['parent_id'],
                    'existing_category_id' => $node['existing'],
                    'anchor' => 'existing:' . $node['existing'], 'path' => [],
                    'depth' => max(1, (int)$category['depth']),
                    'marker' => (string)$category['idnumber'], 'members' => $members,
                ];
            }
            if ($node['parent'] === V8CM_TOP) {
                $anchor = 'top';
                $path = [v8cm_normalize($node['name'])];
                $depth = 1;
                $parentnode = '';
                $parentcategory = 0;
            } else {
                $parent = $resolve($node['parent']);
                $anchor = $parent['anchor'];
                $path = [...$parent['path'], v8cm_normalize($node['name'])];
                $depth = $parent['depth'] + 1;
                $parentnode = $parent['key'];
                $parentcategory = $parent['existing_category_id'];
            }
            return $resolved[$key] = [
                'key' => $key, 'name' => $node['name'], 'parent_node' => $parentnode,
                'parent_category_id' => $parentcategory, 'existing_category_id' => null,
                'anchor' => $anchor, 'path' => $path, 'depth' => $depth,
                'marker' => v8cm_marker($anchor, $path), 'members' => $members,
            ];
        };
        foreach (array_keys($nodes) as $key) {
            $resolve($key);
        }
        foreach ($resolved as $key => $node) {
            if ($node['existing_category_id'] !== null) {
                continue;
            }
            $matches = $existingbymarker[$node['marker']] ?? [];
            if (count($matches) > 1) {
                $issue('MARKER_REPEATED', $key, 'El destino repite el código operativo de esta categoría.');
            }
            foreach ($matches as $category) {
                if (v8cm_lower((string)$category['name']) !== v8cm_lower($node['name'])) {
                    $issue('MARKER_RENAMED', $key,
                        'La categoría ya creada con este código cambió de nombre en el destino.');
                }
            }
            // Una homónima sin este marcador bajo el mismo padre del destino.
            // Solo se comprueba donde el padre ya existe: raíz o existente.
            if ($node['parent_category_id'] !== null) {
                foreach ($existingbyparentname[$node['parent_category_id'] . '|' . v8cm_lower($node['name'])] ?? [] as $category) {
                    if ((string)$category['idnumber'] !== $node['marker']) {
                        $issue('EXISTING_HOMONYM', $key, 'El destino ya tiene una categoría «' .
                            $category['name'] . '» en ese lugar; únala con ella o cambie el nombre.');
                    }
                }
            }
        }
        $pilotplacement = $proposal['pilot'] ?? null;
        if ($issues === [] && is_array($pilotplacement)) {
            $member = v8cm_member((string)$pilotplacement['source'], (int)$pilotplacement['source_category_id']);
            $node = $assigned[$member];
            $chain = [];
            for ($current = $node; $current !== ''; $current = $resolved[$current]['parent_node']) {
                array_unshift($chain, $current);
            }
            $pilot = ['source' => (string)$pilotplacement['source'],
                'source_course_id' => (int)$pilotplacement['source_course_id'],
                'node' => $node, 'chain' => $chain];
        }
    }
    $list = array_values($resolved);
    usort($list, static fn(array $a, array $b): int =>
        [$a['depth'], $a['key']] <=> [$b['depth'], $b['key']]);
    return ['issues' => $issues, 'nodes' => $issues === [] ? $list : [],
        'pilot' => $issues === [] ? $pilot : null];
}

/** El documento sellado que consumen piloto, plan y verificación del lote. */
function v8cm_validation_document(array $proposal, string $proposalsha, string $mappingsha,
        array $mapping, array $result): array {
    $assignments = [];
    foreach ($result['nodes'] as $node) {
        foreach ($node['members'] as $member) {
            $assignments[$member] = $node['key'];
        }
    }
    ksort($assignments, SORT_STRING);
    return [
        'schema_version' => V8CM_SCHEMA,
        'phase' => 'v8-category-map-validation',
        'config_sha256' => (string)($proposal['config_sha256'] ?? ''),
        'proposal_sha256' => $proposalsha,
        'category_map_sha256' => $mappingsha,
        'status' => $result['issues'] === [] ? 'VALID' : 'INVALID',
        'issues' => $result['issues'],
        'nodes' => $result['nodes'],
        'assignments' => $assignments,
        'pilot' => $result['pilot'],
        'operator' => (string)($mapping['operator'] ?? ''),
        'decision_timestamp_utc' => (string)($mapping['decision_timestamp_utc'] ?? ''),
        'evidence_reference' => (string)($mapping['evidence_reference'] ?? ''),
        'destination_write_performed' => false,
    ];
}

/**
 * Carga y revalida un documento de validación sellado; lo usan el plan de
 * fase 6 y la cadena del piloto. Lanza si no es válido o no está íntegro.
 */
function v8cm_load_validation(string $path, string $configsha): array {
    $document = v8cm_read_json($path);
    if (($document['schema_version'] ?? '') !== V8CM_SCHEMA ||
            ($document['phase'] ?? '') !== 'v8-category-map-validation' ||
            ($document['status'] ?? '') !== 'VALID' ||
            ($document['config_sha256'] ?? '') !== $configsha ||
            !preg_match('/^[a-f0-9]{64}$/', (string)($document['category_map_sha256'] ?? '')) ||
            !is_array($document['nodes'] ?? null) || $document['nodes'] === [] ||
            !is_array($document['assignments'] ?? null)) {
        throw new V8CategoryMapException('V8_CATEGORY_MAP_VALIDATION_INVALID: ' . $path);
    }
    $keys = [];
    foreach ($document['nodes'] as $node) {
        $key = (string)($node['key'] ?? '');
        $parent = (string)($node['parent_node'] ?? '');
        $existing = $node['existing_category_id'] ?? null;
        $marker = (string)($node['marker'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) || isset($keys[$key]) ||
                ($existing === null && !preg_match('/^MAP-[A-F0-9]{20}$/D', $marker)) ||
                ($existing !== null && (!is_int($existing) || $existing < 1 || $parent !== '' ||
                    strlen($marker) > 100)) ||
                trim((string)($node['name'] ?? '')) === '' ||
                ($parent !== '' && !isset($keys[$parent])) ||
                ($parent === '' && !is_int($node['parent_category_id'] ?? null))) {
            throw new V8CategoryMapException('V8_CATEGORY_MAP_VALIDATION_INVALID: nodo ' . $key);
        }
        $keys[$key] = true;
    }
    foreach ($document['assignments'] as $member => $node) {
        if (!isset($keys[(string)$node])) {
            throw new V8CategoryMapException('V8_CATEGORY_MAP_VALIDATION_INVALID: asignación ' . $member);
        }
    }
    return $document;
}

function v8cm_options(array $argv): array {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!preg_match('/^--([a-z]+)=(.*)$/s', $argument, $match)) {
            throw new V8CategoryMapException('V8_CATEGORY_MAP_ARGUMENT_INVALID: ' . $argument);
        }
        $options[$match[1]] = $match[2];
    }
    return $options;
}

function v8cm_main(array $options): int {
    $mode = (string)($options['mode'] ?? '');
    if ($mode === 'proposal') {
        $batch = v8cm_read_json((string)$options['batch']);
        $sources = array_values(array_map('strval', $batch['sources'] ?? []));
        $aliases = is_array($batch['source_aliases'] ?? null) ? $batch['source_aliases'] : [];
        if ($sources === []) {
            throw new V8CategoryMapException('V8_CATEGORY_MAP_BATCH_INVALID');
        }
        $inventories = [];
        foreach ($sources as $source) {
            if (!preg_match('/^[a-z][a-z0-9_-]*$/', $source)) {
                throw new V8CategoryMapException('V8_CATEGORY_MAP_BATCH_INVALID: ' . $source);
            }
            $inventories[$source] = v8cm_read_json(rtrim((string)$options['inventories'], '/\\') .
                '/source-inventory-' . $source . '.json');
        }
        $target = v8cm_read_json((string)$options['target']);
        if (($target['phase'] ?? '') !== 'v8-category-map-target' ||
                ($target['write_performed'] ?? null) !== false) {
            throw new V8CategoryMapException('V8_CATEGORY_MAP_TARGET_INVALID');
        }
        $pilot = isset($options['pilot']) && $options['pilot'] !== '' ? v8cm_read_json($options['pilot']) : null;
        $proposal = v8cm_build_proposal($inventories, $aliases, $target['categories'] ?? [],
            $pilot, (string)$options['configsha']);
        v8cm_write_json((string)$options['output'], $proposal);
        fwrite(STDOUT, 'V8_CATEGORY_MAP_PROPOSAL_OK sources=' . count($inventories) .
            ' suggestions=' . count($proposal['suggestions']) . PHP_EOL);
        return 0;
    }
    if ($mode === 'validate') {
        $proposalpath = (string)$options['proposal'];
        $mappingpath = (string)$options['mapping'];
        $proposal = v8cm_read_json($proposalpath);
        $mapping = v8cm_read_json($mappingpath);
        $proposalsha = (string)hash_file('sha256', $proposalpath);
        $result = v8cm_validate($proposal, $proposalsha, $mapping);
        $document = v8cm_validation_document($proposal, $proposalsha,
            (string)hash_file('sha256', $mappingpath), $mapping, $result);
        v8cm_write_json((string)$options['output'], $document);
        fwrite(STDOUT, 'V8_CATEGORY_MAP_' . $document['status'] . ' nodes=' .
            count($document['nodes']) . ' issues=' . count($document['issues']) . PHP_EOL);
        return $document['status'] === 'VALID' ? 0 : 3;
    }
    throw new V8CategoryMapException('V8_CATEGORY_MAP_MODE_INVALID');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        exit(v8cm_main(v8cm_options($argv)));
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(2);
    }
}
