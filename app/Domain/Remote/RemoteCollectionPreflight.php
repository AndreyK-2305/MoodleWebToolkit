<?php

namespace App\Domain\Remote;

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Tools\ToolOperationGate;
use App\Enums\ConnectionStatus;
use App\Enums\ProjectType;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\Server;
use App\Models\ToolDistribution;
use Throwable;

/**
 * Preflight for a remote collection. It reads only evidence the platform
 * already holds (connection test, deployed tool, source check); it never
 * calls the server, so confirming and starting stay fast and deterministic.
 */
final class RemoteCollectionPreflight
{
    public function __construct(
        private readonly RemoteCollectionConfiguration $configurations,
        private readonly CollectorConfiguration $hashes,
        private readonly RemoteServerConnections $connections,
        private readonly ToolOperationGate $gate,
    ) {}

    /** @return list<array{id: string, description: string, result: string, detail: string}> */
    public function evaluate(Project $project, ProjectConfiguration $configuration): array
    {
        $errors = $this->configurationErrors($project, $configuration);
        $checks = [$this->check('collector.configuration', 'Configuración remota vigente', $errors === [],
            $errors === [] ? 'La revisión, los servidores de origen y sus perfiles coinciden.' : implode(' ', $errors))];
        if ($errors !== []) {
            return $checks;
        }
        $settings = $this->configurations->settings($configuration);
        // One check per kind across every source; the detail names the sources still pending.
        $pending = ['connection' => [], 'distribution' => [], 'source' => []];
        $blocked = $this->blocked();
        foreach ($settings['sources'] as $source) {
            $server = Server::query()->where('project_id', $project->id)->where('uuid', $source['server_uuid'])->firstOrFail();
            $label = $server->name.' ('.$source['source_profile'].')';
            $connection = $this->connections->primary($server);
            if ($connection === null || $connection->status !== ConnectionStatus::VALID) {
                $pending['connection'][] = $label;
            }
            if ($blocked === null && ! $this->deployed($server)) {
                $pending['distribution'][] = $label;
            }
            if (($server->metadata['remote_sources'][$source['source_profile']]['ready'] ?? false) !== true) {
                $pending['source'][] = $label;
            }
        }
        $count = count($settings['sources']);
        $checks[] = $this->check('remote.connection', 'Conexión con los servidores de origen', $pending['connection'] === [],
            $pending['connection'] === [] ? "La última prueba de conexión y del agente fue satisfactoria en los {$count} orígenes."
                : 'Configura la conexión e instala el agente en: '.implode(', ', $pending['connection']).'.');
        // A tool the catalog keeps closed is named as such, not as a missing deployment.
        $checks[] = $blocked !== null
            ? $this->check('collector.distribution', 'Recolector 7.4.2 habilitado en la plataforma', false,
                'El Recolector 7.4.2 no está habilitado en el catálogo de herramientas: '.$blocked.' Un administrador de la plataforma debe habilitarlo; desplegarlo de nuevo no lo resuelve.')
            : $this->check('collector.distribution', 'Recolector 7.4.2 desplegado', $pending['distribution'] === [],
                $pending['distribution'] === [] ? 'El árbol desplegado coincide con la distribución verificada del catálogo en cada origen.'
                    : 'Despliega la distribución verificada del Recolector 7.4.2 en: '.implode(', ', $pending['distribution']).'.');
        $checks[] = $this->check('remote.source', 'Perfiles de origen comprobados', $pending['source'] === [],
            $pending['source'] === [] ? 'Cada perfil superó la comprobación de Moodle, PHP y moodledata.'
                : 'Comprueba el perfil de origen en: '.implode(', ', $pending['source']).'.');
        $bucket = is_string(config('toolkit.remote.package_bucket')) && config('toolkit.remote.package_bucket') !== '';
        $checks[] = $this->check('remote.bucket', 'Destino de paquetes', $bucket,
            $bucket ? 'Hay un bucket configurado para publicar el paquete fuente.' : 'Configura el bucket de paquetes fuente de la plataforma.');
        $checks[] = ['id' => 'collector.remote_source', 'description' => 'Recolección sobre el servidor de origen', 'result' => 'WARNING',
            'detail' => 'El Recolector lee el Moodle de origen y crea copias de seguridad en ese servidor; consume CPU, disco y base de datos mientras exporta.'];

        return $checks;
    }

    /** @return list<string> */
    public function configurationErrors(Project $project, ProjectConfiguration $configuration): array
    {
        if ($project->type !== ProjectType::COLLECT || ! config('toolkit.features.remote_agent.enabled')) {
            return ['La recolección remota requiere un proyecto COLLECT y el agente remoto habilitado.'];
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
        $sources = [];
        foreach (RemoteCollectionConfiguration::sources(is_array($options) ? $options : []) as $source) {
            $server = Server::query()->where('project_id', $project->id)->where('uuid', $source['server_uuid'])->first();
            $connection = $server === null ? null : $this->connections->primary($server);
            $profile = $server?->metadata['remote_sources'][$source['source_profile']] ?? null;
            $sources[] = [
                'connection' => $connection === null ? null : ['type' => $connection->type, 'status' => $connection->status->value,
                    'configuration_sha256' => $this->hashes->hash($connection->configuration ?? [])],
                'deployed_sha256' => $server?->metadata['remote_tools'][RemoteCollectorExport::DISTRIBUTION_KEY]['tree_sha256'] ?? null,
                'source' => is_array($profile) ? $this->hashes->hash($profile) : null,
            ];
        }

        return $this->hashes->hash([
            'schema_version' => 'collector-remote-preflight.v2', 'project_uuid' => $project->uuid,
            'configuration_version' => $configuration->version, 'options_sha256' => $this->hashes->hash(is_array($options) ? $options : []),
            'sources' => $sources,
            'distribution_sha256' => ToolDistribution::query()->where('key', RemoteCollectorExport::DISTRIBUTION_KEY)->value('distribution_sha256'),
            'bucket' => config('toolkit.remote.package_bucket'), 'region' => config('toolkit.remote.package_region'),
        ]);
    }

    /** Why the catalog does not let the Recolector run, or null when it does. */
    private function blocked(): ?string
    {
        $distribution = ToolDistribution::query()->with('toolVersion')->where('key', RemoteCollectorExport::DISTRIBUTION_KEY)
            ->where('verification_state', 'VERIFIED')->first();
        if ($distribution === null) {
            return 'su distribución verificada no está registrada.';
        }
        try {
            $this->gate->assertRunnable($distribution->toolVersion, 'moodle.source.export');
        } catch (ToolOperationBlocked $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    private function deployed(Server $server): bool
    {
        $distribution = ToolDistribution::query()->where('key', RemoteCollectorExport::DISTRIBUTION_KEY)->where('verification_state', 'VERIFIED')->first();
        $deployed = $server->metadata['remote_tools'][RemoteCollectorExport::DISTRIBUTION_KEY]['tree_sha256'] ?? null;

        return $distribution !== null && is_string($deployed) && hash_equals($distribution->distribution_sha256, $deployed);
    }

    /** @return array{id: string, description: string, result: string, detail: string} */
    private function check(string $id, string $description, bool $success, string $detail): array
    {
        return ['id' => $id, 'description' => $description, 'result' => $success ? 'SUCCESS' : 'ERROR', 'detail' => $detail];
    }
}
