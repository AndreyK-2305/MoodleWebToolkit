<?php

namespace App\Models;

use App\Enums\NotificationGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's email choices. Without a row the defaults apply: interventions,
 * endings and failures, and backups on; the progress summary off.
 *
 * @property int $id
 * @property int $user_id
 * @property bool $receive_email
 * @property bool $interventions
 * @property bool $outcomes
 * @property bool $backups
 * @property bool $progress
 */
class UserNotificationPreference extends Model
{
    public const DEFAULTS = ['receive_email' => true, 'interventions' => true, 'outcomes' => true, 'backups' => true, 'progress' => false];

    protected $fillable = ['user_id', 'receive_email', 'interventions', 'outcomes', 'backups', 'progress'];

    /** @return array{receive_email: bool, interventions: bool, outcomes: bool, backups: bool, progress: bool} */
    public static function for(User $user): array
    {
        $row = self::query()->where('user_id', $user->getKey())->first();

        return $row === null ? self::DEFAULTS : [
            'receive_email' => $row->receive_email, 'interventions' => $row->interventions,
            'outcomes' => $row->outcomes, 'backups' => $row->backups, 'progress' => $row->progress,
        ];
    }

    public static function defaultFor(NotificationGroup $group): bool
    {
        return self::DEFAULTS[$group->preference()];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['receive_email' => 'boolean', 'interventions' => 'boolean', 'outcomes' => 'boolean', 'backups' => 'boolean', 'progress' => 'boolean'];
    }
}
