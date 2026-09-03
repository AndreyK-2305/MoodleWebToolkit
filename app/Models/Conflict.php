<?php

namespace App\Models;

use App\Enums\ConflictStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $execution_id
 * @property int|null $execution_step_id
 * @property string $key
 * @property string $type
 * @property ConflictStatus $status
 * @property int $version
 * @property array<string, mixed> $details
 * @property array<string, mixed>|null $resolution
 * @property CarbonImmutable|null $resolved_at
 */
class Conflict extends Model
{
    protected $fillable = [
        'execution_id',
        'execution_step_id',
        'key',
        'type',
        'status',
        'version',
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
            'version' => 'integer',
            'details' => 'array',
            'resolution' => 'array',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
