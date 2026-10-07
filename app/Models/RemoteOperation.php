<?php

namespace App\Models;

use App\Enums\RemoteCommunicationState;
use App\Enums\RemoteFunctionalState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemoteOperation extends Model
{
    protected $fillable = [
        'execution_id', 'operation_uuid', 'idempotency_key', 'provider_key', 'host_id', 'runtime_key', 'process_id',
        'command_key', 'command_sha256', 'communication_state', 'functional_state', 'last_heartbeat_at', 'launch_claimed_at',
        'last_observed_at', 'next_poll_at', 'started_at', 'terminated_at', 'exit_code', 'evidence', 'last_error',
    ];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    protected function casts(): array
    {
        return [
            'communication_state' => RemoteCommunicationState::class,
            'functional_state' => RemoteFunctionalState::class,
            'last_heartbeat_at' => 'immutable_datetime', 'launch_claimed_at' => 'immutable_datetime', 'last_observed_at' => 'immutable_datetime',
            'next_poll_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'terminated_at' => 'immutable_datetime', 'exit_code' => 'integer', 'evidence' => 'array',
        ];
    }
}
