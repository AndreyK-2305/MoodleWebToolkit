<?php
// Utilidades mínimas disponibles antes de cargar config.php de Moodle.

declare(strict_types=1);

/**
 * Lee una opción CLI en forma --nombre=valor sin depender aún de Moodle.
 */
function collector_bootstrap_option(
    string $name,
    ?string $default = null
): ?string {
    global $argv;

    $prefix = '--' . $name . '=';
    foreach ($argv ?? [] as $argument) {
        if (str_starts_with((string)$argument, $prefix)) {
            return substr((string)$argument, strlen($prefix));
        }
    }
    return $default;
}

/**
 * Resuelve config.php sin asumir una ruta fija del servidor.
 */
function collector_moodle_config_path(): string {
    $configured = trim((string)collector_bootstrap_option(
        'config',
        (string)(getenv('MOODLE_CONFIG_PATH') ?: '/var/www/html/config.php')
    ));
    if ($configured === '' || !is_readable($configured)) {
        throw new RuntimeException(
            'No se encontró config.php de Moodle. Use --config=/ruta/config.php.'
        );
    }
    return $configured;
}

