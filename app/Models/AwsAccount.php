<?php

namespace App\Models;

use App\Enums\AwsCredentialMode;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One AWS identity the platform can act as. Access keys and the External ID
 * are encrypted at rest and hidden from every serialization; the browser only
 * learns whether they are set and the last characters of the key id.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property AwsCredentialMode $mode
 * @property string|null $access_key_id
 * @property string|null $secret_access_key
 * @property string|null $role_arn
 * @property string|null $external_id
 * @property string|null $session_name
 * @property bool $is_default
 * @property int|null $updated_by
 * @property CarbonImmutable|null $updated_at
 */
class AwsAccount extends Model
{
    use HasPublicUuid;

    protected $fillable = ['uuid', 'name', 'mode', 'access_key_id', 'secret_access_key', 'role_arn', 'external_id',
        'session_name', 'is_default', 'updated_by'];

    protected $hidden = ['access_key_id', 'secret_access_key', 'external_id'];

    public static function default(): ?self
    {
        return self::query()->where('is_default', true)->orderBy('id')->first();
    }

    protected function casts(): array
    {
        return [
            'mode' => AwsCredentialMode::class,
            'access_key_id' => 'encrypted',
            'secret_access_key' => 'encrypted',
            'external_id' => 'encrypted',
            'is_default' => 'boolean',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
