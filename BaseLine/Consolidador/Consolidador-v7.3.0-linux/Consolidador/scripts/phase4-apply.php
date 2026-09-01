<?php
// Fase 4: aplicación explícita e idempotente de usuarios canónicos.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once('/opt/consolidator/phase4-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'phase3' => '/exports/phase3',
        'phase4' => '/exports/phase4',
        'configsha' => null,
        'targetid' => null,
        'oauthissuerid' => null,
        'expectlab' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php phase4-apply.php --phase3=/exports/phase3 --phase4=/exports/phase4 " .
        "--configsha=SHA256 --targetid=target --oauthissuerid=ID " .
        "[--expectlab=1]\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

$phase3dir = rtrim((string)$options['phase3'], '/\\');
$phase4dir = rtrim((string)$options['phase4'], '/\\');
$configsha = p4_norm((string)($options['configsha'] ?? ''));
$targetid = p4_norm((string)($options['targetid'] ?? ''));
$oauthissuerid = (int)($options['oauthissuerid'] ?? 0);
$expectlab = (bool)(int)$options['expectlab'];
if (!preg_match('/^[a-f0-9]{64}$/', $configsha) ||
        !preg_match('/^[a-z][a-z0-9_-]*$/', $targetid) ||
        $oauthissuerid < 1) {
    cli_error('configsha, targetid u oauthissuerid inválido.');
}

$olduser = $USER;
try {
    $bundle = p4_load_plan(
        $phase3dir,
        $phase4dir,
        $configsha,
        $targetid,
        $expectlab
    );
    if ((int)$bundle['summary']['blocking_conflicts'] !== 0) {
        throw new RuntimeException(
            'El plan contiene conflictos bloqueantes. No se aplicó ninguna identidad.'
        );
    }
    // La prevalidación es de solo lectura y ocurre antes de crear campos de
    // perfil o usuarios. Detecta cambios entre los comandos 13 y 14.
    $inventory = p4_target_inventory();
    p4_preflight_plan($bundle, $inventory);
    $oauthissuer = p4_get_oauth2_issuer($oauthissuerid);
    p4_validate_oauth2_identifier_mapping($bundle['rows'], $oauthissuerid);
    p4_preflight_oauth2_links($bundle['rows'], $inventory, $oauthissuerid);
    \core\session\manager::set_user(get_admin());
    $fieldids = p4_ensure_profile_fields();
    $results = [];
    foreach ($bundle['rows'] as $row) {
        $action = (string)$row['action'];
        if (!p4_is_apply_action($action)) {
            $results[] = [
                'plan_row_id' => (string)$row['plan_row_id'],
                'canonical_id' => (string)$row['canonical_id'],
                'planned_action' => $action,
                'apply_status' => 'skipped',
                'target_user_id' => '',
                'target_username' => '',
                'target_email' => '',
                'canonical_marker' => '',
                'oauth2_issuer_id' => '',
                'oauth2_subject' => '',
                'oauth2_link_status' => 'not_applicable',
                'roles_applied' => false,
                'enrolments_applied' => false,
                'message' => (string)$row['blocking_reason'],
            ];
            continue;
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $results[] = p4_apply_plan_row($row, $fieldids, $oauthissuer);
            $transaction->allow_commit();
        } catch (Throwable $error) {
            $transaction->rollback($error);
        }
    }
    $siteadminstate = p4_apply_planned_site_administrators(
        $bundle['rows'],
        $results
    );
    \core\session\manager::set_user($olduser);

    $mappath = $phase4dir . '/target_user_map.csv';
    p4_write_csv($mappath, p4_map_columns(), $results);
    $sourcetargetrows = p4_build_source_target_map(
        $bundle['phase3']['source_map'],
        $results
    );
    $sourcetargetpath = $phase4dir . '/source_to_target_user_map.csv';
    p4_write_csv(
        $sourcetargetpath,
        p4_source_target_columns(),
        $sourcetargetrows
    );
    $sourceaccountsmapped = count(array_filter(
        $sourcetargetrows,
        static fn(array $row): bool => $row['mapping_status'] === 'mapped'
    ));
    $sourceaccountsexcluded = count($sourcetargetrows) - $sourceaccountsmapped;
    $statuscounts = [];
    $oauthlinkcounts = [];
    foreach ($results as $result) {
        $status = (string)$result['apply_status'];
        $statuscounts[$status] = ($statuscounts[$status] ?? 0) + 1;
        $linkstatus = (string)($result['oauth2_link_status'] ?? 'not_applicable');
        $oauthlinkcounts[$linkstatus] =
            ($oauthlinkcounts[$linkstatus] ?? 0) + 1;
    }
    ksort($statuscounts, SORT_STRING);
    ksort($oauthlinkcounts, SORT_STRING);
    $applied = count(array_filter(
        $results,
        static fn(array $row): bool => (int)($row['target_user_id'] ?? 0) > 0
    ));
    $expectedapplied = count(array_filter(
        $bundle['rows'],
        static fn(array $row): bool => p4_is_apply_action((string)($row['action'] ?? ''))
    ));
    $expectedsourcemapped = 0;
    foreach ($bundle['rows'] as $row) {
        if (p4_is_apply_action((string)($row['action'] ?? ''))) {
            $expectedsourcemapped += count(array_filter(array_map(
                'trim',
                explode('|', (string)($row['source_accounts'] ?? ''))
            ), 'strlen'));
        }
    }
    $expectedsourceexcluded = count($sourcetargetrows) - $expectedsourcemapped;
    if ($expectlab &&
            ($applied !== $expectedapplied ||
             $sourceaccountsmapped !== $expectedsourcemapped ||
             $sourceaccountsexcluded !== $expectedsourceexcluded)) {
        throw new RuntimeException(
            'Validación LAB: la aplicación no coincide con las acciones del plan confirmado.'
        );
    }
    $summary = [
        'schema_version' => '1.0',
        'phase' => '4-user-apply',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'plan_sha256' => $bundle['plan_hash'],
        'target_user_map_sha256' => hash_file('sha256', $mappath),
        'source_to_target_user_map_sha256' => hash_file('sha256', $sourcetargetpath),
        'canonical_identities_in_plan' => count($bundle['rows']),
        'source_accounts_in_map' => count($sourcetargetrows),
        'source_accounts_mapped' => $sourceaccountsmapped,
        'source_accounts_excluded' => $sourceaccountsexcluded,
        'target_users_mapped' => $applied,
        'status_counts' => $statuscounts,
        'oauth2_issuer_id' => $oauthissuerid,
        'oauth2_link_status_counts' => $oauthlinkcounts,
        'oauth2_links_materialized' =>
            (int)($oauthlinkcounts['created'] ?? 0) +
            (int)($oauthlinkcounts['updated'] ?? 0) +
            (int)($oauthlinkcounts['reused'] ?? 0),
        'site_administrators_before_ids' => $siteadminstate['before_ids'],
        'site_administrators_planned_ids' => $siteadminstate['planned_ids'],
        'site_administrators_after_ids' => $siteadminstate['after_ids'],
        'site_administrators_added' => $siteadminstate['added'],
        'apply_performed' => true,
        'roles_applied' => false,
        'enrolments_applied' => false,
        'course_data_applied' => false,
    ];
    if ($expectlab) {
        $summary['lab_validation'] = 'passed';
    }
    p4_write_json($phase4dir . '/apply_summary.json', $summary);
    cli_writeln(
        'FASE4_APPLY_OK mapped=' . $applied .
        ' skipped=' . (int)($statuscounts['skipped'] ?? 0) .
        ' oauthlinks=' .
            ((int)($oauthlinkcounts['created'] ?? 0) +
             (int)($oauthlinkcounts['updated'] ?? 0) +
             (int)($oauthlinkcounts['reused'] ?? 0)) .
        ' siteadmins=' . count($siteadminstate['planned_ids']) .
        ' roles=0 enrolments=0'
    );
} catch (Throwable $error) {
    \core\session\manager::set_user($olduser);
    cli_error('FASE4_APPLY_ERROR ' . $error->getMessage());
}
