<?php

namespace App\Models;

use App\Enums\EventSeverity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $execution_id
 * @property int $sequence
 * @property string $type
 * @property string|null $step_key
 * @property EventSeverity $severity
 * @property int|null $progress
 * @property string|null $message
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable $created_at
 */
class ExecutionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'execution_id',
        'sequence',
        'type',
        'step_key',
        'severity',
        'progress',
        'message',
        'payload',
    ];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    protected function casts(): array
    {
        return [
            'severity' => EventSeverity::class,
            'progress' => 'integer',
            'payload' => 'array',
        ];
    }
}
