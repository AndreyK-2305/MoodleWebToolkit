<?php

namespace Tests\Laboratory;

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Collector\CollectorExecutionPreparation;
use App\Domain\Collector\CollectorExecutionProvider;
use App\Domain\Collector\CollectorRegisteredCommand;
use App\Domain\Collector\CollectorWorkflow;
use App\Domain\Collector\RetryCollectorExecution;
use App\Domain\Executions\LocalProcessInspector;
use App\Domain\Executions\RemoteOperationCoordinator;
use App\Domain\Executions\RequestExecutionCancellation;
use App\Domain\Projects\ProjectExecutionManager;
use App\Domain\Projects\ProjectWizard;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Execution;
use App\Models\ExecutionRuntimeConfiguration;
use App\Models\RemoteOperation;
use App\Models\ToolDistribution;
use Database\Seeders\ToolCatalogSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Domain\DomainFixtures;
use Tests\TestCase;

class CollectorRecoveryTest extends TestCase
{
    use DatabaseMigrations, DomainFixtures;

    public function test_real_group_cancellation_and_fresh_export_preserve_failed_evidence_and_lineage(): void
    {
        Storage::set('local', Storage::fake('collector-recovery-'.bin2hex(random_bytes(8))));
        $root = Storage::disk('local')->path('workspaces');
        config(['toolkit.workspaces.root' => $root, 'toolkit.runner.host_id' => gethostname(), 'collector.integration_ready' => true]);
        $this->assertTrue((bool) config('toolkit.features.recolector_742.enabled'));
        $this->assertSame('moodle-tool-runner', gethostname());
        Queue::fake();
        $this->seed(ToolCatalogSeeder::class);
        ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->sole()->toolVersion->update(['enabled' => true]);
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Recovery LAB', 'type' => 'COLLECT']);
        $project = app(CollectorConfiguration::class)->save($project, $actor, ['profile_id' => 'synthetic-moodle', 'workers' => 1,
            'package_name' => 'recovery-proof', 'capacity_bytes' => 268435456, 'safety_margin_percent' => 20]);
        $project->transitionTo(ProjectStatus::READY);
        $first = app(ProjectExecutionManager::class)->queue($project, $actor);
        foreach (app(CollectorExecutionPreparation::class)->plan() as $step) {
            $first->steps()->create(['step_key' => $step->key, 'name' => $step->name, 'position' => $step->position,
                'attempt' => 1, 'status' => 'PENDING', 'metadata' => ['adapter' => CollectorExecutionPreparation::ADAPTER_KEY]]);
        }
        app(CollectorExecutionPreparation::class)->prepare($first, $project->configuration, $actor);
        $start = $first->commands()->create(['step_key' => '__execution__', 'attempt' => 1, 'command_type' => 'START',
            'idempotency_key' => 'recovery-start', 'idempotency_scope' => 'lab:'.$first->id, 'payload_hash' => hash('sha256', 'recovery-start'),
            'payload' => ['adapter' => CollectorExecutionPreparation::ADAPTER_KEY], 'created_by' => $actor->id]);
        $executions = [$first];
        try {
            app(CollectorExecutionProvider::class)->execute($start);
            // Kill the actual launch worker after detachment; its supervisor and
            // registered Recolector process must remain independently identifiable.
            DB::purge();
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::reconnect();
                $this->launch($first);
                posix_kill(getmypid(), SIGKILL);
                exit(1);
            }
            pcntl_waitpid($pid, $status);
            DB::reconnect();
            $this->assertTrue(pcntl_wifsignaled($status));
            $this->assertSame(SIGKILL, pcntl_wtermsig($status));
            $operation = $first->remoteOperations()->sole();
            $this->assertNotNull($operation->process_id);
            $this->assertTrue(app(LocalProcessInspector::class)->isRunning($operation));
            $cancel = app(RequestExecutionCancellation::class)->request($first, $actor, 'recovery-cancel');
            $this->assertTrue($cancel->created);
            $this->assertFalse(app(RequestExecutionCancellation::class)->request($first, $actor, 'recovery-cancel')->created);
            app(CollectorExecutionProvider::class)->execute($first->commands()->where('command_type', 'CANCEL')->sole());
            $this->observeUntil($first, $operation, ExecutionStatus::CANCELLED, 40);
            $this->assertSame(ExecutionStatus::CANCELLED, $first->fresh()->status);
            $this->assertTrue(app(RemoteOperationCoordinator::class)->verifyTerminalEvidence($operation->fresh()));
            $sequence = $first->fresh()->last_event_sequence;
            $next = app(RetryCollectorExecution::class)->retry($first, $actor, 'recovery-fresh-export', $project->configuration->version, true)->execution;
            $executions[] = $next;
            $this->assertSame($first->id, $next->retried_from_execution_id);
            $this->assertNotSame($first->workspace_key, $next->workspace_key);
            $this->assertNull($next->resume_checkpoint_id);
            app(CollectorExecutionProvider::class)->execute($next->commands()->sole());
            $nextOperation = $this->launch($next);
            $this->assertNotSame($operation->operation_uuid, $nextOperation->operation_uuid);
            $this->observeUntil($next, $nextOperation, ExecutionStatus::REVIEW, 240);
            $this->assertSame(ExecutionStatus::REVIEW, $next->fresh()->status);
            $this->assertSame(ExecutionStatus::CANCELLED, $first->fresh()->status);
            $this->assertSame($sequence, $first->fresh()->last_event_sequence);
            $this->assertSame(1, $next->events()->min('sequence'));
            $this->assertSame(0, $first->artifacts()->count());
            $this->assertSame(6, $next->artifacts()->count());
            $this->assertDatabaseCount('remote_operations', 2);
            $this->assertDatabaseCount('source_packages', 1);
            $this->assertDatabaseCount('checkpoints', 0);
        } finally {
            foreach ($executions as $execution) {
                $operation = $execution->remoteOperations()->first();
                if ($operation !== null && app(RemoteOperationCoordinator::class)->verifyTerminalEvidence($operation->fresh())) {
                    $path = $root.'/'.$project->uuid.'/'.$execution->uuid;
                    if (is_dir($path)) {
                        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
                            if ($item->isDir() && ! $item->isLink()) {
                                chmod($item->getPathname(), 0700);
                            }
                        }
                        File::deleteDirectory($path);
                        Storage::disk('local')->deleteDirectory('executions/'.$execution->workspace_key);
                    }
                }
            }
        }
    }

    private function launch(Execution $execution): RemoteOperation
    {
        $runtime = ExecutionRuntimeConfiguration::query()->findOrFail($execution->toolBinding->runtime_configuration_id);
        $operation = $execution->remoteOperations()->sole();

        return app(RemoteOperationCoordinator::class)->runScheduled($operation->id, CollectorRegisteredCommand::KEY,
            ['project_uuid' => $execution->project->uuid, 'execution_uuid' => $execution->uuid, 'runtime_sha256' => $runtime->content_sha256],
            'moodle-recolector-742-linux-tree');
    }

    private function observeUntil(Execution $execution, RemoteOperation $operation, ExecutionStatus $status, int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            app(CollectorWorkflow::class)->observe($operation->fresh());
            usleep(200000);
        } while ($execution->fresh()->status !== $status && ! $execution->fresh()->status->isTerminal() && microtime(true) < $deadline);
    }
}
