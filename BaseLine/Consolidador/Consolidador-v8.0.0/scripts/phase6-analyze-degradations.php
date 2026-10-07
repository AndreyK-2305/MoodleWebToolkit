<?php
// Fase 12b: preflight selectivo de degradaciones antes de cualquier restore masivo.

declare(strict_types=1);

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once('/opt/consolidator/phase5-lib.php');
require_once('/opt/consolidator/phase6-lib.php');

[$options, $unrecognized] = cli_get_params([
    'phase6' => '/exports/phase6',
    'resolutions' => '/exports/phase6/degradation-resolutions.csv',
    'source-integrity' => '/exports/phase6/source-integrity-policies.csv',
    'help' => false,
], ['h' => 'help']);
if ($options['help']) {
    cli_writeln("Uso: php phase6-analyze-degradations.php --phase6=/exports/phase6\n");
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $phase6 = rtrim((string)$options['phase6'], '/\\');
    $manifestpath = $phase6 . '/batch_manifest.json';
    $manifest = p5_read_json($manifestpath);
    if (($manifest['manifest_status'] ?? '') !== 'prepared' ||
            ($manifest['phase'] ?? '') !== '6-multi-course-reference-manifest' ||
            ($manifest['destination_write_performed'] ?? true) !== false) {
        throw new RuntimeException('DEGRADATION_BATCH_MANIFEST_INVALID');
    }
    $resolutionpath = (string)$options['resolutions'];
    $resolutionrows = is_readable($resolutionpath)
        ? p5_read_csv($resolutionpath) : [];
    $resolutions = p6_degradation_resolution_map($resolutionrows);
    $resolutionsfilesha = is_readable($resolutionpath)
        ? hash_file('sha256', $resolutionpath) : hash('sha256', '');
    $sourceintegritypath = (string)$options['source-integrity'];
    if (!is_readable($sourceintegritypath)) {
        throw new RuntimeException('SOURCE_INTEGRITY_POLICY_FILE_MISSING');
    }
    $sourceintegrityrows = p5_read_csv($sourceintegritypath);
    $sourceintegritypolicies = p6_degradation_source_integrity_map(
        $sourceintegrityrows
    );
    $sourceintegrityfilesha = hash_file('sha256', $sourceintegritypath);
    foreach ([$phase6 . '/course-degradation-plans',
            $phase6 . '/degradation-cache'] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('DEGRADATION_OUTPUT_CREATE_FAILED');
        }
    }

    $started = microtime(true);
    $plans = [];
    $totals = [
        'courses_analyzed' => 0,
        'courses_reused' => 0,
        'courses_rebuilt' => 0,
        'courses_affected' => 0,
        'missing_payloads' => 0,
        'known_degraded_missing_payloads' => 0,
        'unexpected_missing_payloads' => 0,
        'unexpected_missing_payloads_approved' => 0,
        'structural_warnings' => 0,
        'items_to_omit' => 0,
        'blocking_errors' => 0,
        'archive_bytes_inspected' => 0,
        'archive_members_inspected' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
    ];
    foreach ($manifest['entries'] ?? [] as $position => $entry) {
        $coursekey = (string)($entry['course_key'] ?? '');
        $basename = p6_backup_basename($coursekey);
        $inventorypath = $phase6 . '/' . ltrim(
            (string)($entry['source_inventory_file'] ?? ''), '/\\'
        );
        $jobpath = $phase6 . '/' . ltrim(
            (string)($entry['course_job_file'] ?? ''), '/\\'
        );
        $backuppath = '/exports/' . ltrim(
            (string)($entry['source_backup_file'] ?? ''), '/\\'
        );
        $backupsha = p5_require_sha256(
            (string)($entry['source_backup_sha256'] ?? ''),
            'source_backup_sha256'
        );
        $inventorydocument = p5_read_json($inventorypath);
        $inventory = $inventorydocument['inventory'] ?? null;
        if (!is_array($inventory) || !is_readable($backuppath) ||
                !is_readable($jobpath)) {
            throw new RuntimeException('DEGRADATION_INPUT_MISSING: ' . $coursekey);
        }
        $planpath = $phase6 . '/course-degradation-plans/plan-' . $basename . '.json';
        $inventorysha = hash_file('sha256', $inventorypath);
        $jobsha = hash_file('sha256', $jobpath);
        if ($inventorysha !== ($entry['source_inventory_sha256'] ?? '') ||
                $jobsha !== ($entry['course_job_sha256'] ?? '')) {
            throw new RuntimeException('DEGRADATION_INPUT_SEAL_INVALID: ' . $coursekey);
        }
        $sourceid = (string)$entry['source'];
        $sourceintegrity = p6_degradation_source_integrity_policy(
            $sourceintegritypolicies,
            $sourceid
        );
        $courseresolutions = p6_degradation_course_resolutions(
            $resolutions,
            $coursekey
        );
        $courseresolutionmap = p6_degradation_resolution_map($courseresolutions);
        $dependencies = [
            'course_backup_sha256' => $backupsha,
            'course_inventory_sha256' => $inventorysha,
            'course_job_sha256' => $jobsha,
            'source_integrity_policy_sha256' =>
                p6_degradation_source_integrity_hash($sourceintegrity),
            'course_resolution_sha256' =>
                p6_degradation_course_resolution_hash($resolutions, $coursekey),
        ];
        $reused = false;
        if (is_readable($planpath)) {
            $existing = p5_read_json($planpath);
            $reused = p6_degradation_plan_reusable($existing, $dependencies);
        }
        if (!$reused) {
            $plan = p6_build_course_degradation_plan(
                $backuppath,
                $backupsha,
                $inventory,
                $sourceid,
                (int)$entry['source_course_id'],
                $coursekey,
                $phase6 . '/degradation-cache/' . $basename,
                $courseresolutionmap,
                $sourceintegrity
            );
            $plan = array_merge($plan, $dependencies, [
                // Alias de auditoría para consumidores RC2 iniciales.
                'source_backup_sha256' => $backupsha,
                'source_inventory_sha256' => $inventorysha,
                'source_integrity_policy' => $sourceintegrity,
            ]);
            p5_write_json($planpath, $plan);
            $totals['courses_rebuilt']++;
            cli_writeln('DEGRADATION_PLAN_REBUILD course_key=' . $coursekey);
        } else {
            $plan = $existing;
            $totals['courses_reused']++;
            cli_writeln('DEGRADATION_PLAN_REUSE course_key=' . $coursekey);
        }
        $plans[] = [
            'course_key' => $coursekey,
            'source' => (string)$entry['source'],
            'source_course_id' => (int)$entry['source_course_id'],
            'plan_file' => 'course-degradation-plans/' . basename($planpath),
            'plan_sha256' => hash_file('sha256', $planpath),
            'status' => (string)$plan['status'],
            'blocking_errors' => (int)$plan['blocking_errors'],
            'missing_payloads' => (int)$plan['missing_payloads'],
            'known_degraded_missing_payloads' =>
                (int)$plan['known_degraded_missing_payloads'],
            'unexpected_missing_payloads' =>
                (int)$plan['unexpected_missing_payloads'],
            'unexpected_missing_payloads_approved' =>
                (int)$plan['unexpected_missing_payloads_approved'],
            'structural_warnings' => (int)$plan['structural_warnings'],
            'items_to_omit' => (int)$plan['items_to_omit'],
            'reused' => $reused,
        ];
        $totals['courses_analyzed']++;
        $affected = count($plan['items'] ?? []) > 0;
        $totals['courses_affected'] += $affected ? 1 : 0;
        foreach (['missing_payloads', 'known_degraded_missing_payloads',
                'unexpected_missing_payloads',
                'unexpected_missing_payloads_approved', 'structural_warnings',
                'items_to_omit', 'blocking_errors'] as $field) {
            $totals[$field] += (int)$plan[$field];
        }
        if (!$reused) {
            $totals['archive_bytes_inspected'] += (int)$plan['metrics']['archive_bytes'];
            $totals['archive_members_inspected'] +=
                (int)$plan['metrics']['archive_members_inspected'];
            $totals[(bool)$plan['metrics']['cache_hit'] ? 'cache_hits' : 'cache_misses']++;
        }
        cli_writeln(
            'DEGRADATION_COURSE_ANALYZED position=' . ($position + 1) .
            ' course_key=' . $coursekey . ' status=' . $plan['status'] .
            ' missing=' . (int)$plan['missing_payloads'] .
            ' structural=' . (int)$plan['structural_warnings'] .
            ' blocking=' . (int)$plan['blocking_errors'] .
            ' reuse=' . ($reused ? '1' : '0')
        );
    }
    usort($plans, static fn(array $a, array $b): int =>
        [$a['source'], $a['source_course_id']] <=> [$b['source'], $b['source_course_id']]
    );
    $elapsed = microtime(true) - $started;
    $batch = [
        'schema_version' => '1.0',
        'phase' => '6-batch-degradation-preflight',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => (string)$manifest['config_sha256'],
        'batch_id' => (string)$manifest['batch_id'],
        'batch_manifest_sha256' => hash_file('sha256', $manifestpath),
        'resolutions_file_sha256' => $resolutionsfilesha,
        'source_integrity_policies_file_sha256' => $sourceintegrityfilesha,
        'status' => $totals['blocking_errors'] > 0
            ? 'blocking' : ($totals['courses_affected'] > 0
                ? 'ready_for_approval' : 'clean'),
        'totals' => $totals,
        'metrics' => [
            'analysis_seconds' => round($elapsed, 6),
            'courses_per_second' => $elapsed > 0
                ? round($totals['courses_analyzed'] / $elapsed, 6) : 0,
            'bytes_per_second' => $elapsed > 0
                ? round($totals['archive_bytes_inspected'] / $elapsed, 2) : 0,
            'preparation_before_first_restore_seconds' => round($elapsed, 6),
        ],
        'plans_sha256' => p6_value_sha256($plans),
        'plans' => $plans,
        'destination_write_performed' => false,
    ];
    p5_write_json($phase6 . '/degradation_batch.json', $batch);
    cli_writeln(
        'DEGRADATION_BATCH_' . strtoupper($batch['status']) .
        ' courses=' . $totals['courses_analyzed'] .
        ' affected=' . $totals['courses_affected'] .
        ' missing=' . $totals['missing_payloads'] .
        ' known_missing=' . $totals['known_degraded_missing_payloads'] .
        ' unexpected_missing=' . $totals['unexpected_missing_payloads'] .
        ' structural=' . $totals['structural_warnings'] .
        ' blocking=' . $totals['blocking_errors'] .
        ' reused=' . $totals['courses_reused'] .
        ' rebuilt=' . $totals['courses_rebuilt']
    );
    if ($totals['blocking_errors'] > 0) {
        throw new RuntimeException(
            'DEGRADATION_PREFLIGHT_BLOCKING: resuelva las inconsistencias antes de Fase 13.'
        );
    }
} catch (Throwable $error) {
    cli_error('FASE6_DEGRADATION_PREFLIGHT_ERROR ' . $error->getMessage());
}
