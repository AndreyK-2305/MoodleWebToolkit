<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $proposal_version
 * @property string|null $fingerprint
 * @property VerificationStatus $status
 * @property bool|null $approved
 * @property string|null $summary
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable|null $checked_at
 */
class Verification extends Model
{
    protected $fillable = [
        'execution_id', 'key', 'proposal_version', 'fingerprint', 'status', 'approved',
        'requested_by', 'summary', 'details', 'checked_at',
    ];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    protected function casts(): array
    {
        return [
            'status' => VerificationStatus::class,
            'proposal_version' => 'integer',
            'approved' => 'boolean',
            'details' => 'array',
            'checked_at' => 'immutable_datetime',
        ];
    }
}
