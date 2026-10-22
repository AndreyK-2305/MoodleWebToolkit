<?php

namespace App\Http\Controllers;

use App\Domain\Remote\RemoteConsolidationConfiguration;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class RemoteConsolidationConfigurationController extends Controller
{
    public function __invoke(Request $request, Project $project, RemoteConsolidationConfiguration $configurations): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless((bool) config('toolkit.features.remote_agent.enabled'), 409, 'El agente remoto está deshabilitado.');
        // Reject unknown input before validation can flash it to the session.
        abort_if(array_diff(array_keys($request->except('_token')), RemoteConsolidationConfiguration::INPUT) !== [], 422,
            'Parámetros de consolidación remota no registrados.');
        $validated = $request->validate([
            'server_uuid' => ['required', 'uuid'],
            'destination_profile' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/D'],
            'source_packages' => ['required', 'array', 'list', 'min:'.RemoteConsolidationConfiguration::MIN_PACKAGES, 'max:'.RemoteConsolidationConfiguration::MAX_PACKAGES],
            'source_packages.*' => ['required', 'uuid', 'distinct'],
            'workers' => ['required', 'string', 'regex:/^(auto|[1-4])$/D'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $configurations->save($project, $actor, $validated);

        return to_route('projects.show', $project->uuid);
    }
}
