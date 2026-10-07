<?php
// Fase 6: crea o reutiliza la jerarquía y asegura el rol de contingencia.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once('/opt/consolidator/phase5-lib.php');
require_once('/opt/consolidator/phase6-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'phase4' => '/exports/phase4',
        'phase6' => '/exports/phase6',
        'configsha' => null,
        'targetid' => null,
        'expectlab' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln("Uso: php phase6-apply-categories.php --phase4=DIR --phase6=DIR " .
        "--configsha=SHA256 --targetid=target [--expectlab=1]\n");
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $phase4dir = rtrim((string)$options['phase4'], '/\\');
    $phase6dir = rtrim((string)$options['phase6'], '/\\');
    $configsha = p5_require_sha256((string)$options['configsha'], 'configsha');
    $targetid = p5_norm((string)$options['targetid']);
    $expectlab = (bool)(int)$options['expectlab'];
    $bundle = p6_load_reference_manifest(
        $phase4dir,
        $phase6dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $preflightpath = $phase6dir . '/apply_preflight.json';
    $preflight = p5_read_json($preflightpath);
    if (($preflight['preflight_status'] ?? '') !== 'applicable' ||
            ($preflight['manifest_sha256'] ?? '') !== $bundle['manifest_sha256'] ||
            ($preflight['destination_write_performed'] ?? null) !== false) {
        throw new RuntimeException('La prevalidación de aplicación no es utilizable.');
    }

    $role = p6_ensure_personalizado_role();
    $siteadmins = p6_apply_planned_site_administrators($bundle['phase4']);
    $rows = $bundle['category_rows'];
    usort($rows, static fn(array $left, array $right): int =>
        [(int)$left['source_depth'], (string)$left['category_key']] <=>
        [(int)$right['source_depth'], (string)$right['category_key']]
    );
    $idsbykey = [];
    $maprows = [];
    $created = 0;
    $reused = 0;
    foreach ($rows as $row) {
        $key = (string)$row['category_key'];
        $parentkey = (string)$row['parent_category_key'];
        $parentid = $parentkey === ''
            ? (int)$row['target_parent_category_id']
            : (int)($idsbykey[$parentkey] ?? 0);
        if ($parentid < 1 ||
                !$DB->record_exists('course_categories', ['id' => $parentid])) {
            throw new RuntimeException('No existe la categoría padre de ' . $key . '.');
        }
        $marker = (string)$row['target_category_marker'];
        $matches = $DB->get_records(
            'course_categories',
            ['idnumber' => $marker],
            'id ASC',
            'id,parent,name,idnumber'
        );
        if (count($matches) > 1) {
            throw new RuntimeException('El destino repite el marcador de ' . $key . '.');
        }
        $action = 'reused';
        if (count($matches) === 1) {
            $category = reset($matches);
            if ((int)$category->parent !== $parentid ||
                    p5_norm((string)$category->name) !==
                        p5_norm((string)$row['category_name'])) {
                throw new RuntimeException(
                    'La categoría marcada ' . $key . ' cambió de nombre o padre.'
                );
            }
            $categoryid = (int)$category->id;
            $reused++;
        } else {
            $collision = $DB->get_record(
                'course_categories',
                ['parent' => $parentid, 'name' => (string)$row['category_name']],
                'id,idnumber',
                IGNORE_MISSING
            );
            if ($collision) {
                throw new RuntimeException(
                    'Existe una categoría homónima sin marcador para ' . $key . '.'
                );
            }
            $category = core_course_category::create([
                'name' => (string)$row['category_name'],
                'parent' => $parentid,
                'idnumber' => $marker,
                'visible' => 1,
            ]);
            $categoryid = (int)$category->id;
            $action = 'created';
            $created++;
        }
        $idsbykey[$key] = $categoryid;
        $maprows[] = [
            'category_key' => $key,
            'target_category_id' => $categoryid,
            'target_parent_category_id' => $parentid,
            'target_category_marker' => $marker,
            'category_name' => (string)$row['category_name'],
            'apply_status' => $action,
        ];
    }
    $mappath = $phase6dir . '/category_map.csv';
    p5_write_csv($mappath, [
        'category_key',
        'target_category_id',
        'target_parent_category_id',
        'target_category_marker',
        'category_name',
        'apply_status',
    ], $maprows);
    $summary = [
        'schema_version' => '1.0',
        'phase' => '6-category-apply',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'batch_id' => (string)$bundle['summary']['batch_id'],
        'manifest_sha256' => $bundle['manifest_sha256'],
        'apply_preflight_sha256' => hash_file('sha256', $preflightpath),
        'category_map_sha256' => hash_file('sha256', $mappath),
        'categories_total' => count($maprows),
        'categories_created' => $created,
        'categories_reused' => $reused,
        'personalizado_role_id' => (int)$role['role_id'],
        'personalizado_action' => (string)$role['action'],
        'personalizado_profile' => 'student_readonly',
        'site_administrator_ids_before' => $siteadmins['before_ids'],
        'site_administrator_ids_planned' => $siteadmins['planned_ids'],
        'site_administrator_ids_after' => $siteadmins['after_ids'],
        'site_administrators_added' => (int)$siteadmins['added'],
        'destination_write_performed' => true,
        'apply_status' => 'applied',
    ];
    p5_write_json($phase6dir . '/category_apply_summary.json', $summary);
    cli_writeln(
        'FASE6_CATEGORIES_OK total=' . count($maprows) .
        ' created=' . $created .
        ' reused=' . $reused .
        ' role=' . $role['action'] .
        ' siteadmins_added=' . (int)$siteadmins['added']
    );
} catch (Throwable $error) {
    cli_error('FASE6_CATEGORIES_ERROR ' . $error->getMessage());
}
