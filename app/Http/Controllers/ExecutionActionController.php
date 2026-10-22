<?php

namespace App\Http\Controllers;

use App\Domain\Collector\RetryCollectorExecution;
use App\Domain\Executions\RequestExecutionCancellation;
use App\Domain\Executions\ResolveExecutionConflict;
use App\Domain\Executions\ResumeProjectExecution;
use App\Domain\Remote\RemoteConsolidationBackup;
use App\Domain\Remote\RemoteConsolidationInterventions;
use App\Domain\Remote\RemoteConsolidationPreparation;
use App\Domain\Remote\RemoteConsolidationWorkflow;
use App\Domain\Remote\RetryRemoteConsolidation;
use App\Exceptions\ExecutionDispatchFailed;
use App\Exceptions\RemoteAgentUnavailable;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Checkpoint;
use App\Models\Conflict;
use App\Models\Execution;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ExecutionActionController extends Controller
{
    public function freshExport(Request $request, Project $project, Execution $execution, RetryCollectorExecution $retry): JsonResponse|RedirectResponse
    {
        $this->ensureBelongsToProject($project, $execution);
        $key = $this->idempotencyKey($request);
        $input = $request->validate(['configuration_version' => ['required', 'integer', 'min:1'], 'accept_laboratory' => ['required', 'accepted']]);
        try {
            $result = $retry->retry($execution, $request->user(), $key, (int) $input['configuration_version'], true);
        } catch (ExecutionDispatchFailed $exception) {
            return $this->dispatchFailure($request, $project, $exception);
        }

        return $this->response($request, $project, $result->execution, $result->created,
            $result->created ? 'Nueva exportación desde cero; la evidencia del intento anterior se conserva.' : 'El nuevo intento ya estaba registrado.', 201);
    }

    /**
     * The administrator's answers to a consolidation intervention (identity review, themes):
     * validated against what the intervention offered, kept on it as evidence, and applied
     * through the same audited resolution as any other decision.
     */
    public function answerIntervention(Request $request, Project $project, Execution $execution, Conflict $conflict, ResolveExecutionConflict $resolver): JsonResponse|RedirectResponse
    {
        $this->ensureBelongsToProject($project, $execution);
        abort_unless($conflict->execution_id === $execution->getKey(), 404);
        abort_unless(in_array($conflict->type, [RemoteConsolidationInterventions::IDENTITY, RemoteConsolidationInterventions::THEMES,
            RemoteConsolidationInterventions::PLUGINS], true), 404);
        Gate::authorize('control', $execution);
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        $details = $conflict->details ?? [];
        abort_unless(in_array('APPLY_ANSWERS', is_array($details['allowed_decisions'] ?? null) ? $details['allowed_decisions'] : [], true), 422);
        $base = ['conflict_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => $this->idempotencyRules()];
        if ($conflict->type === RemoteConsolidationInterventions::PLUGINS) {
            // Only components inspected on the destination, whose proposal the administrator reviewed.
            $proposed = array_keys(is_array($details['proposals'] ?? null) ? $details['proposals'] : []);
            $input = $request->validate([...$base, 'promote' => ['required', 'array', 'min:1', 'max:10'],
                'promote.*' => ['required', 'string', 'distinct', Rule::in($proposed)], 'confirm' => ['accepted']]);
            $answers = ['promote' => array_values($input['promote'])];
        } elseif ($conflict->type === RemoteConsolidationInterventions::THEMES) {
            $input = $request->validate([...$base, 'theme' => ['required', 'string', Rule::in($details['themes'] ?? [])],
                'accept_legacy' => [($details['legacy_sources'] ?? []) !== [] && ($details['legacy_accepted'] ?? false) !== true ? 'accepted' : 'boolean']]);
            $answers = ['theme' => (string) $input['theme'], 'accept_legacy' => (bool) ($input['accept_legacy'] ?? false)];
        } else {
            $candidates = is_array($details['candidates'] ?? null) ? array_values(array_filter($details['candidates'], 'is_array')) : [];
            $groups = collect($candidates)->keyBy('group');
            $input = $request->validate([...$base, 'decisions' => ['required', 'array', 'size:'.$groups->count()],
                'decisions.*.group' => ['required', 'string', 'distinct', Rule::in($groups->keys()->all())],
                'decisions.*.resolution' => ['required', Rule::in(['MERGE', 'KEEP_SEPARATE', 'IGNORE'])],
                'decisions.*.justification' => ['required', 'string', 'min:3', 'max:500'],
                'decisions.*.canonical_target' => ['nullable', 'required_if:decisions.*.resolution,MERGE', 'string', 'max:80'],
                'decisions.*.canonical_email' => ['nullable', 'required_if:decisions.*.resolution,MERGE', 'email', 'max:120'],
                'decisions.*.canonical_username' => ['nullable', 'required_if:decisions.*.resolution,MERGE', 'string', 'regex:/^[a-z0-9._@-]{1,100}$/D'],
                'decisions.*.oauth_policy' => ['nullable', 'required_if:decisions.*.resolution,MERGE', Rule::in(['canonical_account', 'none'])]]);
            $answers = ['decisions' => []];
            foreach ($input['decisions'] as $index => $decision) {
                $merge = $decision['resolution'] === 'MERGE';
                $candidate = $groups->get($decision['group']);
                $members = [...($candidate['a']['members'] ?? []), ...($candidate['b']['members'] ?? [])];
                if ($merge && ! in_array($decision['canonical_target'], $members, true)) {
                    throw ValidationException::withMessages(["decisions.$index.canonical_target" => 'La cuenta canónica debe ser una de las cuentas del grupo.']);
                }
                $answers['decisions'][] = ['group' => $decision['group'], 'resolution' => $decision['resolution'], 'justification' => $decision['justification'],
                    'canonical_target' => $merge ? $decision['canonical_target'] : null, 'canonical_email' => $merge ? mb_strtolower($decision['canonical_email']) : null,
                    'canonical_username' => $merge ? $decision['canonical_username'] : null, 'oauth_policy' => $merge ? $decision['oauth_policy'] : null];
            }
        }
        DB::transaction(function () use ($conflict, $input, $answers, $request): void {
            $locked = Conflict::query()->whereKey($conflict->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status->value !== 'OPEN' || $locked->version !== (int) $input['conflict_version']) {
                throw ValidationException::withMessages(['conflict' => 'La intervención cambió mientras se respondía. Actualice el seguimiento.']);
            }
            $locked->details = [...($locked->details ?? []), 'answers' => [...$answers, 'answered_by' => $request->user()->getKey(),
                'answered_at' => now()->utc()->toIso8601String()]];
            $locked->save();
        });
        try {
            $result = $resolver->resolve($execution, $conflict->fresh(), $request->user(), 'APPLY_ANSWERS',
                (int) $input['conflict_version'], (string) $input['idempotency_key']);
        } catch (ExecutionDispatchFailed $exception) {
            return $this->dispatchFailure($request, $project, $exception);
        }

        return $this->response($request, $project, $result->execution, $result->created,
            $result->created ? 'Respuestas registradas; el destino las aplica y la consolidación continúa.' : 'Las respuestas ya estaban registradas.');
    }

    /** Inspects on the destination a plugin the sources need and the catalog lacks, from its Git repository and commit. */
    public function inspectPlugin(Request $request, Project $project, Execution $execution, Conflict $conflict, RemoteConsolidationWorkflow $workflow): RedirectResponse
    {
        $this->ensureBelongsToProject($project, $execution);
        abort_unless($conflict->execution_id === $execution->getKey() && $conflict->type === RemoteConsolidationInterventions::PLUGINS, 404);
        Gate::authorize('control', $execution);
        $input = $request->validate([
            'component' => ['required', 'string', Rule::in(is_array($conflict->details['unknown'] ?? null) ? $conflict->details['unknown'] : [])],
            // The Consolidator's catalog accepts public repositories on GitHub or Bitbucket.
            'repository' => ['required', 'string', 'max:100', 'regex:~^https://(github\.com|bitbucket\.org)/[A-Za-z0-9._/-]{3,80}\.git$~D', 'not_regex:/\.\./'],
            'commit' => ['required', 'string', 'regex:/^[0-9a-f]{40}$/D'],
        ]);
        try {
            $workflow->inspectPlugin($execution, $conflict, $request->user(), (string) $input['component'], (string) $input['repository'], (string) $input['commit']);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Inspección iniciada en el destino; el resultado aparece en la intervención.']);
        } catch (ToolOperationBlocked|RemoteAgentUnavailable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return to_route('projects.executions.show', [$project->uuid, $execution->uuid]);
    }

    /** A new attempt of a failed or cancelled remote consolidation; it continues from the destination's checkpoints. */
    public function retryConsolidation(Request $request, Project $project, Execution $execution, RetryRemoteConsolidation $retry): JsonResponse|RedirectResponse
    {
        $this->ensureBelongsToProject($project, $execution);
        $key = $this->idempotencyKey($request);
        $input = $request->validate(['configuration_version' => ['required', 'integer', 'min:1']]);
        try {
            $result = $retry->retry($execution, $request->user(), $key, (int) $input['configuration_version']);
        } catch (ExecutionDispatchFailed $exception) {
            return $this->dispatchFailure($request, $project, $exception);
        }

        return $this->response($request, $project, $result->execution, $result->created,
            $result->created ? 'Nuevo intento: el Consolidador continúa desde sus checkpoints; el intento anterior se conserva.' : 'El nuevo intento ya estaba registrado.', 201);
    }

    /** Back up the consolidation's sealed site package to S3 while it is in review. */
    public function backupConsolidation(Request $request, Project $project, Execution $execution, RemoteConsolidationBackup $backups): RedirectResponse
    {
        $this->ensureBelongsToProject($project, $execution);
        abort_unless(RemoteConsolidationPreparation::selected($execution->toolBinding), 404);
        Gate::authorize('control', $execution);
        try {
            $backups->start($execution, $request->user());
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Respaldo de la copia integral iniciado en el destino.']);
        } catch (ToolOperationBlocked|RemoteAgentUnavailable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return to_route('projects.executions.show', [$project->uuid, $execution->uuid]);
    }

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
            'decision' => ['required', 'string', Rule::in(['ACCEPT', 'CONFIRM_COMPLETED', 'CONTINUE_WITHOUT_PLUGINS'])],
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
