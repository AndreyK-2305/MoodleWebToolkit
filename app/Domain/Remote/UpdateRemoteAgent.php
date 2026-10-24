<?php

namespace App\Domain\Remote;

use App\Domain\Remote\DTOs\RemoteTarget;
use App\Enums\ProjectStatus;
use App\Enums\RemoteCommunicationState;
use App\Exceptions\RemoteAgentUnavailable;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Updates the agent of a server while its project is in progress, for example while the
 * Execution waits for the administrator. Only an administrator does it, after an explicit
 * confirmation, and only when nothing runs on that server: an operation of the agent, a
 * preparation, a transfer or a deployment. Updating an agent that already matches the
 * verified distribution is a no-op; after installing, the version is asked to the agent.
 */
class UpdateRemoteAgent
{
    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly InstallRemoteAgent $installer,
        private readonly RemoteAgent $agent,
    ) {}

    /** @return array{updated: bool, already_current: bool, agent_version: string, error_code: string|null} */
    public function handle(Server $server, User $actor): array
    {
        $this->connections->assertEnabled();
        $project = Project::query()->findOrFail($server->project_id);
        if (! $actor->is_active || ! $actor->isAdmin()) {
            throw new AuthorizationException('Solo un administrador puede actualizar el agente de un servidor.');
        }
        if ($project->status === ProjectStatus::COMPLETED) {
            throw ValidationException::withMessages(['agent' => 'El proyecto está completado; su agente ya no se actualiza desde aquí.']);
        }
        if (($busy = $this->busy($server->fresh() ?? $server)) !== null) {
            throw ValidationException::withMessages(['agent' => 'No se puede actualizar el agente de '.$server->name.' mientras '.$busy
                .'. Espere a que termine o a que la Ejecución quede esperando una acción del administrador.']);
        }

        $target = $this->connections->target($server);
        $distribution = $this->agent->distribution();
        if ($this->current($target)) {
            $this->audit($server, $actor, $distribution, 'ALREADY_CURRENT', null);

            return ['updated' => false, 'already_current' => true, 'agent_version' => $distribution['version'], 'error_code' => null];
        }

        $install = $this->installer->handle($server, $actor);
        $errorCode = $install['error_code'];
        if ($errorCode === null && ! $this->current($target)) {
            $errorCode = 'agent_version_mismatch';
        }
        $this->audit($server, $actor, $distribution, $errorCode === null ? 'SUCCESS' : 'ERROR', $errorCode);

        return ['updated' => $errorCode === null, 'already_current' => false, 'agent_version' => $distribution['version'], 'error_code' => $errorCode];
    }

    /** What runs on the server and keeps its agent from being replaced, or null. */
    public function busy(Server $server): ?string
    {
        $running = RemoteOperation::query()->where('provider_key', RemoteAgentOperations::PROVIDER)->where('host_id', $server->uuid)
            ->where('communication_state', '!=', RemoteCommunicationState::TERMINATED->value)->exists();
        if ($running) {
            return 'una operación remota está en curso';
        }
        $metadata = is_array($server->metadata) ? $server->metadata : [];
        $states = fn (string $key): array => collect(is_array($metadata[$key] ?? null) ? $metadata[$key] : [])
            ->filter(fn (mixed $item): bool => is_array($item))->pluck('state')->all();
        if (array_intersect($states('remote_destination_preparations'), PrepareRemoteDestination::ACTIVE) !== []) {
            return 'una preparación del destino está en curso';
        }
        $fetches = collect(is_array($metadata['remote_destination_fetches'] ?? null) ? $metadata['remote_destination_fetches'] : [])
            ->filter(fn (mixed $profile): bool => is_array($profile))->flatMap(fn (array $profile): array => $profile)
            ->filter(fn (mixed $fetch): bool => is_array($fetch))->pluck('state')->all();
        if (array_intersect($fetches, RemoteDestinationPackages::ACTIVE) !== []) {
            return 'una transferencia de paquetes está en curso';
        }
        if (array_intersect($states('remote_tool_deployments'), ['QUEUED', 'RUNNING']) !== []) {
            return 'un despliegue de herramienta está en curso';
        }

        return null;
    }

    /**
     * Asks the agent on the server for its version; the answer changes once it is installed.
     *
     * @phpstan-impure
     */
    private function current(RemoteTarget $target): bool
    {
        $distribution = $this->agent->distribution();
        try {
            $version = $this->agent->call($target, 'version');
        } catch (RemoteAgentUnavailable) {
            return false;
        }

        return $version->ok && $version->agentVersion === $distribution['version']
            && ($version->data['agent_sha256'] ?? null) === $distribution['sha256'];
    }

    /** @param array{version: string, sha256: string} $distribution */
    private function audit(Server $server, User $actor, array $distribution, string $result, ?string $errorCode): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->getKey(), 'project_id' => $server->project_id, 'action' => 'REMOTE_AGENT_UPDATED',
            'auditable_type' => $server->getMorphClass(), 'auditable_id' => $server->getKey(),
            'payload' => ['agent_version' => $distribution['version'], 'agent_sha256' => $distribution['sha256'],
                'result' => $result, 'error_code' => $errorCode],
        ]);
    }
}
