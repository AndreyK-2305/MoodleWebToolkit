<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $execution_id
 * @property int $estimate_bytes
 * @property int $available_bytes_observed
 * @property int $approved_quota_bytes
 * @property int $margin_percent
 * @property int $approved_by
 * @property CarbonImmutable $approved_at
 * @property string $fingerprint
 * @property array<string, mixed>|null $evidence
 */
class ExecutionCapacityApproval extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'execution_id', 'estimate_bytes', 'available_bytes_observed', 'approved_quota_bytes',
        'margin_percent', 'approved_by', 'approved_at', 'fingerprint', 'evidence',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('La aprobación de capacidad es inmutable.'));
        static::deleting(fn (): never => throw new LogicException('La aprobación de capacidad es inmutable.'));
    }

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected function casts(): array
    {
        return [
            'estimate_bytes' => 'integer',
            'available_bytes_observed' => 'integer',
            'approved_quota_bytes' => 'integer',
            'margin_percent' => 'integer',
            'approved_at' => 'immutable_datetime',
            'evidence' => 'array',
        ];
    }
}
