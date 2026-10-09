<?php

namespace Tests\Feature\Tools;

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Collector\CollectorRegisteredCommand;
use App\Domain\Collector\CollectorRuntimeConfiguration;
use App\Domain\Processes\RegisteredCommandRegistry;
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
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Tests\Feature\Domain\DomainTestCase;

class CollectorBridgeTest extends DomainTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/collector-bridge-'.bin2hex(random_bytes(8));
        foreach (['code', 'data', 'workspaces', 'references'] as $directory) {
            mkdir($this->root.'/'.$directory, 0700, true);
        }
        config(['toolkit.workspaces.root' => $this->root.'/workspaces', 'collector.secret_root' => $this->root.'/references',
            'toolkit.features.recolector_742.enabled' => true, 'toolkit.features.local_runner.enabled' => true,
            'collector.profiles' => ['bridge-test' => ['name' => 'Moodle sintético', 'root' => $this->root,
                'code' => $this->root.'/code', 'data' => $this->root.'/data', 'base_url' => 'http://moodle-lab.test',
                'db_host' => 'moodle-lab-db', 'db_port' => 5432, 'db_name' => 'moodle_lab', 'db_user' => 'moodle_lab', 'db_prefix' => 'mdl_',
                'credential_reference' => 'bridge-test-db', 'credential_version' => '1', 'source_id' => 'bridge-test', 'moodle_series' => '4.5']]]);
        file_put_contents($this->root.'/.moodle-toolkit-synthetic-lab.json', json_encode(['schema_version' => 'synthetic-moodle.v1', 'fixture_id' => bin2hex(random_bytes(16))]));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
                chmod($file->getPathname(), $file->isDir() ? 0700 : 0600);
            }
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_real_command_is_closed_and_has_no_artificial_wall_or_cpu_limit(): void
    {
        $parameters = ['project_uuid' => (string) Str::uuid(), 'execution_uuid' => (string) Str::uuid(), 'runtime_sha256' => str_repeat('a', 64)];
        $definition = app(RegisteredCommandRegistry::class)->resolve(CollectorRegisteredCommand::KEY, $parameters);
        $this->assertSame([(string) config('collector.php_binary'), '-c', (string) config('collector.php_ini'), base_path('bin/collector-bridge.php'), ...array_values($parameters)], $definition['argv']);
        $this->assertNull($definition['wall_timeout_seconds']);
        $this->assertNull($definition['resource_limits']['cpu_seconds']);
        $this->assertSame(7200, $definition['stall_timeout_seconds']);
        $this->assertCount(6, $definition['artifact_descriptors']);
        $this->assertArrayNotHasKey('PHP_INI_SCAN_DIR', $definition['environment']);
        $this->expectException(InvalidArgumentException::class);
        app(RegisteredCommandRegistry::class)->resolve(CollectorRegisteredCommand::KEY, [...$parameters, 'command' => 'anything']);
    }

    public function test_flags_cannot_be_overridden_by_a_configured_command_with_the_real_key(): void
    {
        config(['toolkit.features.recolector_742.enabled' => false,
            'toolkit.runner.commands.'.CollectorRegisteredCommand::KEY => ['executable' => PHP_BINARY]]);
        $this->expectException(ToolOperationBlocked::class);
        app(RegisteredCommandRegistry::class)->resolve(CollectorRegisteredCommand::KEY);
    }

    public function test_bridge_refuses_writable_source_before_materializing_credentials_or_exporting(): void
    {
        $this->seed(ToolCatalogSeeder::class);
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Protected source boundary', 'type' => 'COLLECT']);
        $project = app(CollectorConfiguration::class)->save($project, $actor, ['profile_id' => 'bridge-test', 'workers' => 1,
            'package_name' => 'bridge-test', 'capacity_bytes' => 33554432, 'safety_margin_percent' => 20]);
        $execution = $this->execution($project);
        app(ApproveExecutionCapacity::class)->approve($execution, 33554432, 20, $actor);
        $distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->sole();
        $runtime = app(CollectorRuntimeConfiguration::class)->approve($execution, $project->configuration, $distribution, $actor);
        app(DeployToolDistribution::class)->deploy($execution, $distribution);
        $material = bin2hex(random_bytes(24));
        file_put_contents($this->root.'/references/bridge-test-db.1', $material);
        chmod($this->root.'/references/bridge-test-db.1', 0600);
        file_put_contents($this->root.'/code/version.php', 'private-source-content');
        $this->assertTrue(is_writable($this->root.'/code'));
        $operation = (string) Str::uuid();
        $process = new Process(['/usr/bin/setsid', (string) config('collector.php_binary'), '-c', (string) config('collector.php_ini'),
            base_path('bin/collector-bridge.php'), $project->uuid, $execution->uuid, $runtime->content_sha256], base_path(), [
                'PHP_INI_SCAN_DIR' => (string) config('collector.php_scan_dir'), 'COLLECTOR_WORKSPACE_ROOT' => $this->root.'/workspaces',
                'COLLECTOR_REFERENCE_ROOT' => $this->root.'/references', 'MOODLE_OPERATION_ID' => $operation,
            ], timeout: 10);
        $this->assertSame(1, $process->run());
        $signal = json_decode(trim($process->getOutput()), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('error', $signal['type']);
        $this->assertSame($operation, $signal['operation_uuid']);
        $this->assertTrue($process->getErrorOutput() === '', 'The bridge must not produce stderr.');
        foreach ([$this->root, $material, 'private-source-content'] as $private) {
            $this->assertFalse(str_contains($process->getOutput(), $private), 'The bridge output exposed private material.');
        }
        $this->assertFileDoesNotExist(app(ExecutionWorkspaceManager::class)->resolve($execution, 'input', 'moodle-runtime.php'));
        $this->assertSame([], glob(app(ExecutionWorkspaceManager::class)->resolve($execution, 'output').'/*'));
        $this->assertSame(0, $execution->artifacts()->count());
    }

    public function test_standalone_bridge_refuses_changed_runtime_and_copied_distribution_before_reading_reference(): void
    {
        $this->seed(ToolCatalogSeeder::class);
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Bridge boundary', 'type' => 'COLLECT']);
        $project = app(CollectorConfiguration::class)->save($project, $actor, ['profile_id' => 'bridge-test', 'workers' => 1, 'package_name' => 'bridge-test', 'capacity_bytes' => 33554432, 'safety_margin_percent' => 20]);
        $execution = $this->execution($project);
        app(ApproveExecutionCapacity::class)->approve($execution, 33554432, 20, $actor);
        $distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->sole();
        $runtime = app(CollectorRuntimeConfiguration::class)->approve($execution, $project->configuration, $distribution, $actor);
        $deployment = app(DeployToolDistribution::class)->deploy($execution, $distribution);
        $path = app(ExecutionWorkspaceManager::class)->resolve($execution, 'state', 'collector-runtime.json');
        $content = (string) file_get_contents($path);
        $material = bin2hex(random_bytes(24));
        file_put_contents($this->root.'/references/bridge-test-db.1', $material);
        chmod($this->root.'/references/bridge-test-db.1', 0600);
        foreach (['runtime', 'distribution'] as $corruption) {
            if ($corruption === 'runtime') {
                file_put_contents($path, '{}');
            } else {
                file_put_contents($path, $content);
                $script = $deployment['path'].'/scripts/source-export.php';
                chmod($script, 0600);
                file_put_contents($script, 'altered');
            }
            $operation = (string) Str::uuid();
            $process = new Process(['/usr/bin/setsid', (string) config('collector.php_binary'), '-c', (string) config('collector.php_ini'), base_path('bin/collector-bridge.php'),
                $project->uuid, $execution->uuid, $runtime->content_sha256], base_path(), [
                    'PHP_INI_SCAN_DIR' => (string) config('collector.php_scan_dir'), 'COLLECTOR_WORKSPACE_ROOT' => $this->root.'/workspaces',
                    'COLLECTOR_REFERENCE_ROOT' => $this->root.'/references', 'MOODLE_OPERATION_ID' => $operation,
                ], timeout: 10);
            $this->assertSame(1, $process->run());
            $signal = json_decode(trim($process->getOutput()), true);
            $this->assertSame('error', $signal['type']);
            $this->assertSame($operation, $signal['operation_uuid']);
            $this->assertSame(1, $signal['sequence']);
            $this->assertFalse(str_contains($process->getOutput().$process->getErrorOutput(), $material), 'The bridge output exposed the private credential.');
            $this->assertFileDoesNotExist(app(ExecutionWorkspaceManager::class)->resolve($execution, 'input', 'moodle-runtime.php'));
            $this->assertSame([], glob(app(ExecutionWorkspaceManager::class)->resolve($execution, 'output').'/*'));
        }
    }
}
