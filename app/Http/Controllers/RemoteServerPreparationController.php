<?php

namespace App\Http\Controllers;

use App\Domain\Remote\RemoteServerPreparation;
use App\Enums\ServerRole;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The «Preparar origen» and «Preparar destino» checklists: the steps that a
 * server administrator used to run by hand, done from the web. Creating a
 * profile is for administrators only and, like every change, needs the
 * password confirmation of the action.confirmed middleware.
 */
final class RemoteServerPreparationController extends Controller
{
    private const PROFILE = ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/D'];

    public function detectMoodle(Request $request, Project $project, Server $server, RemoteServerPreparation $preparation): RedirectResponse
    {
        $this->authorizeServer($project, $server, ServerRole::SOURCE);
        /** @var User $actor */
        $actor = $request->user();
        $result = $preparation->detectMoodle($server, $actor);
        Inertia::flash('toast', match (true) {
            ! $result['detected'] => ['type' => 'error', 'message' => 'No se pudo buscar Moodle en el servidor ('.$result['error_code'].').'],
            $result['candidates'] === [] => ['type' => 'warning', 'message' => 'No se encontró ninguna instalación de Moodle; escriba la ruta de config.php.'],
            default => ['type' => 'success', 'message' => 'Instalaciones de Moodle encontradas: '.count($result['candidates']).'. Elija una y confírmela.'],
        });

        return to_route('projects.show', $project->uuid);
    }

    public function createSource(Request $request, Project $project, Server $server, RemoteServerPreparation $preparation): RedirectResponse
    {
        $this->authorizeServer($project, $server, ServerRole::SOURCE);
        $this->authorizeAdmin($request);
        $input = $request->validate([
            'source' => self::PROFILE,
            'config_path' => ['required', 'string', 'max:110'],
            'runtime' => ['required', Rule::in(['native', 'compose'])],
            'run_as' => ['required', 'string', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/D'],
            'compose_dir' => ['nullable', 'required_if:runtime,compose', 'string', 'max:110'],
            'compose_service' => ['nullable', 'required_if:runtime,compose', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,62}$/D'],
            'compose_profile' => ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,62}$/D'],
            'force' => ['sometimes', 'boolean'],
        ], ['run_as.regex' => 'El usuario de ejecución debe ser un nombre de usuario de Linux válido.']);
        /** @var User $actor */
        $actor = $request->user();
        try {
            /** @var array{source: string, config_path: string, runtime: string, run_as: string, compose_dir?: string|null, compose_service?: string|null, compose_profile?: string|null, force?: bool} $input */
            $result = $preparation->createSource($server, $actor, [...$input, 'force' => (bool) ($input['force'] ?? false)]);
        } catch (ToolOperationBlocked $exception) {
            return to_route('projects.show', $project->uuid)->withErrors(['source_profile' => $exception->getMessage()]);
        }
        if (! $result['created']) {
            return to_route('projects.show', $project->uuid)->withErrors(['source_profile' => 'El agente no creó el perfil ('.$result['error_code'].').']);
        }
        $ready = ($result['check']['ready'] ?? false) === true;
        Inertia::flash('toast', ['type' => $ready ? 'success' : 'warning', 'message' => 'Perfil '.$input['source'].($result['replaced'] ? ' reemplazado' : ' creado')
            .($ready ? ' y comprobado: listo para recolectar.' : '; la comprobación encontró problemas ('.($result['check']['error_code'] ?? 'revise el detalle').').')]);

        return to_route('projects.show', $project->uuid);
    }

    public function probeStorage(Request $request, Project $project, Server $server, RemoteServerPreparation $preparation): RedirectResponse
    {
        $this->authorizeServer($project, $server, null);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $result = $preparation->probeStorage($server, $actor);
        } catch (ToolOperationBlocked $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return to_route('projects.show', $project->uuid);
        }
        Inertia::flash('toast', $result['ok']
            ? ['type' => 'success', 'message' => $server->role === ServerRole::DESTINATION
                ? 'El destino puede leer y escribir el bucket de paquetes; la plataforma lo confirmó con su propia identidad.'
                : 'El origen puede subir al bucket de paquetes; la plataforma confirmó el objeto con su propia identidad.']
            : ['type' => 'error', 'message' => 'La prueba de S3 falló ('.$result['error_code'].').']);

        return to_route('projects.show', $project->uuid);
    }

    public function createDestination(Request $request, Project $project, Server $server, RemoteServerPreparation $preparation): RedirectResponse
    {
        $this->authorizeServer($project, $server, ServerRole::DESTINATION);
        $this->authorizeAdmin($request);
        $input = $request->validate([
            'destination' => self::PROFILE,
            'public_url' => ['required', 'string', 'max:110', 'starts_with:https://'],
            'admin_email' => ['required', 'email:rfc', 'max:100', 'regex:/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/D'],
            'admin_user' => ['sometimes', 'string', 'regex:/^[a-z][a-z0-9._-]{2,31}$/D'],
            'http_port' => ['sometimes', 'integer', 'between:1,65535'],
            'run_as' => ['nullable', 'string', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/D', 'not_in:root'],
            'force' => ['sometimes', 'boolean'],
        ], ['public_url.starts_with' => 'La URL pública debe empezar por https://.',
            'admin_user.regex' => 'El usuario administrador admite minúsculas, números, punto, guion o guion bajo (3 a 32).']);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $result = $preparation->createDestination($server, $actor, ['destination' => $input['destination'], 'public_url' => $input['public_url'],
                'admin_email' => $input['admin_email'], 'admin_user' => $input['admin_user'] ?? 'admin', 'http_port' => (int) ($input['http_port'] ?? 8090),
                'run_as' => $input['run_as'] ?? null, 'force' => (bool) ($input['force'] ?? false)]);
        } catch (ToolOperationBlocked $exception) {
            return to_route('projects.show', $project->uuid)->withErrors(['destination_profile' => $exception->getMessage()]);
        }
        if (! $result['created']) {
            return to_route('projects.show', $project->uuid)->withErrors(['destination_profile' => 'El agente no creó el perfil ('.$result['error_code'].').']);
        }
        Inertia::flash('toast', ['type' => ($result['check']['ready'] ?? false) === true ? 'success' : 'warning',
            'message' => 'Perfil de destino '.$input['destination'].' creado. La clave del administrador de Moodle quedó solo en el servidor; revise la comprobación.']);

        return to_route('projects.show', $project->uuid);
    }

    private function authorizeAdmin(Request $request): void
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor->isAdmin(), 403, 'Solo un administrador puede crear perfiles en un servidor.');
    }

    private function authorizeServer(Project $project, Server $server, ?ServerRole $role): void
    {
        Gate::authorize('update', $project);
        abort_unless((bool) config('toolkit.features.remote_agent.enabled', false), 409, 'Las conexiones remotas están deshabilitadas.');
        abort_unless((int) $server->project_id === (int) $project->getKey(), 404);
        abort_if($role !== null && $server->role !== $role, 422, $role === ServerRole::SOURCE
            ? 'Solo un servidor de origen tiene perfiles de origen.' : 'Solo un servidor de destino aloja el Consolidador.');
    }
}
