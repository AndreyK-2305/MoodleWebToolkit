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
            $errors === [] ? 'La revisión, el servidor de origen y el perfil coinciden.' : implode(' ', $errors))];
        if ($errors !== []) {
            return $checks;
        }
        $settings = $this->configurations->settings($configuration);
        $server = $this->configurations->server($configuration);
        $connection = $this->connections->primary($server);
        $connected = $connection !== null && $connection->status === ConnectionStatus::VALID;
        $checks[] = $this->check('remote.connection', 'Conexión con el servidor de origen', $connected,
            $connected ? 'La última prueba de conexión y del agente fue satisfactoria.' : 'Configura la conexión e instala el agente; la prueba de conexión debe ser satisfactoria.');
        $deployed = $this->deployed($server);
        $checks[] = $this->check('collector.distribution', 'Recolector 7.4.2 desplegado', $deployed,
            $deployed ? 'El árbol desplegado coincide con la distribución verificada del catálogo.' : 'Despliega en el servidor la distribución verificada del Recolector 7.4.2.');
        $source = ($server->metadata['remote_sources'][$settings['source_profile']]['ready'] ?? false) === true;
        $checks[] = $this->check('remote.source', 'Perfil de origen comprobado', $source,
            $source ? 'El perfil del servidor superó la comprobación de Moodle y runtime.' : 'El perfil de origen no existe en el servidor o no superó su comprobación.');
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
        $server = is_string($options['server_uuid'] ?? null)
            ? Server::query()->where('project_id', $project->id)->where('uuid', $options['server_uuid'])->first() : null;
        $connection = $server === null ? null : $this->connections->primary($server);
        $source = is_string($options['source_profile'] ?? null) ? ($server?->metadata['remote_sources'][$options['source_profile']] ?? null) : null;

        return $this->hashes->hash([
            'schema_version' => 'collector-remote-preflight.v1', 'project_uuid' => $project->uuid,
            'configuration_version' => $configuration->version, 'options_sha256' => $this->hashes->hash(is_array($options) ? $options : []),
            'connection' => $connection === null ? null : ['type' => $connection->type, 'status' => $connection->status->value,
                'configuration_sha256' => $this->hashes->hash($connection->configuration ?? [])],
            'deployed_sha256' => $server?->metadata['remote_tools'][RemoteCollectorExport::DISTRIBUTION_KEY]['tree_sha256'] ?? null,
            'distribution_sha256' => ToolDistribution::query()->where('key', RemoteCollectorExport::DISTRIBUTION_KEY)->value('distribution_sha256'),
            'source' => is_array($source) ? $this->hashes->hash($source) : null,
            'bucket' => config('toolkit.remote.package_bucket'), 'region' => config('toolkit.remote.package_region'),
        ]);
    }

    private function deployed(Server $server): bool
    {
        $distribution = ToolDistribution::query()->with('toolVersion')->where('key', RemoteCollectorExport::DISTRIBUTION_KEY)
            ->where('verification_state', 'VERIFIED')->first();
        $deployed = $server->metadata['remote_tools'][RemoteCollectorExport::DISTRIBUTION_KEY]['tree_sha256'] ?? null;
        if ($distribution === null || ! is_string($deployed) || ! hash_equals($distribution->distribution_sha256, $deployed)) {
            return false;
        }
        try {
            $this->gate->assertRunnable($distribution->toolVersion, 'moodle.source.export');
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
