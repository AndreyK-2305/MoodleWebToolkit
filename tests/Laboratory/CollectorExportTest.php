<?php

namespace Tests\Laboratory;

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Collector\CollectorPackageInspector;
use App\Domain\Collector\CollectorRuntimeConfiguration;
use App\Domain\Collector\CollectorSourceEvidence;
use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Collector\LabMoodleProfiles;
use App\Domain\Collector\SyntheticMoodleProbe;
use App\Domain\Collector\TestingSecretProvider;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Tools\DeployToolDistribution;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\UserRole;
use App\Exceptions\ToolOperationBlocked;
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
            $this->assertTrue($process->getErrorOutput() === '', 'The bridge must not produce stderr.');
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
            $this->assertSame($runtime->content_sha256, $audit['runtime_sha256']);
            $this->assertArrayNotHasKey('source_write', $audit);
            $this->assertArrayNotHasKey('destination_write', $audit);
            (new CollectorSourceEvidence)->validate($audit['source_access']);
            $this->assertSame('PROHIBITED_AND_ENFORCED', $audit['source_access']['source_code_write']);
            $this->assertSame('VERIFIED_UNCHANGED', $audit['source_access']['code']['result']);
            $this->assertSame('READ_ONLY_MOUNT_OBSERVED_ALL_ENTRIES_AND_EFFECTIVE_WRITE_ACCESS_DENIED', $audit['source_access']['code']['enforcement']);
            $this->assertSame($audit['source_access']['code']['before_sha256'], $audit['source_access']['code']['after_sha256']);
            $this->assertSame('TEMPORARY_ALLOWED', $audit['source_access']['source_data_write']);
            $this->assertSame('NOT_VERIFIED', $audit['source_access']['data']['cleanup']);
            $this->assertSame('NOT_VERIFIED', $audit['source_access']['source_database_mutation']);
            $this->assertSame('NOT_APPLICABLE', $audit['source_access']['destination_write']);
            $this->assertSame('DECLARED_NOT_VERIFIED', $audit['producer_write_declarations']['source_write_performed']);
            foreach ([$profile['root'], $profile['code'], $profile['data'], $profile['credential_reference']] as $private) {
                $this->assertFalse(str_contains(json_encode($audit, JSON_THROW_ON_ERROR), $private), 'Source evidence exposed private scope metadata.');
            }
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
            $inspector = app(CollectorPackageInspector::class);
            $package = $output.'/source-package.zip';
            $verified = $inspector->inspect($execution, $distribution, $package, hash_file('sha256', $package), filesize($package));
            $this->assertSame('7.4.2-linux', $verified['producer_version']);
            $this->assertSame('VALID', $verified['result']);
            $this->assertSame($execution->uuid, $verified['execution_uuid']);
            $this->assertSame($project->uuid, $verified['project_uuid']);
            $this->assertSame(2, $verified['counts']['courses_cross_checked']);
            $this->assertSame([], glob($workspaces->resolve($execution, 'temporary').'/package-audit-*'));
            $legacy = $workspaces->resolve($execution, 'temporary', 'legacy.zip');
            copy($package, $legacy);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($legacy));
            $legacyManifest = $manifest;
            $legacyManifest['collector_version'] = '7.4.1-linux';
            unset($legacyManifest['capabilities']);
            $legacyBytes = json_encode($legacyManifest, JSON_THROW_ON_ERROR);
            $checksums = $zip->getFromName('checksums.sha256');
            $checksums = preg_replace('/^[a-f0-9]{64}  manifest\.json$/m', hash('sha256', $legacyBytes).'  manifest.json', $checksums);
            $zip->addFromString('manifest.json', $legacyBytes);
            $zip->addFromString('checksums.sha256', $checksums);
            $zip->close();
            $legacyAudit = $inspector->inspect($execution, $distribution, $legacy, hash_file('sha256', $legacy), filesize($legacy));
            $this->assertSame('7.4.1-linux', $legacyAudit['producer_version']);
            $this->assertSame('LEGACY', $legacyAudit['metadata_state']);
            $this->assertSame(2, $legacyAudit['counts']['courses_cross_checked']);
            foreach (['hash', 'mbz', 'link', 'traversal', 'producer'] as $fault) {
                $altered = $workspaces->resolve($execution, 'temporary', 'altered-'.$fault.'.zip');
                copy($package, $altered);
                $hash = hash_file('sha256', $altered);
                $bytes = filesize($altered);
                if ($fault === 'link') {
                    link($altered, $altered.'.linked');
                } elseif ($fault !== 'hash') {
                    $zip = new ZipArchive;
                    $this->assertTrue($zip->open($altered));
                    if ($fault === 'mbz') {
                        $zip->addFromString($manifest['entries'][0]['backup_file'], 'damaged backup');
                    } elseif ($fault === 'traversal') {
                        $zip->addFromString('../outside.txt', 'untrusted');
                    } else {
                        $zip->addFromString('manifest.json', json_encode([...$manifest, 'collector_version' => '9.0-unknown']));
                    }
                    $zip->close();
                    $hash = hash_file('sha256', $altered);
                    $bytes = filesize($altered);
                }
                try {
                    $inspector->inspect($execution, $distribution, $altered, $fault === 'hash' ? str_repeat('0', 64) : $hash, $bytes);
                    $this->fail('Altered packages must not be accepted.');
                } catch (ToolOperationBlocked) {
                    $this->assertSame(0, $execution->artifacts()->count());
                }
            }
            $actualSecrets = app(SecretProvider::class);
            $testingValue = 'synthetic-only-'.bin2hex(random_bytes(16));
            app()->instance(SecretProvider::class, new TestingSecretProvider(['moodle-lab-db' => ['1' => $testingValue]]));
            try {
                $leak = $workspaces->resolve($execution, 'temporary', 'private-material.zip');
                copy($package, $leak);
                $zip = new ZipArchive;
                $this->assertTrue($zip->open($leak));
                $zip->addFromString('private-material.txt', str_repeat('x', 65530).$testingValue);
                $zip->close();
                try {
                    app(CollectorPackageInspector::class)->inspect($execution, $distribution, $leak, hash_file('sha256', $leak), filesize($leak));
                    $this->fail('Private material split across read boundaries must be rejected.');
                } catch (ToolOperationBlocked $error) {
                    $this->assertFalse(str_contains($error->getMessage(), $testingValue), 'The rejection exposed private material.');
                    $this->assertTrue($error->getPrevious() !== null && str_contains($error->getPrevious()->getMessage(), 'contenido privado'), 'Private content must produce the expected rejection.');
                }
            } finally {
                app()->instance(SecretProvider::class, $actualSecrets);
            }
            app(SecretProvider::class)->consume('moodle-lab-db', '1', function (string $secret) use ($process, $output): void {
                $this->assertFalse(str_contains($process->getOutput().$process->getErrorOutput(), $secret), 'Private material reached process output.');
                foreach (glob($output.'/*.json') as $path) {
                    $this->assertFalse(str_contains(file_get_contents($path), $secret), 'Private material reached output metadata.');
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
