<?php

namespace App\Domain\Remote;

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Tools\ToolOperationGate;
use App\Enums\ConnectionStatus;
use App\Enums\ProjectType;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\Server;
use App\Models\ToolDistribution;
use Throwable;

/**
 * Preflight for a remote consolidation. Like the remote collection's, it
 * reads only evidence the platform already holds: the destination check, the
 * last preparation and the last listing of copias/. The Consolidator imports
 * every ZIP in copias/, so that listing must hold exactly the chosen packages.
 */
final class RemoteConsolidationPreflight
{
    public function __construct(
        private readonly RemoteConsolidationConfiguration $configurations,
        private readonly CollectorConfiguration $hashes,
        private readonly RemoteServerConnections $connections,
        private readonly ToolOperationGate $gate,
    ) {}

    /** @return list<array{id: string, description: string, result: string, detail: string}> */
    public function evaluate(Project $project, ProjectConfiguration $configuration): array
    {
        $errors = $this->configurationErrors($project, $configuration);
        $checks = [$this->check('consolidation.configuration', 'Configuración de la consolidación vigente', $errors === [],
            $errors === [] ? 'La revisión, el destino y los paquetes coinciden.' : implode(' ', $errors))];
        if ($errors !== []) {
            return $checks;
        }
        $settings = $this->configurations->settings($configuration);
        $server = Server::query()->where('project_id', $project->id)->where('uuid', $settings['server_uuid'])->firstOrFail();
        $profile = $settings['destination_profile'];
        $check = $server->metadata['remote_destinations'][$profile] ?? null;
        $preparation = $server->metadata['remote_destination_preparations'][$profile] ?? null;
        $copias = $server->metadata['remote_destination_copias'][$profile] ?? null;

        $connection = $this->connections->primary($server)?->status === ConnectionStatus::VALID;
        $checks[] = $this->check('remote.connection', 'Conexión con el servidor de destino', $connection,
            $connection ? 'La última prueba de conexión y del agente fue satisfactoria.' : 'Configura la conexión e instala el agente en '.$server->name.'.');
        $deployed = $this->deployed($server);
        $checks[] = $this->check('consolidator.distribution', 'Consolidador V8 desplegado y habilitado', $deployed,
            $deployed ? 'El árbol desplegado coincide con la distribución verificada del catálogo.'
                : 'Despliega el Consolidador verificado y habilita su modo remoto (consolidator:enable-remote).');
        $ready = is_array($check) && ($check['ready'] ?? false) === true;
        $checks[] = $this->check('remote.destination', 'Perfil de destino comprobado', $ready,
            $ready ? 'El perfil '.$profile.' superó su comprobación.' : 'Comprueba el perfil de destino '.$profile.'.');
        $prepared = ($preparation['state'] ?? null) === 'SUCCEEDED' || ($check['report']['target_running'] ?? false) === true;
        $checks[] = $this->check('destination.prepared', 'Destino preparado', $prepared,
            $prepared ? 'Moodle 5.2.1 destino quedó instalado por la preparación del Consolidador.' : 'Prepara el destino antes de consolidar.');
        $written = ($check['report']['destination_written'] ?? false) === true || ($copias['write_locked'] ?? false) === true;
        $checks[] = $this->check('destination.unwritten', 'Destino sin consolidaciones previas', ! $written,
            $written ? 'El Consolidador ya escribió en este destino; una nueva consolidación requiere un destino limpio.'
                : 'El Consolidador aún no escribió en el destino.');
        [$matches, $detail] = $this->copias(is_array($copias) ? $copias : null, $settings['source_packages']);
        $checks[] = $this->check('destination.copias', 'Paquetes en copias/', $matches, $detail);
        $checks[] = ['id' => 'consolidation.oauth', 'description' => 'Configuración manual de Google OAuth2', 'result' => 'WARNING',
            'detail' => 'La Ejecución se detendrá para que el administrador configure el servicio OAuth2 en el Moodle destino; luego se continúa desde la plataforma.'];

        return $checks;
    }

    /** @return list<string> */
    public function configurationErrors(Project $project, ProjectConfiguration $configuration): array
    {
        if ($project->type !== ProjectType::CONSOLIDATE || ! config('toolkit.features.remote_agent.enabled')) {
            return ['La consolidación remota requiere un proyecto CONSOLIDATE y el agente remoto habilitado.'];
        }
        try {
            $this->configurations->settings($configuration);
        } catch (Throwable) {
            return ['La revisión de configuración está incompleta, alterada u obsoleta.'];
        }

        return [];
    }

    public function fingerprint(Project $project, ProjectConfiguration $configuration): string
    {
        $options = $configuration->settings['options'] ?? [];
        $options = is_array($options) ? $options : [];
        $server = is_string($options['server_uuid'] ?? null) ? Server::query()->where('project_id', $project->id)->where('uuid', $options['server_uuid'])->first() : null;
        $profile = is_string($options['destination_profile'] ?? null) ? $options['destination_profile'] : '';
        $connection = $server === null ? null : $this->connections->primary($server);
        $evidence = fn (string $key): ?string => is_array($server?->metadata[$key][$profile] ?? null) ? $this->hashes->hash($server->metadata[$key][$profile]) : null;

        return $this->hashes->hash([
            'schema_version' => 'consolidator-remote-preflight.v1', 'project_uuid' => $project->uuid,
            'configuration_version' => $configuration->version, 'options_sha256' => $this->hashes->hash($options),
            'connection' => $connection === null ? null : ['type' => $connection->type, 'status' => $connection->status->value,
                'configuration_sha256' => $this->hashes->hash($connection->configuration ?? [])],
            'deployed_sha256' => $server?->metadata['remote_tools'][CheckRemoteDestination::DISTRIBUTION_KEY]['tree_sha256'] ?? null,
            'destination' => $evidence('remote_destinations'), 'preparation' => $evidence('remote_destination_preparations'),
            'copias' => $evidence('remote_destination_copias'),
            'distribution_sha256' => ToolDistribution::query()->where('key', CheckRemoteDestination::DISTRIBUTION_KEY)->value('distribution_sha256'),
        ]);
    }

    /**
     * copias/ must hold exactly the configured packages, each with its seal.
     *
     * @param  array<string, mixed>|null  $copias
     * @param  list<array{uuid: string, sha256: string, source_id: string, name: string}>  $packages
     * @return array{bool, string}
     */
    public static function copias(?array $copias, array $packages): array
    {
        if ($copias === null) {
            return [false, 'Lista copias/ en el destino con «Actualizar lista».'];
        }
        $listed = collect(is_array($copias['packages'] ?? null) ? $copias['packages'] : [])->filter(fn ($item): bool => is_array($item));
        $present = $listed->pluck('sidecar_sha256')->filter(fn ($sha): bool => is_string($sha))->all();
        $expected = array_column($packages, 'sha256');
        $missing = array_values(array_diff($expected, $present));
        $extra = $listed->filter(fn (array $item): bool => ! in_array($item['sidecar_sha256'] ?? null, $expected, true))->pluck('name')->all();
        if ($missing === [] && $extra === []) {
            return [true, 'copias/ contiene exactamente los '.count($packages).' paquetes configurados con su sello.'];
        }
        $names = collect($packages)->filter(fn (array $package): bool => in_array($package['sha256'], $missing, true))->pluck('name')->all();

        return [false, trim(($names === [] ? '' : 'Faltan en copias/: '.implode(', ', $names).'. ')
            .($extra === [] ? '' : 'Retira de copias/: '.implode(', ', $extra).' (el Consolidador importa todos los ZIP).'))];
    }

    private function deployed(Server $server): bool
    {
        $distribution = ToolDistribution::query()->with('toolVersion')->where('key', CheckRemoteDestination::DISTRIBUTION_KEY)
            ->where('verification_state', 'VERIFIED')->first();
        $deployed = $server->metadata['remote_tools'][CheckRemoteDestination::DISTRIBUTION_KEY]['tree_sha256'] ?? null;
        if ($distribution === null || ! is_string($deployed) || ! hash_equals($distribution->distribution_sha256, $deployed)) {
            return false;
        }
        try {
            $this->gate->assertRunnable($distribution->toolVersion, RemoteConsolidationConfiguration::WORKFLOW);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** @return array{id: string, description: string, result: string, detail: string} */
    private function check(string $id, string $description, bool $success, string $detail): array
    {
        return ['id' => $id, 'description' => $description, 'result' => $success ? 'SUCCESS' : 'ERROR', 'detail' => $detail];
    }
}
