<?php
// Fase 6: valida todos los checkpoints y sella la aplicación del lote.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');
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
    cli_writeln("Uso: php phase6-seal-apply.php --phase4=DIR --phase6=DIR " .
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
    $rows = [];
    $enrolments = 0;
    $roles = 0;
    foreach ($bundle['restore_courses'] as $courseplan) {
        $coursekey = (string)$courseplan['course_key'];
        $basename = p6_backup_basename($coursekey);
        $checkpointpath =
            $phase6dir . '/apply-checkpoints/checkpoint-' . $basename . '.json';
        $checkpoint = p5_read_json($checkpointpath);
        $courseid = (int)($checkpoint['target_course_id'] ?? 0);
        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,category,fullname,shortname,idnumber',
            MUST_EXIST
        );
        if (($checkpoint['checkpoint_status'] ?? '') !== 'applied' ||
                ($checkpoint['manifest_sha256'] ?? '') !==
                    $bundle['manifest_sha256'] ||
                ($checkpoint['course_key'] ?? '') !== $coursekey ||
                ($checkpoint['target_shortname'] ?? '') !==
                    (string)$courseplan['target_shortname'] ||
                ($checkpoint['target_fullname'] ?? '') !==
                    (string)$courseplan['target_fullname'] ||
                (string)$course->idnumber !==
                    (string)$courseplan['target_course_marker'] ||
                p5_norm((string)$course->shortname) !==
                    p5_norm((string)$courseplan['target_shortname']) ||
                p5_norm((string)$course->fullname) !==
                    p5_norm((string)$courseplan['target_fullname'])) {
            throw new RuntimeException(
                'El checkpoint final de ' . $coursekey . ' no es válido.'
            );
        }
        $rows[] = [
            'course_key' => $coursekey,
            'source' => (string)$courseplan['source'],
            'source_course_id' => (int)$courseplan['source_course_id'],
            'target_course_id' => $courseid,
            'target_category_id' => (int)$checkpoint['target_category_id'],
            'target_course_marker' => (string)$course->idnumber,
            'target_shortname' => (string)$course->shortname,
            'target_fullname' => (string)$course->fullname,
            'effective_enrolments' => (int)$checkpoint['effective_enrolments'],
            'effective_roles' => (int)$checkpoint['effective_roles'],
            'checkpoint_sha256' => hash_file('sha256', $checkpointpath),
            'apply_status' => 'applied',
        ];
        $enrolments += (int)$checkpoint['effective_enrolments'];
        $roles += (int)$checkpoint['effective_roles'];
    }
    $expectedcheckpointnames = array_map(
        static fn(array $courseplan): string =>
            'checkpoint-' .
            p6_backup_basename((string)$courseplan['course_key']) . '.json',
        $bundle['restore_courses']
    );
    sort($expectedcheckpointnames, SORT_STRING);
    $actualcheckpointnames = array_map(
        'basename',
        glob($phase6dir . '/apply-checkpoints/checkpoint-*.json') ?: []
    );
    sort($actualcheckpointnames, SORT_STRING);
    if ($actualcheckpointnames !== $expectedcheckpointnames) {
        throw new RuntimeException(
            'Los checkpoints de aplicación contienen faltantes o archivos ajenos.'
        );
    }
    $mappath = $phase6dir . '/course_map.csv';
    p5_write_csv($mappath, [
        'course_key',
        'source',
        'source_course_id',
        'target_course_id',
        'target_category_id',
        'target_course_marker',
        'target_shortname',
        'target_fullname',
        'effective_enrolments',
        'effective_roles',
        'checkpoint_sha256',
        'apply_status',
    ], $rows);
    $summary = [
        'schema_version' => '1.0',
        'phase' => '6-multi-course-apply',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'batch_id' => (string)$bundle['summary']['batch_id'],
        'manifest_sha256' => $bundle['manifest_sha256'],
        'category_apply_summary_sha256' =>
            hash_file('sha256', $phase6dir . '/category_apply_summary.json'),
        'category_map_sha256' => hash_file('sha256', $phase6dir . '/category_map.csv'),
        'course_map_sha256' => hash_file('sha256', $mappath),
        'courses_expected' => count($bundle['restore_courses']),
        'courses_applied' => count($rows),
        'courses_pending' => 0,
        'effective_enrolments' => $enrolments,
        'effective_roles' => $roles,
        'pilot_excluded' => (int)$bundle['summary']['courses_excluded_phase5_pilot'],
        'apply_status' => 'applied_pending_batch_verification',
        'destination_write_performed' => true,
        'categories_created' => true,
        'courses_restored' => true,
    ];
    if ($expectlab) {
        $summary['lab_validation'] =
            count($rows) === count($bundle['restore_courses'])
                ? 'passed'
                : 'failed';
        if ($summary['lab_validation'] !== 'passed') {
            throw new RuntimeException(
                'La aplicación LAB no conserva el total derivado del plan.'
            );
        }
    }
    p5_write_json($phase6dir . '/batch_apply_summary.json', $summary);
    cli_writeln(
        'FASE6_APPLY_OK courses=' . count($rows) .
        ' pending=0 enrolments=' . $enrolments .
        ' roles=' . $roles .
        ' pilot_excluded=' . $summary['pilot_excluded']
    );
} catch (Throwable $error) {
    cli_error('FASE6_APPLY_ERROR ' . $error->getMessage());
}
