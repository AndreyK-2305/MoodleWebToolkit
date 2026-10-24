<?php

namespace App\Http\Controllers;

use App\Domain\Remote\CheckRemoteDestination;
use App\Domain\Remote\CheckRemoteSource;
use App\Domain\Remote\DetectRemoteDestinations;
use App\Domain\Remote\DetectRemoteSources;
use App\Domain\Remote\InstallRemoteAgent;
use App\Domain\Remote\PrepareRemoteDestination;
use App\Domain\Remote\RemoteCollectorExport;
use App\Domain\Remote\RemoteDestinationPackages;
use App\Domain\Remote\RemoteServerConnections;
use App\Domain\Remote\RemoteToolDeployer;
use App\Domain\Remote\TestRemoteConnection;
use App\Domain\Remote\UpdateRemoteAgent;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\ServerRole;
use App\Exceptions\RemoteAgentUnavailable;
use App\Exceptions\ToolOperationBlocked;
use App\Jobs\DeployRemoteTool;
use App\Models\Project;
use App\Models\Server;
use App\Models\SourcePackage;
use App\Models\ToolDistribution;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class RemoteConnectionController extends Controller
{
    private const FIELDS = ['type', 'instance_id', 'region', 'host', 'port', 'user', 'host_key', 'secret_reference', 'secret_version', 'use_sudo'];

    public function update(Request $request, Project $project, Server $server, RemoteServerConnections $connections): RedirectResponse
    {
        $this->authorizeServer($project, $server);
        // Reject unknown input before validation can flash it to the session.
        abort_if(array_diff(array_keys($request->except('_token')), self::FIELDS) !== [], 422, 'Parámetros de conexión no registrados.');
        $validated = $request->validate([
            'type' => ['required', Rule::enum(ConnectionType::class)],
            'instance_id' => ['nullable', 'string', 'max:32'],
            'region' => ['nullable', 'string', 'max:32'],
            'host' => ['nullable', 'string', 'max:253'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'user' => ['nullable', 'string', 'max:32'],
            'host_key' => ['nullable', 'string', 'max:1100'],
            'secret_reference' => ['nullable', 'string', 'max:64'],
            'secret_version' => ['nullable', 'string', 'max:9'],
            'use_sudo' => ['nullable', 'boolean'],
        ]);
        $type = ConnectionType::from($validated['type']);
        $input = $type === ConnectionType::AWS_SSM
            ? ['instance_id' => $validated['instance_id'] ?? null, 'region' => $validated['region'] ?? null]
            : ['host' => $validated['host'] ?? null, 'port' => isset($validated['port']) ? (int) $validated['port'] : 22,
                'user' => $validated['user'] ?? null, 'host_key' => $validated['host_key'] ?? null,
                'secret_version' => $validated['secret_version'] ?? null, 'use_sudo' => (bool) ($validated['use_sudo'] ?? false)];
        $reference = $type === ConnectionType::SSH ? ($validated['secret_reference'] ?? null) : null;

        /** @var User $actor */
        $actor = $request->user();
        $connections->configure($server, $type, $input, $reference, $actor);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Conexión guardada. Pruébela antes de usar el servidor.']);

        return to_route('projects.show', $project->uuid);
    }

    public function installAgent(Request $request, Project $project, Server $server, InstallRemoteAgent $installer): RedirectResponse
    {
        $this->authorizeServer($project, $server);
        /** @var User $actor */
        $actor = $request->user();
        $result = $installer->handle($server, $actor);
        Inertia::flash('toast', $result['installed']
            ? ['type' => 'success', 'message' => 'Agente '.$result['agent_version'].' instalado y verificado.']
            : ['type' => 'error', 'message' => 'No se instaló el agente ('.$result['error_code'].').']);

        return to_route('projects.show', $project->uuid);
    }

    /**
     * Replaces the agent while the project is in progress (the wizard is read-only then).
     * Only an administrator does it, confirming it explicitly, and never while something runs on the server.
     */
    public function updateAgent(Request $request, Project $project, Server $server, UpdateRemoteAgent $updater): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless((bool) config('toolkit.features.remote_agent.enabled', false), 409, 'Las conexiones remotas están deshabilitadas.');
        abort_unless((int) $server->project_id === (int) $project->getKey(), 404);
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor->isAdmin(), 403, 'Solo un administrador puede actualizar el agente de un servidor.');
        $request->validate(['acknowledged' => ['accepted']], ['acknowledged.accepted' => 'Confirme que desea actualizar el agente.']);
        try {
            $result = $updater->handle($server, $actor);
        } catch (ToolOperationBlocked|RemoteAgentUnavailable $exception) {
            throw ValidationException::withMessages(['agent' => $exception->getMessage()]);
        }
        Inertia::flash('toast', match (true) {
            $result['already_current'] => ['type' => 'success', 'message' => 'El agente '.$result['agent_version'].' ya estaba actualizado y verificado.'],
            $result['updated'] => ['type' => 'success', 'message' => 'Agente actualizado a '.$result['agent_version'].' y verificado.'],
            default => ['type' => 'error', 'message' => 'No se actualizó el agente ('.$result['error_code'].').'],
        });

        return to_route('projects.show', $project->uuid);
    }

    public function test(Request $request, Project $project, Server $server, TestRemoteConnection $tester): RedirectResponse
    {
        $this->authorizeServer($project, $server);
        /** @var User $actor */
        $actor = $request->user();
        $result = $tester->handle($server, $actor);
        Inertia::flash('toast', $result['status'] === 'VALID'
            ? ['type' => 'success', 'message' => 'Conexión verificada.']
            : ['type' => 'error', 'message' => 'La conexión no superó la prueba; revise las comprobaciones.']);

        return to_route('projects.show', $project->uuid);
    }

    /** Deploys the verified Collector distribution of the catalog; it is hash-checked on the server. */
    public function deployCollector(Request $request, Project $project, Server $server, RemoteToolDeployer $deployer): RedirectResponse
    {
        $this->authorizeServer($project, $server);
        /** @var User $actor */
        $actor = $request->user();
        $distribution = ToolDistribution::query()->where('key', RemoteCollectorExport::DISTRIBUTION_KEY)->sole();
        $result = $deployer->deploy($server, $distribution, $actor);
        Inertia::flash('toast', $result['installed']
            ? ['type' => 'success', 'message' => $result['already_present'] ? 'El Recolector 7.4.2 ya estaba desplegado e íntegro.' : 'Recolector 7.4.2 desplegado y verificado por hash.']
            : ['type' => 'error', 'message' => 'No se desplegó el Recolector ('.$result['error_code'].').']);

        return to_route('projects.show', $project->uuid);
    }

    /** Lists the profiles the server's administrator defined, by identifier only. */
    public function detectSources(Request $request, Project $project, Server $server, DetectRemoteSources $sources): RedirectResponse
    {
        $this->authorizeServer($project, $server);
        /** @var User $actor */
        $actor = $request->user();
        $result = $sources->handle($server, $actor);
        Inertia::flash('toast', $result['detected']
            ? ['type' => count($result['sources']) > 0 ? 'success' : 'warning', 'message' => count($result['sources']) > 0
                ? 'Perfiles de origen detectados: '.count($result['sources']).'.'
                : 'El servidor no tiene perfiles de origen; el administrador debe crearlos (ver la guía).']
            : ['type' => 'error', 'message' => 'No se pudieron listar los perfiles ('.$result['error_code'].').']);

        return to_route('projects.show', $project->uuid);
    }

    /** Checks a root-owned source profile on the server; the platform never sees its paths or secrets. */
    public function checkSource(Request $request, Project $project, Server $server, CheckRemoteSource $sources): RedirectResponse
    {
        $this->authorizeServer($project, $server);
        $input = $request->validate(['source' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/D']]);
        /** @var User $actor */
        $actor = $request->user();
        $result = $sources->handle($server, (string) $input['source'], $actor);
        Inertia::flash('toast', $result['ready']
            ? ['type' => 'success', 'message' => 'El perfil '.$result['source'].' está listo para recolectar.']
            : ['type' => 'error', 'message' => 'El perfil '.$result['source'].' no superó la comprobación'.($result['error_code'] ? ' ('.$result['error_code'].')' : '').'.']);

        return to_route('projects.show', $project->uuid);
    }

    /** Queues the Consolidator deployment: dozens of calls, too many for one request. */
    public function deployConsolidator(Request $request, Project $project, Server $server, RemoteServerConnections $connections): RedirectResponse
    {
        $this->authorizeDestination($project, $server);
        $distribution = ToolDistribution::query()->where('key', CheckRemoteDestination::DISTRIBUTION_KEY)->sole();
        if ($connections->primary($server)?->status !== ConnectionStatus::VALID || $distribution->verification_state !== 'VERIFIED') {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'El servidor debe superar la prueba de conexión y la distribución estar verificada.']);

            return to_route('projects.show', $project->uuid);
        }
        DeployRemoteTool::record($server, $distribution->key, 'QUEUED', null);
        DeployRemoteTool::dispatch($server->getKey(), $distribution->getKey(), $request->user()?->getKey());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Despliegue del Consolidador en curso; el panel muestra su avance.']);

        return to_route('projects.show', $project->uuid);
    }

    /** Lists the destination profiles the server's administrator defined. */
    public function detectDestinations(Request $request, Project $project, Server $server, DetectRemoteDestinations $destinations): RedirectResponse
    {
        $this->authorizeDestination($project, $server);
        /** @var User $actor */
        $actor = $request->user();
        $result = $destinations->handle($server, $actor);
        Inertia::flash('toast', $result['detected']
            ? ['type' => count($result['destinations']) > 0 ? 'success' : 'warning', 'message' => count($result['destinations']) > 0
                ? 'Perfiles de destino detectados: '.count($result['destinations']).'.'
                : 'El servidor no tiene perfiles de destino; el administrador debe crearlos (ver la guía).']
            : ['type' => 'error', 'message' => 'No se pudieron listar los perfiles ('.$result['error_code'].').']);

        return to_route('projects.show', $project->uuid);
    }

    /** Checks a root-owned destination profile; the platform never sees the runtime's secrets. */
    public function checkDestination(Request $request, Project $project, Server $server, CheckRemoteDestination $destinations): RedirectResponse
    {
        $this->authorizeDestination($project, $server);
        $input = $request->validate(['destination' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/D']]);
        /** @var User $actor */
        $actor = $request->user();
        $result = $destinations->handle($server, (string) $input['destination'], $actor);
        Inertia::flash('toast', $result['ready']
            ? ['type' => $result['warnings'] === [] ? 'success' : 'warning', 'message' => 'El perfil '.$result['destination'].' está listo para consolidar'
                .($result['warnings'] === [] ? '.' : ', con advertencias: '.implode(', ', $result['warnings']).'.')]
            : ['type' => 'error', 'message' => 'El perfil '.$result['destination'].' no superó la comprobación ('
                .($result['error_code'] ?? implode(', ', $result['blockers'])).').']);

        return to_route('projects.show', $project->uuid);
    }

    /** Launches the Consolidator's preparation as a durable operation on the server. */
    public function prepareDestination(Request $request, Project $project, Server $server, PrepareRemoteDestination $preparations): RedirectResponse
    {
        $this->authorizeDestination($project, $server);
        $input = $request->validate(['destination' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/D'],
            'reset' => ['sometimes', 'boolean'], 'confirmation' => ['nullable', 'string', 'max:20']]);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $result = $preparations->start($server, (string) $input['destination'], $actor, (bool) ($input['reset'] ?? false),
                isset($input['confirmation']) ? (string) $input['confirmation'] : null);
            Inertia::flash('toast', ($result['state'] ?? null) === 'FAILED'
                ? ['type' => 'error', 'message' => 'El agente rechazó la preparación ('.($result['error_code'] ?? 'error').').']
                : ['type' => 'success', 'message' => 'Preparación del destino iniciada; continúa en el servidor aunque se cierre esta página.']);
        } catch (ToolOperationBlocked $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return to_route('projects.show', $project->uuid);
    }

    public function cancelDestinationPreparation(Request $request, Project $project, Server $server, PrepareRemoteDestination $preparations): RedirectResponse
    {
        $this->authorizeDestination($project, $server);
        $input = $request->validate(['destination' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/D']]);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $preparations->cancel($server, (string) $input['destination'], $actor);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Cancelación de la preparación solicitada.']);
        } catch (ToolOperationBlocked|RemoteAgentUnavailable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return to_route('projects.show', $project->uuid);
    }

    /** Brings a verified source package from S3 into the destination runtime's copias/. */
    public function fetchDestinationPackage(Request $request, Project $project, Server $server, RemoteDestinationPackages $packages): RedirectResponse
    {
        $this->authorizeDestination($project, $server);
        $input = $request->validate(['destination' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/D'],
            'source_package' => ['required', 'uuid']]);
        $package = SourcePackage::query()->where('uuid', $input['source_package'])->with('project')->firstOrFail();
        // The package may come from another project; its own project must be visible to the actor.
        Gate::authorize('view', $package->project);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $result = $packages->fetch($server, (string) $input['destination'], $package, $actor);
            Inertia::flash('toast', ($result['state'] ?? null) === 'FAILED'
                ? ['type' => 'error', 'message' => 'El agente rechazó la transferencia ('.($result['error_code'] ?? 'error').').']
                : ['type' => 'success', 'message' => 'Transferencia del paquete '.$package->name.' iniciada en el servidor.']);
        } catch (ToolOperationBlocked $exception) {
            // Next to the form, not a passing toast: it stays until the next attempt.
            return to_route('projects.show', $project->uuid)->withErrors(['source_package' => $exception->getMessage()]);
        }

        return to_route('projects.show', $project->uuid);
    }

    public function listDestinationPackages(Request $request, Project $project, Server $server, RemoteDestinationPackages $packages): RedirectResponse
    {
        $this->authorizeDestination($project, $server);
        $input = $request->validate(['destination' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/D']]);
        try {
            $result = $packages->list($server, (string) $input['destination']);
            Inertia::flash('toast', $result['listed']
                ? ['type' => 'success', 'message' => 'Paquetes en el destino: '.count($result['packages']).'.']
                : ['type' => 'error', 'message' => 'No se pudo listar copias/ ('.$result['error_code'].').']);
        } catch (ToolOperationBlocked $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return to_route('projects.show', $project->uuid);
    }

    public function removeDestinationPackage(Request $request, Project $project, Server $server, RemoteDestinationPackages $packages): RedirectResponse
    {
        $this->authorizeDestination($project, $server);
        $input = $request->validate(['destination' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/D'],
            'name' => ['required', 'string', 'regex:/^[a-z][a-z0-9_-]{0,63}\.zip$/D']]);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $packages->remove($server, (string) $input['destination'], (string) $input['name'], $actor);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Paquete '.$input['name'].' retirado del destino.']);
        } catch (ToolOperationBlocked|RemoteAgentUnavailable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return to_route('projects.show', $project->uuid);
    }

    private function authorizeDestination(Project $project, Server $server): void
    {
        $this->authorizeServer($project, $server);
        abort_unless($server->role === ServerRole::DESTINATION, 422, 'Solo un servidor de destino aloja el Consolidador.');
    }

    private function authorizeServer(Project $project, Server $server): void
    {
        Gate::authorize('update', $project);
        abort_unless((bool) config('toolkit.features.remote_agent.enabled', false), 409, 'Las conexiones remotas están deshabilitadas.');
        abort_unless((int) $server->project_id === (int) $project->getKey(), 404);
    }
}
