<?php

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Collector\CollectorExecutionPreparation;
use App\Domain\Collector\CollectorWorkflow;
use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Executions\LocalProcessInspector;
use App\Domain\Executions\RemoteOperationCoordinator;
use App\Domain\Executions\StartProjectExecution;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Tools\SourcePackageRegistry;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\ExecutionStatus;
use App\Enums\UserRole;
use App\Models\Execution;
use App\Models\SourcePackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/quality-bootstrap.php';
ini_set('zend.exception_ignore_args', '1');
$stage = 'GUARD';
try {
    if (! config('toolkit.features.recolector_742.enabled') || ! config('toolkit.features.local_runner.enabled')
        || ! config('collector.profiles.synthetic-moodle') || gethostname() !== 'moodle-tool-runner') {
        throw new RuntimeException;
    }
    $action = $argv[1] ?? '';
    $inspector = app(LocalProcessInspector::class);
    if ($action === 'scan-reports') {
        $stage = 'REPORT_SCAN';
        if (count($argv) !== 2) {
            throw new RuntimeException;
        }
        $directory = '/tmp/collector-report-scan';
        $names = ['collector-lab-phpunit.xml', 'collector-lab-playwright.xml'];
        $identity = static fn (array $stat): array => array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime']));
        $directoryStat = @lstat($directory);
        if ($directoryStat === false || is_link($directory) || realpath($directory) !== $directory
            || ($directoryStat['mode'] & 0170000) !== 0040000 || ($directoryStat['mode'] & 0777) !== 0700
            || $directoryStat['uid'] !== posix_geteuid()) {
            throw new RuntimeException;
        }
        $bytes = 0;
        try {
            $entries = @scandir($directory);
            if ($entries === false || array_values(array_diff($entries, ['.', '..'], $names)) !== []
                || array_diff($names, $entries) !== []) {
                throw new RuntimeException;
            }
            app(SecretProvider::class)->consume('moodle-lab-db', '1', static function (string $secret) use ($directory, $names, $identity, &$bytes): void {
                $representations = [$secret,
                    substr(json_encode($secret, JSON_THROW_ON_ERROR), 1, -1),
                    substr(json_encode($secret, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 1, -1)];
                $needles = $representations;
                foreach ($representations as $representation) {
                    $needles[] = htmlspecialchars($representation, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
                    $needles[] = htmlspecialchars($representation, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                $needles = array_values(array_unique($needles));
                $overlap = max(1, max(array_map('strlen', $needles)) - 1);
                foreach ($names as $name) {
                    $path = $directory.'/'.$name;
                    $stat = @lstat($path);
                    if ($stat === false || is_link($path) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
                        || $stat['size'] < 1 || $stat['size'] > 16777216) {
                        throw new RuntimeException;
                    }
                    $stream = @fopen($path, 'rb');
                    if ($stream === false) {
                        throw new RuntimeException;
                    }
                    try {
                        $opened = fstat($stream);
                        if ($opened === false || $identity($opened) !== $identity($stat)) {
                            throw new RuntimeException;
                        }
                        $tail = '';
                        $read = 0;
                        while (! feof($stream)) {
                            $chunk = @fread($stream, 65536);
                            if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                                throw new RuntimeException;
                            }
                            $window = $tail.$chunk;
                            foreach ($needles as $needle) {
                                if (str_contains($window, $needle)) {
                                    throw new RuntimeException;
                                }
                            }
                            $read += strlen($chunk);
                            if ($read > 16777216) {
                                throw new RuntimeException;
                            }
                            $tail = substr($window, -$overlap);
                        }
                        $closed = fstat($stream);
                        clearstatcache(true, $path);
                        $current = @lstat($path);
                        if ($closed === false || $current === false || $read !== $stat['size']
                            || $identity($closed) !== $identity($stat) || $identity($current) !== $identity($stat)) {
                            throw new RuntimeException;
                        }
                        $bytes += $read;
                    } finally {
                        fclose($stream);
                    }
                }
            });
        } finally {
            clearstatcache(true, $directory);
            $currentDirectory = @lstat($directory);
            if ($currentDirectory === false || is_link($directory) || realpath($directory) !== $directory
                || $currentDirectory['dev'] !== $directoryStat['dev'] || $currentDirectory['ino'] !== $directoryStat['ino']
                || ($currentDirectory['mode'] & 0170000) !== 0040000 || ($currentDirectory['mode'] & 0777) !== 0700
                || $currentDirectory['uid'] !== posix_geteuid()) {
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
        }
        $result = ['schema_version' => 'collector-report-hygiene.v1', 'result' => 'PASSED',
            'method' => 'LAB_REAL_SECRET_RAW_XML_JSON_STREAMING_64K_WITH_OVERLAP', 'scope' => 'LAB_PHPUNIT_AND_PLAYWRIGHT_JUNIT',
            'reports_scanned' => count($names), 'bytes_scanned' => $bytes, 'private_staging_removed' => true];
    } elseif ($action === 'prepare') {
        $stage = 'PREPARE';
        $actor = User::factory()->create(['role' => UserRole::ADMIN]);
        $wizard = app(ProjectWizard::class);
        $project = $wizard->create($actor, ['name' => 'Collector restart proof', 'type' => 'COLLECT']);
        $project = app(CollectorConfiguration::class)->save($project, $actor, ['profile_id' => 'synthetic-moodle', 'workers' => 1,
            'package_name' => 'resilience-proof', 'capacity_bytes' => 268435456, 'safety_margin_percent' => 20]);
        $checks = $wizard->runPreflight($project, $actor);
        if (collect($checks)->contains(fn (array $check): bool => $check['result'] === 'ERROR')) {
            throw new RuntimeException;
        }
        $warnings = collect($checks)->where('result', 'WARNING')->pluck('id')->all();
        $project = $wizard->confirm($project, $actor, $project->fresh()->configuration->version, $warnings);
        $execution = app(StartProjectExecution::class)->start($project, $actor, 'resilience-'.Str::uuid(), $project->configuration->version)->execution;
        $deadline = microtime(true) + 30;
        do {
            $operation = $execution->remoteOperations()->first();
            if ($operation !== null && $inspector->isRunning($operation)) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        $stage = 'HOLD';
        if ($operation === null || ! $inspector->isRunning($operation)
            || ! posix_kill(-(int) $operation->process_group_id, SIGSTOP)) {
            throw new RuntimeException;
        }
        // Fault infrastructure holds the registered group only long enough to
        // restart services. This does not expose or advertise a pause capability.
        $marker = ['execution_uuid' => $execution->uuid, 'operation_uuid' => $operation->operation_uuid,
            'process_group_id' => $operation->process_group_id, 'process_start_identity' => $operation->process_start_identity];
        $path = '/tmp/collector-resilience-'.$execution->uuid.'.json';
        if (file_put_contents($path, json_encode($marker, JSON_THROW_ON_ERROR)) === false || ! chmod($path, 0600)) {
            posix_kill(-(int) $operation->process_group_id, SIGCONT);
            throw new RuntimeException;
        }
        $result = ['schema_version' => 'collector-resilience.v1', 'phase' => 'HELD',
            'execution_uuid' => $execution->uuid, 'operation_uuid' => $operation->operation_uuid];
    } elseif (in_array($action, ['continue', 'release'], true)) {
        $uuid = $argv[2] ?? '';
        if (preg_match('/^[a-f0-9-]{36}$/D', $uuid) !== 1) {
            throw new RuntimeException;
        }
        $execution = Execution::query()->where('uuid', $uuid)->sole();
        if ($execution->toolBinding?->adapter_key !== CollectorExecutionPreparation::ADAPTER_KEY) {
            throw new RuntimeException;
        }
        $operation = $execution->remoteOperations()->sole();
        $marker = json_decode(file_get_contents('/tmp/collector-resilience-'.$uuid.'.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($marker['operation_uuid'] !== $operation->operation_uuid || $marker['process_group_id'] !== $operation->process_group_id
            || $marker['process_start_identity'] !== $operation->process_start_identity) {
            throw new RuntimeException;
        }
        $stage = 'RELEASE';
        $alive = $inspector->isRunning($operation);
        if ($alive && ! posix_kill(-(int) $operation->process_group_id, SIGCONT)) {
            throw new RuntimeException;
        }
        if ($action === 'release') {
            $result = ['phase' => 'RELEASED', 'registered_group_alive' => $alive];
        } else {
            if (! $alive) {
                throw new RuntimeException;
            }
            $stage = 'OBSERVE';
            $deadline = microtime(true) + 120;
            do {
                app(CollectorWorkflow::class)->observe($operation->fresh());
                usleep(200000);
            } while ($execution->fresh()->status !== ExecutionStatus::REVIEW
                && ! $execution->fresh()->status->isTerminal() && microtime(true) < $deadline);
            $execution->refresh();
            $operation->refresh();
            if ($execution->status !== ExecutionStatus::REVIEW || $execution->progress !== null
                || $execution->remoteOperations()->count() !== 1 || $execution->artifacts()->count() !== 6
                || $execution->checkpoints()->count() !== 0
                || ! app(RemoteOperationCoordinator::class)->verifyTerminalEvidence($operation)
                || $inspector->hasActiveOperation($operation)) {
                throw new RuntimeException;
            }
            $package = SourcePackage::query()->where('producer_execution_id', $execution->id)->where('project_id', $execution->project_id)->sole();
            app(SourcePackageRegistry::class)->validate($package);
            $sequences = $execution->events()->orderBy('sequence')->pluck('sequence')->all();
            if ($sequences !== range(1, count($sequences)) || $package->validation_state !== 'VALID'
                || $package->producer_tool_version !== '7.4.2-linux') {
                throw new RuntimeException;
            }
            $stage = 'PRIVACY';
            $paths = [];
            $root = dirname(app(ExecutionWorkspaceManager::class)->resolve($execution, 'state'));
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isLink() || ! $file->isFile()) {
                    throw new RuntimeException;
                }
                $paths[] = $file->getPathname();
            }
            foreach ($execution->artifacts as $artifact) {
                $paths[] = Storage::disk($artifact->disk)->path($artifact->path);
            }
            $tables = Schema::getTableListing();
            app(SecretProvider::class)->consume('moodle-lab-db', '1', static function (string $secret) use ($paths, $tables): void {
                foreach ($tables as $table) {
                    $rows = DB::table($table)->limit(10001)->get();
                    $json = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    if ($rows->count() > 10000 || strlen($json) > 16777216 || str_contains($json, $secret)) {
                        throw new RuntimeException;
                    }
                }
                foreach ($paths as $path) {
                    $stream = fopen($path, 'rb');
                    if ($stream === false) {
                        throw new RuntimeException;
                    }
                    try {
                        $tail = '';
                        while (! feof($stream)) {
                            $chunk = fread($stream, 65536);
                            if ($chunk === false || str_contains($tail.$chunk, $secret)) {
                                throw new RuntimeException;
                            }
                            $tail = substr($tail.$chunk, -max(1, strlen($secret) - 1));
                        }
                    } finally {
                        fclose($stream);
                    }
                }
            });
            if (is_file(app(ExecutionWorkspaceManager::class)->resolve($execution, 'input', 'moodle-runtime.php'))) {
                throw new RuntimeException;
            }
            $result = ['schema_version' => 'collector-resilience.v1', 'result' => 'PASSED',
                'execution_uuid' => $execution->uuid, 'operation_uuid' => $operation->operation_uuid,
                'same_registered_group_after_restart' => true, 'remote_operations' => 1, 'artifacts' => 6,
                'checkpoints' => 0, 'progress' => null, 'source_sha256' => $package->sha256,
                'events' => count($sequences), 'database_tables_scanned' => count($tables),
                'files_scanned' => count($paths), 'private_configuration_removed' => true, 'secret_hygiene' => 'PASSED'];
        }
    } else {
        throw new RuntimeException;
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable) {
    // No exception arguments, private paths, SQL bindings or provider value in output.
    fwrite(STDERR, 'Collector resilience failed at '.$stage.".\n");
    exit(1);
}
