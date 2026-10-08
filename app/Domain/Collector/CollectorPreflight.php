<?php

namespace App\Domain\Collector;

use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Tools\CollectorAdapter;
use App\Domain\Tools\ToolDistributionVerifier;
use App\Domain\Tools\ToolOperationGate;
use App\Enums\ProjectType;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ToolDistribution;
use Throwable;

final class CollectorPreflight
{
    public function __construct(
        private readonly CollectorConfiguration $configurations,
        private readonly LabMoodleProfiles $profiles,
        private readonly SecretProvider $secrets,
        private readonly SyntheticMoodleProbe $moodle,
        private readonly ToolDistributionVerifier $distributions,
        private readonly ToolOperationGate $gate,
        private readonly CollectorPhpRuntime $runtime,
    ) {}

    /** @return list<array{id: string, description: string, result: string, detail: string}> */
    public function evaluate(Project $project, ProjectConfiguration $configuration): array
    {
        $checks = [];
        $errors = $this->configurationErrors($project, $configuration);
        $checks[] = $this->check('collector.configuration', 'Configuración real vigente', $errors === [], $errors === [] ? 'La revisión y el perfil autorizado coinciden.' : implode(' ', $errors));
        if ($errors !== []) {
            return $checks;
        }
        $options = $this->configurations->settings($configuration);
        $profile = $this->profiles->get($options['profile_id']);
        $distribution = ToolDistribution::query()->with('toolVersion')->where('key', $options['distribution_key'])->first();
        $verified = false;
        try {
            if ($distribution !== null && $distribution->toolVersion->version === '7.4.2-linux') {
                $this->gate->assertRunnable($distribution->toolVersion, 'moodle.source.export');
                $verification = $this->distributions->verify($distribution);
                $verified = isset($verification->manifestFiles['scripts/source-export.php'])
                    && $distribution->toolVersion->capabilities()->where('key', 'themes.inventory')->where('support_state', 'SUPPORTED')->exists();
            }
        } catch (Throwable) {
            // Technical errors and physical paths never enter HTTP props or audit.
        }
        $checks[] = $this->check('collector.distribution', 'Distribución 7.4.2 autorizada', $verified, $verified ? 'Versión, manifiesto, hashes, entrypoint y capabilities verificados.' : 'Falta una distribución íntegra, habilitada y compatible para COLLECT LAB.');
        $runtime = $this->runtime->available();
        $checks[] = $this->check('collector.runtime', 'Runtime compatible con Moodle 4.5', $runtime, $runtime ? 'Linux/PHP 8.3 y dependencias disponibles; ejecución PHP directa sin prompts.' : 'Se requiere el perfil Linux/PHP 8.3 con las extensiones Moodle y los wrappers de proceso.');
        $paths = is_file($profile['code'].'/version.php') && is_readable($profile['code'].'/lib/setup.php')
            && is_dir($profile['data']) && is_readable($profile['data']) && is_writable($profile['data']);
        $checks[] = $this->check('collector.paths', 'Código y almacenamiento Moodle', $paths, $paths ? 'Accesos dentro del ámbito sintético autorizado.' : 'Código o moodledata no están disponibles con los permisos requeridos.');
        $available = $this->secrets->available($profile['credential_reference'], $profile['credential_version']);
        $checks[] = $this->check('collector.reference', 'Referencia privada versionada', $available, $available ? 'La referencia existe; su valor no se expone.' : 'La referencia LAB no está disponible o no cumple los permisos privados.');
        $access = false;
        if ($paths && $available) {
            try {
                $this->moodle->inspect($profile);
                $access = true;
            } catch (Throwable) {
                // The probe uses a bounded connection; raw database errors are discarded.
            }
        }
        $checks[] = $this->check('collector.moodle', 'Moodle sintético y base de datos', $access, $access ? 'Identidad de laboratorio, Moodle 4.5, 1–3 cursos, usuarios y OAuth comprobados.' : 'No se pudo acreditar acceso a la instancia sintética pequeña y su base de datos.');
        $root = (string) config('toolkit.workspaces.root');
        $volume = $root;
        while (! is_dir($volume) && dirname($volume) !== $volume) {
            $volume = dirname($volume);
        }
        $space = @disk_free_space($volume);
        $quota = (int) ceil($options['capacity_bytes'] * (100 + $options['safety_margin_percent']) / 100);
        $capacity = ! is_link($root) && is_writable($volume) && is_float($space) && $space >= $quota;
        $checks[] = $this->check('collector.capacity', 'Capacidad y salida del workspace', $capacity, $capacity ? 'La cuota estimada con su margen cabe en el volumen de ejecución.' : 'No hay espacio o permisos suficientes para la cuota aprobada.');
        $checks[] = $this->check('collector.adapter', 'Integración de laboratorio', class_exists(CollectorAdapter::class), 'La integración debe estar instalada antes de confirmar una recolección real.');
        $checks[] = ['id' => 'collector.laboratory', 'description' => 'Uso exclusivo de laboratorio', 'result' => 'WARNING', 'detail' => 'Acepta ejecutar sobre datos sintéticos y crear un paquete para validación experimental.'];

        return $checks;
    }

    /** @return list<string> */
    public function configurationErrors(Project $project, ProjectConfiguration $configuration): array
    {
        if ($project->type !== ProjectType::COLLECT || ! config('toolkit.features.recolector_742.enabled') || ! config('toolkit.features.local_runner.enabled')) {
            return ['COLLECT LAB requiere ambas feature flags explícitas.'];
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
        $distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->first();
        $profileHash = null;
        try {
            if (is_array($options) && is_string($options['profile_id'] ?? null)) {
                $profileHash = $this->profiles->fingerprint($options['profile_id']);
            }
        } catch (Throwable) {
        }

        return $this->configurations->hash([
            'schema_version' => 'collector-preflight.v1', 'project_uuid' => $project->uuid,
            'configuration_version' => $configuration->version, 'options_sha256' => $this->configurations->hash(is_array($options) ? $options : []),
            'profile_sha256' => $profileHash, 'distribution_sha256' => $distribution?->distribution_sha256,
            'manifest_sha256' => $distribution?->manifest_sha256, 'workspace_policy_sha256' => hash('sha256', (string) json_encode(config('toolkit.workspaces'))),
            'runtime_policy_sha256' => hash('sha256', (string) json_encode([
                'binary' => config('collector.php_binary'), 'ini' => config('collector.php_ini'),
                'scan_dir' => config('collector.php_scan_dir'), 'target_host' => config('toolkit.runner.host_id'),
                'reference_scope' => config('collector.secret_root'),
            ])),
        ]);
    }

    /** @return array{id: string, description: string, result: string, detail: string} */
    private function check(string $id, string $description, bool $success, string $detail): array
    {
        return ['id' => $id, 'description' => $description, 'result' => $success ? 'SUCCESS' : 'ERROR', 'detail' => $detail];
    }
}
