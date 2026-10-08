<?php

namespace App\Domain\Collector;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Standalone bridge. Raw child output and exceptions never leave this process. */
final class CollectorBridge
{
    private int $sequence = 0;

    private bool $cancelled = false;

    private string $operationUuid = '';

    public function run(string $root, string $referenceRoot, string $projectUuid, string $executionUuid, string $runtimeHash, string $operationUuid): int
    {
        $this->operationUuid = preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $operationUuid) === 1 ? $operationUuid : '';
        umask(0077);
        try {
            foreach ([$projectUuid, $executionUuid, $operationUuid] as $uuid) {
                if (preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $uuid) !== 1) {
                    throw new RuntimeException('Invalid identity.');
                }
            }
            if (! function_exists('posix_getpgrp') || posix_getpgrp() !== getmypid()) {
                throw new RuntimeException('The bridge requires its own process group.');
            }
            if (realpath($root) !== $root || preg_match('/^[a-f0-9]{64}$/D', $runtimeHash) !== 1) {
                throw new RuntimeException('Invalid scope.');
            }
            $workspace = $root.'/'.$projectUuid.'/'.$executionUuid;
            $this->normalPath($root, $workspace);
            $runtimePath = $workspace.'/state/collector-runtime.json';
            $content = $this->regularContents($runtimePath, 65536, true);
            if (! hash_equals($runtimeHash, hash('sha256', $content))) {
                throw new RuntimeException('Runtime changed.');
            }
            $runtime = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($runtime) || ($runtime['schema_version'] ?? null) !== 'collector-runtime.v1'
                || ($runtime['project_uuid'] ?? null) !== $projectUuid || ($runtime['execution_uuid'] ?? null) !== $executionUuid
                || ($runtime['collector_version'] ?? null) !== '7.4.2-linux' || ($runtime['scope'] ?? null) !== 'lab'
                || ($runtime['mode'] ?? null) !== 'LABORATORY' || ($runtime['notify_every'] ?? null) !== 0
                || ($runtime['reuse_backups'] ?? null) !== false || ($runtime['restart'] ?? null) !== false
                || ($runtime['reference_scope_sha256'] ?? null) !== hash('sha256', $referenceRoot)) {
                throw new RuntimeException('Unsupported runtime document.');
            }
            $profile = $runtime['profile'] ?? null;
            $settings = $runtime['settings'] ?? null;
            if (! is_array($profile) || ! is_array($settings) || ! is_int($settings['workers'] ?? null)
                || $settings['workers'] < 1 || $settings['workers'] > 4 || ! is_int($settings['capacity_bytes'] ?? null)
                || ! is_int($settings['safety_margin_percent'] ?? null) || $settings['capacity_bytes'] < 16777216
                || $settings['capacity_bytes'] > 21474836480 || $settings['safety_margin_percent'] < 10 || $settings['safety_margin_percent'] > 100) {
                throw new RuntimeException('Invalid settings.');
            }
            foreach (['root', 'code', 'data', 'base_url', 'db_host', 'db_name', 'db_user', 'db_prefix', 'credential_reference', 'credential_version', 'source_id', 'moodle_series', 'name'] as $key) {
                if (! is_string($profile[$key] ?? null) || $profile[$key] === '') {
                    throw new RuntimeException('Invalid profile.');
                }
            }
            if (! is_int($profile['db_port'] ?? null) || $profile['moodle_series'] !== '4.5'
                || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $profile['source_id']) !== 1) {
                throw new RuntimeException('Invalid source.');
            }
            $this->normalPath($profile['root'], $profile['code']);
            $this->normalPath($profile['root'], $profile['data']);
            $marker = json_decode($this->regularContents($profile['root'].'/.moodle-toolkit-synthetic-lab.json', 4096), true);
            if (! is_array($marker) || ($marker['schema_version'] ?? null) !== 'synthetic-moodle.v1') {
                throw new RuntimeException('Missing synthetic identity.');
            }
            $tool = $this->verifyDistribution($workspace, $runtime);
            foreach (['input', 'state', 'output', 'temporary'] as $area) {
                $this->normalPath($workspace, $workspace.'/'.$area);
            }
            if (iterator_count(new FilesystemIterator($workspace.'/output', FilesystemIterator::SKIP_DOTS)) !== 0
                || file_exists($workspace.'/state/collector-work')) {
                throw new RuntimeException('A new attempt requires an unused workspace.');
            }
            mkdir($workspace.'/state/collector-work', 0700);
            $quota = (int) ceil($settings['capacity_bytes'] * (100 + $settings['safety_margin_percent']) / 100);
            $provider = new LabFileSecretProvider($referenceRoot);
            $materializer = new MoodleConfigurationMaterializer($provider, $root);
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void {
                $this->cancelled = true;
            });
            pcntl_signal(SIGINT, function (): void {
                $this->cancelled = true;
            });
            /** @var array{name: string, root: string, code: string, data: string, base_url: string, db_host: string, db_port: int, db_name: string, db_user: string, db_prefix: string, credential_reference: string, credential_version: string, source_id: string, moodle_series: string} $profile */
            $result = $materializer->consume($workspace.'/input/moodle-runtime.php', $profile, function (string $config) use ($workspace, $tool, $profile, $settings, $quota): int {
                $this->emit('started');
                $work = $workspace.'/state/collector-work';
                $package = $workspace.'/output/source-package.zip';
                $args = [PHP_BINARY, '-c', (string) php_ini_loaded_file(), $tool.'/scripts/source-export.php',
                    '--config='.$config, '--sourceid='.$profile['source_id'], '--sourcename='.$profile['name'],
                    '--outputdir='.$work, '--outputzip='.$package, '--scope=lab', '--workers='.$settings['workers'],
                    '--workersrequested='.$settings['workers'], '--cputhreads='.$settings['workers'], '--autoworkerscap=4',
                    '--notifyevery=0', '--smtpconfig=', '--statusfile='.$work.'/status.json', '--progressfile='.$work.'/export-progress.json',
                    '--executionmode=foreground', '--logfile=', '--startedepoch='.time(), '--collectorversion=7.4.2-linux',
                    '--tempdir='.$workspace.'/temporary', '--reusebackups=', '--reuseonly=0', '--restart=0'];
                $exit = $this->child($args, $workspace, $quota, $work.'/export-progress.json');
                if ($exit !== 0 || $this->cancelled) {
                    return $this->cancelled ? 143 : 1;
                }
                $this->emit('snapshot', ['stage' => 'validation']);
                $reportPath = $work.'/package-audit.json';
                $audit = $this->child([PHP_BINARY, '-c', (string) php_ini_loaded_file(), $tool.'/scripts/validate-package.php',
                    '--zip='.$package, '--sidecar='.$package.'.sha256', '--report='.$reportPath], $workspace, $quota);
                $report = json_decode($this->regularContents($reportPath, 1048576), true, 64, JSON_THROW_ON_ERROR);
                if ($audit !== 0 || ! is_array($report) || ($report['result'] ?? null) !== 'ok' || ($report['failures'] ?? null) !== []) {
                    return 1;
                }
                $this->publishEvidence($workspace, $package, $report);
                $this->emit('package_validated');

                return 0;
            });
            if ($result !== 0) {
                $this->emit($this->cancelled ? 'cancelled' : 'error');
            }

            return $result;
        } catch (Throwable) {
            // Never print exception strings, child stdout/stderr or profile values.
            $this->emit($this->cancelled ? 'cancelled' : 'error');

            return $this->cancelled ? 143 : 1;
        }
    }

    /** @param array<string, mixed> $runtime */
    private function verifyDistribution(string $workspace, array $runtime): string
    {
        if (($runtime['tool_directory'] ?? null) !== 'moodle-recolector-742-linux-tree'
            || ($runtime['distribution_sha256'] ?? null) !== '4daaa16d278991f098b7f9c85361f7800193c4825e1f8cb6cb2c4b2870103c2e'
            || ($runtime['manifest_sha256'] ?? null) !== '55cc3bf9bbe8964bd3459f703e8a09c1a97e7d4f8867df3465cbfdd95ff00672'
            || ! is_array($runtime['manifest_files'] ?? null)) {
            throw new RuntimeException('Unapproved distribution.');
        }
        $tool = $workspace.'/tools/'.$runtime['tool_directory'];
        $this->normalPath($workspace, $tool);
        $files = $runtime['manifest_files'];
        $files['FILES.sha256'] = $runtime['manifest_sha256'];
        if (count($files) !== 29) {
            throw new RuntimeException('Invalid file manifest.');
        }
        $actual = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tool, FilesystemIterator::SKIP_DOTS)) as $entry) {
            $path = $entry->getPathname();
            $relative = substr($path, strlen($tool) + 1);
            $stat = lstat($path);
            if ($entry->isLink() || ! $entry->isFile() || $stat === false || $stat['nlink'] !== 1
                || ! is_string($files[$relative] ?? null) || ! hash_equals($files[$relative], (string) hash_file('sha256', $path))) {
                throw new RuntimeException('Deployment changed.');
            }
            $actual[] = $relative;
        }
        if (array_diff(array_keys($files), $actual) !== []) {
            throw new RuntimeException('Deployment incomplete.');
        }

        return $tool;
    }

    /** @param list<string> $argv */
    private function child(array $argv, string $workspace, int $quota, ?string $progressFile = null): int
    {
        $environment = ['LANG' => 'C.UTF-8', 'PATH' => '/usr/bin:/bin',
            'HOME' => $workspace.'/temporary', 'TMPDIR' => $workspace.'/temporary',
            'PHPRC' => (string) php_ini_loaded_file(),
            'PHP_INI_SCAN_DIR' => (string) getenv('PHP_INI_SCAN_DIR')];
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            $workspace, $environment, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to spawn collector.');
        }
        foreach ([1, 2] as $index) {
            stream_set_blocking($pipes[$index], false);
        }
        $exit = -1;
        $snapshot = '';
        $nextObservation = 0;
        try {
            do {
                // Drain bounded chunks continuously. None of this raw output is persisted.
                foreach ([1, 2] as $index) {
                    fread($pipes[$index], 32768);
                }
                $status = proc_get_status($process);
                if (hrtime(true) >= $nextObservation) {
                    $nextObservation = hrtime(true) + 1_000_000_000;
                    if ($this->usage($workspace) > $quota || (float) disk_free_space($workspace) < 1048576) {
                        $this->cancelled = true;
                        // Stop the whole verified operation group, including Moodle descendants.
                        posix_kill(-posix_getpgrp(), SIGTERM);
                    }
                    if ($progressFile !== null && is_file($progressFile) && ! is_link($progressFile)) {
                        $progress = json_decode($this->regularContents($progressFile, 65536), true);
                        if (is_array($progress)) {
                            $closed = array_intersect_key($progress, array_flip(['stage', 'total_courses', 'completed_courses', 'failed_courses']));
                            if (! in_array($closed['stage'] ?? null, ['identities', 'source-inventory', 'plugins', 'existing-backups-index', 'course-backups', 'sealing', 'sealed'], true)) {
                                $closed = [];
                            } else {
                                foreach (['total_courses', 'completed_courses', 'failed_courses'] as $key) {
                                    if (! is_int($closed[$key] ?? null) || $closed[$key] < 0 || $closed[$key] > 1000000) {
                                        unset($closed[$key]);
                                    }
                                }
                            }
                            $fingerprint = (string) json_encode($closed);
                            if ($closed !== [] && $fingerprint !== $snapshot) {
                                $this->emit('snapshot', $closed);
                                $snapshot = $fingerprint;
                            }
                        }
                    }
                }
                if (! $status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                if ($this->cancelled) {
                    proc_terminate($process, SIGTERM);
                }
                usleep(20000);
            } while (true);
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                // Preserve material until descendants have stopped; the runner reconciles the group.
                posix_kill(-posix_getpgrp(), SIGTERM);
                proc_terminate($process, SIGTERM);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($process);
        }

        return $exit >= 0 ? $exit : $closed;
    }

    /** @param array<string, mixed> $report */
    private function publishEvidence(string $workspace, string $package, array $report): void
    {
        $zip = new ZipArchive;
        if ($zip->open($package, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Unable to inspect package.');
        }
        try {
            foreach (['manifest.json' => 'manifest.json', 'inventario-origen.json' => 'inventory.json'] as $entry => $name) {
                $value = $zip->getFromName($entry, 1048577);
                if (! is_string($value) || strlen($value) > 1048576 || ! is_array(json_decode($value, true))) {
                    throw new RuntimeException('Missing inventory evidence.');
                }
                $this->write($workspace.'/output/'.$name, $value);
            }
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (! is_array($manifest) || ($manifest['collector_version'] ?? null) !== '7.4.2-linux') {
                throw new RuntimeException('Unsupported producer.');
            }
            $inventory = json_decode((string) $zip->getFromName('inventario-origen.json'), true);
            if (! is_array($inventory)) {
                throw new RuntimeException('Missing visual inventory.');
            }
            $this->write($workspace.'/output/visual-inventory.json', json_encode([
                'schema_version' => 'collector-visual-inventory.v1',
                'themes' => $inventory['themes'] ?? null, 'theme_assignments' => $inventory['theme_assignments'] ?? null,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            $evidence = ['schema_version' => 'collector-package-audit.v1', 'result' => 'VALID',
                'package_sha256' => hash_file('sha256', $package), 'package_bytes' => filesize($package),
                'manifest_sha256' => hash('sha256', (string) $zip->getFromName('manifest.json')),
                'validator_version' => '7.4.2-linux', 'operation_uuid' => $this->operationUuid,
                'counts' => array_filter(is_array($report['counts'] ?? null) ? $report['counts'] : [], 'is_int'),
                'warnings_count' => count(is_array($report['warnings'] ?? null) ? $report['warnings'] : []),
                'source_write' => false, 'destination_write' => false];
            $this->write($workspace.'/output/validation.json', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } finally {
            $zip->close();
        }
    }

    private function write(string $path, string $value): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Evidence already exists.');
        }
        try {
            if (fwrite($handle, $value) !== strlen($value) || ! fflush($handle) || ! chmod($path, 0600)) {
                throw new RuntimeException('Evidence write failed.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function regularContents(string $path, int $maximum, bool $private = false): string
    {
        $stat = @lstat($path);
        if ($stat === false || is_link($path) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || $stat['size'] > $maximum || ($private && ($stat['mode'] & 0777) !== 0600)) {
            throw new RuntimeException('Invalid private evidence.');
        }
        $content = file_get_contents($path);
        if (! is_string($content) || strlen($content) > $maximum) {
            throw new RuntimeException('Unable to read evidence.');
        }

        return $content;
    }

    private function normalPath(string $root, string $path): void
    {
        if (realpath($root) !== $root || ! str_starts_with($path.'/', $root.'/') || realpath($path) !== $path || ! is_dir($path)) {
            throw new RuntimeException('Invalid directory scope.');
        }
        $cursor = $root;
        foreach (explode('/', substr($path, strlen($root) + 1)) as $segment) {
            $cursor .= '/'.$segment;
            if (is_link($cursor)) {
                throw new RuntimeException('Linked scope.');
            }
        }
    }

    private function usage(string $workspace): int
    {
        $bytes = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                throw new RuntimeException('Unsafe workspace content.');
            }
            $bytes += $file->getSize();
        }

        return $bytes;
    }

    /** @param array<string, mixed> $payload */
    private function emit(string $type, array $payload = []): void
    {
        echo json_encode(['schema_version' => 'collector-event.v1', 'operation_uuid' => $this->operationUuid,
            'sequence' => ++$this->sequence, 'type' => $type, ...$payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        fflush(STDOUT);
    }
}
