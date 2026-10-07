<?php
// Metadatos no secretos para el paquete integral del Moodle consolidado.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/phase5-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'output' => null,
        'targetid' => null,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php site-backup-metadata.php --output=RUTA --targetid=target\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $output = trim((string)$options['output']);
    $targetid = core_text::strtolower(trim((string)$options['targetid']));
    if ($output === '' ||
            !preg_match('/^[a-z][a-z0-9_-]*$/', $targetid)) {
        throw new RuntimeException('output o targetid inválido.');
    }

    p5_write_json($output, [
        'schema_version' => '1.0',
        'phase' => '8-consolidated-site-metadata',
        'generated_at_utc' => gmdate('c'),
        'target_id' => $targetid,
        'moodle_version' => (string)get_config('moodle', 'version'),
        'moodle_release' => (string)get_config('moodle', 'release'),
        'wwwroot_at_backup' => (string)$CFG->wwwroot,
        'database_type' => (string)$CFG->dbtype,
        'database_name_at_backup' => (string)$CFG->dbname,
        'code_root_at_backup' => (string)$CFG->dirroot,
        'data_root_at_backup' => (string)$CFG->dataroot,
        'config_php_included' => false,
        'database_connection_credentials_included' => false,
        'contains_sensitive_authentication_data' => true,
        'write_performed' => false,
    ]);
    cli_writeln(
        'SITE_BACKUP_METADATA_OK target=' . $targetid .
        ' release=' . (string)get_config('moodle', 'release') .
        ' write=0'
    );
} catch (Throwable $error) {
    cli_error('SITE_BACKUP_METADATA_ERROR ' . $error->getMessage());
}
