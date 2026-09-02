<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Checkpoint extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['execution_id', 'step_key', 'type', 'resume_token', 'validated', 'metadata'];

    protected $hidden = ['resume_token'];

    public function isResumable(): bool
    {
        return $this->validated;
    }

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return HasMany<Execution, $this> */
    public function resumedExecutions(): HasMany
    {
        return $this->hasMany(Execution::class, 'resume_checkpoint_id');
    }

    protected function casts(): array
    {
        return [
            'resume_token' => 'encrypted',
            'validated' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
