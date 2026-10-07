<?php

namespace Tests\Feature\Tools;

use App\Domain\Tools\DeployToolDistribution;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\ToolDistribution;
use Database\Seeders\ToolCatalogSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\Domain\DomainTestCase;

class ToolDistributionDeploymentTest extends DomainTestCase
{
    private string $workspaceRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaceRoot = storage_path('framework/deployment-tests/'.Str::uuid());
        config(['toolkit.workspaces.root' => $this->workspaceRoot]);
        $this->seed(ToolCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspaceRoot) && is_dir($this->workspaceRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->workspaceRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($iterator as $entry) {
                @chmod($entry->getPathname(), $entry->isDir() ? 0700 : 0600);
            }
            File::deleteDirectory($this->workspaceRoot);
        }
        parent::tearDown();
    }

    public function test_recolector_is_copied_to_an_isolated_workspace_and_reverified(): void
    {
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);
        $distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->firstOrFail();
        $result = app(DeployToolDistribution::class)->deploy($execution, $distribution);

        $this->assertDirectoryExists($result['path']);
        $this->assertFileExists($result['path'].DIRECTORY_SEPARATOR.'EXPORTAR-ORIGEN.sh');
        $this->assertSame(29, $result['evidence']['deployed_file_count']);
        $this->assertNotSame(
            fileinode(base_path('BaseLine/Recolector/Recolector-v7.4.2/EXPORTAR-ORIGEN.sh')),
            fileinode($result['path'].DIRECTORY_SEPARATOR.'EXPORTAR-ORIGEN.sh'),
        );

        $again = app(DeployToolDistribution::class)->deploy($execution, $distribution);
        $this->assertSame($result['path'], $again['path']);
        $this->assertSame($result['evidence']['deployed_tree_sha256'], $again['evidence']['deployed_tree_sha256']);
    }

    public function test_v8_benchmark_and_operational_configuration_is_not_copied_into_runtime(): void
    {
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);
        $distribution = ToolDistribution::query()->where('key', 'moodle-consolidador-8.0.0-linux-rc12-tree')->firstOrFail();
        $result = app(DeployToolDistribution::class)->deploy($execution, $distribution);

        $this->assertFileDoesNotExist($result['path'].DIRECTORY_SEPARATOR.'config/phase5-pilot-package.json');
        $this->assertFileDoesNotExist($result['path'].DIRECTORY_SEPARATOR.'config/phase6-batch.json');
        $this->assertSame([], $result['evidence']['workspace_overlays']);
        $this->assertSame(250, $result['evidence']['deployed_file_count']);
        $activeConfig = app(ExecutionWorkspaceManager::class)->resolve(
            $execution,
            'state',
            'runtime-config/'.Str::slug($distribution->key),
        );
        $this->assertDirectoryExists($activeConfig);
        $this->assertSame([], File::allFiles($activeConfig));
        $contents = implode("\n", array_map(fn ($file): string => File::get($file->getPathname()), File::allFiles($activeConfig)));
        foreach (['pregrado-2026-03-04-directo', 'posgrados-2025-05-02-directo', 'benchmark-operator'] as $benchmarkValue) {
            $this->assertStringNotContainsString($benchmarkValue, $contents);
        }
        $this->assertFalse($result['evidence']['runtime_configuration_approved']);
    }

    private function approveCapacity(\App\Models\Execution $execution): void
    {
        app(ApproveExecutionCapacity::class)->approve($execution, 512 * 1024 * 1024, 10, $execution->creator);
    }
}
