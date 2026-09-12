<?php

namespace App\Models;

use App\Enums\ProjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AcademicSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['execution_id', 'project_type', 'schema_version', 'fingerprint', 'tree'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('La previsualización académica es inmutable.'));
        static::deleting(fn (): never => throw new LogicException('La previsualización académica es inmutable.'));
    }

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    protected function casts(): array
    {
        return [
            'project_type' => ProjectType::class,
            'schema_version' => 'integer',
            'tree' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
