<?php
// Fase 4: simulación no destructiva de usuarios canónicos en Moodle destino.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once('/opt/consolidator/phase4-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'input' => '/exports/phase3',
        'output' => '/exports/phase4',
        'configsha' => null,
        'targetid' => null,
        'expectlab' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php phase4-plan.php --input=/exports/phase3 --output=/exports/phase4 " .
        "--configsha=SHA256 --targetid=target [--expectlab=1]\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

$inputdir = rtrim((string)$options['input'], '/\\');
$outputdir = rtrim((string)$options['output'], '/\\');
$configsha = p4_norm((string)($options['configsha'] ?? ''));
$targetid = p4_norm((string)($options['targetid'] ?? ''));
$expectlab = (bool)(int)$options['expectlab'];
if (!preg_match('/^[a-f0-9]{64}$/', $configsha) ||
        !preg_match('/^[a-z][a-z0-9_-]*$/', $targetid)) {
    cli_error('configsha o targetid inválido.');
}

try {
    $phase3 = p4_load_phase3($inputdir, $configsha, $targetid, $expectlab);
    $inventory = p4_target_inventory();
    $plan = p4_build_plan($phase3, $inventory);
    if (!is_dir($outputdir) && !mkdir($outputdir, 0770, true) && !is_dir($outputdir)) {
        throw new RuntimeException('No fue posible crear ' . $outputdir . '.');
    }

    $planpath = $outputdir . '/target_user_plan.csv';
    p4_write_csv($planpath, p4_plan_columns(), $plan);
    $actioncounts = p4_action_counts($plan);
    $rowconflicts = count(array_filter(
        $plan,
        static fn(array $row): bool => p4_is_conflict_action((string)$row['action'])
    ));
    $orphanmarkers = p4_orphan_canonical_markers($phase3, $inventory);
    $conflicts = $rowconflicts + count($orphanmarkers);
    $applicable = count(array_filter(
        $plan,
        static fn(array $row): bool => p4_is_apply_action((string)$row['action'])
    ));
    $blocked = (int)($actioncounts['skip_blocked'] ?? 0);
    $review = (int)($actioncounts['skip_identity_review'] ?? 0);
    $excluded = (int)($actioncounts['skip_excluded'] ?? 0);
    $siteadmins = count(array_filter(
        $plan,
        static fn(array $row): bool =>
            p4_bool($row['siteadmin_required'] ?? false) &&
            p4_is_apply_action((string)$row['action'])
    ));
    if ($expectlab) {
        $phase4expected = $phase3['summary']['phase4_expected'] ?? [];
        if (count($plan) !== (int)$phase3['summary']['canonical_identities'] ||
                $applicable !== (int)($phase4expected['applicable_identities'] ?? -1) ||
                $blocked !== (int)($phase4expected['blocked_identities'] ?? -1) ||
                $review !== (int)($phase4expected['identity_review_pending'] ?? -1) ||
                $excluded !== (int)($phase4expected['excluded_identities'] ?? -1) ||
                $rowconflicts !== 0 || $orphanmarkers) {
            throw new RuntimeException(
                'Validación LAB: el plan no coincide con phase4_expected de summary.json.'
            );
        }
    }

    $summary = [
        'schema_version' => '1.0',
        'phase' => '4-user-plan',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'target_site_url' => $CFG->wwwroot,
        'phase3_input_sha256' => $phase3['hashes'],
        'target_inventory_sha256' => p4_inventory_fingerprint($inventory),
        'target_users_observed' => count($inventory),
        'canonical_identities' => count($plan),
        'applicable_identities' => $applicable,
        'blocked_identities' => $blocked,
        'identity_review_pending' => $review,
        'excluded_identities' => $excluded,
        'site_administrators_planned' => $siteadmins,
        'row_blocking_conflicts' => $rowconflicts,
        'orphan_canonical_marker_count' => count($orphanmarkers),
        'orphan_canonical_markers' => $orphanmarkers,
        'blocking_conflicts' => $conflicts,
        'action_counts' => $actioncounts,
        'plan_sha256' => hash_file('sha256', $planpath),
        'apply_performed' => false,
        'roles_applied' => false,
        'enrolments_applied' => false,
    ];
    if ($expectlab) {
        $summary['lab_validation'] = 'passed';
    }
    p4_write_json($outputdir . '/plan_summary.json', $summary);
    cli_writeln(
        'FASE4_PLAN_OK canonical=' . count($plan) .
        ' applicable=' . $applicable .
        ' blocked=' . $blocked .
        ' review=' . $review .
        ' excluded=' . $excluded .
        ' conflicts=' . $conflicts
    );
} catch (Throwable $error) {
    cli_error('FASE4_PLAN_ERROR ' . $error->getMessage());
}
