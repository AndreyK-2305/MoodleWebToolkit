<?php

namespace App\Domain\Remote;

use App\Domain\Executions\ExecutionEventRecorder;
use App\Enums\ExecutionStatus;
use App\Enums\RemoteCommunicationState;
use App\Exceptions\RemoteAgentUnavailable;
use App\Models\Execution;
use App\Models\RemoteOperation;
use App\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * Frees the disk a remote collection used on its source server once the
 * package is no longer needed there: verified in S3 (REVIEW onwards) or the
 * Execution closed without it. The agent removes only the heavy outputs and
 * keeps the evidence; a failed call is retried with backoff and flagged for
 * review after repeated attempts, never blocking the Execution.
 */
class RemoteSourceCleanup
{
    public const STATUSES = ['REVIEW', 'COMPLETED', 'FAILED', 'CANCELLED'];

    private const MANUAL_AFTER = 8;

    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
        private readonly ExecutionEventRecorder $events,
    ) {}

    public function clean(Execution $execution): void
    {
        if (! in_array($execution->status->value, self::STATUSES, true)
            || $execution->remoteOperations()->where('communication_state', '!=', RemoteCommunicationState::TERMINATED->value)->exists()) {
            return;
        }
        $operations = $execution->remoteOperations()->where('provider_key', RemoteAgentOperations::PROVIDER)
            ->whereIn('command_key', [RemoteCollectorExport::COMMAND, RemoteSourcePackagePublisher::COMMAND])->orderBy('id')->get();
        $pending = $operations->filter(fn (RemoteOperation $operation): bool => ! isset($operation->evidence['cleanup']) && $this->due($operation));
        if ($pending->isEmpty()) {
            return;
        }
        foreach ($pending as $operation) {
            $this->cleanOperation($operation);
        }
        $operations = $operations->map(fn (RemoteOperation $operation): RemoteOperation => $operation->fresh());
        if ($operations->every(fn (RemoteOperation $operation): bool => isset($operation->evidence['cleanup']))) {
            $freed = (int) $operations->sum(fn (RemoteOperation $operation): int => (int) ($operation->evidence['cleanup']['freed_bytes'] ?? 0));
            $this->events->record($execution, 'collector.source_cleaned', 'collection',
                message: 'Se liberó el espacio de la exportación en el servidor de origen; se conservan su evidencia y sus registros.',
                payload: ['freed_bytes' => $freed, 'execution_status' => $execution->status->value], operation: $operations->first());
        }
    }

    private function cleanOperation(RemoteOperation $operation): void
    {
        $server = Server::query()->where('uuid', (string) $operation->host_id)->first();
        $errorCode = null;
        $cleanup = null;
        try {
            if ($server === null) {
                $errorCode = 'server_not_found';
            } else {
                $response = $this->agent->call($this->connections->target($server), 'cleanup', [$operation->operation_uuid], 120);
                if ($response->ok && is_array($response->data['cleanup'] ?? null)) {
                    $cleanup = ['freed_bytes' => max(0, (int) ($response->data['cleanup']['freed_bytes'] ?? 0)),
                        'cleaned_at' => is_string($response->data['cleanup']['cleaned_at'] ?? null) ? $response->data['cleanup']['cleaned_at'] : null,
                        'already_clean' => ($response->data['already_clean'] ?? false) === true];
                } else {
                    $errorCode = $response->errorCode ?? 'invalid_response';
                }
            }
        } catch (RemoteAgentUnavailable $exception) {
            $errorCode = $exception->errorCode;
        }

        DB::transaction(function () use ($operation, $cleanup, $errorCode): void {
            $locked = RemoteOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            $evidence = $locked->evidence ?? [];
            if ($cleanup !== null) {
                $evidence['cleanup'] = $cleanup;
                unset($evidence['cleanup_pending']);
            } else {
                $attempts = (int) ($evidence['cleanup_pending']['attempts'] ?? 0) + 1;
                $evidence['cleanup_pending'] = ['attempts' => $attempts, 'error_code' => $errorCode, 'manual' => $attempts >= self::MANUAL_AFTER,
                    'next_at' => now()->addSeconds(min(3600, 60 * (2 ** min(6, $attempts - 1))))->toIso8601String()];
            }
            $locked->forceFill(['evidence' => $evidence])->save();
        }, attempts: 3);
    }

    private function due(RemoteOperation $operation): bool
    {
        $pending = $operation->evidence['cleanup_pending'] ?? null;
        if (! is_array($pending)) {
            return true;
        }

        return ($pending['manual'] ?? false) !== true
            && (! is_string($pending['next_at'] ?? null) || strtotime($pending['next_at']) <= now()->getTimestamp());
    }

    /** @return array{state: string, freed_bytes: int|null}|null */
    public static function state(Execution $execution): ?array
    {
        if (! in_array($execution->status, [ExecutionStatus::REVIEW, ExecutionStatus::COMPLETED, ExecutionStatus::FAILED, ExecutionStatus::CANCELLED], true)) {
            return null;
        }
        $operations = $execution->remoteOperations()->where('provider_key', RemoteAgentOperations::PROVIDER)
            ->whereIn('command_key', [RemoteCollectorExport::COMMAND, RemoteSourcePackagePublisher::COMMAND])->get();
        if ($operations->isEmpty()) {
            return null;
        }
        if ($operations->every(fn (RemoteOperation $operation): bool => isset($operation->evidence['cleanup']))) {
            return ['state' => 'CLEANED', 'freed_bytes' => (int) $operations->sum(fn (RemoteOperation $operation): int => (int) ($operation->evidence['cleanup']['freed_bytes'] ?? 0))];
        }

        return ['state' => $operations->contains(fn (RemoteOperation $operation): bool => ($operation->evidence['cleanup_pending']['manual'] ?? false) === true)
            ? 'MANUAL' : 'PENDING', 'freed_bytes' => null];
    }
}
