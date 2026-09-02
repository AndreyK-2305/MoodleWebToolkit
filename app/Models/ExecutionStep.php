<?php

namespace App\Models;

use App\Enums\ExecutionStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExecutionStep extends Model
{
    protected $fillable = [
        'execution_id',
        'step_key',
        'attempt',
        'name',
        'position',
        'status',
        'progress',
        'metadata',
        'started_at',
        'finished_at',
    ];

    public function hasValidatedCheckpoint(): bool
    {
        return $this->execution
            ->checkpoints()
            ->where('step_key', $this->step_key)
            ->where('validated', true)
            ->exists();
    }

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return HasMany<ExecutionLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(ExecutionLog::class);
    }

    /** @return HasMany<Conflict, $this> */
    public function conflicts(): HasMany
    {
        return $this->hasMany(Conflict::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ExecutionStepStatus::class,
            'progress' => 'integer',
            'metadata' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
