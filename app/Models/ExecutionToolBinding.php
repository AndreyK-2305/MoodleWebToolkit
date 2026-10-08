<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $execution_id
 * @property int $project_id
 * @property int $tool_version_id
 * @property int $tool_distribution_id
 * @property string $workflow_key
 * @property string $adapter_key
 * @property string $provider_key
 * @property string $distribution_sha256
 * @property string $configuration_sha256
 * @property array<string, mixed> $capabilities_snapshot
 * @property list<int>|null $input_artifact_ids
 * @property array<string, mixed>|null $configuration_snapshot
 * @property list<int>|null $source_package_ids
 * @property array<int, string>|null $source_package_hashes
 * @property int|null $capacity_approval_id
 * @property int|null $approved_quota_bytes
 * @property int|null $runtime_configuration_id
 * @property-read ToolVersion $toolVersion
 * @property-read ToolDistribution $distribution
 */
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
