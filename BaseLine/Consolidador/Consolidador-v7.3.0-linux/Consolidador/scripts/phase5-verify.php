<?php
// Fase 5: verificación independiente del curso piloto restaurado.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once('/opt/consolidator/phase5-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'phase4' => '/exports/phase4',
        'phase5' => '/exports/phase5',
        'configsha' => null,
        'targetid' => null,
        'expectlab' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php phase5-verify.php --phase4=/exports/phase4 --phase5=/exports/phase5 " .
        "--configsha=SHA256 --targetid=target [--expectlab=1]\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

/**
 * Convierte las relaciones del inventario origen a IDs destino.
 */
function p5_canonicalize_relation_value(mixed $value): mixed {
    if (is_float($value) &&
            is_finite($value) &&
            $value >= PHP_INT_MIN &&
            $value <= PHP_INT_MAX &&
            floor($value) === $value) {
        return (int)$value;
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = p5_canonicalize_relation_value($item);
        }
    }
    return $value;
}

function p5_expected_relations(array $rows, string $sourceid, array $contract): array {
    $mapped = [];
    foreach ($rows as $row) {
        $sourceuserid = (int)($row['source_user_id'] ?? 0);
        $targetuserid = 0;
        if ($sourceuserid > 0) {
            $mapping = $contract['source_by_key'][$sourceid . ':' . $sourceuserid] ?? null;
            if (!$mapping) {
                throw new RuntimeException(
                    'Una relación del inventario perdió su mapa de usuario: ' .
                    $sourceid . ':' . $sourceuserid . '.'
                );
            }
            $targetuserid = (int)$mapping['target_user_id'];
        }
        $row['source_user_id'] = $targetuserid;
        $mapped[] = p5_canonicalize_relation_value($row);
    }
    usort($mapped, static fn(array $a, array $b): int =>
        strcmp(
            json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        )
    );
    return $mapped;
}

function p5_normalize_actual_relations(array $rows): array {
    $rows = array_map('p5_canonicalize_relation_value', $rows);
    usort($rows, static fn(array $a, array $b): int =>
        strcmp(
            json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        )
    );
    return $rows;
}

/**
 * Moodle puede asignar al usuario que ejecuta la restauración los archivos
 * cuyo inventario de origen declara userid=0. Esa sustitución no representa
 * pérdida de autoría: el origen no vinculaba el archivo con una identidad.
 *
 * Se neutraliza únicamente el propietario de las filas concretas que el
 * inventario esperado declara sin usuario. Las entregas, mensajes y demás
 * archivos con source_user_id positivo siguen comparándose estrictamente
 * contra la identidad canónica de destino.
 */
function p5_normalize_restored_unowned_file_relations(
    array $expectedrows,
    array $actualrows
): array {
    $unownedbysignature = [];
    foreach ($expectedrows as $row) {
        if ((int)($row['source_user_id'] ?? 0) !== 0) {
            continue;
        }
        $signature = $row;
        unset($signature['source_user_id']);
        $key = json_encode(
            p5_canonicalize_relation_value($signature),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $unownedbysignature[$key] =
            (int)($unownedbysignature[$key] ?? 0) + 1;
    }
    if (!$unownedbysignature) {
        return $actualrows;
    }
    foreach ($actualrows as &$row) {
        $signature = $row;
        unset($signature['source_user_id']);
        $key = json_encode(
            p5_canonicalize_relation_value($signature),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if ((int)($unownedbysignature[$key] ?? 0) < 1) {
            continue;
        }
        $row['source_user_id'] = 0;
        $unownedbysignature[$key]--;
    }
    unset($row);
    return $actualrows;
}

try {
    $phase4dir = rtrim((string)$options['phase4'], '/\\');
    $phase5dir = rtrim((string)$options['phase5'], '/\\');
    $configsha = p5_require_sha256((string)$options['configsha'], 'configsha');
    $targetid = p5_norm((string)$options['targetid']);
    $expectlab = (bool)(int)$options['expectlab'];
    $bundle = p5_load_plan(
        $phase4dir,
        $phase5dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $applysummarypath = $phase5dir . '/apply_summary.json';
    $coursemapPath = $phase5dir . '/pilot_course_map.csv';
    $applysummary = p5_read_json($applysummarypath);
    if (($applysummary['config_sha256'] ?? '') !== $configsha ||
            ($applysummary['target_id'] ?? '') !== $targetid ||
            ($applysummary['plan_summary_sha256'] ?? '') !==
                hash_file('sha256', $phase5dir . '/plan_summary.json') ||
            ($applysummary['normalized_backup_sha256'] ?? '') !==
                $bundle['hashes']['normalized_backup.mbz'] ||
            ($applysummary['pilot_course_map_sha256'] ?? '') !==
                hash_file('sha256', $coursemapPath) ||
            ($applysummary['apply_performed'] ?? null) !== true) {
        throw new RuntimeException('apply_summary.json no corresponde al plan aplicado.');
    }
    $coursemap = p5_read_csv($coursemapPath);
    if (count($coursemap) !== 1) {
        throw new RuntimeException('pilot_course_map.csv debe contener exactamente un curso.');
    }
    $targetcourseid = (int)$coursemap[0]['target_course_id'];
    if ($targetcourseid < 1 ||
            $targetcourseid !== (int)$applysummary['target_course_id']) {
        throw new RuntimeException('El target_course_id aplicado es inválido.');
    }
    $course = $DB->get_record(
        'course',
        ['id' => $targetcourseid],
        'id,category,shortname,idnumber',
        MUST_EXIST
    );
    $checks = [];
    $addcheck = static function(
        string $name,
        bool $passed,
        mixed $expected,
        mixed $actual,
        string $details = ''
    ) use (&$checks): void {
        $encode = static function(mixed $value): string {
            if (is_array($value)) {
                return (string)json_encode(
                    $value,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            return (string)$value;
        };
        $checks[] = [
            'check_name' => $name,
            'verification_status' => $passed ? 'passed' : 'failed',
            'expected' => $encode($expected),
            'actual' => $encode($actual),
            'details' => $details,
        ];
    };
    $addcheck(
        'course_marker',
        (string)$course->idnumber === (string)$bundle['summary']['target_course_marker'],
        (string)$bundle['summary']['target_course_marker'],
        (string)$course->idnumber
    );
    $addcheck(
        'course_shortname',
        p5_norm((string)$course->shortname) ===
            p5_norm((string)$bundle['summary']['source_shortname']),
        (string)$bundle['summary']['source_shortname'],
        (string)$course->shortname
    );
    $addcheck(
        'course_category',
        (int)$course->category === (int)$bundle['summary']['target_category_id'],
        (int)$bundle['summary']['target_category_id'],
        (int)$course->category
    );

    $actual = p5_collect_course_inventory($targetcourseid);
    $actual['schema_version'] = '1.0';
    $actual['phase'] = '5-target-course-inventory';
    $actual['generated_at_utc'] = gmdate('c');
    $actual['config_sha256'] = $configsha;
    $actual['source_id'] = (string)$bundle['summary']['source_id'];
    $actual['target_id'] = $targetid;
    $actual['target_course_id'] = $targetcourseid;
    $targetinventorypath = $phase5dir . '/target_course_inventory.json';
    p5_write_json($targetinventorypath, $actual);

    $expected = $bundle['source_inventory'];
    $comparison = p5_compare_course_inventories($expected, $actual);
    foreach ($comparison['expected_counts'] as $name => $expectedcount) {
        $rawexpectedcount = (int)($expected['counts'][$name] ?? -1);
        $rawactualcount = (int)($actual['counts'][$name] ?? -1);
        $actualcount = (int)(
            $comparison['comparable_actual_counts'][$name] ?? -1
        );
        $details = '';
        if ($rawexpectedcount !== (int)$expectedcount ||
                $rawactualcount !== $actualcount) {
            $details =
                'Conteo comparable tras aplicar ajustes de compatibilidad entre versiones; ' .
                'conteo bruto origen=' . $rawexpectedcount .
                ', conteo bruto destino=' . $rawactualcount . '.';
        }
        $addcheck(
            'count_' . $name,
            $actualcount === (int)$expectedcount,
            (int)$expectedcount,
            $actualcount,
            $details
        );
    }
    $addcheck(
        'modules_by_type',
        $comparison['expected_modules_by_type'] ===
            $comparison['comparable_actual_modules_by_type'],
        $comparison['expected_modules_by_type'],
        $comparison['comparable_actual_modules_by_type'],
        ((int)$comparison['compatibility_adjustments']['ignored_target_qbank_modules'] > 0)
            ? 'Se excluyó mod_qbank técnico solo de la comparación entre versiones.'
            : ''
    );
    $expectedmodulekeys = $comparison['expected_module_keys'];
    $actualmodulekeys = $comparison['comparable_actual_module_keys'];
    $addcheck(
        'module_keys',
        $expectedmodulekeys === $actualmodulekeys,
        $expectedmodulekeys,
        $actualmodulekeys,
        ((int)$comparison['compatibility_adjustments']['ignored_target_qbank_modules'] > 0)
            ? 'Se excluyó mod_qbank técnico solo de la comparación entre versiones.'
            : ''
    );
    $addcheck(
        'compatibility_qbank_modules',
        true,
        'solo módulos qbank adicionales permitidos',
        (int)$comparison['compatibility_adjustments']['ignored_target_qbank_modules'],
        (string)$comparison['compatibility_adjustments']['qbank_reason']
    );
    $addcheck(
        'compatibility_assignfeedback_editpdf_files',
        true,
        'solo combined, pages y partial regenerables',
        [
            'ignored_source' => (int)$comparison['compatibility_adjustments']
                ['ignored_source_assignfeedback_editpdf_files'],
            'ignored_target' => (int)$comparison['compatibility_adjustments']
                ['ignored_target_assignfeedback_editpdf_files'],
        ],
        (string)$comparison['compatibility_adjustments']
            ['assignfeedback_editpdf_reason']
    );

    $expectedusers = array_map(
        static fn(array $row): array => [
            'target_user_id' => (int)$row['target_user_id'],
            'enrol_method' => (string)$row['enrol_method'],
            'enrol_status' => (int)$row['enrol_status'],
        ],
        $bundle['user_rows']
    );
    $actualusers = array_map(
        static fn(array $row): array => [
            'target_user_id' => (int)$row['source_user_id'],
            'enrol_method' => (string)$row['enrol_method'],
            'enrol_status' => (int)$row['enrol_status'],
        ],
        $actual['enrolments']
    );
    usort($expectedusers, static fn(array $a, array $b): int =>
        [$a['target_user_id'], $a['enrol_method']] <=>
        [$b['target_user_id'], $b['enrol_method']]
    );
    usort($actualusers, static fn(array $a, array $b): int =>
        [$a['target_user_id'], $a['enrol_method']] <=>
        [$b['target_user_id'], $b['enrol_method']]
    );
    $addcheck('enrolment_map', $expectedusers === $actualusers, $expectedusers, $actualusers);

    $expectedroles = array_map(
        static fn(array $row): array => [
            'target_user_id' => (int)$row['target_user_id'],
            'role_shortname' => (string)$row['target_role_shortname'],
        ],
        $bundle['role_rows']
    );
    $actualroles = array_map(
        static fn(array $row): array => [
            'target_user_id' => (int)$row['source_user_id'],
            'role_shortname' => (string)$row['role_shortname'],
        ],
        $actual['roles']
    );
    usort($expectedroles, static fn(array $a, array $b): int =>
        [$a['target_user_id'], $a['role_shortname']] <=>
        [$b['target_user_id'], $b['role_shortname']]
    );
    usort($actualroles, static fn(array $a, array $b): int =>
        [$a['target_user_id'], $a['role_shortname']] <=>
        [$b['target_user_id'], $b['role_shortname']]
    );
    $addcheck('course_role_map', $expectedroles === $actualroles, $expectedroles, $actualroles);

    $sourceid = (string)$bundle['summary']['source_id'];
    foreach ($expected['relations'] as $name => $expectedrows) {
        $comparisonexpectedrows = $expectedrows;
        $comparisonactualrows = $actual['relations'][$name] ?? [];
        $details = '';
        if ($name === 'files') {
            $comparisonexpectedrows =
                p5_filter_comparable_files($comparisonexpectedrows);
            $comparisonactualrows =
                p5_filter_comparable_files($comparisonactualrows);
            $ignoredexpected = count($expectedrows) -
                count($comparisonexpectedrows);
            $ignoredactual = count($actual['relations'][$name] ?? []) -
                count($comparisonactualrows);
            if ($ignoredexpected > 0 || $ignoredactual > 0) {
                $details =
                    'Comparación semántica de archivos; se excluyeron artefactos ' .
                    'regenerables de assignfeedback_editpdf en combined, pages y partial. ' .
                    'Ignorados origen=' . $ignoredexpected .
                    ', destino=' . $ignoredactual . '.';
            }
        }
        $mappedexpected = p5_expected_relations(
            $comparisonexpectedrows,
            $sourceid,
            $bundle['phase4']
        );
        if ($name === 'files') {
            $comparisonactualrows =
                p5_normalize_restored_unowned_file_relations(
                    $mappedexpected,
                    $comparisonactualrows
                );
            $normalizedowners = count(array_filter(
                $mappedexpected,
                static fn(array $row): bool =>
                    (int)($row['source_user_id'] ?? 0) === 0
            ));
            if ($normalizedowners > 0) {
                $details .= ($details === '' ? '' : ' ') .
                    'Se neutralizó el propietario técnico de ' .
                    $normalizedowners .
                    ' archivo(s) que el origen declaró con source_user_id=0; ' .
                    'los archivos asociados a usuarios permanecen estrictos.';
            }
        }
        $actualrows = p5_normalize_actual_relations($comparisonactualrows);
        $addcheck(
            'relations_' . $name,
            $mappedexpected === $actualrows,
            $mappedexpected,
            $actualrows,
            $details
        );
    }

    $failed = count(array_filter(
        $checks,
        static fn(array $row): bool => $row['verification_status'] !== 'passed'
    ));
    $verificationpath = $phase5dir . '/verification.csv';
    p5_write_csv($verificationpath, [
        'check_name', 'verification_status', 'expected', 'actual', 'details',
    ], $checks);
    $summary = [
        'schema_version' => '1.0',
        'phase' => '5-pilot-course-verification',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'plan_summary_sha256' =>
            hash_file('sha256', $phase5dir . '/plan_summary.json'),
        'apply_summary_sha256' => hash_file('sha256', $applysummarypath),
        'pilot_course_map_sha256' => hash_file('sha256', $coursemapPath),
        'target_course_inventory_sha256' => hash_file('sha256', $targetinventorypath),
        'verification_csv_sha256' => hash_file('sha256', $verificationpath),
        'source_id' => $sourceid,
        'target_course_id' => $targetcourseid,
        'enrolments_verified' => count($expectedusers),
        'roles_verified' => count($expectedroles),
        'compatibility_adjustments' =>
            $comparison['compatibility_adjustments'],
        'checks_total' => count($checks),
        'failed_checks' => $failed,
        'roles_applied' => true,
        'enrolments_applied' => true,
        'course_data_applied' => true,
        'validation' => $failed === 0 ? 'passed' : 'failed',
    ];
    if ($expectlab) {
        $summary['lab_validation'] = $failed === 0 ? 'passed' : 'failed';
    }
    p5_write_json($phase5dir . '/verification.json', $summary);
    if ($failed > 0) {
        throw new RuntimeException(
            'La verificación detectó ' . $failed . ' incumplimiento(s).'
        );
    }
    cli_writeln(
        'FASE5_VERIFY_OK course_id=' . $targetcourseid .
        ' users=' . count($expectedusers) .
        ' roles=' . count($expectedroles) .
        ' failed=0'
    );
} catch (Throwable $error) {
    cli_error('FASE5_VERIFY_ERROR ' . $error->getMessage());
}
