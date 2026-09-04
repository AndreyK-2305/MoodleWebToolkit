<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ArtifactDownload extends Model
{
    public const CREATED_AT = 'downloaded_at';

    public const UPDATED_AT = null;

    protected $fillable = ['artifact_id', 'execution_id', 'user_id', 'idempotency_key', 'payload_hash', 'downloaded_at'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('El registro de descarga es inmutable.'));
        static::deleting(fn (): never => throw new LogicException('El registro de descarga es inmutable.'));
    }

    /** @return BelongsTo<Artifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['downloaded_at' => 'immutable_datetime'];
    }
}
