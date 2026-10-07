<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionToolBinding extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'execution_id', 'project_id', 'tool_version_id', 'tool_distribution_id', 'workflow_key', 'adapter_key', 'provider_key',
        'distribution_sha256', 'configuration_sha256', 'capabilities_snapshot', 'input_artifact_ids', 'configuration_snapshot',
        'source_package_ids', 'source_package_hashes', 'capacity_approval_id', 'approved_quota_bytes', 'runtime_configuration_id',
    ];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return BelongsTo<ToolVersion, $this> */
    public function toolVersion(): BelongsTo
    {
        return $this->belongsTo(ToolVersion::class);
    }

    /** @return BelongsTo<ToolDistribution, $this> */
    public function distribution(): BelongsTo
    {
        return $this->belongsTo(ToolDistribution::class, 'tool_distribution_id');
    }

    /** @return BelongsTo<ExecutionCapacityApproval, $this> */
    public function capacityApproval(): BelongsTo
    {
        return $this->belongsTo(ExecutionCapacityApproval::class);
    }

    /** @return BelongsTo<ExecutionRuntimeConfiguration, $this> */
    public function runtimeConfiguration(): BelongsTo
    {
        return $this->belongsTo(ExecutionRuntimeConfiguration::class);
    }

    protected function casts(): array
    {
        return [
            'capabilities_snapshot' => 'array', 'input_artifact_ids' => 'array', 'configuration_snapshot' => 'array',
            'source_package_ids' => 'array', 'source_package_hashes' => 'array', 'approved_quota_bytes' => 'integer',
        ];
    }
}
