<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SourcePackage extends Model
{
    protected $fillable = [
        'uuid', 'project_id', 'producer_execution_id', 'artifact_id', 'producer_tool_version', 'schema_version',
        'source_id', 'name', 'size_bytes', 'sha256', 'manifest_sha256', 'validation_state', 'validated_at',
        'capabilities', 'compatibility', 'sensitivity', 'availability', 'revoked_at', 'evidence',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $package): void {
            if ($package->isDirty(['uuid', 'project_id', 'producer_execution_id', 'artifact_id', 'source_id', 'sha256', 'size_bytes'])) {
                throw new LogicException('La identidad, procedencia y hash del paquete fuente son inmutables.');
            }
            if ($package->getRawOriginal('validation_state') === 'REVOKED' && $package->validation_state !== 'REVOKED') {
                throw new LogicException('Un paquete fuente revocado no se puede validar de nuevo.');
            }
        });
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Execution, $this> */
    public function producerExecution(): BelongsTo
    {
        return $this->belongsTo(Execution::class, 'producer_execution_id');
    }

    /** @return BelongsTo<Artifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'validated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'capabilities' => 'array',
            'compatibility' => 'array',
            'evidence' => 'array',
        ];
    }
}
