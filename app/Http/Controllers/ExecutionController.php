<?php

namespace App\Http\Controllers;

use App\Domain\Executions\ExecutionPresenter;
use App\Domain\Executions\StartProjectExecution;
use App\Exceptions\ExecutionDispatchFailed;
use App\Http\Requests\StartExecutionRequest;
use App\Models\Execution;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ExecutionController extends Controller
{
    public function store(
        StartExecutionRequest $request,
        Project $project,
        StartProjectExecution $starter,
    ): JsonResponse|RedirectResponse {
        $data = $request->validated();

        try {
            $result = $starter->start(
                $project,
                $request->user(),
                (string) $data['idempotency_key'],
                (int) $data['configuration_version'],
            );
        } catch (ExecutionDispatchFailed $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'execution_uuid' => $exception->executionUuid,
                    'recoverable' => true,
                ], 503);
            }

            Inertia::flash('toast', ['type' => 'warning', 'message' => $exception->getMessage()]);

            return to_route('projects.executions.show', [$project->uuid, $exception->executionUuid]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'created' => $result->created,
                'execution_uuid' => $result->execution->uuid,
                'status' => $result->execution->status->value,
            ], $result->created ? 201 : 200);
        }

        Inertia::flash('toast', [
            'type' => $result->created ? 'success' : 'info',
            'message' => $result->created
                ? 'Ejecución encolada. El worker continuará de forma asíncrona.'
                : 'La solicitud ya existía; se recuperó su ejecución sin duplicarla.',
        ]);

        return to_route('projects.executions.show', [$project->uuid, $result->execution->uuid]);
    }

    public function show(
        Request $request,
        Project $project,
        Execution $execution,
        ExecutionPresenter $presenter,
    ): Response {
        $this->ensureBelongsToProject($project, $execution);
        Gate::authorize('view', $execution);
        $execution->load('steps');
        $events = $execution->events()->orderBy('sequence')->limit(200)->get();

        return Inertia::render('projects/executions/show', [
            'project' => [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'status' => $project->status->value,
                'status_label' => $project->status->label(),
            ],
            'execution' => $presenter->execution($execution),
            'events' => $events->map(fn ($event): array => $presenter->event($event))->values(),
        ]);
    }

    private function ensureBelongsToProject(Project $project, Execution $execution): void
    {
        abort_unless($execution->project_id === $project->getKey(), 404);
    }
}
