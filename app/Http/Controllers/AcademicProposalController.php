<?php

namespace App\Http\Controllers;

use App\Domain\Academic\ProposeAcademicChange;
use App\Models\Execution;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AcademicProposalController extends Controller
{
    public function store(
        Request $request,
        Project $project,
        Execution $execution,
        ProposeAcademicChange $proposer,
    ): JsonResponse|RedirectResponse {
        abort_unless($execution->project_id === $project->getKey(), 404);
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        $validated = $request->validate([
            'operation' => ['required', 'string', Rule::in(ProposeAcademicChange::OPERATIONS)],
            'node_id' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'value' => ['required', 'string', 'max:160'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'base_fingerprint' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{operation: string, node_id: string, value: string, expected_version: int, base_fingerprint: string, idempotency_key: string} $validated */
        $result = $proposer->propose($execution, $actor, $validated, $validated['idempotency_key']);
        $proposal = $result->proposal;

        if ($request->expectsJson()) {
            return response()->json([
                'created' => $result->created,
                'proposal_id' => $proposal->getKey(),
                'version' => $proposal->version,
                'fingerprint' => $proposal->resulting_fingerprint,
            ], $result->created ? 201 : 200);
        }

        Inertia::flash('toast', [
            'type' => $result->created ? 'success' : 'info',
            'message' => $result->created
                ? 'Propuesta guardada. La validación anterior dejó de ser apta para finalizar.'
                : 'La propuesta ya estaba registrada y no fue duplicada.',
        ]);

        return to_route('projects.executions.show', [$project->uuid, $execution->uuid]);
    }
}
