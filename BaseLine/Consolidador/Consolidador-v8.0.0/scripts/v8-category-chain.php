<?php
// 8.1.0: crea o reutiliza la cadena de categorías mapeadas del curso piloto.
//
// Usa las mismas reglas que phase6-apply-categories.php: una categoría nueva
// se reconoce por su marcador (idnumber) y debe conservar nombre y padre; una
// homónima sin marcador bajo el mismo padre bloquea. Fase 6 reutiliza luego
// estas categorías por marcador.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once('/opt/consolidator/v8-category-map.php');

[$options, $unrecognized] = cli_get_params(
    ['validation' => null, 'configsha' => null, 'output' => null, 'help' => false],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln("Uso: php v8-category-chain.php --validation=RUTA --configsha=SHA256 --output=RUTA\n");
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $validationpath = (string)$options['validation'];
    $configsha = (string)$options['configsha'];
    $output = trim((string)$options['output']);
    if (!preg_match('/^[a-f0-9]{64}$/', $configsha) || $output === '') {
        throw new RuntimeException('configsha u output inválido.');
    }
    $validation = v8cm_load_validation($validationpath, $configsha);
    $pilot = $validation['pilot'] ?? null;
    if (!is_array($pilot) || !is_array($pilot['chain'] ?? null) || $pilot['chain'] === []) {
        throw new RuntimeException('El mapa no ubica el curso piloto.');
    }
    $nodes = [];
    foreach ($validation['nodes'] as $node) {
        $nodes[(string)$node['key']] = $node;
    }
    $ids = [];
    $rows = [];
    foreach ($pilot['chain'] as $key) {
        $node = $nodes[(string)$key] ?? null;
        if ($node === null) {
            throw new RuntimeException('La cadena del piloto nombra un nodo inexistente.');
        }
        $parentid = (string)$node['parent_node'] === ''
            ? (int)$node['parent_category_id']
            : (int)($ids[(string)$node['parent_node']] ?? -1);
        if ($node['existing_category_id'] !== null) {
            $category = $DB->get_record('course_categories', ['id' => (int)$node['existing_category_id']],
                'id,parent,name,idnumber', IGNORE_MISSING);
            if (!$category || (int)$category->parent !== $parentid ||
                    mb_strtolower(trim((string)$category->name)) !== mb_strtolower(trim((string)$node['name']))) {
                throw new RuntimeException('La categoría existente ' . $key . ' cambió de nombre o padre.');
            }
            $ids[(string)$key] = (int)$category->id;
            $rows[] = ['node' => (string)$key, 'target_category_id' => (int)$category->id,
                'target_parent_category_id' => $parentid, 'marker' => (string)$category->idnumber,
                'action' => 'existing'];
            continue;
        }
        if ($parentid < 0 || ($parentid > 0 && !$DB->record_exists('course_categories', ['id' => $parentid]))) {
            throw new RuntimeException('No existe la categoría padre de ' . $key . '.');
        }
        $marker = (string)$node['marker'];
        $matches = $DB->get_records('course_categories', ['idnumber' => $marker], 'id ASC', 'id,parent,name');
        if (count($matches) > 1) {
            throw new RuntimeException('El destino repite el marcador de ' . $key . '.');
        }
        if (count($matches) === 1) {
            $category = reset($matches);
            if ((int)$category->parent !== $parentid ||
                    mb_strtolower(trim((string)$category->name)) !== mb_strtolower(trim((string)$node['name']))) {
                throw new RuntimeException('La categoría marcada ' . $key . ' cambió de nombre o padre.');
            }
            $action = 'reused';
        } else {
            if ($DB->record_exists('course_categories', ['parent' => $parentid, 'name' => (string)$node['name']])) {
                throw new RuntimeException('Existe una categoría homónima sin marcador para ' . $key . '.');
            }
            $category = core_course_category::create([
                'name' => (string)$node['name'],
                'parent' => $parentid,
                'idnumber' => $marker,
                'visible' => 1,
            ]);
            $action = 'created';
        }
        $ids[(string)$key] = (int)$category->id;
        $rows[] = ['node' => (string)$key, 'target_category_id' => (int)$category->id,
            'target_parent_category_id' => $parentid, 'marker' => $marker, 'action' => $action];
    }
    $last = end($rows);
    v8cm_write_json($output, [
        'schema_version' => '1.0',
        'phase' => 'v8-category-map-pilot-chain',
        'config_sha256' => $configsha,
        'category_map_sha256' => (string)$validation['category_map_sha256'],
        'validation_sha256' => (string)hash_file('sha256', $validationpath),
        'pilot_node' => (string)$pilot['node'],
        'target_category_id' => (int)$last['target_category_id'],
        'chain' => $rows,
        'destination_write_performed' => true,
    ]);
    cli_writeln('V8_CATEGORY_CHAIN_OK category=' . (int)$last['target_category_id'] .
        ' created=' . count(array_filter($rows, static fn(array $row): bool => $row['action'] === 'created')));
} catch (Throwable $error) {
    cli_error('V8_CATEGORY_CHAIN_ERROR ' . $error->getMessage());
}
