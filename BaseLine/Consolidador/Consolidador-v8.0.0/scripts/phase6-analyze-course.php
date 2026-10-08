<?php
// Precheck físico y diagnóstico de un único curso, ejecutado por su worker.

declare(strict_types=1);

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once('/opt/consolidator/phase5-lib.php');
require_once('/opt/consolidator/phase6-lib.php');

[$options, $unrecognized] = cli_get_params([
    'phase4' => '/exports/phase4',
    'phase6' => '/exports/phase6',
    'configsha' => null,
    'targetid' => null,
    'coursekey' => null,
    'source-integrity' => '/exports/phase6/source-integrity-policies.csv',
    'resolutions' => '/exports/phase6/degradation-resolutions.csv',
    'expectlab' => 0,
    'help' => false,
], ['h' => 'help']);
if ($options['help']) {
    cli_writeln('Uso: php phase6-analyze-course.php --coursekey=COURSE-...');
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
    $coursekey = strtoupper(trim((string)$options['coursekey']));
    $expectlab = (bool)(int)$options['expectlab'];
    $bundle = p6_load_course_job(
        $phase4dir,
        $phase6dir,
        $configsha,
        $targetid,
        $coursekey,
        $expectlab
    );
    $entry = $bundle['manifest_entries_by_course'][$coursekey];
    $courseplan = $bundle['courses_by_key'][$coursekey];
    $sourceid = (string)$courseplan['source'];
    $integritypath = (string)$options['source-integrity'];
    $resolutionpath = (string)$options['resolutions'];
    $policies = p6_degradation_source_integrity_map(p5_read_csv($integritypath));
    $policy = p6_degradation_source_integrity_policy($policies, $sourceid);
    $resolutions = p6_degradation_resolution_map(p5_read_csv($resolutionpath));
    $courseresolutions = p6_degradation_resolution_map(
        p6_degradation_course_resolutions($resolutions, $coursekey)
    );
    $inventorydocument = $bundle['source_inventory_document'];
    $inventory = $inventorydocument['inventory'] ?? null;
    if (!is_array($inventory)) {
        throw new RuntimeException('SOURCE_DATA_DEFECT: inventario de curso inválido.');
    }
    $inventorypath = $entry['_paths']['source_inventory'];
    $jobpath = $entry['_paths']['course_job'];
    $dependencies = [
        'course_backup_sha256' => (string)$entry['source_backup_sha256'],
        'course_inventory_sha256' => hash_file('sha256', $inventorypath),
        'course_job_sha256' => hash_file('sha256', $jobpath),
        'source_integrity_policy_sha256' =>
            p6_degradation_source_integrity_hash($policy),
        'course_resolution_sha256' =>
            p6_degradation_course_resolution_hash($resolutions, $coursekey),
        'batch_manifest_sha256' => $bundle['manifest_sha256'],
    ];
    $basename = p6_backup_basename($coursekey);
    $planpath = $phase6dir . '/course-degradation-plans/plan-' . $basename . '.json';
    $reused = false;
    if (is_readable($planpath)) {
        $plan = p5_read_json($planpath);
        $reused = p6_degradation_plan_reusable($plan, $dependencies);
    }
    if (!$reused) {
        $plan = p6_build_course_degradation_plan(
            $entry['_paths']['source_backup'],
            (string)$entry['source_backup_sha256'],
            $inventory,
            $sourceid,
            (int)$entry['source_course_id'],
            $coursekey,
            $phase6dir . '/degradation-cache/' . $basename,
            $courseresolutions,
            $policy
        );
        $plan = array_merge($plan, $dependencies, [
            'source_backup_sha256' => (string)$entry['source_backup_sha256'],
            'source_inventory_sha256' => $dependencies['course_inventory_sha256'],
            'source_integrity_policy' => $policy,
            'worker_course_precheck' => true,
        ]);
        p5_write_json($planpath, $plan);
    }
    if ((int)($plan['blocking_errors'] ?? 0) > 0) {
        $firstblocking = [];
        foreach (($plan['items'] ?? []) as $item) {
            if (is_array($item) && ($item['blocking'] ?? false) === true) {
                $firstblocking = $item;
                break;
            }
        }
        $firstblockingjson = json_encode(
            $firstblocking,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($firstblockingjson)) {
            $firstblockingjson = '{}';
        }
        $firstblockingjson = preg_replace('/\s+/', ' ', $firstblockingjson) ?? '{}';
        $firstblockingjson = substr($firstblockingjson, 0, 1600);
        cli_writeln(
            'COURSE_PRECHECK_BLOCKED course_key=' . $coursekey .
            ' blocking_errors=' . (int)$plan['blocking_errors'] .
            ' missing=' . (int)($plan['missing_payloads'] ?? 0) .
            ' structural=' . (int)($plan['structural_warnings'] ?? 0) .
            ' first_blocking=' . $firstblockingjson
        );
        cli_writeln(
            'WORKER_RESULT status=WAITING_MANUAL category=SOURCE_DATA_DEFECT ' .
            'course_key=' . $coursekey
        );
        exit(30);
    }
    cli_writeln(
        'COURSE_PRECHECK_OK course_key=' . $coursekey .
        ' plan_sha256=' . hash_file('sha256', $planpath) .
        ' missing=' . (int)$plan['missing_payloads'] .
        ' structural=' . (int)$plan['structural_warnings'] .
        ' reused=' . ($reused ? '1' : '0')
    );
} catch (Throwable $error) {
    cli_writeln(
        'WORKER_RESULT status=WAITING_MANUAL category=SOURCE_DATA_DEFECT ' .
        'course_key=' . $coursekey . ' message=' .
        preg_replace('/\s+/', '_', $error->getMessage())
    );
    exit(30);
}
