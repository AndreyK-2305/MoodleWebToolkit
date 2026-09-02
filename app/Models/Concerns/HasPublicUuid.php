<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasPublicUuid
{
    protected static function bootHasPublicUuid(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('uuid') === null) {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }
}
