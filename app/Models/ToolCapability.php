<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolCapability extends Model
{
    protected $fillable = ['tool_version_id', 'key', 'support_state', 'evidence_level', 'source_path', 'details'];

    /** @return BelongsTo<ToolVersion, $this> */
    public function toolVersion(): BelongsTo
    {
        return $this->belongsTo(ToolVersion::class);
    }
}
