<?php

// Standalone: the Collector PHP runtime must not load Laravel's PHP 8.4 dependencies.
echo json_encode([
    'schema_version' => 'collector-php-runtime.v1',
    'php_version_id' => PHP_VERSION_ID,
    'os_family' => PHP_OS_FAMILY,
    'extensions' => array_fill_keys(array_values(array_filter(
        ['dom', 'zip', 'pgsql', 'gd', 'intl', 'mbstring', 'pcntl', 'posix', 'curl', 'xml', 'xmlreader', 'xmlwriter'],
        extension_loaded(...),
    )), true),
], JSON_THROW_ON_ERROR);
