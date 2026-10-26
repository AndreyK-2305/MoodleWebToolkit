<?php

namespace App\Models;

use App\Enums\NotificationGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One notifiable moment of an Execution, written with its event and unique per
 * (execution, dedupe_key). Only operator-facing text is kept: no personal data,
 * secrets or server paths.
 *
 * @property int $id
 * @property int $execution_id
 * @property int|null $execution_event_id
 * @property string $dedupe_key
 * @property NotificationGroup $group
 * @property string $kind
 * @property string $title
 * @property string $line
 * @property int $recipients
 * @property string|null $outcome
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable $created_at
 * @property-read Execution $execution
 */
class ExecutionNotification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['execution_id', 'execution_event_id', 'dedupe_key', 'group', 'kind', 'title', 'line',
        'recipients', 'outcome', 'dispatched_at', 'created_at'];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    protected function casts(): array
    {
        return [
            'group' => NotificationGroup::class,
            'recipients' => 'integer',
            'dispatched_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
