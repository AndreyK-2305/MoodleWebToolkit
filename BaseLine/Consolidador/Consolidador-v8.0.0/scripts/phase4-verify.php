<?php
// Fase 4: verificación independiente del mapa canónico materializado.

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
        "Uso: php phase4-verify.php --phase3=/exports/phase3 --phase4=/exports/phase4 " .
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

try {
    $bundle = p4_load_plan(
        $phase3dir,
        $phase4dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $applysummarypath = $phase4dir . '/apply_summary.json';
    $mappath = $phase4dir . '/target_user_map.csv';
    $sourcetargetpath = $phase4dir . '/source_to_target_user_map.csv';
    $applysummary = p4_read_json($applysummarypath);
    if (($applysummary['plan_sha256'] ?? '') !== $bundle['plan_hash'] ||
            ($applysummary['config_sha256'] ?? '') !== $configsha ||
            ($applysummary['target_id'] ?? '') !== $targetid) {
        throw new RuntimeException('apply_summary.json no corresponde al plan confirmado.');
    }
    if (($applysummary['target_user_map_sha256'] ?? '') !== hash_file('sha256', $mappath)) {
        throw new RuntimeException('target_user_map.csv cambió después de la aplicación.');
    }
    if (($applysummary['source_to_target_user_map_sha256'] ?? '') !==
            hash_file('sha256', $sourcetargetpath)) {
        throw new RuntimeException('source_to_target_user_map.csv cambió después de la aplicación.');
    }
    if (($applysummary['roles_applied'] ?? null) !== false ||
            ($applysummary['enrolments_applied'] ?? null) !== false) {
        throw new RuntimeException('El resumen afirma que se aplicaron permisos fuera del alcance de fase 4.');
    }
    $maprows = p4_read_csv($mappath);
    $mapbycanonical = [];
    foreach ($maprows as $row) {
        $canonicalid = (string)($row['canonical_id'] ?? '');
        if ($canonicalid === '' || (int)($row['target_user_id'] ?? 0) < 1 ||
                isset($mapbycanonical[$canonicalid])) {
            throw new RuntimeException('target_user_map.csv repite ' . $canonicalid . '.');
        }
        $mapbycanonical[$canonicalid] = $row;
    }
    $sourcetargetrows = p4_read_csv($sourcetargetpath);
    if (count($sourcetargetrows) !== count($bundle['phase3']['source_map'])) {
        throw new RuntimeException(
            'source_to_target_user_map.csv no contiene todas las cuentas de origen.'
        );
    }
    $sourcekeys = [];
    foreach ($bundle['phase3']['source_map'] as $row) {
        $key = (string)$row['source'] . ':' . (string)$row['source_user_id'];
        if (isset($sourcekeys[$key])) {
            throw new RuntimeException('El mapa fuente de fase 3 repite ' . $key);
        }
        $sourcekeys[$key] = (string)$row['canonical_id'];
    }
    $seenkeys = [];
    foreach ($sourcetargetrows as $row) {
        $key = (string)($row['source'] ?? '') . ':' . (string)($row['source_user_id'] ?? '');
        if (isset($seenkeys[$key]) || !isset($sourcekeys[$key]) ||
                $sourcekeys[$key] !== (string)($row['canonical_id'] ?? '')) {
            throw new RuntimeException('El mapa final repite o altera la cuenta ' . $key);
        }
        $seenkeys[$key] = true;
    }

    $inventory = p4_target_inventory();
    $indexes = p4_inventory_indexes($inventory);
    p4_get_oauth2_issuer($oauthissuerid);
    p4_validate_oauth2_identifier_mapping($bundle['rows'], $oauthissuerid);
    $checks = [];
    $passed = true;
    $mappedids = [];
    $oauthlinksexpected = 0;
    $oauthlinksverified = 0;
    $siteadminids = p4_current_site_administrator_ids();
    $siteadminsexpected = 0;
    $siteadminsverified = 0;
    $siteadminplannedids = [];
    foreach ($bundle['rows'] as $planrow) {
        $canonicalid = (string)$planrow['canonical_id'];
        $action = (string)$planrow['action'];
        $matches = $indexes['canonical'][$canonicalid] ?? [];
        $status = 'passed';
        $details = '';
        $targetuserid = '';
        $oauthlinkstatus = 'not_applicable';
        if (p4_is_apply_action($action)) {
            if (count($matches) !== 1) {
                $status = 'failed';
                $details = 'Se esperaba exactamente un usuario con el canonical_id.';
            } else {
                $targetuserid = (int)$matches[0];
                if (isset($mappedids[$targetuserid])) {
                    $status = 'failed';
                    $details = 'El mismo target_user_id representa varias identidades canónicas.';
                } else {
                    $mappedids[$targetuserid] = $canonicalid;
                }
                $maprow = $mapbycanonical[$canonicalid] ?? null;
                if (!$maprow || (int)$maprow['target_user_id'] !== $targetuserid) {
                    $status = 'failed';
                    $details = 'El ID real no coincide con target_user_map.csv.';
                }
                $user = $inventory[$targetuserid] ?? null;
                if (!$user ||
                        $user['username'] !== p4_norm((string)$planrow['target_username']) ||
                        $user['email'] !== p4_norm((string)$planrow['target_email'])) {
                    $status = 'failed';
                    $details = 'Username o correo no coincide con el plan aplicado.';
                }
                if ((string)$planrow['identity_method'] === 'pending_relink' &&
                        (!$user || $user['auth'] !== 'manual' ||
                         $user['identity_status'] !== 'pending_relink' ||
                         $user['google_sub'] !== '' ||
                         $user['oauth_linked_username'] !== '' ||
                         $DB->record_exists('auth_oauth2_linked_login', [
                             'userid' => $targetuserid,
                         ]))) {
                    $status = 'failed';
                    $details = 'pending_relink no se conservó como cuenta manual sin vínculo OAuth.';
                }
                if (p4_is_oauth_identity_method(
                    (string)$planrow['identity_method']
                ) &&
                        (!$user ||
                         $user['google_issuer'] !== (string)$planrow['google_issuer'] ||
                         $user['google_sub'] !== (string)$planrow['google_sub'] ||
                         $user['oauth_linked_username'] !==
                            p4_norm((string)$planrow['oauth_linked_username']) ||
                         $user['oauth_identifier_kind'] !==
                            (string)$planrow['oauth_identifier_kind'])) {
                    $status = 'failed';
                    $details = 'La identidad OAuth materializada no coincide con el plan.';
                }
                if (p4_is_oauth_identity_method(
                    (string)$planrow['identity_method']
                )) {
                    $oauthlinksexpected++;
                    $identifier = p4_oauth_identifier($planrow);
                    $links = $DB->get_records('auth_oauth2_linked_login', [
                        'issuerid' => $oauthissuerid,
                        'username' => $identifier,
                    ], 'id ASC');
                    $linked = count($links) === 1 ? reset($links) : null;
                    if (!$user || (string)$user['auth'] !== 'oauth2' ||
                            !$linked ||
                            (int)$linked->userid !== $targetuserid ||
                            (string)$linked->confirmtoken !== '') {
                        $status = 'failed';
                        $oauthlinkstatus = 'failed';
                        $details = 'El linked login OAuth nativo no coincide con el usuario.';
                    } else {
                        $oauthlinkstatus = 'passed';
                        $oauthlinksverified++;
                    }
                }
                if (p4_bool($planrow['siteadmin_required'] ?? false)) {
                    $siteadminsexpected++;
                    if ($targetuserid > 0) {
                        $siteadminplannedids[$targetuserid] = true;
                    }
                    if (!in_array($targetuserid, $siteadminids, true)) {
                        $status = 'failed';
                        $details =
                            'El usuario canónico no conservó el privilegio siteadmin.';
                    } else {
                        $siteadminsverified++;
                    }
                }
            }
        } else if (in_array($action, [
            'skip_blocked', 'skip_identity_review', 'skip_excluded',
        ], true)) {
            if ($matches) {
                $status = 'failed';
                $details = 'Una identidad excluida fue materializada en el destino.';
            } else {
                $details = 'Identidad excluida correctamente.';
            }
        } else {
            $status = 'failed';
            $details = 'El plan conserva una acción bloqueante o desconocida.';
        }
        if ($status !== 'passed') {
            $passed = false;
        }
        $checks[] = [
            'canonical_id' => $canonicalid,
            'planned_action' => $action,
            'target_user_id' => $targetuserid,
            'oauth2_link_status' => $oauthlinkstatus,
            'verification_status' => $status,
            'details' => $details,
        ];
    }

    foreach ($sourcetargetrows as $sourceaccount) {
        $canonicalid = (string)$sourceaccount['canonical_id'];
        $canonicalmap = $mapbycanonical[$canonicalid] ?? null;
        $expectedid = $canonicalmap ? (int)($canonicalmap['target_user_id'] ?? 0) : 0;
        $actualid = (int)($sourceaccount['target_user_id'] ?? 0);
        if ($expectedid < 1 || $actualid !== $expectedid ||
                (string)($sourceaccount['mapping_status'] ?? '') !== 'mapped') {
            $passed = false;
            $checks[] = [
                'canonical_id' => $canonicalid,
                'planned_action' => 'source_account_mapping',
                'target_user_id' => $actualid ?: '',
                'oauth2_link_status' => 'not_applicable',
                'verification_status' => 'failed',
                'details' => 'El usuario destino de la cuenta de origen no coincide con el mapa canónico.',
            ];
        }
    }

    // La verificación cubre todas las cuentas, no solo las que aparecen en el
    // mapa final. Ningún usuario puede representar dos identidades canónicas.
    $failedchecks = count(array_filter(
        $checks,
        static fn(array $check): bool => $check['verification_status'] !== 'passed'
    ));
    if (count($maprows) !== count($mappedids) ||
            $oauthlinksexpected !== $oauthlinksverified ||
            $siteadminsexpected !== $siteadminsverified) {
        $passed = false;
        $failedchecks++;
    }
    $verificationpath = $phase4dir . '/verification.csv';
    p4_write_csv($verificationpath, [
        'canonical_id', 'planned_action', 'target_user_id',
        'oauth2_link_status', 'verification_status', 'details',
    ], $checks);
    $summary = [
        'schema_version' => '1.0',
        'phase' => '4-user-verification',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'oauth2_issuer_id' => $oauthissuerid,
        'canonical_rows_checked' => count($bundle['rows']),
        'source_accounts_checked' => count($sourcetargetrows),
        'target_users_mapped' => count($mappedids),
        'pending_relink_verified' => count(array_filter(
            $bundle['rows'],
            static fn(array $row): bool =>
                $row['identity_method'] === 'pending_relink' &&
                p4_is_apply_action((string)$row['action'])
        )),
        'site_administrators_expected' => $siteadminsexpected,
        'site_administrators_verified' => $siteadminsverified,
        'oauth2_links_expected' => $oauthlinksexpected,
        'oauth2_links_verified' => $oauthlinksverified,
        'oauth2_links_failed' => $oauthlinksexpected - $oauthlinksverified,
        'failed_checks' => $failedchecks,
        'target_user_map_sha256' => hash_file('sha256', $mappath),
        'source_to_target_user_map_sha256' => hash_file('sha256', $sourcetargetpath),
        'verification_csv_sha256' => hash_file('sha256', $verificationpath),
        'roles_applied' => false,
        'enrolments_applied' => false,
        'validation' => $passed && $failedchecks === 0 ? 'passed' : 'failed',
    ];
    if ($expectlab) {
        $summary['lab_validation'] = $summary['validation'];
    }
    p4_write_json($phase4dir . '/verification.json', $summary);
    if ($summary['validation'] !== 'passed') {
        cli_error('FASE4_VERIFY_FAILED checks=' . $failedchecks);
    }
    cli_writeln(
        'FASE4_VERIFY_OK canonical=' . count($bundle['rows']) .
        ' mapped=' . count($mappedids) .
        ' oauth=' . $oauthlinksverified .
        ' siteadmins=' . $siteadminsverified
    );
} catch (Throwable $error) {
    cli_error('FASE4_VERIFY_ERROR ' . $error->getMessage());
}
