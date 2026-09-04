<?php

namespace App\Models;

use App\Enums\LogStream;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property LogStream $stream
 * @property string $level
 * @property string $message
 * @property array<string, mixed>|null $context
 * @property CarbonImmutable|null $logged_at
 */
class ExecutionLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'execution_id',
        'execution_step_id',
        'stream',
        'level',
        'message',
        'context',
        'logged_at',
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

    protected function casts(): array
    {
        return [
            'stream' => LogStream::class,
            'context' => 'array',
            'logged_at' => 'immutable_datetime',
        ];
    }
}
