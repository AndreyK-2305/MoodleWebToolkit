<?php

namespace App\Policies;

use App\Models\ProjectAssignment;
use App\Models\User;

class ProjectAssignmentPolicy
{
    public function create(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function delete(User $user, ProjectAssignment $assignment): bool
    {
        return $user->is_active && $user->isAdmin() && ! $assignment->project->isReadOnly();
    }
}
