<?php

namespace App\Http\Controllers;

use App\Domain\Remote\RemoteConsolidationCopias;
use App\Domain\Remote\RemoteConsolidationSources;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/** Sources step of a remote consolidation: the packages it consolidates, before or after its destination. */
final class ConsolidationSourcesController extends Controller
{
    private const FIELDS = ['source_project', 'source_packages'];

    public function __invoke(Request $request, Project $project, RemoteConsolidationSources $sources, RemoteConsolidationCopias $copias): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless((bool) config('toolkit.features.remote_agent.enabled'), 409, 'El agente remoto está deshabilitado.');
        // Reject unknown input before validation can flash it to the session.
        abort_if(array_diff(array_keys($request->except('_token')), self::FIELDS) !== [], 422, 'Parámetros de orígenes no registrados.');
        $validated = $request->validate([
            'source_project' => ['nullable', 'uuid'],
            'source_packages' => ['required', 'array', 'list', 'min:'.RemoteConsolidationSources::MIN_SOURCES],
            'source_packages.*' => ['required', 'uuid', 'distinct'],
        ], ['source_packages.min' => 'Elija al menos '.RemoteConsolidationSources::MIN_SOURCES.' paquetes, uno por origen.']);
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{source_project?: string|null, source_packages: list<string>} $validated */
        $sources->chooseSources($project, $actor, $validated);
        $copias->advance($project->refresh(), $actor);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Orígenes guardados.']);

        return to_route('projects.show', $project->uuid);
    }
}
