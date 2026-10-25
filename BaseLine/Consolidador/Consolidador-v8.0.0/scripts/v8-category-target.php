<?php
// 8.1.0: categorías actuales del destino para el mapa de categorías (solo lectura).

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    ['output' => null, 'help' => false],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln("Uso: php v8-category-target.php --output=RUTA\n");
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $output = trim((string)$options['output']);
    if ($output === '') {
        throw new RuntimeException('output inválido.');
    }
    $counts = [];
    foreach ($DB->get_records_sql(
        'SELECT category, COUNT(1) AS total FROM {course} WHERE id <> :siteid GROUP BY category',
        ['siteid' => SITEID]
    ) as $row) {
        $counts[(int)$row->category] = (int)$row->total;
    }
    $categories = [];
    foreach ($DB->get_records(
        'course_categories',
        null,
        'depth ASC, id ASC',
        'id,parent,name,idnumber,depth'
    ) as $category) {
        $categories[] = [
            'id' => (int)$category->id,
            'parent_id' => (int)$category->parent,
            'name' => (string)$category->name,
            'idnumber' => (string)$category->idnumber,
            'depth' => (int)$category->depth,
            'course_count' => (int)($counts[(int)$category->id] ?? 0),
        ];
    }
    $directory = dirname($output);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('No fue posible crear ' . $directory . '.');
    }
    $json = json_encode([
        'schema_version' => '1.0',
        'phase' => 'v8-category-map-target',
        'categories' => $categories,
        'write_performed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($output, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('No fue posible escribir ' . $output . '.');
    }
    cli_writeln('V8_CATEGORY_TARGET_OK categories=' . count($categories));
} catch (Throwable $error) {
    cli_error('V8_CATEGORY_TARGET_ERROR ' . $error->getMessage());
}
