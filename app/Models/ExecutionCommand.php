<?php

namespace App\Models;

use App\Enums\ExecutionCommandType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionCommand extends Model
{
    protected $fillable = [
        'execution_id',
        'step_key',
        'attempt',
        'command_type',
        'idempotency_key',
        'idempotency_scope',
        'payload_hash',
        'payload',
        'created_by',
        'dispatched_at',
        'processing_started_at',
        'dispatch_attempts',
        'processed_at',
    ];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'command_type' => ExecutionCommandType::class,
            'payload' => 'array',
            'dispatched_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'dispatch_attempts' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
