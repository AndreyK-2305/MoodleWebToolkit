<?php

namespace App\Models;

use App\Enums\RemoteCommunicationState;
use App\Enums\RemoteFunctionalState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $execution_id
 * @property string $operation_uuid
 * @property string $idempotency_key
 * @property string $provider_key
 * @property string|null $host_id
 * @property string $runtime_key
 * @property string|null $process_id
 * @property string|null $process_group_id
 * @property string|null $process_start_identity
 * @property string $command_key
 * @property string $command_sha256
 * @property RemoteCommunicationState $communication_state
 * @property RemoteFunctionalState $functional_state
 * @property CarbonImmutable|null $last_heartbeat_at
 * @property CarbonImmutable|null $launch_claimed_at
 * @property CarbonImmutable|null $last_observed_at
 * @property CarbonImmutable|null $next_poll_at
 * @property int $reconcile_attempts
 * @property string|null $last_reconcile_error
 * @property bool $manual_intervention_required
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $terminated_at
 * @property int|null $exit_code
 * @property array<string, mixed>|null $evidence
 * @property string|null $last_error
 * @property-read Execution $execution
 */
class RemoteOperation extends Model
{
    protected $fillable = [
        'execution_id', 'operation_uuid', 'idempotency_key', 'provider_key', 'host_id', 'runtime_key', 'process_id', 'process_group_id', 'process_start_identity',
        'command_key', 'command_sha256', 'communication_state', 'functional_state', 'last_heartbeat_at', 'launch_claimed_at',
        'last_observed_at', 'next_poll_at', 'reconcile_attempts', 'last_reconcile_error', 'manual_intervention_required', 'started_at', 'terminated_at', 'exit_code', 'evidence', 'last_error',
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
            'reconcile_attempts' => 'integer', 'manual_intervention_required' => 'boolean',
        ];
    }
}
