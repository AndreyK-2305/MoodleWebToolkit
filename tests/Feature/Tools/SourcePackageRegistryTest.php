<?php

namespace Tests\Feature\Tools;

use App\Domain\Artifacts\RegisterReferencedArtifact;
use App\Domain\Tools\BindExecutionTool;
use App\Domain\Tools\SourcePackageRegistry;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Enums\ArtifactCategory;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Artifact;
use App\Models\Execution;
use App\Models\RemoteOperation;
use App\Models\Tool;
use Database\Seeders\ToolCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\Domain\DomainTestCase;

class SourcePackageRegistryTest extends DomainTestCase
{
    private string $fixturePrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePrefix = 'source-package-tests/'.Str::uuid();
        $this->seed(ToolCatalogSeeder::class);
        config(['toolkit.features.recolector_742.enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixturePrefix)) {
            Storage::disk('local')->deleteDirectory($this->fixturePrefix);
        }
        parent::tearDown();
    }

    public function test_package_from_prior_execution_in_same_project_is_bound_with_hash_snapshot(): void
    {
        $project = $this->project();
        $producer = $this->execution($project, attempt: 1);
        $consumer = $this->execution($project, attempt: 2);
        $artifact = $this->sourceArtifact($producer);
        $package = app(SourcePackageRegistry::class)->register(
            $artifact, 'source-a', '7.4.2-linux', 'recolector-source.v1', 'fuente-a.zip', ['moodle.source.export'],
        );
        app(SourcePackageRegistry::class)->validate($package);

        $version = Tool::query()->where('key', 'moodle-recolector')->firstOrFail()->versions()->where('version', '7.4.2-linux')->firstOrFail();
        $version->forceFill(['enabled' => true])->save();
        $distribution = $version->distributions()->firstOrFail();
        app(ApproveExecutionCapacity::class)->approve($consumer, 16 * 1024 * 1024, 10, $project->creator);

        $binding = app(BindExecutionTool::class)->bind(
            $consumer, $version, $distribution, 'moodle.source.export', 'recolector-742', 'local-registered-process', [], [$package->getKey()],
        );

        $this->assertSame([$package->getKey()], $binding->source_package_ids);
        $this->assertSame($package->sha256, $binding->source_package_hashes[(string) $package->getKey()]);
        $this->assertSame($project->getKey(), $binding->project_id);
        $this->assertSame(1, DB::table('execution_tool_binding_sources')->where('execution_tool_binding_id', $binding->getKey())->count());
    }

    public function test_package_from_another_project_is_rejected(): void
    {
        $projectA = $this->project();
        $projectB = $this->project();
        $producer = $this->execution($projectA);
        $consumer = $this->execution($projectB);
        $package = app(SourcePackageRegistry::class)->register(
            $this->sourceArtifact($producer), 'source-cross-project', '7.4.2-linux', 'recolector-source.v1', 'fuente.zip', ['moodle.source.export'],
        );
        app(SourcePackageRegistry::class)->validate($package);
        [$version, $distribution] = $this->enabledRecolector();
        app(ApproveExecutionCapacity::class)->approve($consumer, 16 * 1024 * 1024, 0, $projectB->creator);

        $this->expectException(ToolOperationBlocked::class);
        app(BindExecutionTool::class)->bind(
            $consumer, $version, $distribution, 'moodle.source.export', 'recolector-742', 'local-registered-process', [], [$package->getKey()],
        );
    }

    public function test_revoked_and_changed_packages_are_rejected_at_binding_time(): void
    {
        $project = $this->project();
        $producer = $this->execution($project);
        $consumer = $this->execution($project, attempt: 2);
        $artifact = $this->sourceArtifact($producer);
        $registry = app(SourcePackageRegistry::class);
        $package = $registry->register($artifact, 'source-revoked', '7.4.2-linux', 'recolector-source.v1', 'fuente.zip', ['moodle.source.export']);
        $registry->validate($package);
        $registry->revoke($package, 'fixture revocation');
        [$version, $distribution] = $this->enabledRecolector();
        app(ApproveExecutionCapacity::class)->approve($consumer, 16 * 1024 * 1024, 0, $project->creator);

        try {
            app(BindExecutionTool::class)->bind($consumer, $version, $distribution, 'moodle.source.export', 'recolector-742', 'local-registered-process', [], [$package->getKey()]);
            $this->fail('Un paquete revocado no debe poder enlazarse.');
        } catch (ToolOperationBlocked) {
            $this->assertSame('REVOKED', $package->fresh()->validation_state);
        }

        $producer2 = $this->execution($project, attempt: 3);
        $consumer2 = $this->execution($project, attempt: 4);
        $artifact2 = $this->sourceArtifact($producer2);
        $changed = $registry->register($artifact2, 'source-changed', '7.4.2-linux', 'recolector-source.v1', 'cambiada.zip', ['moodle.source.export']);
        $registry->validate($changed);
        $absolute = Storage::disk('local')->path($artifact2->path);
        file_put_contents($absolute, 'source-package-changed');
        app(ApproveExecutionCapacity::class)->approve($consumer2, 16 * 1024 * 1024, 0, $project->creator);

        $this->expectException(ToolOperationBlocked::class);
        app(BindExecutionTool::class)->bind($consumer2, $version, $distribution, 'moodle.source.export', 'recolector-742', 'local-registered-process', [], [$changed->getKey()]);
    }

    public function test_non_source_artifacts_are_rejected(): void
    {
        $project = $this->project();
        $producer = $this->execution($project);
        $artifact = $this->sourceArtifact($producer, ArtifactCategory::REPORT);
        $this->expectException(ToolOperationBlocked::class);
        app(SourcePackageRegistry::class)->register($artifact, 'wrong-category', '7.4.2-linux', 'recolector-source.v1', 'report.zip', ['moodle.source.export']);
    }

    public function test_referenced_artifact_cannot_be_registered_before_durable_termination(): void
    {
        $execution = $this->execution($this->project());
        $relative = $this->fixturePrefix.'/pending-output.zip';
        $absolute = Storage::disk('local')->path($relative);
        File::ensureDirectoryExists(dirname($absolute));
        file_put_contents($absolute, 'pending-output');
        $operation = RemoteOperation::query()->create([
            'execution_id' => $execution->getKey(),
            'operation_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'pending-'.Str::uuid(),
            'provider_key' => 'local-registered-process',
            'host_id' => gethostname() ?: 'local',
            'runtime_key' => 'workspace-process-v2',
            'command_key' => 'platform_export',
            'command_sha256' => hash('sha256', 'pending-output'),
            'communication_state' => 'CONNECTED',
            'functional_state' => 'RUNNING',
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(RegisterReferencedArtifact::class)->register(
            $execution,
            $absolute,
            ArtifactCategory::SOURCE_PACKAGE,
            'pending-output.zip',
            expectedSha256: hash_file('sha256', $absolute),
            expectedSize: filesize($absolute),
            remoteOperationId: (int) $operation->getKey(),
        );
    }

    public function test_duplicate_package_ids_are_rejected_instead_of_silently_deduplicated(): void
    {
        $project = $this->project();
        $producer = $this->execution($project);
        $consumer = $this->execution($project, attempt: 2);
        $package = app(SourcePackageRegistry::class)->register(
            $this->sourceArtifact($producer), 'source-duplicate', '7.4.2-linux', 'recolector-source.v1', 'fuente.zip', ['moodle.source.export'],
        );
        app(SourcePackageRegistry::class)->validate($package);
        [$version, $distribution] = $this->enabledRecolector();
        app(ApproveExecutionCapacity::class)->approve($consumer, 16 * 1024 * 1024, 0, $project->creator);

        $this->expectException(ToolOperationBlocked::class);
        app(BindExecutionTool::class)->bind(
            $consumer, $version, $distribution, 'moodle.source.export', 'recolector-742', 'local-registered-process', [], [$package->getKey(), $package->getKey()],
        );
    }

    private function enabledRecolector(): array
    {
        $version = Tool::query()->where('key', 'moodle-recolector')->firstOrFail()->versions()->where('version', '7.4.2-linux')->firstOrFail();
        $version->forceFill(['enabled' => true])->save();

        return [$version, $version->distributions()->firstOrFail()];
    }

    private function sourceArtifact(Execution $execution, ArtifactCategory $category = ArtifactCategory::SOURCE_PACKAGE): Artifact
    {
        $relative = $this->fixturePrefix.'/'.Str::uuid().'.bin';
        $absolute = Storage::disk('local')->path($relative);
        File::ensureDirectoryExists(dirname($absolute));
        file_put_contents($absolute, 'source-package-fixture');
        $hash = hash_file('sha256', $absolute);
        $operationUuid = (string) Str::uuid();
        $commandHash = hash('sha256', 'source-package-test');
        $operation = RemoteOperation::query()->create([
            'execution_id' => $execution->getKey(),
            'operation_uuid' => $operationUuid,
            'idempotency_key' => 'producer-'.Str::uuid(),
            'provider_key' => 'local-registered-process',
            'host_id' => gethostname() ?: 'local',
            'runtime_key' => 'workspace-process-v2',
            'command_key' => 'platform_export',
            'command_sha256' => $commandHash,
            'communication_state' => 'TERMINATED',
            'functional_state' => 'SUCCEEDED',
            'terminated_at' => now()->utc(),
            'exit_code' => 0,
            'evidence' => ['exit_evidence' => [
                'operation_uuid' => $operationUuid,
                'command_sha256' => $commandHash,
            ]],
        ]);

        return Artifact::query()->create([
            'execution_id' => $execution->getKey(),
            'remote_operation_id' => $operation->getKey(),
            'type' => strtolower($category->value),
            'category' => $category->value,
            'storage_mode' => 'MANAGED',
            'disk' => 'local',
            'path' => $relative,
            'filename' => basename($relative),
            'mime_type' => 'application/zip',
            'size' => filesize($absolute),
            'sha256' => $hash,
            'metadata' => [],
        ]);
    }
}
