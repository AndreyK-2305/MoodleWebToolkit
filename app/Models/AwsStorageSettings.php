<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The platform's single package bucket: sources upload sealed packages there,
 * destinations download them and the platform verifies them with the account
 * chosen here (or the default account when none is chosen).
 *
 * @property int $id
 * @property string $bucket
 * @property string $region
 * @property int|null $aws_account_id
 * @property int|null $updated_by
 * @property CarbonImmutable|null $updated_at
 * @property-read AwsAccount|null $account
 */
class AwsStorageSettings extends Model
{
    protected $table = 'aws_storage_settings';

    protected $fillable = ['bucket', 'region', 'aws_account_id', 'updated_by'];

    public static function current(): ?self
    {
        return self::query()->orderBy('id')->first();
    }

    /** @return BelongsTo<AwsAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AwsAccount::class, 'aws_account_id');
    }

    protected function casts(): array
    {
        return [
            'aws_account_id' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
