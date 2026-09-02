<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Exceptions\InvalidProjectAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectAssignment extends Model
{
    protected $fillable = ['project_id', 'user_id', 'assigned_by'];

    protected static function booted(): void
    {
        static::saving(function (self $assignment): void {
            $user = User::query()->find($assignment->user_id);

            if ($user?->role === UserRole::ADMIN) {
                throw new InvalidProjectAssignment;
            }
        });
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
