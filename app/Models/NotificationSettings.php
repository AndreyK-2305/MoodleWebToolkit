<?php

namespace App\Models;

use App\Enums\SmtpEncryption;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The platform's single SMTP configuration. The password is encrypted at rest
 * and hidden from every serialization; the browser only learns whether it is set.
 *
 * @property int $id
 * @property bool $enabled
 * @property string $smtp_host
 * @property int $smtp_port
 * @property SmtpEncryption $smtp_encryption
 * @property string|null $smtp_username
 * @property string|null $smtp_password
 * @property string $from_address
 * @property string $from_name
 * @property int|null $progress_interval_minutes
 * @property int|null $updated_by
 * @property CarbonImmutable|null $updated_at
 */
class NotificationSettings extends Model
{
    protected $table = 'notification_settings';

    protected $fillable = ['enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        'from_address', 'from_name', 'progress_interval_minutes', 'updated_by'];

    protected $hidden = ['smtp_password'];

    public static function current(): ?self
    {
        return self::query()->orderBy('id')->first();
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'smtp_port' => 'integer',
            'smtp_encryption' => SmtpEncryption::class,
            'smtp_password' => 'encrypted',
            'progress_interval_minutes' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
