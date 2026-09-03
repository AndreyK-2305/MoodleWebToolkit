<?php

namespace App\Domain\Executions;

use App\Enums\ExecutionStatus;
use App\Exceptions\InvalidStateTransition;
use App\Models\Execution;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ExecutionLifecycle
{
    public function transition(Execution $execution, ExecutionStatus $target, User $actor): Execution
    {
        return DB::transaction(function () use ($execution, $target, $actor): Execution {
            [$lockedProject, $lockedExecution] = $this->lockProjectAndExecution($execution);
            $lockedExecution->setRelation('project', $lockedProject);

            if (! $actor->can('control', $lockedExecution)) {
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

            return $this->applyTransition($lockedExecution, $lockedProject, $target);
        }, attempts: 3);
    }

    /**
     * Internal worker transition. HTTP entry points must use transition(), which
     * authorizes the actor; workers consume an already authorized command.
     */
    public function transitionForWorker(Execution $execution, ExecutionStatus $target): Execution
    {
        return DB::transaction(function () use ($execution, $target): Execution {
            [$lockedProject, $lockedExecution] = $this->lockProjectAndExecution($execution);

            return $this->applyTransition($lockedExecution, $lockedProject, $target);
        }, attempts: 3);
    }

    /** @return array{Project, Execution} */
    private function lockProjectAndExecution(Execution $execution): array
    {
        $seed = Execution::query()->select(['id', 'project_id'])->findOrFail((int) $execution->getKey());
        $lockedProject = Project::query()->lockForUpdate()->findOrFail($seed->project_id);
        $lockedExecution = Execution::query()->lockForUpdate()->findOrFail((int) $seed->getKey());

        return [$lockedProject, $lockedExecution];
    }

    private function applyTransition(
        Execution $lockedExecution,
        Project $lockedProject,
        ExecutionStatus $target,
    ): Execution {

        if (! $lockedExecution->status->canTransitionTo($target)) {
            throw InvalidStateTransition::between(
                'Execution',
                $lockedExecution->status->value,
                $target->value,
            );
        }

        $projectTarget = $target->projectStatus();

        if (! $lockedProject->status->canTransitionTo($projectTarget)) {
            throw InvalidStateTransition::between(
                'Project',
                $lockedProject->status->value,
                $projectTarget->value,
            );
        }

        if ($target === ExecutionStatus::RUNNING && $lockedExecution->started_at === null) {
            $lockedExecution->started_at = now();
        }

        if ($target->isTerminal()) {
            $lockedExecution->finished_at = now();
        }

        $lockedExecution->transitionTo($target);
        $lockedProject->transitionTo($projectTarget);

        return $lockedExecution->refresh();
    }
}
