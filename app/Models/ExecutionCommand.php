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
        'payload_hash',
        'payload',
        'created_by',
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
            'processed_at' => 'immutable_datetime',
        ];
    }
}
