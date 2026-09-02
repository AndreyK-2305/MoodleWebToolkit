<?php

namespace App\Models;

use App\Enums\EventSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
