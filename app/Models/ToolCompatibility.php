<?php

namespace App\Models;

use App\Enums\ToolCompatibilityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tool_version_id
 * @property string $workflow_key
 * @property ToolCompatibilityStatus $status
 * @property string $feature_flag
 * @property string|null $reason
 * @property array<string, mixed>|null $requirements
 */
class ToolCompatibility extends Model
{
    protected $fillable = ['tool_version_id', 'workflow_key', 'status', 'feature_flag', 'reason', 'requirements'];

    /** @return BelongsTo<ToolVersion, $this> */
    public function toolVersion(): BelongsTo
    {
        return $this->belongsTo(ToolVersion::class);
    }

    protected function casts(): array
    {
        return ['status' => ToolCompatibilityStatus::class, 'requirements' => 'array'];
    }
}
