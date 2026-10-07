<?php

namespace Database\Seeders;

use App\Domain\Tools\ToolDistributionVerifier;
use App\Enums\ToolCompatibilityStatus;
use App\Models\Tool;
use App\Models\ToolCapability;
use App\Models\ToolCompatibility;
use App\Models\ToolDistribution;
use App\Models\ToolVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ToolCatalogSeeder extends Seeder
{
    /** @var list<array<string, mixed>> */
    private const CATALOG = [
        [
            'tool' => ['key' => 'moodle-recolector', 'name' => 'Recolector Moodle', 'description' => 'Exportación de solo lectura para Moodle 4.5.x.'],
            'version' => '7.4.2-linux',
            'source_path' => 'BaseLine/Recolector/Recolector-v7.4.2',
            'tree_sha256' => '4daaa16d278991f098b7f9c85361f7800193c4825e1f8cb6cb2c4b2870103c2e',
            'manifest_sha256' => '55cc3bf9bbe8964bd3459f703e8a09c1a97e7d4f8867df3465cbfdd95ff00672',
            'file_count' => 29,
            'mutable_paths' => [],
            'compatibility' => ['moodle.source.export' => [ToolCompatibilityStatus::LABORATORY, 'recolector_742.enabled', 'Requiere integración y pruebas de la Iteración 3.']],
            'capabilities' => [
                ['source.export.background', 'SUPPORTED', 'DOC', 'README.md', 'El modo background depende de systemd y permisos del host.'],
                ['themes.inventory', 'SUPPORTED', 'CODE', 'scripts/source-export.php', 'Produce inventario de themes con perfiles redactados.'],
                ['checkpoints.resume', 'SUPPORTED', 'CODE', 'scripts', 'Mantiene checkpoints propios del recolector.'],
                ['execution.cancel', 'UNKNOWN', 'DOC', 'README.md', 'No se observó una cancelación granular confirmada.'],
            ],
        ],
        [
            'tool' => ['key' => 'moodle-consolidador', 'name' => 'Consolidador Moodle', 'description' => 'Consolidación académica Moodle 5.2.1 sobre Linux.'],
            'version' => '8.0.0-linux-rc12',
            'source_path' => 'BaseLine/Consolidador/Consolidador-v8.0.0',
            'tree_sha256' => '74d976c5290724ff52452d6abe117aa34876b1daab36d624c5e7adc353ab3ab7',
            'manifest_sha256' => '5f330a916e7ad29f217516f452cdac3e1230c664ea218102aeae2f50060c582f',
            'file_count' => 263,
            'mutable_paths' => ['config/phase5-pilot-package.json', 'config/phase6-batch.json'],
            'deployment_exclusions' => [
                'config/assistant.json',
                'config/oauth2.json',
                'config/identity-policy.json',
                'config/theme-policy.json',
                'config/source-integrity-policies.csv',
                'config/phase5-pilot-package.json',
                'config/phase6-batch.json',
                'config/phase6-degradation-resolutions.csv',
                'config/phase6-role-resolutions.csv',
                'config/identity_resolutions.csv',
                'config/fuzzy_identity_resolutions.csv',
                'config/plugin-compatibility-catalog.json',
                'FILES.sha256',
            ],
            'compatibility' => ['moodle.consolidation.v8' => [ToolCompatibilityStatus::BLOCKED, 'consolidador_800.enabled', 'Se habilitará después de integrar y validar el flujo en la Iteración 4.']],
            'capabilities' => [
                ['themes.global_selection', 'SUPPORTED', 'DOC', 'README.md', 'Selecciona theme global explícitamente y conserva tema por curso cuando es transportable.'],
                ['plugins.catalog_lock', 'SUPPORTED', 'DOC', 'README.md', 'Resuelve plugins desde catálogo, lock, staging y pins.'],
                ['course.worker.manual_wait', 'SUPPORTED', 'CODE', 'course-worker-states', 'WAITING_MANUAL pertenece al worker y no necesariamente detiene la operación global.'],
                ['runtime.cancel', 'UNKNOWN', 'DOC', 'DETENER.sh', 'El script detiene el runtime completo; no equivale a cancelar un curso.'],
            ],
        ],
        [
            'tool' => ['key' => 'moodle-integrador-incremental', 'name' => 'Integrador Incremental Moodle', 'description' => 'Integrador histórico retenido por compatibilidad.'],
            'version' => '1.1.5-linux',
            'source_path' => 'BaseLine/Integrador/Integrador-Incremental-Moodle-v1.1.5-linux',
            'tree_sha256' => '0e1f3c40167a66c272774ccb366438593f492f47e96c80e93e72c5555ae7f6b3',
            'manifest_sha256' => '17b1d119abac93a82fc5e0612cd9d03b15d48f82a10dbf842ad5ae7fd61af4b7',
            'file_count' => 22,
            'mutable_paths' => [],
            'compatibility' => ['moodle.incremental.integration.v1' => [ToolCompatibilityStatus::INCOMPATIBLE, 'integrador_115.enabled', 'Requiere Consolidador 7.3.0 y Recolector 7.4.1; no acepta el flujo 7.4.2 → V8 RC12.']],
            'capabilities' => [
                ['flow.collector_742_to_consolidator_800', 'UNSUPPORTED', 'DOC', 'README.md', 'Las versiones requeridas por el Integrador son anteriores a las distribuciones definitivas.'],
            ],
        ],
    ];

    public function run(): void
    {
        $verifier = app(ToolDistributionVerifier::class);

        DB::transaction(function () use ($verifier): void {
            foreach (self::CATALOG as $entry) {
                $tool = Tool::query()->updateOrCreate(
                    ['key' => $entry['tool']['key']],
                    ['name' => $entry['tool']['name'], 'description' => $entry['tool']['description']],
                );

                /** @var ToolVersion $version */
                $version = $tool->versions()->updateOrCreate(
                    ['version' => $entry['version']],
                    [
                        'archive_name' => null,
                        'archive_sha256' => null,
                        'tree_sha256' => $entry['tree_sha256'],
                        'enabled' => false,
                    ],
                );

                $distribution = ToolDistribution::query()->updateOrCreate(
                    ['key' => $tool->key.'-'.$version->version.'-tree'],
                    [
                        'tool_version_id' => $version->getKey(),
                        'kind' => 'TREE',
                        'source_path' => $entry['source_path'],
                        'manifest_name' => 'FILES.sha256',
                        'manifest_sha256' => $entry['manifest_sha256'],
                        'distribution_sha256' => $entry['tree_sha256'],
                        'file_count' => $entry['file_count'],
                        'verification_state' => 'UNVERIFIED',
                        'verified_at' => null,
                        'mutable_paths' => $entry['mutable_paths'],
                        'deployment_exclusions' => $entry['deployment_exclusions'] ?? [],
                        'evidence' => null,
                    ],
                );

                try {
                    $verified = $verifier->verify($distribution);
                } catch (\Throwable $exception) {
                    throw new RuntimeException("No se registró {$tool->key} {$version->version}: la distribución falló la verificación.", previous: $exception);
                }

                $distribution->forceFill([
                    'verification_state' => 'VERIFIED',
                    'verified_at' => $verified->verifiedAt,
                    'evidence' => $verified->evidence(),
                ])->save();

                foreach ($entry['capabilities'] as [$key, $support, $level, $path, $details]) {
                    ToolCapability::query()->updateOrCreate(
                        ['tool_version_id' => $version->getKey(), 'key' => $key],
                        ['support_state' => $support, 'evidence_level' => $level, 'source_path' => $path, 'details' => $details],
                    );
                }

                foreach ($entry['compatibility'] as $workflow => [$status, $featureFlag, $reason]) {
                    ToolCompatibility::query()->updateOrCreate(
                        ['tool_version_id' => $version->getKey(), 'workflow_key' => $workflow],
                        ['status' => $status, 'feature_flag' => $featureFlag, 'reason' => $reason, 'requirements' => ['distribution_sha256' => $entry['tree_sha256']]],
                    );
                }
            }
        });
    }
}
