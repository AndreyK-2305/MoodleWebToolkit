<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $remote_operation_id
 * @property int $execution_id
 * @property string $package_sha256
 * @property int $package_bytes
 * @property array<string, mixed> $snapshot
 */
final class CollectorPackageAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['remote_operation_id', 'execution_id', 'package_sha256', 'package_bytes', 'snapshot'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('La auditoría del paquete es inmutable.'));
        self::deleting(fn (): never => throw new LogicException('La auditoría del paquete es inmutable.'));
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'package_bytes' => 'integer'];
    }
}
