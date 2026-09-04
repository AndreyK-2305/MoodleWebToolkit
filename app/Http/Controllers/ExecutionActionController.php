<?php

namespace App\Http\Controllers;

use App\Domain\Executions\RequestExecutionCancellation;
use App\Domain\Executions\ResolveExecutionConflict;
use App\Domain\Executions\ResumeProjectExecution;
use App\Exceptions\ExecutionDispatchFailed;
use App\Models\Checkpoint;
use App\Models\Conflict;
use App\Models\Execution;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ExecutionActionController extends Controller
{
    public function cancel(
        Request $request,
        Project $project,
        Execution $execution,
        RequestExecutionCancellation $cancellation,
    ): JsonResponse|RedirectResponse {
        $this->ensureBelongsToProject($project, $execution);
        $key = $this->idempotencyKey($request);
        try {
            $result = $cancellation->request($execution, $request->user(), $key);
        } catch (ExecutionDispatchFailed $exception) {
            return $this->dispatchFailure($request, $project, $exception);
        }

        return $this->response(
            $request,
            $project,
            $result->execution,
            $result->created,
            $result->created
                ? 'Cancelación solicitada. El estado seguirá en CANCELLING hasta la confirmación del worker.'
                : 'La cancelación ya estaba registrada; no se duplicaron sus efectos.',
            202,
        );
    }

    public function resolve(
        Request $request,
        Project $project,
        Execution $execution,
        Conflict $conflict,
        ResolveExecutionConflict $resolver,
    ): JsonResponse|RedirectResponse {
        $this->ensureBelongsToProject($project, $execution);
        abort_unless($conflict->execution_id === $execution->getKey(), 404);
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['ACCEPT', 'CONFIRM_COMPLETED'])],
            'conflict_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        try {
            $result = $resolver->resolve(
                $execution,
                $conflict,
                $request->user(),
                (string) $validated['decision'],
                (int) $validated['conflict_version'],
                (string) $validated['idempotency_key'],
            );
        } catch (ExecutionDispatchFailed $exception) {
            return $this->dispatchFailure($request, $project, $exception);
        }

        return $this->response(
            $request,
            $project,
            $result->execution,
            $result->created,
            $result->created ? 'La decisión fue auditada y se procesará sin repetir pasos.' : 'La decisión ya había sido registrada.',
        );
    }

    public function resume(
        Request $request,
        Project $project,
        Execution $execution,
        ResumeProjectExecution $resumer,
    ): JsonResponse|RedirectResponse {
        $this->ensureBelongsToProject($project, $execution);
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        $validated = $request->validate([
            'checkpoint_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $checkpoint = Checkpoint::query()
            ->where('execution_id', $execution->getKey())
            ->findOrFail((int) $validated['checkpoint_id']);
        try {
            $result = $resumer->resume($execution, $checkpoint, $request->user(), (string) $validated['idempotency_key']);
        } catch (ExecutionDispatchFailed $exception) {
            return $this->dispatchFailure($request, $project, $exception);
        }

        return $this->response(
            $request,
            $project,
            $result->execution,
            $result->created,
            $result->created ? 'Se creó un nuevo intento desde el checkpoint validado.' : 'El intento reanudado ya existía.',
            $result->created ? 201 : 200,
        );
    }

    private function idempotencyKey(Request $request): string
    {
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);

        return (string) $request->validate(['idempotency_key' => $this->idempotencyRules()])['idempotency_key'];
    }

    /** @return list<string> */
    private function idempotencyRules(): array
    {
        return ['required', 'string', 'min:8', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'];
    }

    private function response(
        Request $request,
        Project $project,
        Execution $execution,
        bool $created,
        string $message,
        int $createdStatus = 200,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'created' => $created,
                'execution_uuid' => $execution->uuid,
                'status' => $execution->status->value,
            ], $created ? $createdStatus : 200);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => $message]);

        return to_route('projects.executions.show', [$project->uuid, $execution->uuid]);
    }

    private function ensureBelongsToProject(Project $project, Execution $execution): void
    {
        abort_unless($execution->project_id === $project->getKey(), 404);
    }

    private function dispatchFailure(
        Request $request,
        Project $project,
        ExecutionDispatchFailed $exception,
    ): JsonResponse|RedirectResponse {
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
}
