<?php

namespace App\Domain\Projects;

use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ExecutionAlreadyActive;
use App\Exceptions\InvalidStateTransition;
use App\Exceptions\NewExecutionBlocked;
use App\Models\Execution;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a logical Execution only; command services own durable dispatch.
 */
class ProjectExecutionManager
{
    public function queue(Project $project, User $actor): Execution
    {
        if ($project->status->blocksNewExecution()) {
            throw new NewExecutionBlocked($project->status);
        }

        return DB::transaction(function () use ($project, $actor): Execution {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail((int) $project->getKey());

            if ($lockedProject->status->blocksNewExecution()) {
                throw new NewExecutionBlocked($lockedProject->status);
            }

            if (! $actor->can('execute', $lockedProject)) {
                throw new AuthorizationException;
            }

            if (! $actor->isAdmin()) {
                $hasLockedAssignment = ProjectAssignment::query()
                    ->where('project_id', $lockedProject->getKey())
                    ->where('user_id', $actor->getKey())
                    ->lockForUpdate()
                    ->exists();

                if (! $hasLockedAssignment) {
                    throw new AuthorizationException;
                }
            }

            if ($lockedProject->executions()->whereIn('status', $this->activeStatuses())->exists()) {
                throw new ExecutionAlreadyActive;
            }

            if (! $lockedProject->status->canTransitionTo(ProjectStatus::QUEUED)) {
                throw InvalidStateTransition::between(
                    'Project',
                    $lockedProject->status->value,
                    ProjectStatus::QUEUED->value,
                );
            }

            $attempt = ((int) $lockedProject->executions()->max('attempt')) + 1;

            $execution = $lockedProject->executions()->create([
                'attempt' => $attempt,
                'status' => ExecutionStatus::QUEUED,
                'progress' => null,
                'created_by' => $actor->getKey(),
            ]);

            $lockedProject->transitionTo(ProjectStatus::QUEUED);

            return $execution;
        }, attempts: 3);
    }

    /** @return list<string> */
    private function activeStatuses(): array
    {
        return array_values(array_map(
            static fn (ExecutionStatus $status): string => $status->value,
            array_filter(ExecutionStatus::cases(), static fn (ExecutionStatus $status): bool => $status->isActive()),
        ));
    }
}
