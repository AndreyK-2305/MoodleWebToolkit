<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int $version
 * @property array<string, mixed>|null $settings
 */
class ProjectConfiguration extends Model
{
    protected $fillable = ['project_id', 'version', 'settings'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
