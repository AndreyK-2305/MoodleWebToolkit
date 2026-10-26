<?php

namespace App\Http\Controllers;

use App\Domain\Remote\RemoteConsolidationSources;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/** «Recolectar ahora»: the consolidation creates the COLLECT project its packages will come from. */
final class LinkedCollectionController extends Controller
{
    private const FIELDS = ['server_uuid', 'destination_profile', 'workers', 'collection_workers', 'sources'];

    public function __invoke(Request $request, Project $project, RemoteConsolidationSources $sources): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless((bool) config('toolkit.features.remote_agent.enabled'), 409, 'El agente remoto está deshabilitado.');
        // Reject unknown input before validation can flash it to the session.
        abort_if(array_diff(array_keys($request->except('_token')), self::FIELDS) !== [], 422, 'Parámetros de recolección no registrados.');
        $validated = $request->validate([
            // The destination may come later, in its own step.
            'server_uuid' => ['nullable', 'uuid'],
            'destination_profile' => ['nullable', 'required_with:server_uuid', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/D'],
            'workers' => ['nullable', 'string', 'regex:/^(auto|[1-4])$/D'],
            'collection_workers' => ['required', 'integer', 'between:1,4'],
            'sources' => ['required', 'array', 'list', 'min:'.RemoteConsolidationSources::MIN_SOURCES, 'max:'.RemoteConsolidationSources::MAX_SOURCES],
            'sources.*' => ['required', 'array:name,instance_id,region,source_profile,package_name'],
            'sources.*.name' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
            'sources.*.instance_id' => ['required', 'string', 'max:32', 'distinct'],
            'sources.*.region' => ['required', 'string', 'max:32'],
            'sources.*.source_profile' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/D'],
            'sources.*.package_name' => ['required', 'string', 'regex:/^[a-z][a-z0-9_-]{0,63}$/D', 'distinct'],
        ], [
            'sources.min' => 'Una consolidación necesita al menos '.RemoteConsolidationSources::MIN_SOURCES.' orígenes.',
            'sources.*.name.distinct' => 'Cada origen necesita un nombre distinto.',
            'sources.*.instance_id.distinct' => 'Cada origen es un servidor distinto: repita el ID de instancia solo una vez.',
            'sources.*.package_name.distinct' => 'Cada origen necesita su propio nombre de paquete.',
            'sources.*.source_profile.regex' => 'El perfil usa minúsculas, números y guiones, como en el servidor de origen.',
            'sources.*.package_name.regex' => 'El nombre del paquete empieza con una letra y usa minúsculas, números, guiones o guiones bajos.',
        ]);
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{server_uuid?: string|null, destination_profile?: string|null, workers?: string|null, collection_workers: int, sources: list<array{name: string, instance_id: string, region: string, source_profile: string, package_name: string}>} $validated */
        $collection = $sources->createLinkedCollection($project, $actor, $validated);
        Inertia::flash('toast', ['type' => 'success',
            'message' => 'Se creó la recolección «'.$collection->name.'». Ábrala para conectar los orígenes e iniciarla; al completarse, esta consolidación toma sus paquetes.']);

        return to_route('projects.show', $project->uuid);
    }
}
