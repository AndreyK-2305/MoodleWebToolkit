<?php

namespace App\Models;

use App\Enums\NotificationGroup;
use Illuminate\Database\Eloquent\Model;

/**
 * An extra address that receives the notifications of every project, chosen by an
 * administrator (for example a worker who intervenes and has no account). The
 * address is stored lowercased, so it compares equal to a user's email.
 *
 * @property int $id
 * @property string $email
 * @property bool $interventions
 * @property bool $outcomes
 * @property bool $backups
 * @property bool $progress
 */
class NotificationRecipient extends Model
{
    public const MAX = 20;

    /** A new address hears about interventions and endings or failures. */
    public const DEFAULTS = ['interventions' => true, 'outcomes' => true, 'backups' => false, 'progress' => false];

    protected $fillable = ['email', 'interventions', 'outcomes', 'backups', 'progress'];

    /** @return list<string> */
    public static function addressesFor(NotificationGroup $group): array
    {
        return array_values(self::query()->where($group->preference(), true)->orderBy('id')->get()
            ->map(fn (self $recipient): string => $recipient->email)->all());
    }

    protected function casts(): array
    {
        return ['interventions' => 'boolean', 'outcomes' => 'boolean', 'backups' => 'boolean', 'progress' => 'boolean'];
    }
}
