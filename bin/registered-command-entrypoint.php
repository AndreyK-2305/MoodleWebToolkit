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
pcntl_exec($argv[1], array_slice($argv, 2));
exit(126);
