<?php

namespace Tests\Feature\Domain;

use App\Domain\Executions\ExecutionLifecycle;
use App\Domain\Projects\ProjectAssignmentManager;
use App\Domain\Projects\ProjectExecutionManager;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidProjectAssignment;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;

class AssignmentPermissionsTest extends DomainTestCase
{
    public function test_admin_has_global_access_and_only_admin_manages_assignments(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->project($admin, ProjectStatus::READY);
        $manager = app(ProjectAssignmentManager::class);

        $this->assertTrue($admin->can('view', $project));
        $this->assertTrue($admin->can('update', $project));
        $this->assertTrue($admin->can('execute', $project));

        $assignment = $manager->assign($project, $operator, $admin);

        $this->assertTrue($assignment->project->is($project));
        $this->assertTrue($assignment->user->is($operator));
        $this->assertSame(1, $manager->assign($project, $operator, $admin)->getKey());
        $this->assertDatabaseCount('project_assignments', 1);
    }

    public function test_operator_only_controls_assigned_projects(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $operator = $this->user(UserRole::OPERATOR);
        $assigned = $this->project($admin, ProjectStatus::READY);
        $unassigned = $this->project($admin, ProjectStatus::READY);
        app(ProjectAssignmentManager::class)->assign($assigned, $operator, $admin);

        $this->assertTrue($operator->can('view', $assigned));
        $this->assertTrue($operator->can('update', $assigned));
        $this->assertTrue($operator->can('execute', $assigned));
        $this->assertFalse($operator->can('view', $unassigned));
        $this->assertFalse($operator->can('update', $unassigned));
        $this->assertFalse($operator->can('execute', $unassigned));
        $this->assertSame([$assigned->getKey()], Project::query()->visibleTo($operator)->pluck('id')->all());
    }

    public function test_auditor_has_read_only_access_to_assigned_projects(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $auditor = $this->user(UserRole::AUDITOR);
        $project = $this->project($admin, ProjectStatus::READY);
        app(ProjectAssignmentManager::class)->assign($project, $auditor, $admin);

        $this->assertTrue($auditor->can('view', $project));
        $this->assertFalse($auditor->can('update', $project));
        $this->assertFalse($auditor->can('execute', $project));
    }

    public function test_inactive_users_have_no_project_access(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $operator = $this->user(UserRole::OPERATOR, active: false);
        $project = $this->project($admin, ProjectStatus::READY);
        app(ProjectAssignmentManager::class)->assign($project, $operator, $admin);

        $this->assertFalse($operator->can('view', $project));
        $this->assertFalse($operator->can('update', $project));
        $this->assertFalse($operator->can('execute', $project));
    }

    public function test_admin_cannot_receive_a_redundant_project_assignment(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $project = $this->project($admin);

        $this->expectException(InvalidProjectAssignment::class);

        app(ProjectAssignmentManager::class)->assign($project, $admin, $admin);
    }

    public function test_non_admin_cannot_assign_users(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $operator = $this->user(UserRole::OPERATOR);
        $auditor = $this->user(UserRole::AUDITOR);
        $project = $this->project($admin);

        $this->expectException(AuthorizationException::class);

        app(ProjectAssignmentManager::class)->assign($project, $auditor, $operator);
    }

    public function test_removing_assignment_revokes_operator_access(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->project($admin, ProjectStatus::READY);
        $manager = app(ProjectAssignmentManager::class);
        $assignment = $manager->assign($project, $operator, $admin);

        $this->assertTrue($operator->can('view', $project));
        $this->assertTrue($operator->can('execute', $project));

        $manager->unassign($assignment, $admin);

        $this->assertFalse($operator->can('view', $project));
        $this->assertFalse($operator->can('update', $project));
        $this->assertFalse($operator->can('execute', $project));
        $this->assertSame([], Project::query()->visibleTo($operator)->pluck('id')->all());
    }

    public function test_assigned_operator_can_use_execution_services(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->project($admin, ProjectStatus::READY);
        app(ProjectAssignmentManager::class)->assign($project, $operator, $admin);

        $execution = app(ProjectExecutionManager::class)->queue($project, $operator);
        $execution = app(ExecutionLifecycle::class)->transition($execution, ExecutionStatus::RUNNING, $operator);

        $this->assertSame($operator->getKey(), $execution->created_by);
        $this->assertSame(ExecutionStatus::RUNNING, $execution->status);
        $this->assertSame(ProjectStatus::RUNNING, $project->fresh()->status);
    }

    public function test_auditor_cannot_use_execution_lifecycle_service(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $auditor = $this->user(UserRole::AUDITOR);
        $project = $this->project($admin, ProjectStatus::QUEUED);
        $execution = $this->execution($project, ExecutionStatus::QUEUED, creator: $admin);
        app(ProjectAssignmentManager::class)->assign($project, $auditor, $admin);
        $caught = null;

        try {
            app(ExecutionLifecycle::class)->transition($execution, ExecutionStatus::RUNNING, $auditor);
        } catch (AuthorizationException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(AuthorizationException::class, $caught);
        $this->assertSame(ExecutionStatus::QUEUED, $execution->fresh()->status);
        $this->assertSame(ProjectStatus::QUEUED, $project->fresh()->status);
    }

    public function test_unassigned_operator_cannot_use_execution_services(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->project($admin, ProjectStatus::READY);

        $this->expectException(AuthorizationException::class);

        app(ProjectExecutionManager::class)->queue($project, $operator);
    }
}
