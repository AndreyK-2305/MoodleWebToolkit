<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $execution_event_id
 * @property int $delivery_attempts
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $published_at
 * @property string|null $last_failure_code
 * @property CarbonImmutable $created_at
 * @property-read ExecutionEvent $event
 */
class ExecutionEventOutbox extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'execution_event_outbox';

    protected $fillable = ['execution_event_id', 'delivery_attempts', 'available_at', 'published_at', 'last_failure_code', 'created_at'];

    /** @return BelongsTo<ExecutionEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ExecutionEvent::class, 'execution_event_id');
    }

    protected function casts(): array
    {
        return ['delivery_attempts' => 'integer', 'available_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }
}
