<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property int $producer_execution_id
 * @property int $artifact_id
 * @property string $producer_tool_version
 * @property string $schema_version
 * @property string $source_id
 * @property string $name
 * @property int $size_bytes
 * @property string $sha256
 * @property string|null $manifest_sha256
 * @property string $validation_state
 * @property CarbonImmutable|null $validated_at
 * @property array<string, mixed>|null $capabilities
 * @property array{workflows?: list<string>}|null $compatibility
 * @property string $sensitivity
 * @property string $availability
 * @property CarbonImmutable|null $revoked_at
 * @property array<string, mixed>|null $evidence
 * @property-read Artifact|null $artifact
 */
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
