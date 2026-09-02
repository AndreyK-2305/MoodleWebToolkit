<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->is_active && ($user->isAdmin() || $this->isAssigned($user, $project));
    }

    public function create(User $user): bool
    {
        return $user->is_active && in_array($user->role, [UserRole::ADMIN, UserRole::OPERATOR], true);
    }

    public function update(User $user, Project $project): bool
    {
        if (! $user->is_active || in_array($project->status, [ProjectStatus::REVIEW, ProjectStatus::COMPLETED], true)) {
            return false;
        }

        return $user->isAdmin() || ($user->role === UserRole::OPERATOR && $this->isAssigned($user, $project));
    }

    public function execute(User $user, Project $project): bool
    {
        if (! $user->is_active || $project->status->blocksNewExecution()) {
            return false;
        }

        return $user->isAdmin() || ($user->role === UserRole::OPERATOR && $this->isAssigned($user, $project));
    }

    public function manageAssignments(User $user, Project $project): bool
    {
        return $user->is_active && $user->isAdmin() && ! $project->isReadOnly();
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->is_active && $user->isAdmin() && $project->status === ProjectStatus::DRAFT;
    }

    private function isAssigned(User $user, Project $project): bool
    {
        return $project->assignments()->where('user_id', $user->getKey())->exists();
    }
}
