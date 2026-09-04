<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $execution_id
 * @property string $step_key
 * @property string $type
 * @property string $adapter_key
 * @property string $resume_token
 * @property bool $validated
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 */
class Checkpoint extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['execution_id', 'step_key', 'type', 'adapter_key', 'resume_token', 'validated', 'metadata'];

    protected $hidden = ['resume_token'];

    protected static function booted(): void
    {
        static::updating(function (self $checkpoint): void {
            if ($checkpoint->isDirty('execution_id')) {
                throw new LogicException('La ejecución propietaria de un checkpoint es inmutable.');
            }

            if ($checkpoint->getRawOriginal('validated') === true && $checkpoint->validated === false) {
                throw new LogicException('La validación de un checkpoint no se puede revocar.');
            }

            if ($checkpoint->getRawOriginal('validated') === true && $checkpoint->isDirty()) {
                throw new LogicException('Un checkpoint validado es inmutable.');
            }

            if ($checkpoint->isDirty() && $checkpoint->resumedExecutions()->exists()) {
                throw new LogicException('Un checkpoint ya referenciado es inmutable.');
            }
        });

        static::deleting(function (self $checkpoint): void {
            if ($checkpoint->validated || $checkpoint->resumedExecutions()->exists()) {
                throw new LogicException('Un checkpoint validado o ya referenciado no se puede eliminar.');
            }
        });
    }

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
