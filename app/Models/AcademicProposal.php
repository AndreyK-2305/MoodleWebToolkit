<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $version
 * @property string $operation
 * @property string $node_id
 * @property string $node_type
 * @property array<string, mixed> $old_value
 * @property array<string, mixed> $new_value
 * @property string $base_fingerprint
 * @property string $resulting_fingerprint
 * @property string $status
 */
class AcademicProposal extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'execution_id', 'version', 'operation', 'node_id', 'node_type', 'old_value', 'new_value',
        'base_fingerprint', 'resulting_fingerprint', 'status', 'proposed_by',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Las propuestas académicas son append-only.'));
        static::deleting(fn (): never => throw new LogicException('Las propuestas académicas son append-only.'));
    }

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'old_value' => 'array',
            'new_value' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
