<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tool extends Model
{
    protected $fillable = ['key', 'name', 'description'];

    /** @return HasMany<ToolVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ToolVersion::class);
    }
}
