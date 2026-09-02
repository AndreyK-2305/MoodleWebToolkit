<?php

namespace App\Models;

use App\Enums\ServerRole;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $uuid
 * @property string $name
 * @property ServerRole $role
 * @property string|null $host
 * @property int|null $port
 * @property array<string, mixed>|null $metadata
 */
class Server extends Model
{
    use HasPublicUuid;

    protected $fillable = ['project_id', 'uuid', 'name', 'role', 'host', 'port', 'metadata'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<Connection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    /** @return HasMany<MoodleInstance, $this> */
    public function moodleInstances(): HasMany
    {
        return $this->hasMany(MoodleInstance::class);
    }

    protected function casts(): array
    {
        return [
            'role' => ServerRole::class,
            'metadata' => 'array',
        ];
    }
}
