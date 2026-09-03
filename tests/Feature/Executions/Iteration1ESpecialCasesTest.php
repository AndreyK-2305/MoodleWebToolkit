<?php

namespace Tests\Feature\Executions;

use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\ExecutionCommandLease;
use App\Domain\Executions\ExecutionUnitState;
use App\Domain\Executions\ResolveExecutionConflict;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\ConflictStatus;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Events\ExecutionEventBroadcast;
use App\Jobs\RunExecutionUnit;
use App\Models\AuditLog;
use App\Models\Checkpoint;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class Iteration1ESpecialCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_reaches_the_1e_boundary_without_starting_verification(): void
    {
        [$project, $execution] = $this->startAndRunProcessing('SUCCESS');

        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertSame(ProjectStatus::RUNNING, $project->fresh()->status);
        $this->assertSame(50, $execution->fresh()->progress);
        $this->assertSame([
            ExecutionStepStatus::SUCCESS,
            ExecutionStepStatus::SUCCESS,
            ExecutionStepStatus::PENDING,
            ExecutionStepStatus::PENDING,
        ], $execution->steps()->orderBy('position')->pluck('status')->all());
        $this->assertSame(1, $execution->events()->where('type', 'iteration_1e.boundary')->count());
        $this->assertSame(0, $execution->commands()->whereNull('processed_at')->count());
    }

    public function test_warning_waits_without_an_open_command_and_resolution_continues_same_execution(): void
    {
        [$project, $execution, $operator] = $this->startAndRunProcessing('WARNING');
        $conflict = $execution->conflicts()->sole();

        $this->assertSame(ExecutionStatus::WAITING_USER_ACTION, $execution->fresh()->status);
        $this->assertSame(ExecutionStepStatus::WAITING_USER, $execution->steps()->where('step_key', 'operation')->sole()->status);
        $this->assertSame(ConflictStatus::OPEN, $conflict->status);
        $this->assertSame(0, $execution->commands()->whereNull('processed_at')->count());

        $this->travel(25)->hours();
        $this->artisan('executions:recover-dispatches')->assertSuccessful();
        $this->assertSame(ExecutionStatus::WAITING_USER_ACTION, $execution->fresh()->status);
        $this->actingAs($operator)
            ->postJson(route('action-password.confirm'), ['password' => 'password'])
            ->assertOk();

        $this->actingAs($operator)->postJson(
            route('projects.executions.conflicts.resolve', [$project->uuid, $execution->uuid, $conflict->getKey()]),
            ['decision' => 'ACCEPT', 'conflict_version' => 1],
            ['Idempotency-Key' => 'resolve-warning-0001'],
        )->assertOk()->assertJson(['created' => true, 'execution_uuid' => $execution->uuid]);

        $resumeCommand = $execution->commands()->where('command_type', ExecutionCommandType::RESOLVE_CONFLICT)->sole();
        $this->executeCommand($resumeCommand);
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertSame(50, $execution->fresh()->progress);
        $this->assertSame(ExecutionStepStatus::SUCCESS, $execution->steps()->where('step_key', 'operation')->sole()->status);
        $this->assertDatabaseHas('audit_logs', [
            'execution_id' => $execution->getKey(),
            'action' => 'EXECUTION_WARNING_ACCEPTED',
        ]);
    }

    public function test_manual_intervention_uses_the_declared_decision(): void
    {
        [$project, $execution, $operator] = $this->startAndRunProcessing('INTERVENTION');
        $conflict = $execution->conflicts()->sole();

        $this->assertSame('MANUAL_INTERVENTION', $conflict->type);
        $this->actingAs($operator)->postJson(
            route('projects.executions.conflicts.resolve', [$project->uuid, $execution->uuid, $conflict->getKey()]),
            ['decision' => 'ACCEPT', 'conflict_version' => 1],
            ['Idempotency-Key' => 'wrong-manual-0001'],
        )->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertSame(ConflictStatus::OPEN, $conflict->fresh()->status);

        $this->actingAs($operator)->postJson(
            route('projects.executions.conflicts.resolve', [$project->uuid, $execution->uuid, $conflict->getKey()]),
            ['decision' => 'CONFIRM_COMPLETED', 'conflict_version' => 1],
            ['Idempotency-Key' => 'manual-complete-0001'],
        )->assertOk()->assertJson(['created' => true]);
        $this->actingAs($operator)->postJson(
            route('projects.executions.conflicts.resolve', [$project->uuid, $execution->uuid, $conflict->getKey()]),
            ['decision' => 'CONFIRM_COMPLETED', 'conflict_version' => 1],
            ['Idempotency-Key' => 'manual-complete-0001'],
        )->assertOk()->assertJson(['created' => false]);
        $this->actingAs($operator)->postJson(
            route('projects.executions.conflicts.resolve', [$project->uuid, $execution->uuid, $conflict->getKey()]),
            ['decision' => 'ACCEPT', 'conflict_version' => 1],
            ['Idempotency-Key' => 'manual-complete-0001'],
        )->assertConflict();
        $this->executeCommand($execution->commands()->where('command_type', ExecutionCommandType::RESOLVE_CONFLICT)->sole());
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
    }

    public function test_resolving_one_incidence_does_not_continue_while_another_blocks(): void
    {
        [$project, $execution, $operator] = $this->startAndRunProcessing('WARNING');
        $step = $execution->steps()->where('step_key', 'operation')->sole();
        $first = $execution->conflicts()->sole();
        $second = $execution->conflicts()->create([
            'execution_step_id' => $step->getKey(),
            'key' => 'runtime.second-blocker',
            'type' => 'MANUAL_INTERVENTION',
            'status' => ConflictStatus::OPEN,
            'version' => 1,
            'details' => [
                'message' => 'Segunda incidencia bloqueante.',
                'allowed_decisions' => ['CONFIRM_COMPLETED'],
            ],
        ]);

        $this->actingAs($operator)->postJson(
            route('projects.executions.conflicts.resolve', [$project->uuid, $execution->uuid, $first->getKey()]),
            ['decision' => 'ACCEPT', 'conflict_version' => 1],
            ['Idempotency-Key' => 'first-blocker-0001'],
        )->assertOk();
        $this->assertSame(ExecutionStatus::WAITING_USER_ACTION, $execution->fresh()->status);
        $firstCommand = $execution->commands()->where('idempotency_key', 'first-blocker-0001')->sole();
        $this->assertNotNull($firstCommand->processed_at);

        $this->actingAs($operator)->postJson(
            route('projects.executions.conflicts.resolve', [$project->uuid, $execution->uuid, $second->getKey()]),
            ['decision' => 'CONFIRM_COMPLETED', 'conflict_version' => 1],
            ['Idempotency-Key' => 'second-blocker-0001'],
        )->assertOk();
        $secondCommand = $execution->commands()->where('idempotency_key', 'second-blocker-0001')->sole();
        $this->assertNull($secondCommand->processed_at);
        $this->executeCommand($secondCommand);
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertSame(1, $execution->events()->where('type', 'iteration_1e.boundary')->count());
    }

    public function test_failure_emits_a_private_checkpoint_and_resume_creates_an_independent_attempt(): void
    {
        [$project, $failed, $operator] = $this->startAndRunProcessing('FAILURE');
        $checkpoint = $failed->checkpoints()->sole();

        $this->assertSame(ExecutionStatus::FAILED, $failed->fresh()->status);
        $this->assertTrue($checkpoint->validated);
        $this->assertStringNotContainsString(
            $checkpoint->resume_token,
            $failed->events()->get()->toJson().$failed->logs()->get()->toJson(),
        );

        $response = $this->actingAs($operator)->postJson(
            route('projects.executions.resume', [$project->uuid, $failed->uuid]),
            ['checkpoint_id' => $checkpoint->getKey()],
            ['Idempotency-Key' => 'resume-failure-0001'],
        )->assertCreated();
        $resumed = Execution::query()->where('uuid', $response->json('execution_uuid'))->firstOrFail();

        $this->assertSame(ExecutionStatus::FAILED, $failed->fresh()->status);
        $this->assertSame(2, $resumed->attempt);
        $this->assertSame($failed->getKey(), $resumed->resumed_from_execution_id);
        $this->assertSame($checkpoint->getKey(), $resumed->resume_checkpoint_id);
        $this->assertNotSame($failed->workspace_key, $resumed->workspace_key);
        $this->assertSame(ExecutionStepStatus::REUSED, $resumed->steps()->where('step_key', 'prepare')->sole()->status);

        $this->executeCommand($resumed->commands()->where('command_type', ExecutionCommandType::RESUME)->sole());
        $this->assertSame(ExecutionStatus::RUNNING, $resumed->fresh()->status);
        $this->assertSame(50, $resumed->fresh()->progress);
        $this->assertSame(ExecutionStatus::FAILED, $failed->fresh()->status);
    }

    public function test_resume_is_rejected_without_an_adapter_validated_checkpoint(): void
    {
        [$project, $failed, $operator] = $this->startAndRunProcessing('FAILURE');
        $invalid = Checkpoint::query()->create([
            'execution_id' => $failed->getKey(),
            'step_key' => 'operation',
            'type' => 'UNTRUSTED_STATE',
            'adapter_key' => 'fake',
            'resume_token' => 'not-valid',
            'validated' => false,
        ]);

        $this->actingAs($operator)->postJson(
            route('projects.executions.resume', [$project->uuid, $failed->uuid]),
            ['checkpoint_id' => $invalid->getKey()],
            ['Idempotency-Key' => 'resume-invalid-0001'],
        )->assertUnprocessable()->assertJsonValidationErrors('checkpoint');
        $this->assertDatabaseCount('executions', 1);
    }

    public function test_repeated_queued_cancellation_waits_for_worker_confirmation_and_revokes_old_work(): void
    {
        Queue::fake();
        $operator = User::factory()->create(['role' => UserRole::OPERATOR, 'is_active' => true]);
        $project = $this->readyProject($operator, 'SUCCESS');
        $start = $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $project->configuration->version],
            ['Idempotency-Key' => 'cancel-start-0001'],
        )->assertCreated();
        $execution = Execution::query()->where('uuid', $start->json('execution_uuid'))->firstOrFail();

        foreach (range(1, 2) as $_) {
            $this->actingAs($operator)->postJson(
                route('projects.executions.cancel', [$project->uuid, $execution->uuid]),
                [],
                ['Idempotency-Key' => 'cancel-request-0001'],
            )->assertStatus($_ === 1 ? 202 : 200);
        }

        $this->assertSame(ExecutionStatus::CANCELLING, $execution->fresh()->status);
        $this->assertNull($execution->finished_at);
        $this->assertSame(1, $execution->commands()->where('command_type', ExecutionCommandType::CANCEL)->count());
        $this->executeCommand($execution->commands()->where('command_type', ExecutionCommandType::START)->sole());
        $this->executeCommand($execution->commands()->where('command_type', ExecutionCommandType::CANCEL)->sole());

        $this->assertSame(ExecutionStatus::CANCELLED, $execution->fresh()->status);
        $this->assertSame(ProjectStatus::CANCELLED, $project->fresh()->status);
        $this->assertNotNull($execution->fresh()->finished_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'EXECUTION_CANCELLED')->count());
    }

    public function test_waiting_execution_can_be_cancelled_without_starting_a_continuation(): void
    {
        [$project, $execution, $operator] = $this->startAndRunProcessing('WARNING');
        $conflict = $execution->conflicts()->sole();

        $this->actingAs($operator)->postJson(
            route('projects.executions.cancel', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'cancel-waiting-0001'],
        )->assertAccepted()->assertJson(['status' => 'CANCELLING']);
        $this->assertSame(ExecutionStatus::CANCELLING, $execution->fresh()->status);
        $this->assertSame(ConflictStatus::OPEN, $conflict->fresh()->status);

        $this->executeCommand($execution->commands()->where('command_type', ExecutionCommandType::CANCEL)->sole());

        $this->assertSame(ExecutionStatus::CANCELLED, $execution->fresh()->status);
        $this->assertSame(ConflictStatus::IGNORED, $conflict->fresh()->status);
        $this->assertSame(0, $execution->commands()->whereNull('processed_at')->count());
        $this->assertSame(0, $execution->events()->where('type', 'iteration_1e.boundary')->count());
    }

    public function test_recovery_redispatches_pending_continue_commands(): void
    {
        Queue::fake();
        $operator = User::factory()->create(['role' => UserRole::OPERATOR, 'is_active' => true]);
        $project = $this->readyProject($operator, 'SUCCESS');
        $response = $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $project->configuration->version],
            ['Idempotency-Key' => 'recover-continue-0001'],
        )->assertCreated();
        $execution = Execution::query()->where('uuid', $response->json('execution_uuid'))->firstOrFail();
        $this->executeCommand($execution->commands()->where('command_type', ExecutionCommandType::START)->sole());
        $continue = $execution->commands()->where('command_type', ExecutionCommandType::CONTINUE)->sole();
        $continue->update(['dispatched_at' => null]);

        $this->artisan('executions:recover-dispatches --stale=1')->assertSuccessful();

        $this->assertNotNull($continue->fresh()->dispatched_at);
        $this->assertSame(2, $continue->fresh()->dispatch_attempts);
        Queue::assertPushed(RunExecutionUnit::class, 3);
    }

    public function test_recovery_closes_an_abandoned_continue_without_reexecuting_effects(): void
    {
        Queue::fake();
        $operator = User::factory()->create(['role' => UserRole::OPERATOR, 'is_active' => true]);
        $project = $this->readyProject($operator, 'SUCCESS');
        $response = $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $project->configuration->version],
            ['Idempotency-Key' => 'abandon-continue-0001'],
        )->assertCreated();
        $execution = Execution::query()->where('uuid', $response->json('execution_uuid'))->firstOrFail();
        $this->executeCommand($execution->commands()->where('command_type', ExecutionCommandType::START)->sole());
        $continue = $execution->commands()->where('command_type', ExecutionCommandType::CONTINUE)->sole();
        $claimed = app(ExecutionCommandLease::class)->claim((int) $continue->getKey());
        $this->assertNotNull($claimed);
        $running = app(ExecutionUnitState::class)->begin((int) $continue->getKey(), $claimed->owner);
        $step = $running->steps()->where('step_key', 'operation')->sole();
        app(ExecutionUnitState::class)->applyEvent(
            (int) $continue->getKey(),
            $claimed->owner,
            (int) $step->getKey(),
            new NormalizedToolEvent('phase.started', 'operation', progress: 25),
        );
        $continue->update(['lease_expires_at' => now()->utc()->subSecond()]);

        $this->artisan('executions:recover-dispatches')->assertSuccessful();

        $this->assertSame(ExecutionStatus::FAILED, $execution->fresh()->status);
        $this->assertSame(ExecutionStepStatus::FAILED, $step->fresh()->status);
        $this->assertSame(1, $execution->events()->where('type', 'execution.abandoned')->count());
        $this->assertNotNull($continue->fresh()->processed_at);
    }

    public function test_rolled_back_resolution_persists_no_command_audit_or_broadcast(): void
    {
        [$project, $execution, $operator] = $this->startAndRunProcessing('WARNING');
        $conflict = $execution->conflicts()->sole();
        $beforeEvents = $execution->events()->count();
        $beforeAudits = AuditLog::query()->where('execution_id', $execution->getKey())->count();
        Event::fake([ExecutionEventBroadcast::class]);

        try {
            DB::transaction(function () use ($execution, $conflict, $operator): void {
                app(ResolveExecutionConflict::class)->resolve(
                    $execution,
                    $conflict,
                    $operator,
                    'ACCEPT',
                    1,
                    'rollback-resolution-0001',
                );
                throw new RuntimeException('rollback');
            });
            $this->fail('La transacción exterior no revirtió.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        Event::assertNotDispatched(ExecutionEventBroadcast::class);
        $this->assertSame(ConflictStatus::OPEN, $conflict->fresh()->status);
        $this->assertSame($beforeEvents, $execution->events()->count());
        $this->assertSame($beforeAudits, AuditLog::query()->where('execution_id', $execution->getKey())->count());
        $this->assertSame(0, $execution->commands()->where('idempotency_key', 'rollback-resolution-0001')->count());
    }

    /** @return array{Project, Execution, User} */
    private function startAndRunProcessing(string $scenario): array
    {
        Queue::fake();
        $operator = User::factory()->create(['role' => UserRole::OPERATOR, 'is_active' => true]);
        $project = $this->readyProject($operator, $scenario);
        $response = $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $project->configuration->version],
            ['Idempotency-Key' => 'start-'.strtolower($scenario).'-0001'],
        )->assertCreated();
        $execution = Execution::query()->where('uuid', $response->json('execution_uuid'))->firstOrFail();
        $this->executeCommand($execution->commands()->where('command_type', ExecutionCommandType::START)->sole());
        $this->executeCommand($execution->commands()->where('command_type', ExecutionCommandType::CONTINUE)->sole());

        return [$project, $execution, $operator];
    }

    private function executeCommand(ExecutionCommand $command): void
    {
        (new RunExecutionUnit((int) $command->getKey()))->handle(
            app(ExecutionProvider::class),
            app(ToolAdapter::class),
        );
    }

    private function readyProject(User $actor, string $scenario): Project
    {
        $wizard = app(ProjectWizard::class);
        $project = $wizard->create($actor, [
            'name' => "Caso especial {$scenario}",
            'type' => ProjectType::COLLECT->value,
            'description' => 'Motor determinista 1E.',
        ]);
        $wizard->saveInstances($project, $actor, [[
            'uuid' => null,
            'server_uuid' => null,
            'role' => 'SOURCE',
            'server_name' => 'Servidor simulado',
            'server_host' => strtolower($scenario).'.test',
            'name' => 'Moodle origen',
            'base_url' => 'https://'.strtolower($scenario).'.test',
            'moodle_version' => '4.5',
            'validated' => true,
            'destination_kind' => null,
        ]]);
        $wizard->saveOptions($project, $actor, [
            'simulation_scenario' => 'SUCCESS',
            'processing_scenario' => $scenario,
            'artifact_name' => 'paquete-1e',
        ]);
        $wizard->runPreflight($project, $actor);
        $configuration = $project->fresh('configuration')->configuration;
        $wizard->confirm($project, $actor, $configuration->version, []);

        return $project->fresh(['configuration', 'moodleInstances.server']);
    }
}
