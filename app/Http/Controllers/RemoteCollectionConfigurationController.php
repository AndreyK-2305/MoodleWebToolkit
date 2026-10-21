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
        $keys = array_keys($request->except('_token'));
        // A list of sources, or the single-source shape kept for compatibility.
        $single = in_array('server_uuid', $keys, true);
        // Reject unknown input before validation can flash it to the session.
        abort_if(array_diff($keys, $single ? RemoteCollectionConfiguration::INPUT_V1 : RemoteCollectionConfiguration::INPUT) !== [], 422,
            'Parámetros de recolección remota no registrados.');
        $profile = ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/D'];
        $package = ['required', 'string', 'regex:/^[a-z][a-z0-9_-]{0,63}$/D'];
        $validated = $request->validate($single
            ? ['server_uuid' => ['required', 'uuid'], 'source_profile' => $profile, 'package_name' => $package, 'workers' => ['required', 'integer', 'between:1,4']]
            : ['sources' => ['required', 'array', 'list', 'min:1', 'max:'.RemoteCollectionConfiguration::MAX_SOURCES],
                'sources.*' => ['array:server_uuid,source_profile,package_name'], 'sources.*.server_uuid' => ['required', 'uuid'],
                'sources.*.source_profile' => $profile, 'sources.*.package_name' => $package, 'workers' => ['required', 'integer', 'between:1,4']]);
        $validated['workers'] = (int) $validated['workers'];
        /** @var User $actor */
        $actor = $request->user();
        $configurations->save($project, $actor, $validated);

        return to_route('projects.show', $project->uuid);
    }
}
