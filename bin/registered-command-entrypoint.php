<?php

// Fixed, administrator-owned entrypoint. The parent opens the gate only after
// recording this process identity; exec preserves the PID and process group.
if (PHP_OS_FAMILY === 'Windows' || ! function_exists('pcntl_exec') || count($argv) < 2) {
    exit(126);
}
$ready = fopen('php://fd/3', 'w');
if ($ready === false || fwrite($ready, getmypid()."\n") === false) {
    exit(126);
}
fclose($ready);
stream_set_timeout(STDIN, 10);
if (fread(STDIN, 1) !== '1') {
    exit(126);
}
fclose(STDIN);
$targetScan = getenv('REGISTERED_TARGET_PHP_INI_SCAN_DIR');
if (is_string($targetScan) && $targetScan !== '') {
    // Apply only after PHP has booted: the gate uses the application's runtime,
    // while a registered target may use an isolated PHP CLI with another ABI.
    putenv('PHP_INI_SCAN_DIR='.$targetScan);
}
pcntl_exec($argv[1], array_slice($argv, 2));
exit(126);
