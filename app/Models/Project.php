<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Exceptions\InvalidStateTransition;
use App\Exceptions\ProjectIsReadOnly;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $uuid
 * @property ProjectType $type
 * @property ProjectStatus $status
 * @property int $created_by
 */
class Project extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'uuid',
        'name',
        'type',
        'status',
        'description',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $project): void {
            $original = ProjectStatus::from((string) $project->getRawOriginal('status'));

            if ($original->isTerminal()) {
                throw new ProjectIsReadOnly;
            }

            if ($project->isDirty('status') && ! $original->canTransitionTo($project->status)) {
                throw InvalidStateTransition::between('Project', $original->value, $project->status->value);
            }
        });

        static::deleting(function (self $project): void {
            if ($project->status->isTerminal()) {
                throw new ProjectIsReadOnly;
            }
        });
    }

    /** @param Builder<Project> $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $query->whereHas('assignments', fn (Builder $assignmentQuery) => $assignmentQuery->where('user_id', $user->getKey()));
    }

    public function transitionTo(ProjectStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw InvalidStateTransition::between('Project', $this->status->value, $target->value);
        }

        $this->status = $target;
        $this->save();
    }

    public function isReadOnly(): bool
    {
        return $this->status->isTerminal();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasOne<ProjectConfiguration, $this> */
    public function configuration(): HasOne
    {
        return $this->hasOne(ProjectConfiguration::class);
    }

    /** @return HasMany<ProjectAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectAssignment::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_assignments')->withPivot(['id', 'assigned_by'])->withTimestamps();
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /** @return HasMany<MoodleInstance, $this> */
    public function moodleInstances(): HasMany
    {
        return $this->hasMany(MoodleInstance::class);
    }

    /** @return HasMany<Execution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    protected function casts(): array
    {
        return [
            'type' => ProjectType::class,
            'status' => ProjectStatus::class,
        ];
    }
}
