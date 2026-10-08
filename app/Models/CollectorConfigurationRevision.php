<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $project_id
 * @property int $configuration_version
 * @property string $fingerprint
 * @property array<string, mixed> $snapshot
 */
final class CollectorConfigurationRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'configuration_version', 'schema_version', 'fingerprint', 'snapshot', 'created_by', 'created_at'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('La revisión de configuración es inmutable.'));
        self::deleting(fn (): never => throw new LogicException('La revisión de configuración es inmutable.'));
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
