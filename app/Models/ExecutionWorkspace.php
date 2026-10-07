<?php

namespace App\Models;

use App\Enums\WorkspaceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionWorkspace extends Model
{
    protected $fillable = [
        'execution_id', 'uuid', 'relative_path', 'quota_bytes', 'usage_bytes', 'status', 'last_measured_at', 'cleaned_at',
    ];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    protected function casts(): array
    {
        return [
            'quota_bytes' => 'integer', 'usage_bytes' => 'integer', 'status' => WorkspaceStatus::class,
            'last_measured_at' => 'immutable_datetime', 'cleaned_at' => 'immutable_datetime',
        ];
    }
}
