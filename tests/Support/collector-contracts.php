<?php

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Tools\DeployToolDistribution;
use App\Domain\Tools\ToolDistributionVerifier;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Enums\UserRole;
use App\Models\Execution;
use App\Models\ToolDistribution;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

require __DIR__.'/quality-bootstrap.php';
if (! config('toolkit.features.recolector_742.enabled') || ! config('collector.profiles.synthetic-moodle')) {
    exit(2);
}
$actor = User::factory()->create(['role' => UserRole::ADMIN]);
$project = app(ProjectWizard::class)->create($actor, ['name' => 'Copied collector contracts', 'type' => 'COLLECT']);
$project = app(CollectorConfiguration::class)->save($project, $actor, ['profile_id' => 'synthetic-moodle', 'workers' => 1,
    'package_name' => 'collector-contracts', 'capacity_bytes' => 268435456, 'safety_margin_percent' => 20]);
$execution = Execution::query()->create(['project_id' => $project->id, 'attempt' => 1, 'status' => 'QUEUED', 'created_by' => $actor->id]);
app(ApproveExecutionCapacity::class)->approve($execution, 268435456, 20, $actor);
$distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->sole();
$deployment = app(DeployToolDistribution::class)->deploy($execution, $distribution);
$process = new Process(['/bin/bash', 'tests/verify-package.sh'], $deployment['path'], [
    'PATH' => '/opt/collector-php/bin:/usr/bin:/bin', 'PHPRC' => '/opt/collector-php/php.ini', 'PHP_INI_SCAN_DIR' => '/opt/collector-php/conf.d',
], timeout: 90);
try {
    if ($process->run() !== 0) {
        throw new RuntimeException('Copied collector contracts failed.');
    }
    // Check the copied tree again; contract tests must not mutate distribution bytes.
    app(ToolDistributionVerifier::class)->verify($distribution);
    $rows = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($deployment['path'], FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink() || ! $file->isFile()) {
            throw new RuntimeException('Copied distribution changed.');
        }
        $rows[substr($file->getPathname(), strlen($deployment['path']) + 1)] = hash_file('sha256', $file->getPathname());
    }
    ksort($rows, SORT_STRING);
    $canonical = implode("\n", array_map(fn ($path, $hash) => $path."\0".$hash, array_keys($rows), $rows));
    if (! hash_equals($distribution->distribution_sha256, hash('sha256', $canonical))) {
        throw new RuntimeException('Copied distribution changed.');
    }
    echo json_encode(['schema_version' => 'collector-contract-validation.v1', 'result' => 'PASSED',
        'distribution_sha256' => $distribution->distribution_sha256, 'file_count' => count($rows),
        'contracts' => ['verify-package', 'theme-contracts', 'resume-theme-metadata', 'static-regressions'],
        'php_series' => '8.3', 'executed_from_verified_copy' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $workspace = dirname(dirname($deployment['path']));
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        if ($file->isDir() && ! $file->isLink()) {
            chmod($file->getPathname(), 0700);
        }
    }
    File::deleteDirectory($workspace);
}
