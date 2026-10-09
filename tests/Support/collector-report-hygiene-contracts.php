<?php

use App\Domain\Collector\Contracts\SecretProvider;
use Symfony\Component\Process\Process;

require __DIR__.'/quality-bootstrap.php';
ini_set('zend.exception_ignore_args', '1');
$directory = '/tmp/collector-report-scan';
$names = ['collector-lab-phpunit.xml', 'collector-lab-playwright.xml'];
$ownedDirectory = null;
$clean = static function () use ($directory, $names, &$ownedDirectory): void {
    if ($ownedDirectory === null) {
        return;
    }
    clearstatcache(true, $directory);
    $stat = @lstat($directory);
    if ($stat === false) {
        return;
    }
    if (is_link($directory) || realpath($directory) !== $directory || ($stat['mode'] & 0170000) !== 0040000
        || ($stat['mode'] & 0777) !== 0700 || $stat['uid'] !== posix_geteuid()
        || $stat['dev'] !== $ownedDirectory['dev'] || $stat['ino'] !== $ownedDirectory['ino']) {
        throw new RuntimeException;
    }
    foreach ($names as $name) {
        $path = $directory.'/'.$name;
        if (@lstat($path) !== false && ! @unlink($path)) {
            throw new RuntimeException;
        }
    }
    if (! @rmdir($directory)) {
        throw new RuntimeException;
    }
};
try {
    if (count($argv) !== 1 || ! config('toolkit.features.recolector_742.enabled') || ! config('toolkit.features.local_runner.enabled')
        || ! config('collector.profiles.synthetic-moodle') || gethostname() !== 'moodle-tool-runner') {
        throw new RuntimeException;
    }
    $completed = 0;
    try {
        app(SecretProvider::class)->consume('moodle-lab-db', '1', static function (string $secret) use ($directory, $names, &$ownedDirectory, &$completed): void {
            foreach (['raw', 'xml', 'json', 'missing', 'symlink', 'oversized'] as $case) {
                clearstatcache(true, $directory);
                $ownedDirectory = null;
                if (@lstat($directory) !== false || ! @mkdir($directory, 0700)) {
                    throw new RuntimeException;
                }
                $ownedDirectory = @lstat($directory);
                if ($ownedDirectory === false || ($ownedDirectory['mode'] & 0777) !== 0700 || $ownedDirectory['uid'] !== posix_geteuid()) {
                    throw new RuntimeException;
                }
                $payload = '<testsuites><testsuite tests="0"/></testsuites>';
                if (in_array($case, ['raw', 'xml', 'json'], true)) {
                    $encoded = match ($case) {
                        'raw' => $secret,
                        'xml' => htmlspecialchars($secret, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8'),
                        'json' => htmlspecialchars(substr(json_encode($secret, JSON_THROW_ON_ERROR), 1, -1), ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8'),
                    };
                    $prefix = '<testsuites><testsuite><system-out>'.($case === 'json' ? '{"value":"' : '');
                    $suffix = ($case === 'json' ? '"}' : '').'</system-out></testsuite></testsuites>';
                    $offset = 65536 - max(1, intdiv(strlen($encoded), 2));
                    if ($offset < strlen($prefix) || $offset + strlen($encoded) <= 65536) {
                        throw new RuntimeException;
                    }
                    $payload = $prefix.str_repeat('x', $offset - strlen($prefix)).$encoded.$suffix;
                }
                foreach ($names as $index => $name) {
                    $content = $index === 0 ? $payload : '<testsuites><testsuite tests="0"/></testsuites>';
                    $path = $directory.'/'.$name;
                    if (@file_put_contents($path, $content) !== strlen($content) || ! @chmod($path, 0600)) {
                        throw new RuntimeException;
                    }
                }
                if ($case === 'missing' || $case === 'symlink') {
                    if (! @unlink($directory.'/'.$names[1])
                        || ($case === 'symlink' && ! @symlink($directory.'/'.$names[0], $directory.'/'.$names[1]))) {
                        throw new RuntimeException;
                    }
                } elseif ($case === 'oversized') {
                    $stream = @fopen($directory.'/'.$names[0], 'r+b');
                    if ($stream === false) {
                        throw new RuntimeException;
                    }
                    try {
                        if (fseek($stream, 16777216) !== 0 || fwrite($stream, 'x') !== 1 || ! fflush($stream)) {
                            throw new RuntimeException;
                        }
                    } finally {
                        fclose($stream);
                    }
                }
                // The actual scanner resolves the real reference itself. No
                // secret, report payload or private identity reaches argv/env.
                $process = new Process([PHP_BINARY, base_path('tests/Support/collector-resilience.php'), 'scan-reports'], base_path(), timeout: 30);
                if ($process->run() !== 1 || $process->getOutput() !== ''
                    || $process->getErrorOutput() !== "Collector resilience failed at REPORT_SCAN.\n") {
                    throw new RuntimeException;
                }
                clearstatcache(true, $directory);
                if (@lstat($directory) !== false) {
                    throw new RuntimeException;
                }
                $completed++;
            }
        });
    } finally {
        $clean();
    }
    if ($completed !== 6) {
        throw new RuntimeException;
    }
    echo json_encode(['schema_version' => 'collector-report-hygiene-contracts.v1', 'result' => 'PASSED',
        'method' => 'REAL_LAB_SECRET_SCANNER_NEGATIVE_CONTRACTS_V1', 'cases' => $completed,
        'private_staging_removed' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable) {
    // Never print subprocess output, actual material, filesystem paths or IDs.
    fwrite(STDERR, "Collector report hygiene contracts failed.\n");
    exit(1);
}
