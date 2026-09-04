<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Artifact extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'execution_id',
        'type',
        'disk',
        'path',
        'filename',
        'mime_type',
        'size',
        'sha256',
        'metadata',
    ];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return HasMany<ArtifactDownload, $this> */
    public function downloads(): HasMany
    {
        return $this->hasMany(ArtifactDownload::class);
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'metadata' => 'array',
        ];
    }
}
