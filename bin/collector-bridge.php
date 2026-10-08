<?php

declare(strict_types=1);
use App\Domain\Collector\CollectorBridge;

// PHP 8.3 subprocess: deliberately independent of Laravel/vendor (PHP 8.4).
$root = dirname(__DIR__);
require $root.'/app/Domain/Collector/Contracts/SecretProvider.php';
require $root.'/app/Domain/Collector/LabFileSecretProvider.php';
require $root.'/app/Domain/Collector/MoodleConfigurationMaterializer.php';
require $root.'/app/Domain/Collector/CollectorBridge.php';

if (PHP_OS_FAMILY !== 'Linux' || PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400 || count($argv) !== 4) {
    fwrite(STDERR, "COLLECTOR_BRIDGE_INVALID_RUNTIME_OR_ARGUMENTS\n");
    exit(2);
}

$bridge = new CollectorBridge;
exit($bridge->run(
    (string) getenv('COLLECTOR_WORKSPACE_ROOT'), (string) getenv('COLLECTOR_REFERENCE_ROOT'),
    $argv[1], $argv[2], $argv[3], (string) getenv('MOODLE_OPERATION_ID'),
));
