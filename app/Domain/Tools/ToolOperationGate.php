<?php

namespace App\Domain\Tools;

use App\Enums\ToolCompatibilityStatus;
use App\Exceptions\ToolOperationBlocked;
use App\Models\ToolCompatibility;
use App\Models\ToolVersion;

class ToolOperationGate
{
    public function assertRunnable(ToolVersion $version, string $workflowKey): ToolCompatibility
    {
        $compatibility = $version->compatibilities()->where('workflow_key', $workflowKey)->first();

        if (! $compatibility instanceof ToolCompatibility) {
            throw new ToolOperationBlocked('No existe una regla de compatibilidad registrada para este flujo.');
        }

        if (! in_array($compatibility->status, [ToolCompatibilityStatus::AVAILABLE, ToolCompatibilityStatus::EXPERIMENTAL, ToolCompatibilityStatus::LABORATORY], true)) {
            throw new ToolOperationBlocked((string) ($compatibility->reason ?? 'La compatibilidad del flujo está bloqueada.'));
        }

        if (! $version->enabled || ! config('toolkit.features.'.$compatibility->feature_flag, false)) {
            throw new ToolOperationBlocked('La versión o su feature flag no está habilitada.');
        }

        $distribution = $version->distributions()->where('verification_state', 'VERIFIED')->first();

        if ($distribution === null || ! hash_equals((string) $version->tree_sha256, (string) $distribution->distribution_sha256)) {
            throw new ToolOperationBlocked('La distribución verificada no está disponible o no corresponde al hash fijado.');
        }

        return $compatibility;
    }
}
