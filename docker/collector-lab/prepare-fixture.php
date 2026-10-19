<?php

use App\Domain\Collector\LabFileSecretProvider;
use App\Domain\Collector\MoodleConfigurationMaterializer;

// Standalone PHP 8.3. Only the fixed synthetic profile is accepted here.
$base = dirname(__DIR__, 2);
require $base.'/app/Domain/Collector/Contracts/SecretProvider.php';
require $base.'/app/Domain/Collector/LabFileSecretProvider.php';
require $base.'/app/Domain/Collector/MoodleConfigurationMaterializer.php';
if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400 || PHP_OS_FAMILY !== 'Linux') {
    exit(2);
}
$root = '/tmp/toolkit-synthetic-fixture';
umask(0077);
$project = '00000000-0000-4000-8000-000000000001';
$execution = '00000000-0000-4000-8000-000000000002';
$input = $root.'/'.$project.'/'.$execution.'/input';
if (! is_dir($input)) {
    mkdir($input, 0700, true);
}
$profile = ['name' => 'Moodle sintético', 'root' => '/opt/moodle-lab', 'code' => '/opt/moodle-lab/code', 'data' => '/opt/moodle-lab/data',
    'base_url' => 'http://moodle-lab.test', 'db_host' => 'moodle-lab-db', 'db_port' => 5432,
    'db_name' => 'moodle_lab', 'db_user' => 'moodle_lab', 'db_prefix' => 'mdl_', 'credential_reference' => 'moodle-lab-db',
    'credential_version' => '1', 'source_id' => 'synthetic-lab', 'moodle_series' => '4.5'];
$materializer = new MoodleConfigurationMaterializer(new LabFileSecretProvider('/run/secrets/moodle-toolkit-lab'), $root);
try {
    $result = $materializer->consume($input.'/moodle-runtime.php', $profile, function (string $path) use ($base): int {
        $process = proc_open([PHP_BINARY, '-c', (string) php_ini_loaded_file(), $base.'/docker/collector-lab/seed-moodle.php', $path],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $base,
            ['LANG' => 'C.UTF-8', 'PATH' => '/usr/bin:/bin', 'PHP_INI_SCAN_DIR' => '/opt/collector-php/conf.d', 'PHPRC' => '/opt/collector-php/php.ini'], ['bypass_shell' => true]);
        if (! is_resource($process)) {
            return 1;
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $deadline = hrtime(true) + 600_000_000_000;
        do {
            foreach ($pipes as $pipe) {
                while (fread($pipe, 65536) !== '') {
                    // Discard raw Moodle output; only the closed diagnostic is retained.
                }
            }
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }
            if (hrtime(true) >= $deadline) {
                proc_terminate($process, SIGTERM);
                break;
            }
            usleep(100_000);
        } while (true);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exit = proc_close($process);

        return ! $status['running'] && $status['exitcode'] >= 0 ? $status['exitcode'] : ($exit >= 0 ? $exit : 1);
    });
    echo $result === 0 ? "SYNTHETIC_MOODLE_READY\n" : "SYNTHETIC_MOODLE_FAILED\n";
    exit($result);
} catch (Throwable) {
    fwrite(STDERR, "SYNTHETIC_MOODLE_PREPARATION_FAILED\n");
    exit(1);
}
