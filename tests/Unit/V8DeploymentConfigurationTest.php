<?php

namespace Tests\Unit;

use App\Domain\Tools\DeployToolDistribution;
use App\Domain\Tools\ToolDistributionVerifier;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\Execution;
use App\Models\ExecutionWorkspace;
use App\Models\ToolDistribution;
use Database\Seeders\ToolCatalogSeeder;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

class V8DeploymentConfigurationTest extends TestCase
{
    public function test_v8_deployment_does_not_expose_the_historical_root_configuration_as_operational(): void
    {
        $entries = (new ReflectionClass(ToolCatalogSeeder::class))->getConstant('CATALOG');
        $entry = array_values(array_filter($entries, fn (array $entry): bool => $entry['tool']['key'] === 'moodle-consolidador'))[0];
        $distribution = new ToolDistribution([
            'key' => 'moodle-consolidador-'.$entry['version'].'-tree',
            'kind' => 'TREE',
            'source_path' => $entry['source_path'],
            'manifest_name' => 'FILES.sha256',
            'manifest_sha256' => $entry['manifest_sha256'],
            'distribution_sha256' => $entry['tree_sha256'],
            'file_count' => $entry['file_count'],
            'mutable_paths' => $entry['mutable_paths'],
            'deployment_exclusions' => $entry['deployment_exclusions'],
        ]);
        $root = storage_path('framework/testing/v8-deployment/'.Str::uuid());
        $workspaces = Mockery::mock(ExecutionWorkspaceManager::class);
        $workspaces->shouldReceive('prepare')->andReturn(new ExecutionWorkspace);
        $workspaces->shouldReceive('resolve')->andReturnUsing(function (Execution $execution, string $area, string $relative = '') use ($root): string {
            File::ensureDirectoryExists($root.'/'.$area, 0700);

            return $root.'/'.$area.($relative === '' ? '' : '/'.$relative);
        });
        $workspaces->shouldReceive('measure')->andReturn(0);
        $workspaces->shouldReceive('writeState')->andReturn($root.'/evidence.json');
        try {
            $deployed = (new DeployToolDistribution(new ToolDistributionVerifier, $workspaces))->deploy(new Execution, $distribution);
            $this->assertFileDoesNotExist($deployed['path'].'/config.yaml', 'The root config.yaml contains real benchmark sources and must require fresh materialization.');
            $this->assertFalse($deployed['evidence']['runtime_configuration_approved']);
            $this->assertSame($entry['tree_sha256'], $deployed['evidence']['source_tree_sha256']);
        } finally {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                chmod($entry->getPathname(), $entry->isDir() ? 0700 : 0600);
            }
            File::deleteDirectory($root);
        }
    }
}
