<?php

namespace App\Models;

use App\Enums\MoodleInstanceRole;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoodleInstance extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'project_id',
        'server_id',
        'uuid',
        'name',
        'role',
        'base_url',
        'moodle_version',
        'database_name',
        'validated',
        'metadata',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    protected function casts(): array
    {
        return [
            'role' => MoodleInstanceRole::class,
            'validated' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
