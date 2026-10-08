<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $execution_id
 * @property int|null $remote_operation_id
 * @property string $type
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string|null $mime_type
 * @property int $size
 * @property string $sha256
 * @property array<string, mixed>|null $metadata
 * @property string|null $category
 * @property string|null $storage_mode
 * @property-read Execution|null $execution
 */
class Artifact extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'execution_id',
        'remote_operation_id',
        'type',
        'disk',
        'path',
        'filename',
        'mime_type',
        'size',
        'sha256',
        'metadata',
        'category',
        'storage_mode',
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
