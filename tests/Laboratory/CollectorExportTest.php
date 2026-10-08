<?php

namespace Tests\Laboratory;

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Collector\CollectorRuntimeConfiguration;
use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Collector\LabMoodleProfiles;
use App\Domain\Collector\SyntheticMoodleProbe;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Tools\DeployToolDistribution;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\UserRole;
use App\Models\ToolDistribution;
use Database\Seeders\ToolCatalogSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Domain\DomainTestCase;
use ZipArchive;

/** Explicit LAB suite; ordinary Feature tests keep both real-tool flags disabled. */
class CollectorExportTest extends DomainTestCase
{
    public function test_copied_collector_exports_two_synthetic_courses_and_removes_private_configuration(): void
    {
        $this->assertTrue((bool) config('toolkit.features.recolector_742.enabled'));
        $this->assertTrue((bool) config('toolkit.features.local_runner.enabled'));
        $profile = app(LabMoodleProfiles::class)->get('synthetic-moodle');
        $observation = app(SyntheticMoodleProbe::class)->inspect($profile);
        $this->assertSame(2, $observation['courses']);
        $this->assertSame(5, $observation['users']);
        $this->assertSame(1, $observation['oauth']);
        $root = sys_get_temp_dir().'/collector-positive-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config(['toolkit.workspaces.root' => $root]);
        try {
            $this->seed(ToolCatalogSeeder::class);
            $actor = $this->user(UserRole::ADMIN);
            $project = app(ProjectWizard::class)->create($actor, ['name' => 'Synthetic collector proof', 'type' => 'COLLECT']);
            $project = app(CollectorConfiguration::class)->save($project, $actor, ['profile_id' => 'synthetic-moodle', 'workers' => 1,
                'package_name' => 'synthetic-proof', 'capacity_bytes' => 268435456, 'safety_margin_percent' => 20]);
            $execution = $this->execution($project);
            app(ApproveExecutionCapacity::class)->approve($execution, 268435456, 20, $actor);
            $distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->sole();
            $runtime = app(CollectorRuntimeConfiguration::class)->approve($execution, $project->configuration, $distribution, $actor);
            $deployment = app(DeployToolDistribution::class)->deploy($execution, $distribution);
            $this->assertStringStartsWith($root.'/', $deployment['path']);
            $this->assertStringNotContainsString('/BaseLine/', $deployment['path']);
            $operation = (string) Str::uuid();
            $process = new Process(['/usr/bin/setsid', (string) config('collector.php_binary'), '-c', (string) config('collector.php_ini'),
                base_path('bin/collector-bridge.php'), $project->uuid, $execution->uuid, $runtime->content_sha256], base_path(), [
                    'PHP_INI_SCAN_DIR' => (string) config('collector.php_scan_dir'), 'COLLECTOR_WORKSPACE_ROOT' => $root,
                    'COLLECTOR_REFERENCE_ROOT' => (string) config('collector.secret_root'), 'MOODLE_OPERATION_ID' => $operation,
                ], timeout: 180);
            $this->assertSame(0, $process->run(), 'The copied collector must export and validate the synthetic fixture.');
            $this->assertSame('', $process->getErrorOutput());
            $signals = array_map(fn (string $line): array => json_decode($line, true, 16, JSON_THROW_ON_ERROR),
                explode("\n", trim($process->getOutput())));
            $this->assertSame('started', $signals[0]['type']);
            $this->assertSame('package_validated', $signals[array_key_last($signals)]['type']);
            foreach ($signals as $index => $signal) {
                $this->assertSame($operation, $signal['operation_uuid']);
                $this->assertSame($index + 1, $signal['sequence']);
            }
            $workspaces = app(ExecutionWorkspaceManager::class);
            $this->assertFileDoesNotExist($workspaces->resolve($execution, 'input', 'moodle-runtime.php'));
            $output = $workspaces->resolve($execution, 'output');
            $this->assertCount(6, glob($output.'/*'));
            $manifest = json_decode(file_get_contents($output.'/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame('7.4.2-linux', $manifest['collector_version']);
            $this->assertSame('1.0', $manifest['capabilities']['theme_inventory']);
            $this->assertFalse($manifest['source_write_performed']);
            $this->assertFalse($manifest['destination_write_performed']);
            $audit = json_decode(file_get_contents($output.'/validation.json'), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame('VALID', $audit['result']);
            $this->assertSame(hash_file('sha256', $output.'/source-package.zip'), $audit['package_sha256']);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($output.'/source-package.zip', ZipArchive::RDONLY));
            $mbz = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                if (str_ends_with($zip->getNameIndex($index), '.mbz')) {
                    $mbz++;
                }
            }
            $zip->close();
            $this->assertSame(2, $mbz);
            app(SecretProvider::class)->consume('moodle-lab-db', '1', function (string $secret) use ($process, $output): void {
                $this->assertStringNotContainsString($secret, $process->getOutput().$process->getErrorOutput());
                foreach (glob($output.'/*.json') as $path) {
                    $this->assertStringNotContainsString($secret, file_get_contents($path));
                }
            });
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
                if ($file->isDir()) {
                    chmod($file->getPathname(), 0700);
                }
            }
            $this->assertTrue(File::deleteDirectory($root));
        }
    }
}
