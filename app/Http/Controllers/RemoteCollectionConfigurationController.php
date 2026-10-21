<?php

namespace App\Http\Controllers;

use App\Domain\Remote\RemoteCollectionConfiguration;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class RemoteCollectionConfigurationController extends Controller
{
    public function __invoke(Request $request, Project $project, RemoteCollectionConfiguration $configurations): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless((bool) config('toolkit.features.remote_agent.enabled'), 409, 'El agente remoto está deshabilitado.');
        // Reject unknown input before validation can flash it to the session.
        abort_if(array_diff(array_keys($request->except('_token')), RemoteCollectionConfiguration::INPUT) !== [], 422, 'Parámetros de recolección remota no registrados.');
        $validated = $request->validate([
            'server_uuid' => ['required', 'uuid'],
            'source_profile' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/D'],
            'workers' => ['required', 'integer', 'between:1,4'],
            'package_name' => ['required', 'string', 'regex:/^[a-z][a-z0-9_-]{0,63}$/D'],
        ]);
        $validated['workers'] = (int) $validated['workers'];
        /** @var User $actor */
        $actor = $request->user();
        $configurations->save($project, $actor, $validated);

        return to_route('projects.show', $project->uuid);
    }
}
