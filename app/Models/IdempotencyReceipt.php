<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class IdempotencyReceipt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'execution_id',
        'user_id',
        'action',
        'resource_type',
        'resource_id',
        'scope',
        'idempotency_key',
        'payload_hash',
        'result_type',
        'result_id',
        'response_status',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('El recibo de idempotencia es inmutable.'));
        static::deleting(fn (): never => throw new LogicException('El recibo de idempotencia es inmutable.'));
    }

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'resource_id' => 'integer',
            'result_id' => 'integer',
            'response_status' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
