<?php

namespace Tests\Feature\Executions;

use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\ExecutionCommandLease;
use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Executions\ExecutionFailureCloser;
use App\Domain\Executions\ExecutionLifecycle;
use App\Domain\Executions\ExecutionUnitState;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Domain\Tools\DTOs\ExecutionStepDefinition;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\EventSeverity;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Events\ExecutionEventBroadcast;
use App\Exceptions\ExecutionCommandLeaseLost;
use App\Jobs\RunExecutionUnit;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\ExecutionLog;
use App\Models\ExecutionStep;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ExecutionFailureRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_exception_after_phase_started_closes_the_active_step(): void
    {
        [$project, $command] = $this->startExecution();
        [$job, $failure] = $this->runUntilAdapterFailure($command, new ControlledFailureAdapter);

        $job->failed($failure);

        $execution = $command->execution->fresh();
        $steps = $execution->steps()->orderBy('position')->get();
        $failedStep = $steps->first();
        $closedCommand = $command->fresh();

        $this->assertSame(ProjectStatus::FAILED, $project->fresh()->status);
        $this->assertSame(ExecutionStatus::FAILED, $execution->status);
        $this->assertNotNull($execution->finished_at);
        $this->assertSame(ExecutionStepStatus::FAILED, $failedStep->status);
        $this->assertNotNull($failedStep->started_at);
        $this->assertNotNull($failedStep->finished_at);
        $this->assertSame(3, $steps->where('status', ExecutionStepStatus::PENDING)->count());
        $this->assertNotNull($closedCommand->processed_at);
        $this->assertNull($closedCommand->lease_owner);
        $this->assertNull($closedCommand->lease_expires_at);

        $event = $execution->events()->where('type', 'tool.failed')->sole();
        $this->assertSame('prepare', $event->step_key);
        $this->assertSame(EventSeverity::ERROR, $event->severity);
        $this->assertSame($failedStep->getKey(), ExecutionLog::query()
            ->where('execution_id', $execution->getKey())
            ->sole()
            ->execution_step_id);
        $this->assertSame(1, AuditLog::query()
            ->where('execution_id', $execution->getKey())
            ->where('action', 'EXECUTION_WORKER_FAILED')
            ->count());
    }

    public function test_failure_before_a_step_starts_does_not_invent_executed_work(): void
    {
        [$project, $command] = $this->startExecution();
        [$job, $failure] = $this->runUntilAdapterFailure($command, new ControlledFailureAdapter(true));

        $job->failed($failure);

        $execution = $command->execution->fresh();
        $this->assertSame(ProjectStatus::FAILED, $project->fresh()->status);
        $this->assertSame(ExecutionStatus::FAILED, $execution->status);
        $this->assertSame(4, $execution->steps()->where('status', ExecutionStepStatus::PENDING)->count());
        $this->assertSame(0, $execution->steps()->whereNotNull('started_at')->count());
        $this->assertNull($execution->events()->where('type', 'tool.failed')->sole()->step_key);
        $this->assertNull(ExecutionLog::query()
            ->where('execution_id', $execution->getKey())
            ->sole()
            ->execution_step_id);
    }

    public function test_closure_preserves_successful_and_pending_steps_while_failing_only_the_active_step(): void
    {
        [, $command] = $this->startExecution();
        $claimed = app(ExecutionCommandLease::class)->claim((int) $command->getKey());
        $this->assertNotNull($claimed);
        $execution = app(ExecutionUnitState::class)->begin((int) $command->getKey(), $claimed->owner);
        $steps = $execution->steps()->orderBy('position')->get();
        $steps[0]->update([
            'status' => ExecutionStepStatus::SUCCESS,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $steps[1]->update([
            'status' => ExecutionStepStatus::RUNNING,
            'started_at' => now(),
        ]);

        $this->assertTrue(app(ExecutionFailureCloser::class)->closeWorkerFailure((int) $command->getKey()));

        $statuses = $execution->steps()->orderBy('position')->pluck('status')->all();
        $this->assertSame([
            ExecutionStepStatus::SUCCESS,
            ExecutionStepStatus::FAILED,
            ExecutionStepStatus::PENDING,
            ExecutionStepStatus::PENDING,
        ], $statuses);
        $this->assertNotNull($steps[0]->fresh()->finished_at);
        $this->assertNotNull($steps[1]->fresh()->finished_at);
        $this->assertNull($steps[2]->fresh()->started_at);
        $this->assertNull($steps[2]->fresh()->finished_at);
        $this->assertNull($steps[3]->fresh()->started_at);
        $this->assertNull($steps[3]->fresh()->finished_at);
    }

    public function test_reconciler_closes_an_expired_claim_when_failed_notification_could_not_persist(): void
    {
        [$project, $command] = $this->startExecution();
        $adapter = new ControlledFailureAdapter;
        [$job, $failure] = $this->runUntilAdapterFailure($command, $adapter);
        $unavailableCloser = Mockery::mock(ExecutionFailureCloser::class);
        $unavailableCloser->shouldReceive('closeWorkerFailure')
            ->once()
            ->andThrow(new RuntimeException('PostgreSQL unavailable'));
        $this->app->instance(ExecutionFailureCloser::class, $unavailableCloser);

        $job->failed($failure);
        $this->app->forgetInstance(ExecutionFailureCloser::class);

        $this->assertNull($command->fresh()->processed_at);
        $this->assertSame(ExecutionStatus::RUNNING, $command->execution->fresh()->status);
        $this->travel(app(ExecutionCommandLease::class)->durationSeconds() + 1)->seconds();

        $this->artisan('executions:recover-dispatches')->assertSuccessful();

        $this->assertSame(1, $adapter->calls);
        $this->assertNotNull($command->fresh()->processed_at);
        $this->assertSame(ProjectStatus::FAILED, $project->fresh()->status);
        $this->assertSame(ExecutionStatus::FAILED, $command->execution->fresh()->status);
        $this->assertSame(1, $command->execution->events()->where('type', 'execution.abandoned')->count());
    }

    public function test_reconciler_does_not_close_a_claim_within_its_execution_lease(): void
    {
        [$project, $command] = $this->startExecution();
        $this->runUntilAdapterFailure($command, new ControlledFailureAdapter);
        $this->assertSame(240, app(ExecutionCommandLease::class)->durationSeconds());
        $this->travel(
            app(ExecutionCommandLease::class)->durationSeconds()
            - ExecutionCommandLease::ABANDONMENT_GRACE_SECONDS,
        )->seconds();

        $this->artisan('executions:recover-dispatches')->assertSuccessful();

        $this->assertNull($command->fresh()->processed_at);
        $this->assertSame(ProjectStatus::RUNNING, $project->fresh()->status);
        $this->assertSame(ExecutionStatus::RUNNING, $command->execution->fresh()->status);
        $this->assertSame(ExecutionStepStatus::RUNNING, $command->execution->steps()->orderBy('position')->firstOrFail()->status);
        $this->assertSame(0, $command->execution->events()->where('type', 'execution.abandoned')->count());
    }

    public function test_legacy_claim_without_lease_is_reconciled_after_the_same_execution_window(): void
    {
        [$project, $command] = $this->startExecution();
        $this->runUntilAdapterFailure($command, new ControlledFailureAdapter);
        $command->update([
            'processing_started_at' => now(),
            'lease_owner' => null,
            'lease_expires_at' => null,
        ]);
        $this->travel(app(ExecutionCommandLease::class)->durationSeconds() + 1)->seconds();

        $this->artisan('executions:recover-dispatches')->assertSuccessful();

        $this->assertNotNull($command->fresh()->processed_at);
        $this->assertSame(ProjectStatus::FAILED, $project->fresh()->status);
        $this->assertSame(ExecutionStatus::FAILED, $command->execution->fresh()->status);
        $this->assertSame(1, $command->execution->events()->where('type', 'execution.abandoned')->count());
    }

    public function test_processed_1d_boundary_at_twenty_five_percent_is_never_reconciled_as_abandoned(): void
    {
        [$project, $command] = $this->startExecution();
        $job = new RunExecutionUnit((int) $command->getKey());

        $job->handle(app(ExecutionProvider::class), app(ToolAdapter::class));
        $this->travel(app(ExecutionCommandLease::class)->durationSeconds() + 1)->seconds();
        $this->artisan('executions:recover-dispatches')->assertSuccessful();

        $execution = $command->execution->fresh();
        $this->assertSame(ProjectStatus::RUNNING, $project->fresh()->status);
        $this->assertSame(ExecutionStatus::RUNNING, $execution->status);
        $this->assertSame(25, $execution->progress);
        $this->assertNotNull($command->fresh()->processed_at);
        $this->assertSame(ExecutionStepStatus::SUCCESS, $execution->steps()->orderBy('position')->firstOrFail()->status);
        $this->assertSame(3, $execution->steps()->where('status', ExecutionStepStatus::PENDING)->count());
        $this->assertSame(6, $execution->events()->count());
        $this->assertSame(0, $execution->events()->where('type', 'execution.abandoned')->count());
    }

    public function test_old_worker_cannot_persist_after_reconciler_closes_its_expired_lease(): void
    {
        [, $command] = $this->startExecution();
        $this->runUntilAdapterFailure($command, new ControlledFailureAdapter);
        $claimed = $command->fresh();
        $owner = $claimed->lease_owner;
        $step = $claimed->execution->steps()->orderBy('position')->firstOrFail();
        $this->assertNotNull($owner);
        $this->travel(app(ExecutionCommandLease::class)->durationSeconds() + 1)->seconds();
        $this->assertTrue(app(ExecutionFailureCloser::class)->closeAbandoned((int) $command->getKey()));
        $eventCount = $claimed->execution->events()->count();

        try {
            app(ExecutionUnitState::class)->applyEvent(
                (int) $command->getKey(),
                $owner,
                (int) $step->getKey(),
                new NormalizedToolEvent('phase.completed', 'prepare', progress: 25),
            );
            $this->fail('The stale worker was allowed to persist after lease closure.');
        } catch (ExecutionCommandLeaseLost) {
            // Expected: the processed command no longer grants write ownership.
        }

        $this->assertSame(ExecutionStepStatus::FAILED, $step->fresh()->status);
        $this->assertSame(ExecutionStatus::FAILED, $claimed->execution->fresh()->status);
        $this->assertSame($eventCount, $claimed->execution->events()->count());
    }

    public function test_repeated_failed_callback_and_reconciliation_do_not_duplicate_evidence(): void
    {
        [, $command] = $this->startExecution();
        [$job, $failure] = $this->runUntilAdapterFailure($command, new ControlledFailureAdapter);

        $job->failed($failure);
        $job->failed($failure);
        $this->travel(app(ExecutionCommandLease::class)->durationSeconds() + 1)->seconds();
        $this->artisan('executions:recover-dispatches')->assertSuccessful();
        $this->artisan('executions:recover-dispatches')->assertSuccessful();

        $execution = $command->execution;
        $this->assertSame(1, $execution->events()->where('type', 'tool.failed')->count());
        $this->assertSame(0, $execution->events()->where('type', 'execution.abandoned')->count());
        $this->assertSame(1, ExecutionLog::query()->where('execution_id', $execution->getKey())->count());
        $this->assertSame(1, AuditLog::query()
            ->where('execution_id', $execution->getKey())
            ->where('action', 'EXECUTION_WORKER_FAILED')
            ->count());
    }

    public function test_failure_inside_closure_transaction_rolls_back_and_can_be_reconciled_later(): void
    {
        [$project, $command] = $this->startExecution();
        $this->runUntilAdapterFailure($command, new ControlledFailureAdapter);
        $execution = $command->execution;
        $step = $execution->steps()->orderBy('position')->firstOrFail();
        $before = [
            'events' => $execution->events()->count(),
            'logs' => ExecutionLog::query()->where('execution_id', $execution->getKey())->count(),
            'audits' => AuditLog::query()->where('execution_id', $execution->getKey())->count(),
        ];
        $realRecorder = app(ExecutionEventRecorder::class);
        $failingRecorder = Mockery::mock(ExecutionEventRecorder::class);
        $failingRecorder->shouldReceive('recordNormalized')
            ->once()
            ->andReturnUsing(function (Execution $lockedExecution, NormalizedToolEvent $event) use ($realRecorder): never {
                $realRecorder->recordNormalized($lockedExecution, $event);

                throw new RuntimeException('Controlled failure before closure commit.');
            });
        $closer = new ExecutionFailureCloser(
            app(ExecutionCommandLease::class),
            app(ExecutionLifecycle::class),
            $failingRecorder,
        );

        try {
            $closer->closeWorkerFailure((int) $command->getKey());
            $this->fail('The controlled closure failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Controlled failure before closure commit.', $exception->getMessage());
        }

        $this->assertNull($command->fresh()->processed_at);
        $this->assertSame(ProjectStatus::RUNNING, $project->fresh()->status);
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertSame(ExecutionStepStatus::RUNNING, $step->fresh()->status);
        $this->assertNull($step->fresh()->finished_at);
        $this->assertSame($before['events'], $execution->events()->count());
        $this->assertSame($before['logs'], ExecutionLog::query()->where('execution_id', $execution->getKey())->count());
        $this->assertSame($before['audits'], AuditLog::query()->where('execution_id', $execution->getKey())->count());

        $this->travel(app(ExecutionCommandLease::class)->durationSeconds() + 1)->seconds();
        $this->assertTrue(app(ExecutionFailureCloser::class)->closeAbandoned((int) $command->getKey()));
        $this->assertNotNull($command->fresh()->processed_at);
        $this->assertSame(ProjectStatus::FAILED, $project->fresh()->status);
        $this->assertSame(ExecutionStatus::FAILED, $execution->fresh()->status);
        $this->assertSame(ExecutionStepStatus::FAILED, $step->fresh()->status);
    }

    public function test_rolled_back_closure_emits_no_broadcast(): void
    {
        [$project, $command] = $this->startExecution();
        $this->runUntilAdapterFailure($command, new ControlledFailureAdapter);
        $execution = $command->execution;
        $step = $execution->steps()->orderBy('position')->firstOrFail();
        Event::fake([ExecutionEventBroadcast::class]);

        try {
            DB::transaction(function () use ($command): void {
                app(ExecutionFailureCloser::class)->closeWorkerFailure((int) $command->getKey());

                throw new RuntimeException('Rollback outer transaction.');
            });
            $this->fail('The outer rollback was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback outer transaction.', $exception->getMessage());
        }

        Event::assertNotDispatched(ExecutionEventBroadcast::class);
        $this->assertNull($command->fresh()->processed_at);
        $this->assertSame(ProjectStatus::RUNNING, $project->fresh()->status);
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertSame(ExecutionStepStatus::RUNNING, $step->fresh()->status);
        $this->assertSame(0, $execution->events()->where('type', 'tool.failed')->count());
        $this->assertSame(0, ExecutionLog::query()->where('execution_id', $execution->getKey())->count());
    }

    /** @return array{Project, ExecutionCommand} */
    private function startExecution(): array
    {
        Queue::fake();
        $operator = User::factory()->create(['role' => UserRole::OPERATOR, 'is_active' => true]);
        $project = $this->readyProject($operator);
        $response = $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $project->configuration->version],
            ['Idempotency-Key' => 'failure-recovery-'.fake()->uuid()],
        )->assertCreated();
        $execution = Execution::query()->where('uuid', $response->json('execution_uuid'))->firstOrFail();

        return [$project, $execution->commands()->sole()];
    }

    /** @return array{RunExecutionUnit, RuntimeException} */
    private function runUntilAdapterFailure(
        ExecutionCommand $command,
        ControlledFailureAdapter $adapter,
    ): array {
        $job = new RunExecutionUnit((int) $command->getKey());

        try {
            $job->handle(app(ExecutionProvider::class), $adapter);
        } catch (RuntimeException $exception) {
            $this->assertSame('Controlled adapter failure.', $exception->getMessage());

            return [$job, $exception];
        }

        $this->fail('The simulated adapter failure was not raised.');
    }

    private function readyProject(User $actor): Project
    {
        $wizard = app(ProjectWizard::class);
        $project = $wizard->create($actor, [
            'name' => 'Recuperación de fallo 1D',
            'type' => ProjectType::COLLECT->value,
            'description' => 'Regresión de cierre durable.',
        ]);
        $wizard->saveInstances($project, $actor, [[
            'uuid' => null,
            'server_uuid' => null,
            'role' => 'SOURCE',
            'server_name' => 'Servidor simulado',
            'server_host' => 'failure-source.test',
            'name' => 'Moodle origen',
            'base_url' => 'https://failure-source.test',
            'moodle_version' => '4.5',
            'validated' => true,
            'destination_kind' => null,
        ]]);
        $wizard->saveOptions($project, $actor, [
            'simulation_scenario' => 'SUCCESS',
            'artifact_name' => 'failure-recovery',
        ]);
        $wizard->runPreflight($project, $actor);
        $configuration = $project->fresh('configuration')->configuration;
        $wizard->confirm($project, $actor, $configuration->version, []);

        return $project->fresh(['configuration', 'moodleInstances.server']);
    }
}

class ControlledFailureAdapter implements ToolAdapter
{
    public int $calls = 0;

    public function __construct(private readonly bool $failBeforeStep = false) {}

    public function key(): string
    {
        return 'controlled-failure';
    }

    public function capabilities(): array
    {
        return ['resume' => false, 'retry' => false, 'cancel' => false, 'pause' => false];
    }

    public function plan(Project $project): array
    {
        return [new ExecutionStepDefinition('prepare', 'Preparación', 1)];
    }

    public function executeUnit(Execution $execution, ExecutionStep $step): iterable
    {
        $this->calls++;

        if ($this->failBeforeStep) {
            throw new RuntimeException('Controlled adapter failure.');
        }

        yield new NormalizedToolEvent('phase.started', $step->step_key);

        throw new RuntimeException('Controlled adapter failure.');
    }
}
