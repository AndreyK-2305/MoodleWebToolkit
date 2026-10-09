<?php

namespace Tests\Feature\Tools;

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Collector\CollectorExecutionPreparation;
use App\Domain\Collector\CollectorExecutionProvider;
use App\Domain\Collector\CollectorRegisteredCommand;
use App\Domain\Collector\CollectorWorkflow;
use App\Domain\Executions\ExecutionCommandDispatcher;
use App\Domain\Executions\ExecutionCommandLease;
use App\Domain\Executions\ExecutionFailureCloser;
use App\Domain\Executions\ExecutionRuntimeResolver;
use App\Domain\Executions\FakeExecutionProvider;
use App\Domain\Executions\LocalToolExecutionProvider;
use App\Domain\Executions\RemoteOperationCoordinator;
use App\Domain\Executions\RequestExecutionCancellation;
use App\Domain\Projects\ProjectExecutionManager;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Tools\CollectorAdapter;
use App\Domain\Tools\FakeToolAdapter;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Exceptions\ToolOperationBlocked;
use App\Jobs\RunExecutionUnit;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\ExecutionRuntimeConfiguration;
use App\Models\ToolDistribution;
use Database\Seeders\ToolCatalogSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Feature\Domain\DomainTestCase;

class CollectorRuntimeRoutingTest extends DomainTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->root = Storage::disk('local')->path('profile');
        foreach (['code', 'data', 'secrets'] as $area) {
            File::ensureDirectoryExists($this->root.'/'.$area, 0700);
        }
        config(['toolkit.features.recolector_742.enabled' => true, 'toolkit.features.local_runner.enabled' => true,
            'toolkit.runner.host_id' => gethostname(), 'toolkit.workspaces.root' => Storage::disk('local')->path('workspaces'),
            'collector.secret_root' => $this->root.'/secrets', 'collector.profiles' => ['routing-lab' => [
                'name' => 'Routing LAB', 'root' => $this->root, 'code' => $this->root.'/code', 'data' => $this->root.'/data',
                'base_url' => 'http://moodle-lab.test', 'db_host' => 'moodle-lab-db', 'db_port' => 5432,
                'db_name' => 'moodle_lab', 'db_user' => 'moodle_lab', 'db_prefix' => 'mdl_',
                'credential_reference' => 'routing-db', 'credential_version' => '1', 'source_id' => 'routing-lab', 'moodle_series' => '4.5',
            ]]]);
        $this->seed(ToolCatalogSeeder::class);
        ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->sole()->toolVersion->update(['enabled' => true]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $root = (string) config('toolkit.workspaces.root');
        if (is_dir($root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    chmod($entry->getPathname(), 0700);
                }
            }
            File::deleteDirectory($root);
        }
        parent::tearDown();
    }

    private function prepared(): Execution
    {
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Routing COLLECT', 'type' => 'COLLECT']);
        $project = app(CollectorConfiguration::class)->save($project, $actor, ['profile_id' => 'routing-lab', 'workers' => 1,
            'package_name' => 'routing-proof', 'capacity_bytes' => 16777216, 'safety_margin_percent' => 20]);
        $project->transitionTo(ProjectStatus::READY);
        $execution = app(ProjectExecutionManager::class)->queue($project, $actor);
        foreach (app(CollectorAdapter::class)->plan($project) as $step) {
            $execution->steps()->create(['step_key' => $step->key, 'name' => $step->name, 'position' => $step->position, 'attempt' => 1,
                'status' => 'PENDING', 'metadata' => ['adapter' => CollectorExecutionPreparation::ADAPTER_KEY]]);
        }
        app(CollectorExecutionPreparation::class)->prepare($execution, $project->configuration, $actor);

        return $execution;
    }

    private function command(Execution $execution): ExecutionCommand
    {
        return $execution->commands()->create(['step_key' => '__execution__', 'attempt' => 1, 'command_type' => 'START',
            'idempotency_key' => 'routing-start-key', 'idempotency_scope' => 'routing:'.$execution->id,
            'payload_hash' => hash('sha256', 'routing-start'), 'payload' => ['adapter' => CollectorExecutionPreparation::ADAPTER_KEY],
            'created_by' => $execution->created_by]);
    }

    public function test_job_resolves_real_provider_from_binding_after_flags_and_project_options_change(): void
    {
        $execution = $this->prepared();
        $command = $this->command($execution);
        $execution->project->configuration->update(['settings' => ['options' => ['mode' => 'DEMONSTRATION']]]);
        config(['toolkit.features.recolector_742.enabled' => false, 'toolkit.features.local_runner.enabled' => false]);
        $provider = Mockery::mock(LocalToolExecutionProvider::class);
        $provider->shouldReceive('execute')->once()->withArgs(fn (ExecutionCommand $received, $adapter): bool => $received->id === $command->id
            && $adapter instanceof CollectorAdapter && $adapter->key() === CollectorExecutionPreparation::ADAPTER_KEY);
        app()->instance(LocalToolExecutionProvider::class, $provider);
        (new RunExecutionUnit($command->id))->handle(app(FakeExecutionProvider::class), app(FakeToolAdapter::class));
        $this->assertSame('collector-742-lab', $execution->toolBinding->adapter_key);
    }

    public function test_real_command_without_binding_cannot_fall_back_to_fake(): void
    {
        $command = $this->command($this->execution($this->project()));
        $this->expectException(ToolOperationBlocked::class);
        app(ExecutionRuntimeResolver::class)->resolve($command, app(FakeExecutionProvider::class), app(FakeToolAdapter::class));
    }

    public function test_dispatch_routes_real_commands_to_runner_namespace(): void
    {
        $command = $this->command($this->prepared());
        app(ExecutionCommandDispatcher::class)->dispatch($command);
        Queue::assertPushed(RunExecutionUnit::class, fn (RunExecutionUnit $job): bool => $job->commandId === $command->id
            && $job->connection === 'redis-tool-runs' && $job->queue === 'tool-runs');
    }

    public function test_cancellation_before_start_creates_no_process_or_fake_checkpoint(): void
    {
        $execution = $this->prepared();
        $start = $this->command($execution);
        app(RequestExecutionCancellation::class)->request($execution, $execution->creator, 'cancel-routing-key');
        $cancel = $execution->commands()->where('command_type', 'CANCEL')->sole();
        app(CollectorExecutionProvider::class)->execute($cancel);
        $this->assertSame(ExecutionStatus::CANCELLED, $execution->fresh()->status);
        $this->assertSame(ProjectStatus::CANCELLED, $execution->project->fresh()->status);
        $this->assertNotNull($start->fresh()->processed_at);
        $this->assertNotNull($cancel->fresh()->processed_at);
        $this->assertDatabaseCount('remote_operations', 0);
        $this->assertDatabaseCount('checkpoints', 0);
    }

    public function test_worker_failure_after_registration_keeps_execution_and_releases_only_command_for_recovery(): void
    {
        $execution = $this->prepared();
        $command = $this->command($execution);
        app(ExecutionCommandLease::class)->claim($command->id);
        $operation = app(CollectorWorkflow::class)->start($execution);
        $this->assertTrue(app(ExecutionFailureCloser::class)->closeWorkerFailure($command->id));
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertNull($command->fresh()->processed_at);
        $this->assertNull($command->fresh()->processing_started_at);
        $this->assertSame($operation->id, app(CollectorWorkflow::class)->start($execution->fresh())->id);
        $this->assertDatabaseCount('remote_operations', 1);
        $this->assertDatabaseCount('checkpoints', 0);
    }

    public function test_disabled_flag_before_launch_does_not_commit_a_claim_or_invoke_fake(): void
    {
        $execution = $this->prepared();
        $operation = app(CollectorWorkflow::class)->start($execution);
        $runtime = ExecutionRuntimeConfiguration::query()->findOrFail($execution->toolBinding->runtime_configuration_id);
        config(['toolkit.features.recolector_742.enabled' => false]);
        try {
            app(RemoteOperationCoordinator::class)->runScheduled($operation->id, CollectorRegisteredCommand::KEY,
                ['project_uuid' => $execution->project->uuid, 'execution_uuid' => $execution->uuid, 'runtime_sha256' => $runtime->content_sha256],
                'moodle-recolector-742-linux-tree');
            $this->fail('A closed flag allowed a launch.');
        } catch (ToolOperationBlocked) {
            $this->assertNull($operation->fresh()->launch_claimed_at);
            $this->assertNull($operation->fresh()->process_id);
            $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        }
        app(CollectorWorkflow::class)->observe($operation->fresh());
        $this->assertNull($operation->fresh()->launch_claimed_at);
        $this->assertDatabaseCount('remote_operations', 1);
        $this->assertDatabaseCount('checkpoints', 0);
    }
}
