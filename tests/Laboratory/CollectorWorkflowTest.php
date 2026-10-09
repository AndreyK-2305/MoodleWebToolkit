<?php

namespace Tests\Laboratory;

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Collector\CollectorExecutionPreparation;
use App\Domain\Collector\CollectorExecutionProvider;
use App\Domain\Collector\CollectorRegisteredCommand;
use App\Domain\Collector\CollectorWorkflow;
use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Executions\LocalToolExecutionProvider;
use App\Domain\Executions\RemoteOperationCoordinator;
use App\Domain\Executions\RequestExecutionFinalization;
use App\Domain\Projects\ProjectExecutionManager;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Tools\SourcePackageRegistry;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Exceptions\ToolOperationBlocked;
use App\Models\CollectorPackageAudit;
use App\Models\ExecutionRuntimeConfiguration;
use App\Models\SourcePackage;
use App\Models\ToolDistribution;
use Database\Seeders\ToolCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Domain\DomainFixtures;
use Tests\TestCase;

/** Executes the registered collector; no fabricated exit evidence or package. */
class CollectorWorkflowTest extends TestCase
{
    use DatabaseMigrations, DomainFixtures;

    public function test_detached_real_operation_is_audited_registered_and_observed_idempotently(): void
    {
        $this->assertTrue((bool) config('toolkit.features.recolector_742.enabled'));
        $this->assertTrue((bool) config('toolkit.features.local_runner.enabled'));
        $this->assertSame('moodle-tool-runner', gethostname());
        Storage::set('local', Storage::fake('collector-lab-'.bin2hex(random_bytes(8))));
        $root = Storage::disk('local')->path('workspaces');
        config(['toolkit.workspaces.root' => $root, 'toolkit.runner.host_id' => gethostname()]);
        Queue::fake();
        $this->seed(ToolCatalogSeeder::class);
        $distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->sole();
        $distribution->toolVersion->update(['enabled' => true]);
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Durable COLLECT LAB', 'type' => 'COLLECT']);
        $project = app(CollectorConfiguration::class)->save($project, $actor, ['profile_id' => 'synthetic-moodle', 'workers' => 1,
            'package_name' => 'durable-proof', 'capacity_bytes' => 268435456, 'safety_margin_percent' => 20]);
        $project->transitionTo(ProjectStatus::READY);
        $execution = app(ProjectExecutionManager::class)->queue($project, $actor);
        foreach (app(CollectorExecutionPreparation::class)->plan() as $step) {
            $execution->steps()->create(['step_key' => $step->key, 'name' => $step->name, 'position' => $step->position,
                'attempt' => 1, 'status' => 'PENDING', 'metadata' => ['adapter' => CollectorExecutionPreparation::ADAPTER_KEY]]);
        }
        $binding = app(CollectorExecutionPreparation::class)->prepare($execution, $project->configuration, $actor);
        $runtime = ExecutionRuntimeConfiguration::query()->findOrFail($binding->runtime_configuration_id);
        $workflow = app(CollectorWorkflow::class);
        $start = $execution->commands()->create(['step_key' => '__execution__', 'attempt' => 1, 'command_type' => 'START',
            'idempotency_key' => 'lab-real-start', 'idempotency_scope' => 'lab:'.$execution->id,
            'payload_hash' => hash('sha256', 'lab-real-start'), 'payload' => ['adapter' => CollectorExecutionPreparation::ADAPTER_KEY],
            'created_by' => $actor->id]);
        app(CollectorExecutionProvider::class)->execute($start);
        $operation = $execution->remoteOperations()->sole();
        $this->assertNotNull($start->fresh()->processed_at);
        $this->assertSame(1, $execution->events()->where('type', 'collector.operation_registered')->count());
        $this->assertSame($operation->id, $workflow->start($execution->fresh())->id);
        $operation = app(RemoteOperationCoordinator::class)->runScheduled($operation->id, CollectorRegisteredCommand::KEY,
            ['project_uuid' => $project->uuid, 'execution_uuid' => $execution->uuid, 'runtime_sha256' => $runtime->content_sha256],
            'moodle-recolector-742-linux-tree');
        $deadline = microtime(true) + 240;
        try {
            do {
                $workflow->observe($operation->fresh());
                usleep(200000);
            } while ($execution->fresh()->status !== ExecutionStatus::REVIEW && microtime(true) < $deadline);
            $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
            $this->assertSame(ProjectStatus::REVIEW, $project->fresh()->status);
            $this->assertSame('TERMINATED', $operation->fresh()->communication_state->value);
            $this->assertTrue(app(RemoteOperationCoordinator::class)->verifyTerminalEvidence($operation));
            $this->assertDatabaseCount('remote_operations', 1);
            $this->assertDatabaseCount('artifacts', 6);
            $this->assertDatabaseCount('source_packages', 1);
            $audit = CollectorPackageAudit::query()->sole();
            $beforeIds = $execution->artifacts()->orderBy('id')->pluck('id')->all();
            $again = app(LocalToolExecutionProvider::class)->collectArtifacts($operation->fresh(), ['source-package.zip' => [
                'collector_audit' => $audit->snapshot, 'manifest_sha256' => $audit->snapshot['manifest_sha256'], 'collector_audit_id' => $audit->id]]);
            $this->assertSame($beforeIds, collect($again)->pluck('id')->sort()->values()->all());
            $this->assertDatabaseCount('artifacts', 6);
            $this->assertDatabaseCount('collector_package_audits', 1);
            $package = SourcePackage::query()->sole();
            $this->assertSame('7.4.2-linux', $package->producer_tool_version);
            $this->assertSame('VALID', $package->validation_state);
            $this->assertSame('durable-proof', $package->name);
            $this->assertSame('1.0', $package->capabilities['theme_inventory']);
            $this->assertSame(2, $package->evidence['collector_audit']['courses']);
            $this->assertFileDoesNotExist(app(ExecutionWorkspaceManager::class)->resolve($execution, 'input', 'moodle-runtime.php'));
            $this->assertNull($execution->fresh()->progress);
            $this->assertTrue($execution->verifications()->sole()->approved);
            $sequence = $execution->fresh()->last_event_sequence;
            $workflow->observe($operation->fresh());
            $this->assertSame($sequence, $execution->fresh()->last_event_sequence);
            $this->assertDatabaseCount('artifacts', 6);
            $this->assertDatabaseCount('source_packages', 1);
            app(SecretProvider::class)->consume('moodle-lab-db', '1', function (string $value) use ($execution): void {
                $this->assertStringNotContainsString($value, $execution->events()->get()->toJson());
                $this->assertStringNotContainsString($value, $execution->logs()->get()->toJson());
                $this->assertStringNotContainsString($value, $execution->artifacts()->get()->toJson());
            });
            try {
                DB::transaction(fn () => DB::table('collector_package_audits')->where('id', CollectorPackageAudit::query()->sole()->id)->update(['package_bytes' => 1]));
                $this->fail('Immutable audit changed.');
            } catch (QueryException) {
                $this->assertSame($package->sha256, CollectorPackageAudit::query()->sole()->package_sha256);
            }
            $manifest = $execution->artifacts()->where('metadata->source_relative_path', 'manifest.json')->sole();
            $manifestPath = Storage::disk($manifest->disk)->path($manifest->path);
            $original = file_get_contents($manifestPath);
            chmod($manifestPath, 0600);
            try {
                file_put_contents($manifestPath, 'changed');
                try {
                    $workflow->assertFinalizable($execution->fresh());
                    $this->fail('Altered manifest authorized closure.');
                } catch (ToolOperationBlocked) {
                    $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
                }
            } finally {
                file_put_contents($manifestPath, $original);
                chmod($manifestPath, 0400);
            }
            app(RequestExecutionFinalization::class)->request($execution, $actor, 'lab-real-finalize');
            $finalize = $execution->commands()->where('command_type', 'FINALIZE')->sole();
            for ($job = 0; $job < 80 && $finalize->fresh()->processed_at === null; $job++) {
                app(CollectorExecutionProvider::class)->execute($finalize->fresh());
            }
            $this->assertSame(ExecutionStatus::COMPLETED, $execution->fresh()->status);
            $this->assertSame(ProjectStatus::COMPLETED, $project->fresh()->status);
            $this->assertDatabaseCount('artifacts', 10);
            $this->assertDatabaseCount('checkpoints', 0);
            $this->assertNull($execution->academicSnapshot);
            $this->assertSame(10, $execution->fresh()->completion_summary['artifact_count']);
            $report = $execution->artifacts()->where('type', 'JSON_REPORT')->sole();
            $reportJson = json_decode(Storage::disk($report->disk)->get($report->path), true, 64, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('academic_nodes', $reportJson);
            $this->assertSame($package->uuid, $reportJson['source_package']['uuid']);
            $this->assertSame(2, $reportJson['audit']['courses']);
            app(SecretProvider::class)->consume('moodle-lab-db', '1', function (string $secret) use ($execution): void {
                foreach ($execution->artifacts()->whereIn('type', ['JSON_REPORT', 'VERIFICATION_REPORT', 'LOG_EXPORT', 'FINAL_SUMMARY'])->get() as $artifact) {
                    $this->assertStringNotContainsString($secret, Storage::disk($artifact->disk)->get($artifact->path));
                }
            });
            $path = Storage::disk($package->artifact->disk)->path($package->artifact->path);
            chmod($path, 0600);
            file_put_contents($path, 'tampered', FILE_APPEND);
            $this->expectException(ToolOperationBlocked::class);
            app(SourcePackageRegistry::class)->validate($package);
        } finally {
            if ($operation->fresh()->communication_state->value === 'TERMINATED') {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
                    if ($item->isDir() && ! $item->isLink()) {
                        chmod($item->getPathname(), 0700);
                    }
                }
                File::deleteDirectory($root);
                Storage::disk('local')->deleteDirectory('executions/'.$execution->workspace_key);
            }
        }
    }
}
