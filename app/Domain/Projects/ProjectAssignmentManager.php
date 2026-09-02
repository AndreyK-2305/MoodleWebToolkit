<?php

namespace App\Domain\Projects;

use App\Enums\UserRole;
use App\Exceptions\InvalidProjectAssignment;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ProjectAssignmentManager
{
    public function assign(Project $project, User $target, User $actor): ProjectAssignment
    {
        if (! $actor->can('manageAssignments', $project)) {
            throw new AuthorizationException;
        }

        if ($target->role === UserRole::ADMIN) {
            throw new InvalidProjectAssignment;
        }

        return ProjectAssignment::query()->firstOrCreate(
            ['project_id' => $project->getKey(), 'user_id' => $target->getKey()],
            ['assigned_by' => $actor->getKey()],
        );
    }

    public function unassign(ProjectAssignment $assignment, User $actor): void
    {
        if (! $actor->can('delete', $assignment)) {
            throw new AuthorizationException;
        }

        $assignment->delete();
    }
}
