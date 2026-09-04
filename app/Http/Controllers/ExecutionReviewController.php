<?php

namespace App\Http\Controllers;

use App\Domain\Executions\RequestExecutionFinalization;
use App\Domain\Executions\RequestExecutionValidation;
use App\Exceptions\ExecutionDispatchFailed;
use App\Models\Execution;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExecutionReviewController extends Controller
{
    public function validateExecution(
        Request $request,
        Project $project,
        Execution $execution,
        RequestExecutionValidation $validation,
    ): JsonResponse|RedirectResponse {
        $this->ensureBelongsToProject($project, $execution);
        $key = $this->idempotencyKey($request);

        try {
            /** @var User $actor */
            $actor = $request->user();
            $result = $validation->request($execution, $actor, $key);
        } catch (ExecutionDispatchFailed $exception) {
            return $this->dispatchFailure($request, $project, $exception);
        }

        return $this->response($request, $project, $result->execution, $result->created,
            $result->created ? 'Validación encolada sobre la misma ejecución.' : 'La validación ya estaba registrada; no se duplicaron sus efectos.');
    }

    public function finalize(
        Request $request,
        Project $project,
        Execution $execution,
        RequestExecutionFinalization $finalization,
    ): JsonResponse|RedirectResponse {
        $this->ensureBelongsToProject($project, $execution);
        $key = $this->idempotencyKey($request);

        try {
            /** @var User $actor */
            $actor = $request->user();
            $result = $finalization->request($execution, $actor, $key);
        } catch (ExecutionDispatchFailed $exception) {
            return $this->dispatchFailure($request, $project, $exception);
        }

        return $this->response($request, $project, $result->execution, $result->created,
            $result->created ? 'Finalización encolada; el cierre sólo ocurrirá después de verificar los cuatro artefactos.' : 'El cierre ya estaba registrado; no se duplicaron sus efectos.');
    }

    private function idempotencyKey(Request $request): string
    {
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);

        return (string) $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ])['idempotency_key'];
    }

    private function response(Request $request, Project $project, Execution $execution, bool $created, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['created' => $created, 'execution_uuid' => $execution->uuid, 'status' => $execution->fresh()->status->value], $created ? 202 : 200);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => $message]);

        return to_route('projects.executions.show', [$project->uuid, $execution->uuid]);
    }

    private function ensureBelongsToProject(Project $project, Execution $execution): void
    {
        abort_unless($execution->project_id === $project->getKey(), 404);
    }

    private function dispatchFailure(Request $request, Project $project, ExecutionDispatchFailed $exception): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $exception->getMessage(), 'execution_uuid' => $exception->executionUuid, 'recoverable' => true], 503);
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => $exception->getMessage()]);

        return to_route('projects.executions.show', [$project->uuid, $exception->executionUuid]);
    }
}
