<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Execution;
use App\Models\Project;
use App\Models\User;

class ExecutionPolicy
{
    public function view(User $user, Execution $execution): bool
    {
        return $user->can('view', $execution->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('execute', $project);
    }

    public function control(User $user, Execution $execution): bool
    {
        if (! $user->is_active || $user->role === UserRole::AUDITOR || $execution->project->isReadOnly()) {
            return false;
        }

        return $user->isAdmin() || (
            $user->role === UserRole::OPERATOR
            && $execution->project->assignments()->where('user_id', $user->getKey())->exists()
        );
    }
}
