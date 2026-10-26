<?php

namespace App\Domain\Remote;

use App\Enums\ConnectionStatus;
use App\Enums\ProjectType;
use App\Exceptions\RemoteAgentUnavailable;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Project;
use App\Models\Server;
use App\Models\SourcePackage;
use App\Models\User;
use Throwable;

/**
 * Brings exactly the configured packages of a remote consolidation into its
 * destination's copias/. The agent moves one package at a time, so each call
 * starts at most one transfer; the observation of a finished transfer calls
 * it again for the next one. It never removes anything: a ZIP that does not
 * belong is reported by the preflight for the administrator to retire.
 *
 * A failed transfer is not retried on its own: the next explicit request
 * (saving the configuration or running the preflight) tries it again.
 *
 * @phpstan-type CopiasProgress array{
 *   state: 'NOT_CONFIGURED'|'BLOCKED'|'FETCHING'|'FAILED'|'READY',
 *   ready: int, total: int, current: string|null, missing: list<string>, detail: string
 * }
 */
class RemoteConsolidationCopias
{
    public function __construct(
        private readonly RemoteConsolidationConfiguration $configurations,
        private readonly RemoteDestinationPackages $packages,
        private readonly RemoteServerConnections $connections,
    ) {}

    /**
     * Starts the next missing transfer, if any. With $retry a failed transfer is attempted again.
     *
     * @return CopiasProgress
     */
    public function advance(Project $project, ?User $actor, bool $retry = true): array
    {
        $target = $this->target($project);
        if ($target === null || $this->blocked($target[0], $target[1]) !== null) {
            return $this->progress($project);
        }
        [$server, $profile, $configured] = $target;
        if (! is_array($server->metadata['remote_destination_copias'][$profile] ?? null)) {
            // Without a listing nothing is known about copias/: ask the agent once.
            try {
                $this->packages->list($server, $profile);
            } catch (ToolOperationBlocked) {
                return $this->progress($project);
            }
        }
        $progress = $this->progress($project);
        if ($progress['state'] === 'FETCHING' || $progress['state'] === 'READY' || ($progress['state'] === 'FAILED' && ! $retry)) {
            return $progress;
        }
        $next = $this->nextMissing($server->refresh(), $profile, $configured);
        if ($next === null) {
            return $this->progress($project);
        }
        $package = SourcePackage::query()->where('uuid', $next['uuid'])->first();
        if ($package === null) {
            return $progress;
        }
        try {
            $this->packages->fetch($server, $profile, $package, $actor);
        } catch (ToolOperationBlocked|RemoteAgentUnavailable) {
            // The progress below tells what is missing; the panel keeps the manual transfer.
        } catch (Throwable $exception) {
            // An S3 verification error must not break the request that asked for the transfer.
            report($exception);
        }

        return $this->progress($project->refresh());
    }

    /**
     * Where the configured packages stand, read from the evidence the platform holds; no remote call.
     *
     * @return CopiasProgress
     */
    public function progress(Project $project): array
    {
        $target = $this->target($project);
        if ($target === null) {
            return ['state' => 'NOT_CONFIGURED', 'ready' => 0, 'total' => 0, 'current' => null, 'missing' => [],
                'detail' => 'La consolidación aún no tiene sus paquetes.'];
        }
        [$server, $profile, $configured] = $target;
        $total = count($configured);
        $present = $this->present($server, $profile);
        $fetches = $server->metadata['remote_destination_fetches'][$profile] ?? [];
        $fetches = is_array($fetches) ? $fetches : [];
        $missing = array_values(array_filter($configured, fn (array $package): bool => ! in_array($package['sha256'], $present, true)));
        $ready = $total - count($missing);
        $names = array_column($missing, 'name');
        if ($missing === []) {
            return ['state' => 'READY', 'ready' => $ready, 'total' => $total, 'current' => null, 'missing' => [],
                'detail' => 'Los '.$total.' paquetes configurados están en copias/.'];
        }
        foreach ($fetches as $fetch) {
            if (is_array($fetch) && in_array($fetch['state'] ?? null, RemoteDestinationPackages::ACTIVE, true)) {
                $name = is_string($fetch['package_name'] ?? null) ? $fetch['package_name'] : null;

                return ['state' => 'FETCHING', 'ready' => $ready, 'total' => $total, 'current' => $name, 'missing' => $names,
                    'detail' => 'Trayendo '.($name ?? 'un paquete').' desde S3 a copias/ ('.$ready.' de '.$total.' listos).'];
            }
        }
        $failed = collect($missing)->first(fn (array $package): bool => in_array($fetches[$package['uuid']]['state'] ?? null, ['FAILED', 'UNKNOWN', 'CANCELLED'], true));
        if ($failed !== null) {
            $error = $fetches[$failed['uuid']]['error_code'] ?? null;

            return ['state' => 'FAILED', 'ready' => $ready, 'total' => $total, 'current' => $failed['name'], 'missing' => $names,
                'detail' => 'No se pudo traer '.$failed['name'].' a copias/'.(is_string($error) ? ' ('.$error.')' : '').'. Ejecute de nuevo el preflight para reintentarlo.'];
        }
        $blocked = $this->blocked($server, $profile);

        return ['state' => 'BLOCKED', 'ready' => $ready, 'total' => $total, 'current' => null, 'missing' => $names,
            'detail' => $blocked ?? 'Faltan en copias/: '.implode(', ', $names).'. Se traerán desde S3 al ejecutar el preflight.'];
    }

    /**
     * The first configured package neither in copias/ nor moving there.
     *
     * @param  list<array{uuid: string, sha256: string, source_id: string, name: string}>  $configured
     * @return array{uuid: string, sha256: string, source_id: string, name: string}|null
     */
    private function nextMissing(Server $server, string $profile, array $configured): ?array
    {
        $present = $this->present($server, $profile);
        foreach ($configured as $package) {
            if (! in_array($package['sha256'], $present, true)) {
                return $package;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function present(Server $server, string $profile): array
    {
        $listed = $server->metadata['remote_destination_copias'][$profile]['packages'] ?? [];
        $present = [];
        foreach (is_array($listed) ? $listed : [] as $item) {
            if (is_array($item) && is_string($item['sidecar_sha256'] ?? null)) {
                $present[] = $item['sidecar_sha256'];
            }
        }

        return $present;
    }

    /** Why no transfer can start now, or null when the destination accepts one. */
    private function blocked(Server $server, string $profile): ?string
    {
        if (! config('toolkit.features.remote_agent.enabled')) {
            return 'El agente remoto está deshabilitado.';
        }
        if ($this->connections->primary($server)?->status !== ConnectionStatus::VALID) {
            return 'Pruebe la conexión con '.$server->name.' para que la plataforma traiga los paquetes a copias/.';
        }
        $check = $server->metadata['remote_destinations'][$profile] ?? null;
        if (! is_array($check) || ($check['ready'] ?? false) !== true) {
            return 'Compruebe el perfil de destino '.$profile.' para que la plataforma traiga los paquetes a copias/.';
        }
        if (($check['report']['destination_written'] ?? false) === true
            || ($server->metadata['remote_destination_copias'][$profile]['write_locked'] ?? false) === true) {
            return 'El Consolidador ya escribió en el destino; sus paquetes no cambian.';
        }

        return null;
    }

    /** @return array{Server, string, list<array{uuid: string, sha256: string, source_id: string, name: string}>}|null */
    private function target(Project $project): ?array
    {
        $configuration = $project->configuration()->first();
        if ($project->type !== ProjectType::CONSOLIDATE || ! $this->configurations->selected($configuration) || $configuration === null) {
            return null;
        }
        try {
            $settings = $this->configurations->settings($configuration);
        } catch (Throwable) {
            return null;
        }
        $server = Server::query()->where('project_id', $project->getKey())->where('uuid', $settings['server_uuid'])->first();

        return $server === null ? null : [$server, $settings['destination_profile'], $settings['source_packages']];
    }
}
