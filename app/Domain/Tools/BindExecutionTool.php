<?php

namespace App\Domain\Tools;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Execution;
use App\Models\ExecutionToolBinding;
use App\Models\ToolCapability;
use App\Models\ToolCompatibility;
use App\Models\ToolDistribution;
use App\Models\ToolVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BindExecutionTool
{
    public function __construct(
        private readonly ToolDistributionVerifier $verifier,
        private readonly ToolOperationGate $gate,
        private readonly SensitiveValueRedactor $redactor,
    ) {}

    /** @param list<int> $inputArtifactIds @param array<string, mixed> $configuration */
    public function bind(
        Execution $execution,
        ToolVersion $version,
        ToolDistribution $distribution,
        string $workflowKey,
        string $adapterKey,
        string $providerKey,
        array $configuration,
        array $inputArtifactIds = [],
    ): ExecutionToolBinding {
        if ((int) $distribution->tool_version_id !== (int) $version->getKey()) {
            throw new ToolOperationBlocked('La distribución no pertenece a la versión de herramienta seleccionada.');
        }

        $this->gate->assertRunnable($version, $workflowKey);
        $verified = $this->verifier->verify($distribution);
        $inputArtifactIds = array_values(array_unique(array_map('intval', $inputArtifactIds)));
        sort($inputArtifactIds, SORT_NUMERIC);

        $configurationHash = hash('sha256', json_encode($this->canonicalize($configuration), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $safeConfiguration = $this->redactor->redact($configuration);

        return DB::transaction(function () use (
            $execution,
            $version,
            $distribution,
            $workflowKey,
            $adapterKey,
            $providerKey,
            $configurationHash,
            $safeConfiguration,
            $inputArtifactIds,
            $verified,
        ): ExecutionToolBinding {
            $locked = Execution::query()->lockForUpdate()->findOrFail($execution->getKey());
            $lockedVersion = ToolVersion::query()->lockForUpdate()->findOrFail($version->getKey());
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
                || ! hash_equals((string) $lockedVersion->tree_sha256, (string) $lockedDistribution->distribution_sha256)
                || ! hash_equals((string) $lockedDistribution->distribution_sha256, $verified->treeSha256)
            ) {
                throw new ToolOperationBlocked('El catálogo cambió durante la creación del binding; vuelve a verificar la distribución.');
            }

            if ($inputArtifactIds !== [] && $locked->artifacts()->whereIn('id', $inputArtifactIds)->lockForUpdate()->get(['id'])->count() !== count($inputArtifactIds)) {
                throw new ToolOperationBlocked('Uno o más paquetes de entrada ya no pertenecen a la ejecución.');
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
                'tool_version_id' => $version->getKey(),
                'tool_distribution_id' => $distribution->getKey(),
                'workflow_key' => $workflowKey,
                'adapter_key' => $adapterKey,
                'provider_key' => $providerKey,
                'distribution_sha256' => $verified->treeSha256,
                'configuration_sha256' => $configurationHash,
                'capabilities_snapshot' => $capabilities,
                'input_artifact_ids' => $inputArtifactIds,
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
                    && $existing->input_artifact_ids === $inputArtifactIds;

                if (! $same) {
                    throw new ToolOperationBlocked('La ejecución ya está fijada a otra combinación inmutable de herramienta.');
                }

                return $existing;
            }

            return $locked->toolBinding()->create($snapshot);
        });
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
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
}
