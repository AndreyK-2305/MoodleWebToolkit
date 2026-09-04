<?php

namespace Tests\Feature\Executions;

use App\Domain\Artifacts\LocalArtifactStorage;
use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Executions\StartProjectExecution;
use App\Domain\Projects\ProjectAssignmentManager;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Realtime\ProjectSessionChannels;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Events\ExecutionEventBroadcast;
use App\Jobs\RunExecutionUnit;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ExecutionEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_http_start_persists_before_dispatch_and_worker_leaves_bounded_demo_running(): void
    {
        Queue::fake();
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->readyProject($operator);
        $version = $project->configuration->version;

        $response = $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $version],
            ['Idempotency-Key' => 'start-http-0001'],
        );

        $response->assertCreated()->assertJson(['created' => true, 'status' => 'QUEUED']);
        $execution = Execution::query()->sole();
        $command = ExecutionCommand::query()->sole();
        $this->assertSame(ProjectStatus::QUEUED, $project->fresh()->status);
        $this->assertSame(ExecutionStatus::QUEUED, $execution->status);
        $this->assertCount(4, $execution->steps);
        $this->assertNotNull($command->dispatched_at);
        Queue::assertPushed(RunExecutionUnit::class, fn (RunExecutionUnit $job): bool => $job->commandId === $command->getKey());

        $job = new RunExecutionUnit((int) $command->getKey());
        $job->handle(app(ExecutionProvider::class), app(ToolAdapter::class));
        $job->handle(app(ExecutionProvider::class), app(ToolAdapter::class));

        $execution->refresh();
        $this->assertSame(ExecutionStatus::RUNNING, $execution->status);
        $this->assertSame(ProjectStatus::RUNNING, $project->fresh()->status);
        $this->assertSame(25, $execution->progress);
        $this->assertSame(6, $execution->events()->count());
        $this->assertSame(range(1, 6), $execution->events()->pluck('sequence')->all());
        $this->assertSame('SUCCESS', $execution->steps()->orderBy('position')->firstOrFail()->status->value);
        $this->assertSame(3, $execution->steps()->where('status', 'PENDING')->count());
        $this->assertNotNull($command->fresh()->processed_at);
    }

    public function test_idempotent_replay_returns_existing_execution_after_it_advanced(): void
    {
        Queue::fake();
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->readyProject($operator);
        $version = $project->configuration->version;
        $headers = ['Idempotency-Key' => 'same-request-0001'];

        $first = $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $version],
            $headers,
        )->assertCreated();
        $command = ExecutionCommand::query()->sole();
        (new RunExecutionUnit((int) $command->getKey()))
            ->handle(app(ExecutionProvider::class), app(ToolAdapter::class));

        $second = $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $version],
            $headers,
        );

        $second->assertOk()
            ->assertJson(['created' => false, 'execution_uuid' => $first->json('execution_uuid'), 'status' => 'RUNNING']);
        $this->assertDatabaseCount('executions', 1);
        $this->assertDatabaseCount('execution_commands', 2);
    }

    public function test_same_key_with_different_content_and_different_key_while_active_are_rejected(): void
    {
        Queue::fake();
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->readyProject($operator);
        $version = $project->configuration->version;

        $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $version],
            ['Idempotency-Key' => 'collision-key-0001'],
        )->assertCreated();

        $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $version + 1],
            ['Idempotency-Key' => 'collision-key-0001'],
        )->assertConflict();

        $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $version],
            ['Idempotency-Key' => 'different-key-0002'],
        )->assertConflict();

        $this->assertDatabaseCount('executions', 1);
        $this->assertDatabaseCount('execution_commands', 1);
    }

    public function test_stale_preflight_or_confirmation_prevents_start(): void
    {
        Queue::fake();
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->readyProject($operator);
        $configuration = $project->configuration;
        $settings = $configuration->settings;
        $settings['confirmation']['configuration_version']++;
        $configuration->update(['settings' => $settings]);

        $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $configuration->version],
            ['Idempotency-Key' => 'stale-confirmation-0001'],
        )->assertUnprocessable()->assertJsonValidationErrors('confirmation');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('executions', 0);
    }

    public function test_transaction_rollback_dispatches_neither_job_nor_broadcast(): void
    {
        Queue::fake();
        Event::fake([ExecutionEventBroadcast::class]);
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->readyProject($operator);

        try {
            DB::transaction(function () use ($operator, $project): void {
                app(StartProjectExecution::class)->start(
                    $project,
                    $operator,
                    'rollback-start-0001',
                    $project->configuration->version,
                );
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Expected by the test.
        }

        Queue::assertNothingPushed();
        Event::assertNotDispatched(ExecutionEventBroadcast::class);
        $this->assertDatabaseCount('executions', 0);
        $this->assertSame(ProjectStatus::READY, $project->fresh()->status);

        $queuedProject = Project::query()->create([
            'name' => 'Rollback de evento',
            'type' => ProjectType::COLLECT,
            'status' => ProjectStatus::QUEUED,
            'created_by' => $operator->getKey(),
        ]);
        $execution = Execution::query()->create([
            'project_id' => $queuedProject->getKey(),
            'attempt' => 1,
            'status' => ExecutionStatus::QUEUED,
            'created_by' => $operator->getKey(),
        ]);

        try {
            DB::transaction(function () use ($execution): void {
                app(ExecutionEventRecorder::class)->record($execution, 'rolled.back');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Expected by the test.
        }

        Event::assertNotDispatched(ExecutionEventBroadcast::class);
        $this->assertDatabaseCount('execution_events', 0);
    }

    public function test_dispatch_failure_after_commit_is_visible_and_same_key_recovers_it(): void
    {
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->readyProject($operator);
        $payload = ['configuration_version' => $project->configuration->version];
        $headers = ['Idempotency-Key' => 'recover-dispatch-0001'];
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis unavailable'));

        $this->actingAs($operator)
            ->postJson(route('projects.executions.store', $project->uuid), $payload, $headers)
            ->assertStatus(503)
            ->assertJson(['recoverable' => true]);

        $this->assertDatabaseCount('executions', 1);
        $this->assertDatabaseCount('execution_commands', 1);
        $this->assertDatabaseHas('execution_commands', ['dispatched_at' => null]);
        $this->assertDatabaseHas('execution_logs', [
            'stream' => 'SYSTEM',
            'level' => 'ERROR',
        ]);
        $this->assertSame(ProjectStatus::QUEUED, $project->fresh()->status);

        Bus::fake();
        $this->actingAs($operator)
            ->postJson(route('projects.executions.store', $project->uuid), $payload, $headers)
            ->assertOk()
            ->assertJson(['created' => false]);

        Bus::assertDispatched(RunExecutionUnit::class);
        $this->assertNotNull(ExecutionCommand::query()->sole()->dispatched_at);
        $this->assertDatabaseCount('executions', 1);
    }

    public function test_roles_protect_start_read_endpoints_and_private_channel(): void
    {
        Queue::fake();
        config()->set('broadcasting.default', 'reverb');
        require base_path('routes/channels.php');
        $admin = $this->user(UserRole::ADMIN);
        $operator = $this->user(UserRole::OPERATOR);
        $auditor = $this->user(UserRole::AUDITOR);
        $unassigned = $this->user(UserRole::AUDITOR);
        $project = $this->readyProject($admin);
        app(ProjectAssignmentManager::class)->assign($project, $operator, $admin);
        app(ProjectAssignmentManager::class)->assign($project, $auditor, $admin);

        $this->actingAs($auditor)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $project->configuration->version],
            ['Idempotency-Key' => 'auditor-denied-0001'],
        )->assertForbidden();

        $start = $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $project->configuration->version],
            ['Idempotency-Key' => 'operator-start-0001'],
        )->assertCreated();
        $execution = Execution::query()->where('uuid', $start->json('execution_uuid'))->firstOrFail();

        $this->actingAs($auditor)
            ->get(route('projects.executions.show', [$project->uuid, $execution->uuid]))
            ->assertOk();
        $this->actingAs($auditor)
            ->getJson(route('projects.executions.events', [$project->uuid, $execution->uuid]))
            ->assertOk();
        $this->actingAs($unassigned)
            ->getJson(route('projects.executions.events', [$project->uuid, $execution->uuid]))
            ->assertForbidden();

        config()->set('session.driver', 'database');

        foreach ([$admin, $operator, $auditor] as $allowed) {
            $sessionId = 'channel-authorization-'.$allowed->getKey();
            DB::table('sessions')->insert([
                'id' => $sessionId,
                'user_id' => $allowed->getKey(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Channel authorization regression',
                'payload' => '',
                'last_activity' => now()->timestamp,
            ]);
            $channel = app(ProjectSessionChannels::class)->current($project, $sessionId);

            $this->actingAs($allowed)->postJson('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-'.$channel,
            ])->assertOk();
        }

        $unassignedSessionId = 'channel-authorization-'.$unassigned->getKey();
        DB::table('sessions')->insert([
            'id' => $unassignedSessionId,
            'user_id' => $unassigned->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Channel authorization regression',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
        $unassignedChannel = app(ProjectSessionChannels::class)->current($project, $unassignedSessionId);

        $this->actingAs($unassigned)->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-'.$unassignedChannel,
        ])->assertForbidden();
    }

    public function test_execution_view_and_catch_up_preserve_null_progress(): void
    {
        Queue::fake();
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->readyProject($operator);
        $response = $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $project->configuration->version],
            ['Idempotency-Key' => 'indeterminate-0001'],
        )->assertCreated();
        $execution = Execution::query()->where('uuid', $response->json('execution_uuid'))->firstOrFail();

        $this->actingAs($operator)
            ->get(route('projects.executions.show', [$project->uuid, $execution->uuid]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/executions/show')
                ->where('execution.progress', null)
                ->has('execution.steps', 4)
                ->has('events', 0));

        app(ExecutionEventRecorder::class)->record($execution, 'unknown.progress', progress: null);

        $this->actingAs($operator)
            ->getJson(route('projects.executions.events', [$project->uuid, $execution->uuid]).'?after=0')
            ->assertOk()
            ->assertJsonPath('events.0.sequence', 1)
            ->assertJsonPath('events.0.progress', null);
    }

    public function test_reload_pages_persisted_events_in_sequence_order(): void
    {
        Queue::fake();
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->readyProject($operator);
        $response = $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $project->configuration->version],
            ['Idempotency-Key' => 'paginated-catch-up-0001'],
        )->assertCreated();
        $execution = Execution::query()->where('uuid', $response->json('execution_uuid'))->firstOrFail();
        $createdAt = now();

        DB::table('execution_events')->insert(collect(range(1, 201))
            ->map(fn (int $sequence): array => [
                'execution_id' => $execution->getKey(),
                'sequence' => $sequence,
                'type' => 'progress',
                'severity' => 'INFO',
                'progress' => $sequence % 101,
                'message' => "Evento {$sequence}",
                'created_at' => $createdAt,
            ])
            ->all());
        $execution->update(['last_event_sequence' => 201]);

        $this->actingAs($operator)
            ->get(route('projects.executions.show', [$project->uuid, $execution->uuid]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 200)
                ->where('events.0.sequence', 1)
                ->where('events.199.sequence', 200));

        $this->actingAs($operator)
            ->getJson(route('projects.executions.events', [$project->uuid, $execution->uuid]).'?after=200')
            ->assertOk()
            ->assertJsonPath('events.0.sequence', 201)
            ->assertJsonPath('has_more', false);
    }

    public function test_local_artifact_storage_is_scoped_and_reports_integrity(): void
    {
        Storage::fake('local');
        $storage = new LocalArtifactStorage;
        $artifact = $storage->put('executions/demo/report.json', '{"ok":true}');

        $this->assertTrue($storage->exists($artifact->path));
        $this->assertSame('{"ok":true}', $storage->read($artifact->path));
        $this->assertSame(hash('sha256', '{"ok":true}'), $artifact->checksum);

        $this->expectException(\InvalidArgumentException::class);
        $storage->put('../escape.txt', 'blocked');
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function readyProject(User $actor): Project
    {
        $wizard = app(ProjectWizard::class);
        $project = $wizard->create($actor, [
            'name' => 'Proyecto 1D',
            'type' => ProjectType::COLLECT->value,
            'description' => 'Motor asíncrono simulado.',
        ]);
        $wizard->saveInstances($project, $actor, [[
            'uuid' => null,
            'server_uuid' => null,
            'role' => 'SOURCE',
            'server_name' => 'Servidor simulado',
            'server_host' => 'moodle-source.test',
            'name' => 'Moodle origen',
            'base_url' => 'https://moodle-source.test',
            'moodle_version' => '4.5',
            'validated' => true,
            'destination_kind' => null,
        ]]);
        $wizard->saveOptions($project, $actor, [
            'simulation_scenario' => 'SUCCESS',
            'artifact_name' => 'paquete-1d',
        ]);
        $wizard->runPreflight($project, $actor);
        $configuration = $project->fresh('configuration')->configuration;
        $wizard->confirm($project, $actor, $configuration->version, []);

        return $project->fresh(['configuration', 'moodleInstances.server']);
    }
}
