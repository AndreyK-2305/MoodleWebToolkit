<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $execution_id
 * @property int $execution_command_id
 * @property string $stage
 * @property int $log_cursor
 * @property int $event_cursor
 * @property string|null $lease_owner
 * @property CarbonImmutable|null $lease_expires_at
 * @property string $staging_prefix
 * @property string $final_prefix
 * @property list<array<string, mixed>> $temporary_files
 * @property list<array<string, mixed>> $artifacts
 * @property list<string> $verified_types
 * @property list<string> $promoted_types
 * @property string|null $verification_type
 * @property int $verification_offset
 * @property array{hash: list<int>, buffer: string, length: int}|null $verification_hash_state
 * @property CarbonImmutable|null $finalization_started_at
 * @property CarbonImmutable|null $closure_ready_at
 * @property CarbonImmutable|null $completed_at
 */
class ExecutionFinalization extends Model
{
    protected $fillable = [
        'execution_id',
        'execution_command_id',
        'stage',
        'log_cursor',
        'event_cursor',
        'lease_owner',
        'lease_expires_at',
        'staging_prefix',
        'final_prefix',
        'temporary_files',
        'artifacts',
        'verified_types',
        'promoted_types',
        'verification_type',
        'verification_offset',
        'verification_hash_state',
        'finalization_started_at',
        'closure_ready_at',
        'completed_at',
    ];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return BelongsTo<ExecutionCommand, $this> */
    public function command(): BelongsTo
    {
        return $this->belongsTo(ExecutionCommand::class, 'execution_command_id');
    }

    protected function casts(): array
    {
        return [
            'log_cursor' => 'integer',
            'event_cursor' => 'integer',
            'lease_expires_at' => 'immutable_datetime',
            'temporary_files' => 'array',
            'artifacts' => 'array',
            'verified_types' => 'array',
            'promoted_types' => 'array',
            'verification_offset' => 'integer',
            'verification_hash_state' => 'array',
            'finalization_started_at' => 'immutable_datetime',
            'closure_ready_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
