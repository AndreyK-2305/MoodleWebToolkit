<?php

namespace Tests\Feature\Domain;

use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Models\Execution;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class DomainTestCase extends TestCase
{
    use RefreshDatabase;

    protected function user(UserRole $role = UserRole::OPERATOR, bool $active = true): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => $active]);
    }

    protected function project(?User $creator = null, ProjectStatus $status = ProjectStatus::DRAFT): Project
    {
        $creator ??= $this->user(UserRole::ADMIN);

        return Project::query()->create([
            'name' => 'Migración de prueba',
            'type' => ProjectType::CONSOLIDATE,
            'status' => $status,
            'created_by' => $creator->getKey(),
        ]);
    }

    protected function execution(
        Project $project,
        ExecutionStatus $status = ExecutionStatus::QUEUED,
        int $attempt = 1,
        ?User $creator = null,
    ): Execution {
        return Execution::query()->create([
            'project_id' => $project->getKey(),
            'attempt' => $attempt,
            'status' => $status,
            'created_by' => $creator?->getKey() ?? $project->created_by,
        ]);
    }
}
