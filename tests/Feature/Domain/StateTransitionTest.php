<?php

namespace Tests\Feature\Domain;

use App\Domain\Executions\ExecutionLifecycle;
use App\Domain\Projects\ProjectExecutionManager;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidStateTransition;
use App\Exceptions\NewExecutionBlocked;
use App\Exceptions\ProjectIsReadOnly;
use App\Models\Artifact;
use App\Models\ProjectConfiguration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class StateTransitionTest extends DomainTestCase
{
    public function test_valid_execution_transitions_keep_project_and_execution_in_sync(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $project = $this->project($admin, ProjectStatus::READY);
        $execution = app(ProjectExecutionManager::class)->queue($project, $admin);
        $lifecycle = app(ExecutionLifecycle::class);

        $this->assertSame(ExecutionStatus::QUEUED, $execution->status);
        $this->assertSame(ProjectStatus::QUEUED, $project->fresh()->status);
        $this->assertNull($execution->progress);
        $this->assertCount(0, $execution->commands);
        $this->assertDatabaseCount('jobs', 0);

        foreach ([
            ExecutionStatus::RUNNING,
            ExecutionStatus::WAITING_USER_ACTION,
            ExecutionStatus::RUNNING,
            ExecutionStatus::VERIFYING,
            ExecutionStatus::REVIEW,
            ExecutionStatus::COMPLETED,
        ] as $target) {
            $execution = $lifecycle->transition($execution, $target, $admin);
            $this->assertSame($target, $execution->status);
            $this->assertSame($target->projectStatus(), $project->fresh()->status);
        }

        $this->assertNotNull($execution->finished_at);
    }

    public function test_invalid_project_transition_is_rejected(): void
    {
        $project = $this->project(status: ProjectStatus::READY);

        $this->expectException(InvalidStateTransition::class);

        $project->transitionTo(ProjectStatus::RUNNING);
    }

    public function test_invalid_execution_transition_is_rejected(): void
    {
        $execution = $this->execution($this->project(status: ProjectStatus::QUEUED));

        $this->expectException(InvalidStateTransition::class);

        $execution->transitionTo(ExecutionStatus::VERIFYING);
    }

    public function test_failure_path_keeps_states_coherent_and_next_attempt_is_distinct(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $project = $this->project($admin, ProjectStatus::READY);
        $manager = app(ProjectExecutionManager::class);
        $lifecycle = app(ExecutionLifecycle::class);
        $first = $manager->queue($project, $admin);

        $first = $lifecycle->transition($first, ExecutionStatus::RUNNING, $admin);
        $first = $lifecycle->transition($first, ExecutionStatus::FAILED, $admin);

        $this->assertSame(ExecutionStatus::FAILED, $first->status);
        $this->assertSame(ProjectStatus::FAILED, $project->fresh()->status);

        $second = $manager->queue($project->fresh(), $admin);

        $this->assertSame(2, $second->attempt);
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(ExecutionStatus::FAILED, $first->fresh()->status);
        $this->assertSame(ExecutionStatus::QUEUED, $second->status);
        $this->assertSame(ProjectStatus::QUEUED, $project->fresh()->status);
    }

    public function test_cancellation_path_keeps_project_and_execution_coherent(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $project = $this->project($admin, ProjectStatus::READY);
        $execution = app(ProjectExecutionManager::class)->queue($project, $admin);
        $lifecycle = app(ExecutionLifecycle::class);

        foreach ([ExecutionStatus::RUNNING, ExecutionStatus::CANCELLING, ExecutionStatus::CANCELLED] as $target) {
            $execution = $lifecycle->transition($execution, $target, $admin);
            $this->assertSame($target, $execution->status);
            $this->assertSame($target->projectStatus(), $project->fresh()->status);
        }
    }

    public function test_queue_rolls_back_execution_when_project_update_fails_mid_transaction(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $project = $this->project($admin, ProjectStatus::READY);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fail_test_project_queue() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'QUEUED' THEN
                    RAISE EXCEPTION 'forced queue failure' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER projects_force_queue_failure
                BEFORE UPDATE ON projects
                FOR EACH ROW EXECUTE FUNCTION fail_test_project_queue();
            SQL);

        $caught = null;

        try {
            app(ProjectExecutionManager::class)->queue($project, $admin);
        } catch (QueryException $exception) {
            $caught = $exception;
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS projects_force_queue_failure ON projects');
            DB::statement('DROP FUNCTION IF EXISTS fail_test_project_queue()');
        }

        $this->assertInstanceOf(QueryException::class, $caught);
        $this->assertSame(ProjectStatus::READY, $project->fresh()->status);
        $this->assertDatabaseMissing('executions', ['project_id' => $project->getKey()]);
    }

    public function test_review_blocks_a_new_execution_in_the_domain_service(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $project = $this->project($admin, ProjectStatus::REVIEW);

        $this->expectException(NewExecutionBlocked::class);

        app(ProjectExecutionManager::class)->queue($project, $admin);
    }

    public function test_completed_project_cannot_be_executed_again(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $project = $this->project($admin, ProjectStatus::COMPLETED);

        $this->expectException(NewExecutionBlocked::class);

        app(ProjectExecutionManager::class)->queue($project, $admin);
    }

    public function test_completed_project_rejects_model_changes(): void
    {
        $project = $this->project(status: ProjectStatus::COMPLETED);

        $this->expectException(ProjectIsReadOnly::class);

        $project->update(['name' => 'Intento de reapertura']);
    }

    public function test_completed_project_is_read_only_at_database_level(): void
    {
        $project = $this->project(status: ProjectStatus::COMPLETED);

        $this->expectException(QueryException::class);

        DB::table('projects')->where('id', $project->getKey())->update(['name' => 'Mutación directa']);
    }

    public function test_completed_project_rejects_new_child_records(): void
    {
        $project = $this->project(status: ProjectStatus::COMPLETED);

        $this->expectException(QueryException::class);

        ProjectConfiguration::query()->create([
            'project_id' => $project->getKey(),
            'settings' => [],
        ]);
    }

    public function test_completed_project_rejects_indirect_configuration_updates(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $project = $this->project($admin, ProjectStatus::REVIEW);
        $configuration = ProjectConfiguration::query()->create([
            'project_id' => $project->getKey(),
            'settings' => ['locale' => 'es'],
        ]);
        $execution = $this->execution($project, ExecutionStatus::REVIEW, creator: $admin);
        app(ExecutionLifecycle::class)->transition($execution, ExecutionStatus::COMPLETED, $admin);

        $this->expectException(QueryException::class);

        $configuration->update(['settings' => ['locale' => 'en']]);
    }

    public function test_completed_project_rejects_indirect_result_updates(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $project = $this->project($admin, ProjectStatus::REVIEW);
        $execution = $this->execution($project, ExecutionStatus::REVIEW, creator: $admin);
        $artifact = Artifact::query()->create([
            'execution_id' => $execution->getKey(),
            'type' => 'REPORT',
            'disk' => 'local',
            'path' => 'reports/completed.json',
            'filename' => 'completed.json',
            'size' => 2,
            'sha256' => str_repeat('a', 64),
        ]);
        app(ExecutionLifecycle::class)->transition($execution, ExecutionStatus::COMPLETED, $admin);

        $this->expectException(QueryException::class);

        $artifact->update(['filename' => 'mutated.json']);
    }

    public function test_completed_project_rejects_moving_its_execution_to_another_project(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $completedProject = $this->project($admin, ProjectStatus::REVIEW);
        $execution = $this->execution($completedProject, ExecutionStatus::REVIEW, creator: $admin);
        app(ExecutionLifecycle::class)->transition($execution, ExecutionStatus::COMPLETED, $admin);
        $activeProject = $this->project($admin, ProjectStatus::READY);

        $this->expectException(QueryException::class);

        DB::table('executions')
            ->where('id', $execution->getKey())
            ->update(['project_id' => $activeProject->getKey()]);
    }

    public function test_completed_project_rejects_moving_its_result_to_another_execution(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $completedProject = $this->project($admin, ProjectStatus::REVIEW);
        $completedExecution = $this->execution(
            $completedProject,
            ExecutionStatus::REVIEW,
            creator: $admin,
        );
        $artifact = Artifact::query()->create([
            'execution_id' => $completedExecution->getKey(),
            'type' => 'REPORT',
            'disk' => 'local',
            'path' => 'reports/reparent.json',
            'filename' => 'reparent.json',
            'size' => 2,
            'sha256' => str_repeat('b', 64),
        ]);
        app(ExecutionLifecycle::class)->transition(
            $completedExecution,
            ExecutionStatus::COMPLETED,
            $admin,
        );
        $activeExecution = $this->execution(
            $this->project($admin, ProjectStatus::FAILED),
            ExecutionStatus::FAILED,
            creator: $admin,
        );

        $this->expectException(QueryException::class);

        DB::table('artifacts')
            ->where('id', $artifact->getKey())
            ->update(['execution_id' => $activeExecution->getKey()]);
    }
}
