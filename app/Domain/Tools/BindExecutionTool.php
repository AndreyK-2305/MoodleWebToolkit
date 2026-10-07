<?php

namespace App\Domain\Tools;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Execution;
use App\Models\ExecutionCapacityApproval;
use App\Models\ExecutionRuntimeConfiguration;
use App\Models\ExecutionToolBinding;
use App\Models\SourcePackage;
use App\Models\ToolCapability;
use App\Models\ToolCompatibility;
use App\Models\ToolDistribution;
use App\Models\ToolVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BindExecutionTool
{
    public function __construct(
        private readonly ToolDistributionVerifier $verifier,
        private readonly ToolOperationGate $gate,
        private readonly SensitiveValueRedactor $redactor,
    ) {}

    /** @param list<int> $sourcePackageIds @param array<string, mixed> $configuration */
    public function bind(
        Execution $execution,
        ToolVersion $version,
        ToolDistribution $distribution,
        string $workflowKey,
        string $adapterKey,
        string $providerKey,
        array $configuration,
        array $sourcePackageIds = [],
    ): ExecutionToolBinding {
        if ((int) $distribution->tool_version_id !== (int) $version->getKey()) {
            throw new ToolOperationBlocked('La distribución no pertenece a la versión de herramienta seleccionada.');
        }

        $this->gate->assertRunnable($version, $workflowKey);
        $verified = $this->verifier->verify($distribution);
        $normalizedSourcePackageIds = array_map('intval', $sourcePackageIds);
        if (count(array_unique($normalizedSourcePackageIds)) !== count($normalizedSourcePackageIds)) {
            throw new ToolOperationBlocked('Un paquete fuente no puede aparecer dos veces en el binding.');
        }
        $sourcePackageIds = array_values($normalizedSourcePackageIds);
        sort($sourcePackageIds, SORT_NUMERIC);

        $safeConfiguration = $this->sanitizeConfiguration($configuration);
        $configurationHash = hash('sha256', json_encode($this->canonicalize($safeConfiguration), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use (
            $execution,
            $version,
            $distribution,
            $workflowKey,
            $adapterKey,
            $providerKey,
            $configurationHash,
            $safeConfiguration,
            $sourcePackageIds,
            $verified,
        ): ExecutionToolBinding {
            $locked = Execution::query()->lockForUpdate()->findOrFail($execution->getKey());
            $lockedVersion = ToolVersion::query()->lockForUpdate()->findOrFail($version->getKey());
            $lockedVersion->loadMissing('tool');
            $lockedDistribution = ToolDistribution::query()->lockForUpdate()->findOrFail($distribution->getKey());
            $compatibility = ToolCompatibility::query()
                ->where('tool_version_id', $lockedVersion->getKey())
                ->where('workflow_key', $workflowKey)
                ->lockForUpdate()
                ->first();
            if ($compatibility === null) {
                throw new ToolOperationBlocked('La regla de compatibilidad desapareció durante la creación del binding.');
            }
            $this->gate->assertRunnable($lockedVersion, $workflowKey);
            if ((int) $lockedDistribution->tool_version_id !== (int) $lockedVersion->getKey()
                || $lockedDistribution->verification_state !== 'VERIFIED'
                || hash_equals((string) $lockedVersion->tree_sha256, (string) $lockedDistribution->distribution_sha256) === false
                || hash_equals((string) $lockedDistribution->distribution_sha256, $verified->treeSha256) === false
            ) {
                throw new ToolOperationBlocked('El catálogo cambió durante la creación del binding; vuelve a verificar la distribución.');
            }

            $capacity = ExecutionCapacityApproval::query()->where('execution_id', $locked->getKey())->lockForUpdate()->first();
            if ($capacity === null) {
                throw new ToolOperationBlocked('La ejecución requiere una estimación y aprobación de capacidad antes de fijar herramientas.');
            }
            $packages = SourcePackage::query()->whereIn('id', $sourcePackageIds)->lockForUpdate()->get()->keyBy('id');
            if ($packages->count() !== count($sourcePackageIds)) {
                throw new ToolOperationBlocked('Uno o más paquetes fuente no existen.');
            }
            $sourceHashes = [];
            foreach ($sourcePackageIds as $sourcePackageId) {
                /** @var SourcePackage $package */
                $package = $packages->get($sourcePackageId);
                $package->loadMissing('artifact');
                if ((int) $package->project_id !== (int) $locked->project_id
                    || $package->validation_state !== 'VALID'
                    || $package->availability !== 'AVAILABLE'
                    || $package->revoked_at !== null
                    || $package->artifact?->category !== 'SOURCE_PACKAGE'
                    || hash_equals($package->sha256, (string) $package->artifact?->sha256) === false
                    || in_array($workflowKey, $package->compatibility['workflows'] ?? [], true) === false
                ) {
                    throw new ToolOperationBlocked('El paquete fuente no pertenece al proyecto, no está vigente o no es compatible con el flujo.');
                }
                $artifactPath = Storage::disk($package->artifact->disk)->path($package->artifact->path);
                $currentHash = is_file($artifactPath) && is_link($artifactPath) === false ? hash_file('sha256', $artifactPath) : false;
                $currentSize = is_file($artifactPath) && is_link($artifactPath) === false ? filesize($artifactPath) : false;
                if (is_string($currentHash) === false || hash_equals($package->sha256, $currentHash) === false || $currentSize !== $package->size_bytes) {
                    throw new ToolOperationBlocked('El paquete fuente cambió después de su validación.');
                }
                $sourceHashes[(string) $sourcePackageId] = $package->sha256;
            }
            ksort($sourceHashes, SORT_NUMERIC);

            $runtimeConfiguration = null;
            if ($lockedVersion->tool->key === 'moodle-consolidador') {
                $runtimeConfiguration = ExecutionRuntimeConfiguration::query()
                    ->where('execution_id', $locked->getKey())
                    ->where('tool_version_id', $lockedVersion->getKey())
                    ->where('approval_state', 'APPROVED')
                    ->lockForUpdate()
                    ->first();
                if ($runtimeConfiguration === null) {
                    throw new ToolOperationBlocked('V8 requiere configuración runtime generada y aprobada para esta ejecución.');
                }
            }

            $capabilities = ToolCapability::query()
                ->where('tool_version_id', $lockedVersion->getKey())
                ->orderBy('key')
                ->lockForUpdate()
                ->get(['key', 'support_state', 'evidence_level', 'source_path'])
                ->mapWithKeys(fn ($capability): array => [$capability->key => [
                    'support' => $capability->support_state,
                    'evidence' => $capability->evidence_level,
                    'source' => $capability->source_path,
                ]])->all();
            $existing = $locked->toolBinding()->first();

            $snapshot = [
                'project_id' => $locked->project_id,
                'tool_version_id' => $version->getKey(),
                'tool_distribution_id' => $distribution->getKey(),
                'workflow_key' => $workflowKey,
                'adapter_key' => $adapterKey,
                'provider_key' => $providerKey,
                'distribution_sha256' => $verified->treeSha256,
                'configuration_sha256' => $configurationHash,
                'capabilities_snapshot' => $capabilities,
                'input_artifact_ids' => [],
                'source_package_ids' => $sourcePackageIds,
                'source_package_hashes' => $sourceHashes,
                'capacity_approval_id' => $capacity->getKey(),
                'approved_quota_bytes' => $capacity->approved_quota_bytes,
                'runtime_configuration_id' => $runtimeConfiguration?->getKey(),
                'configuration_snapshot' => $safeConfiguration,
            ];

            if ($existing !== null) {
                $same = (int) $existing->tool_version_id === (int) $version->getKey()
                    && (int) $existing->tool_distribution_id === (int) $distribution->getKey()
                    && $existing->workflow_key === $workflowKey
                    && $existing->adapter_key === $adapterKey
                    && $existing->provider_key === $providerKey
                    && hash_equals($existing->configuration_sha256, $configurationHash)
                    && $existing->distribution_sha256 === $verified->treeSha256
                    && $existing->capabilities_snapshot === $capabilities
                    && $existing->input_artifact_ids === []
                    && $existing->source_package_ids === $sourcePackageIds
                    && $existing->source_package_hashes === $sourceHashes
                    && (int) $existing->capacity_approval_id === (int) $capacity->getKey()
                    && $existing->runtime_configuration_id === $runtimeConfiguration?->getKey();

                if ($same === false) {
                    throw new ToolOperationBlocked('La ejecución ya está fijada a otra combinación inmutable de herramienta.');
                }

                return $existing;
            }

            $binding = $locked->toolBinding()->create($snapshot);
            foreach ($sourcePackageIds as $sourcePackageId) {
                DB::table('execution_tool_binding_sources')->insert([
                    'execution_tool_binding_id' => $binding->getKey(),
                    'source_package_id' => $sourcePackageId,
                    'project_id' => $locked->project_id,
                    'package_sha256' => $sourceHashes[(string) $sourcePackageId],
                ]);
            }

            return $binding;
        });
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value) === false) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    private function sanitizeConfiguration(array $configuration): array
    {
        $safe = [];
        foreach ($configuration as $key => $value) {
            if ($this->redactor->isSensitiveKeyName((string) $key)) {
                if (is_array($value) === false || array_diff(array_keys($value), ['secret_ref', 'version']) !== []
                    || is_string($value['secret_ref'] ?? null) === false || is_string($value['version'] ?? null) === false
                    || $value['secret_ref'] === '' || $value['version'] === ''
                    || $this->redactor->redactString($value['secret_ref']) !== $value['secret_ref']
                    || $this->redactor->redactString($value['version']) !== $value['version']
                ) {
                    throw new ToolOperationBlocked('No existe un almacén de secretos aprobado; se bloqueó la configuración con un secreto en claro.');
                }
                $safe[$key] = ['secret_ref' => $value['secret_ref'], 'version' => $value['version']];
                continue;
            }
            if (is_string($value) && $this->redactor->redactString($value) !== $value) {
                throw new ToolOperationBlocked('La configuración contiene material sensible dentro de un valor de texto.');
            }
            $safe[$key] = is_array($value) ? $this->sanitizeConfiguration($value) : $value;
        }

        return $safe;
    }
}
