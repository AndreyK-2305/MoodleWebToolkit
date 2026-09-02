<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolVersion extends Model
{
    protected $fillable = [
        'tool_id',
        'version',
        'archive_name',
        'archive_sha256',
        'tree_sha256',
        'enabled',
    ];

    /** @return BelongsTo<Tool, $this> */
    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
