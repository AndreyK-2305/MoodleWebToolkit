<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $execution_id
 * @property int $tool_version_id
 * @property string $schema_version
 * @property string $source
 * @property int $approved_by
 * @property string $relative_path
 * @property string $content_sha256
 * @property string $fingerprint
 * @property string $approval_state
 * @property CarbonImmutable|null $approved_at
 */
class ExecutionRuntimeConfiguration extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'execution_id', 'tool_version_id', 'schema_version', 'source', 'approved_by',
        'relative_path', 'content_sha256', 'fingerprint', 'approval_state', 'approved_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $configuration): void {
            if ($configuration->isDirty(['execution_id', 'tool_version_id', 'schema_version', 'source', 'approved_by', 'relative_path', 'content_sha256', 'fingerprint', 'approved_at'])) {
                throw new LogicException('La configuración de runtime aprobada es inmutable.');
            }
            if ($configuration->getRawOriginal('approval_state') === 'REVOKED' && $configuration->approval_state !== 'REVOKED') {
                throw new LogicException('Una configuración revocada no se puede reactivar.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('La configuración de runtime aprobada es inmutable.'));
    }

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return BelongsTo<ToolVersion, $this> */
    public function toolVersion(): BelongsTo
    {
        return $this->belongsTo(ToolVersion::class);
    }

    protected function casts(): array
    {
        return ['approved_at' => 'immutable_datetime'];
    }
}
