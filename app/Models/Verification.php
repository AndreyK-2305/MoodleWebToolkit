<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Verification extends Model
{
    protected $fillable = ['execution_id', 'key', 'status', 'summary', 'details', 'checked_at'];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    protected function casts(): array
    {
        return [
            'status' => VerificationStatus::class,
            'details' => 'array',
            'checked_at' => 'immutable_datetime',
        ];
    }
}
