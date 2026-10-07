<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tool_version_id
 * @property string $key
 * @property string $kind
 * @property string $source_path
 * @property string|null $manifest_name
 * @property string|null $manifest_sha256
 * @property string $distribution_sha256
 * @property int $file_count
 * @property string $verification_state
 * @property \Carbon\CarbonImmutable|null $verified_at
 * @property list<string>|null $mutable_paths
 * @property list<string>|null $deployment_exclusions
 * @property array<string, mixed>|null $evidence
 * @property-read ToolVersion $toolVersion
 */
class ToolDistribution extends Model
{
    protected $fillable = [
        'tool_version_id', 'key', 'kind', 'source_path', 'manifest_name', 'manifest_sha256',
        'distribution_sha256', 'file_count', 'verification_state', 'verified_at', 'mutable_paths', 'deployment_exclusions', 'evidence',
    ];

    /** @return BelongsTo<ToolVersion, $this> */
    public function toolVersion(): BelongsTo
    {
        return $this->belongsTo(ToolVersion::class);
    }

    /** @return HasMany<ExecutionToolBinding, $this> */
    public function executionBindings(): HasMany
    {
        return $this->hasMany(ExecutionToolBinding::class);
    }

    protected function casts(): array
    {
        return ['file_count' => 'integer', 'verified_at' => 'immutable_datetime', 'mutable_paths' => 'array', 'deployment_exclusions' => 'array', 'evidence' => 'array'];
    }
}
