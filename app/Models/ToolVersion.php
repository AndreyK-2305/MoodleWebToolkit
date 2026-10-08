<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tool_id
 * @property string $version
 * @property string|null $archive_name
 * @property string|null $archive_sha256
 * @property string|null $tree_sha256
 * @property bool $enabled
 * @property-read Tool $tool
 */
class ToolVersion extends Model
{
    protected $fillable = [
        'tool_id',
        'version',
        'archive_name',
        'archive_sha256',
        'tree_sha256',
        'enabled',
    ];

    /** @return BelongsTo<Tool, $this> */
    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    /** @return HasMany<ToolDistribution, $this> */
    public function distributions(): HasMany
    {
        return $this->hasMany(ToolDistribution::class);
    }

    /** @return HasMany<ToolCapability, $this> */
    public function capabilities(): HasMany
    {
        return $this->hasMany(ToolCapability::class);
    }

    /** @return HasMany<ToolCompatibility, $this> */
    public function compatibilities(): HasMany
    {
        return $this->hasMany(ToolCompatibility::class);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
