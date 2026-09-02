<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use App\Exceptions\InvalidStateTransition;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $project_id
 * @property string $uuid
 * @property int $attempt
 * @property ExecutionStatus $status
 * @property int|null $progress
 * @property int $last_event_sequence
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
class Execution extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'project_id',
        'uuid',
        'attempt',
        'status',
        'progress',
        'last_event_sequence',
        'created_by',
        'resumed_from_execution_id',
        'resume_checkpoint_id',
        'cancel_requested_at',
        'started_at',
        'finished_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $execution): void {
            if ($execution->isDirty(['project_id', 'uuid', 'attempt'])) {
                throw new LogicException('La identidad de una ejecución es inmutable.');
            }

            $hadResumeLineage = $execution->getRawOriginal('resumed_from_execution_id') !== null;

            if ($hadResumeLineage && $execution->isDirty(['resumed_from_execution_id', 'resume_checkpoint_id'])) {
                throw new LogicException('El linaje de reanudación es inmutable una vez asignado.');
            }

            if (! $execution->isDirty('status')) {
                return;
            }

            $original = ExecutionStatus::from((string) $execution->getRawOriginal('status'));

            if (! $original->canTransitionTo($execution->status)) {
                throw InvalidStateTransition::between('Execution', $original->value, $execution->status->value);
            }
        });
    }

    public function transitionTo(ExecutionStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw InvalidStateTransition::between('Execution', $this->status->value, $target->value);
        }

        $this->status = $target;
        $this->save();
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Execution, $this> */
    public function resumedFromExecution(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resumed_from_execution_id');
    }

    /** @return BelongsTo<Checkpoint, $this> */
    public function resumeCheckpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class, 'resume_checkpoint_id');
    }

    /** @return HasMany<Execution, $this> */
    public function resumedExecutions(): HasMany
    {
        return $this->hasMany(self::class, 'resumed_from_execution_id');
    }

    /** @return HasMany<ExecutionCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(ExecutionCommand::class);
    }

    /** @return HasMany<ExecutionStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ExecutionStep::class)->orderBy('position');
    }

    /** @return HasMany<ExecutionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ExecutionEvent::class)->orderBy('sequence');
    }

    /** @return HasMany<ExecutionLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(ExecutionLog::class);
    }

    /** @return HasMany<Checkpoint, $this> */
    public function checkpoints(): HasMany
    {
        return $this->hasMany(Checkpoint::class);
    }

    /** @return HasMany<Conflict, $this> */
    public function conflicts(): HasMany
    {
        return $this->hasMany(Conflict::class);
    }

    /** @return HasMany<Verification, $this> */
    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class);
    }

    /** @return HasMany<Artifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ExecutionStatus::class,
            'progress' => 'integer',
            'last_event_sequence' => 'integer',
            'cancel_requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
