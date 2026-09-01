<?php
// Verifica la configuración manual de Google OAuth2 sin leer credenciales.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'output' => '/exports/oauth2',
        'configsha' => null,
        'oauthconfigsha' => null,
        'targetid' => null,
        'issuerid' => 'auto',
        'servicetype' => 'google',
        'expectedbaseurl' => 'https://accounts.google.com',
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php oauth2-validate.php --output=/exports/oauth2 " .
        "--configsha=SHA256 --oauthconfigsha=SHA256 --targetid=target " .
        "[--issuerid=auto|ID] [--servicetype=google] " .
        "[--expectedbaseurl=https://accounts.google.com]\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

$outputdir = rtrim((string)$options['output'], '/\\');
$configsha = strtolower(trim((string)($options['configsha'] ?? '')));
$oauthconfigsha = strtolower(trim(
    (string)($options['oauthconfigsha'] ?? '')
));
$targetid = strtolower(trim((string)($options['targetid'] ?? '')));
$issuerselector = strtolower(trim((string)$options['issuerid']));
$servicetype = strtolower(trim((string)$options['servicetype']));
$expectedbaseurl = rtrim(strtolower(trim(
    (string)$options['expectedbaseurl']
)), '/');
if (!preg_match('/^[a-f0-9]{64}$/', $configsha) ||
        !preg_match('/^[a-f0-9]{64}$/', $oauthconfigsha) ||
        !preg_match('/^[a-z][a-z0-9_-]*$/', $targetid) ||
        !preg_match('/^[a-z0-9_]+$/', $servicetype) ||
        ($issuerselector !== 'auto' &&
         (!ctype_digit($issuerselector) || (int)$issuerselector < 1)) ||
        ($expectedbaseurl !== '' &&
         !preg_match('#^https://[a-z0-9.-]+(?::[0-9]+)?(?:/.*)?$#', $expectedbaseurl))) {
    cli_error('Los parámetros de validación OAuth2 no son válidos.');
}

function oauth2_write_json(string $path, array $document): void {
    $json = json_encode(
        $document,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($json === false || file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('No fue posible escribir ' . $path . '.');
    }
}

try {
    global $DB;
    if (!is_dir($outputdir) &&
            !mkdir($outputdir, 0770, true) && !is_dir($outputdir)) {
        throw new RuntimeException('No fue posible crear ' . $outputdir . '.');
    }

    $issues = [];
    $dbman = $DB->get_manager();
    foreach (['oauth2_issuer', 'oauth2_endpoint', 'auth_oauth2_linked_login'] as $tablename) {
        if (!$dbman->table_exists(new xmldb_table($tablename))) {
            $issues[] = 'Falta la tabla Moodle ' . $tablename . '.';
        }
    }

    $authenabled = function_exists('is_enabled_auth') && is_enabled_auth('oauth2');
    if (!$authenabled) {
        $issues[] = 'El método de autenticación OAuth 2 no está habilitado.';
    }

    $candidates = [];
    if ($dbman->table_exists(new xmldb_table('oauth2_issuer'))) {
        $records = $issuerselector === 'auto'
            ? $DB->get_records('oauth2_issuer', ['servicetype' => $servicetype], 'id ASC')
            : $DB->get_records('oauth2_issuer', ['id' => (int)$issuerselector], 'id ASC');
        foreach ($records as $record) {
            $candidates[] = [
                'id' => (int)$record->id,
                'name' => (string)$record->name,
                'service_type' => (string)$record->servicetype,
                'enabled' => (bool)$record->enabled,
                'show_on_login_page' => (int)$record->showonloginpage,
                'base_url' => (string)$record->baseurl,
            ];
        }
    }

    $selected = null;
    if (count($candidates) !== 1) {
        $issues[] = $issuerselector === 'auto'
            ? 'Debe existir exactamente un servicio OAuth2 de tipo ' . $servicetype .
                '; se encontraron ' . count($candidates) . '.'
            : 'No se encontró el issuer_id OAuth2 seleccionado.';
    } else {
        $selected = $DB->get_record(
            'oauth2_issuer',
            ['id' => (int)$candidates[0]['id']],
            '*',
            MUST_EXIST
        );
        if (strtolower((string)$selected->servicetype) !== $servicetype) {
            $issues[] = 'El issuer seleccionado no corresponde al tipo ' . $servicetype . '.';
        }
        if (!(bool)$selected->enabled) {
            $issues[] = 'El servicio OAuth2 está deshabilitado.';
        }
        if (in_array((int)$selected->showonloginpage, [0, 3], true)) {
            $issues[] = 'El servicio OAuth2 no está disponible en la página de acceso.';
        }
        if (trim((string)$selected->clientid) === '' ||
                trim((string)$selected->clientsecret) === '') {
            $issues[] = 'El servicio OAuth2 no tiene Client ID y Client secret completos.';
        }
        $actualbaseurl = rtrim(strtolower(trim((string)$selected->baseurl)), '/');
        if ($expectedbaseurl !== '' && $actualbaseurl !== $expectedbaseurl) {
            $issues[] = 'La URL base del servicio no coincide con el issuer Google esperado.';
        }
        $userinfoendpoint = $DB->get_record('oauth2_endpoint', [
            'issuerid' => (int)$selected->id,
            'name' => 'userinfo_endpoint',
        ], 'id,url', IGNORE_MISSING);
        if (!$userinfoendpoint || trim((string)$userinfoendpoint->url) === '') {
            $issues[] = 'El servicio OAuth2 no tiene un endpoint userinfo utilizable.';
        }
    }

    $issuerid = $selected ? (int)$selected->id : 0;
    $endpointcount = $issuerid > 0
        ? $DB->count_records('oauth2_endpoint', ['issuerid' => $issuerid])
        : 0;
    $ready = count($issues) === 0;
    $summary = [
        'schema_version' => '1.0',
        'phase' => 'oauth2-manual-configuration-validation',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'oauth_config_sha256' => $oauthconfigsha,
        'target_id' => $targetid,
        'target_url' => (string)$CFG->wwwroot,
        'callback_url' => rtrim((string)$CFG->wwwroot, '/') .
            '/admin/oauth2callback.php',
        'service_type' => $servicetype,
        'expected_subject_issuer' => $expectedbaseurl,
        'issuer_selector' => $issuerselector,
        'issuer_id' => $issuerid,
        'issuer_name' => $selected ? (string)$selected->name : '',
        'issuer_base_url' => $selected ? (string)$selected->baseurl : '',
        'issuer_enabled' => $selected ? (bool)$selected->enabled : false,
        'show_on_login_page' => $selected
            ? !in_array((int)$selected->showonloginpage, [0, 3], true)
            : false,
        'client_credentials_present' => $selected
            ? trim((string)$selected->clientid) !== '' &&
                trim((string)$selected->clientsecret) !== ''
            : false,
        'auth_plugin_enabled' => $authenabled,
        'endpoints_configured' => (int)$endpointcount,
        'candidates' => $candidates,
        'issues' => $issues,
        'destination_write_performed' => false,
        'status' => $ready ? 'ready' : 'manual_action_required',
        'validation' => $ready ? 'passed' : 'failed',
    ];
    oauth2_write_json($outputdir . '/validation.json', $summary);
    if (!$ready) {
        cli_error(
            'OAUTH2_MANUAL_REQUIRED issues=' . count($issues) .
            ' callback=' . $summary['callback_url']
        );
    }
    cli_writeln(
        'OAUTH2_READY issuerid=' . $issuerid .
        ' service=' . $servicetype .
        ' login=1 auth=1'
    );
} catch (Throwable $error) {
    cli_error('OAUTH2_VALIDATION_ERROR ' . $error->getMessage());
}
