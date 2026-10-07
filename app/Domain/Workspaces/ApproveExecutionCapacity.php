<?php

namespace App\Domain\Workspaces;

use App\Exceptions\ToolOperationBlocked;
use App\Models\Execution;
use App\Models\ExecutionCapacityApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ApproveExecutionCapacity
{
    public function approve(Execution $execution, int $estimateBytes, int $marginPercent, User $actor): ExecutionCapacityApproval
    {
        if ($estimateBytes < 1 || $marginPercent < 0 || $marginPercent > 500) {
            throw new ToolOperationBlocked('La estimación y el margen de capacidad no son válidos.');
        }

        $root = rtrim((string) config('toolkit.workspaces.root'), DIRECTORY_SEPARATOR);
        $volumePath = $root;
        while (! is_dir($volumePath) && dirname($volumePath) !== $volumePath) {
            $volumePath = dirname($volumePath);
        }
        $available = is_dir($volumePath) ? @disk_free_space($volumePath) : false;
        if (! is_float($available) && ! is_int($available)) {
            throw new ToolOperationBlocked('No se pudo observar el espacio disponible del volumen de workspaces.');
        }

        $quota = (int) ceil($estimateBytes * (100 + $marginPercent) / 100);
        if ($quota < 1 || $quota > $available) {
            throw new ToolOperationBlocked('La capacidad disponible observada no cubre la estimación más su margen aprobado.');
        }

        $evidence = [
            'schema_version' => 'capacity-approval.v1',
            'execution_uuid' => $execution->uuid,
            'estimate_bytes' => $estimateBytes,
            'available_bytes_observed' => (int) $available,
            'approved_quota_bytes' => $quota,
            'margin_percent' => $marginPercent,
            'approved_by' => $actor->getKey(),
            'approved_at' => now()->utc()->toIso8601String(),
        ];
        $fingerprint = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($execution, $estimateBytes, $marginPercent, $actor, $available, $quota, $evidence, $fingerprint): ExecutionCapacityApproval {
            $existing = ExecutionCapacityApproval::query()->where('execution_id', $execution->getKey())->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->estimate_bytes === $estimateBytes
                    && (int) $existing->margin_percent === $marginPercent
                    && (int) $existing->approved_by === (int) $actor->getKey()
                ) {
                    return $existing;
                }
                throw new ToolOperationBlocked('La aprobación de capacidad de esta ejecución ya es inmutable.');
            }

            return ExecutionCapacityApproval::query()->create([
                'execution_id' => $execution->getKey(),
                'estimate_bytes' => $estimateBytes,
                'available_bytes_observed' => (int) $available,
                'approved_quota_bytes' => $quota,
                'margin_percent' => $marginPercent,
                'approved_by' => $actor->getKey(),
                'approved_at' => now()->utc(),
                'fingerprint' => $fingerprint,
                'evidence' => $evidence,
            ]);
        });
    }
}
