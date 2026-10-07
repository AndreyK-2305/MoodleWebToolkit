<?php

namespace Tests\Feature\Tools;

use App\Domain\Tools\DeployToolDistribution;
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

    public function test_v8_mutable_overlays_are_kept_out_of_the_sealed_distribution(): void
    {
        $execution = $this->execution($this->project());
        $distribution = ToolDistribution::query()->where('key', 'moodle-consolidador-8.0.0-linux-rc12-tree')->firstOrFail();
        $result = app(DeployToolDistribution::class)->deploy($execution, $distribution);

        $this->assertFileDoesNotExist($result['path'].DIRECTORY_SEPARATOR.'config/phase5-pilot-package.json');
        $this->assertFileDoesNotExist($result['path'].DIRECTORY_SEPARATOR.'config/phase6-batch.json');
        $this->assertArrayHasKey('config/phase5-pilot-package.json', $result['evidence']['workspace_overlays']);
        $overlay = $result['evidence']['workspace_overlays']['config/phase5-pilot-package.json'];
        $this->assertFileExists($overlay['path']);
        $this->assertSame($overlay['source_sha256'], $overlay['workspace_sha256']);
        $this->assertSame(261, $result['evidence']['deployed_file_count']);
    }
}
