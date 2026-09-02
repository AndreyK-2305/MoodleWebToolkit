<?php

namespace App\Models;

use App\Enums\ConflictStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conflict extends Model
{
    protected $fillable = [
        'execution_id',
        'execution_step_id',
        'key',
        'type',
        'status',
        'details',
        'resolution',
        'resolved_by',
        'resolved_at',
    ];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return BelongsTo<ExecutionStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(ExecutionStep::class, 'execution_step_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    protected function casts(): array
    {
        return [
            'status' => ConflictStatus::class,
            'details' => 'array',
            'resolution' => 'array',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
