<?php

namespace App\Domain\Collector;

use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\EventSeverity;
use App\Exceptions\ToolOperationBlocked;
use App\Models\CollectorObservationCursor;
use App\Models\Execution;
use App\Models\ExecutionLog;
use App\Models\Project;
use App\Models\RemoteOperation;
use Illuminate\Support\Facades\DB;

final class CollectorEventObserver
{
    public function __construct(
        private readonly CollectorLogReader $reader,
        private readonly ExecutionWorkspaceManager $workspaces,
        private readonly ExecutionEventRecorder $events,
    ) {}

    public function observe(RemoteOperation $operation): CollectorObservationCursor
    {
        if ($operation->command_key !== CollectorRegisteredCommand::KEY || $operation->provider_key !== 'local-registered-process') {
            throw new ToolOperationBlocked('El observador requiere una operación registrada del Recolector.');
        }

        return DB::transaction(function () use ($operation): CollectorObservationCursor {
            Project::query()->whereKey($operation->execution->project_id)->lockForUpdate()->firstOrFail();
            $execution = Execution::query()->whereKey($operation->execution_id)->lockForUpdate()->firstOrFail();
            $lockedOperation = RemoteOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            $cursor = CollectorObservationCursor::query()->firstOrCreate(['remote_operation_id' => $operation->getKey()],
                ['execution_id' => $execution->getKey(), 'prefix_sha256' => hash('sha256', '')]);
            $lostCursor = $cursor->wasRecentlyCreated && $execution->events()->where('remote_operation_id', $operation->id)
                ->whereJsonContainsKey('payload->collector_wire_sequence')->exists();
            $cursor = CollectorObservationCursor::query()->whereKey($cursor->getKey())->lockForUpdate()->firstOrFail();
            $seed = ['offset' => $cursor->stdout_offset, 'wire_sequence' => $cursor->last_wire_sequence,
                'device' => $cursor->file_device, 'inode' => $cursor->file_inode, 'prefix_sha256' => $cursor->prefix_sha256, 'discarding' => $cursor->discarding];
            $result = $lostCursor || ($cursor->reader_health === 'ALTERED' && $cursor->file_inode === null)
                ? ['cursor' => $seed, 'health' => 'ALTERED', 'complete' => false, 'events' => []]
                : $this->reader->read($this->workspaces->operationLogPath($execution, $operation->operation_uuid, 'stdout'),
                    $operation->operation_uuid, $seed, $lockedOperation->communication_state->value === 'TERMINATED');
            foreach ($result['events'] as $entry) {
                $event = $entry['event'];
                $payload = [...($event->payload ?? []), 'operation_uuid' => $operation->operation_uuid, 'collector_wire_sequence' => $entry['wire_sequence']];
                $this->events->recordNormalized($execution, new NormalizedToolEvent($event->type, $event->stepKey, $event->severity,
                    $event->progress, $event->message, $payload), operation: $lockedOperation);
                if ($event->type === 'collector.progress') {
                    $execution->forceFill(['progress' => $event->progress])->save();
                    $execution->steps()->where('step_key', 'collection')->first()?->forceFill(['progress' => $event->progress,
                        'metadata' => $payload])->save();
                }
                if ($event->type === 'collector.log') {
                    ExecutionLog::query()->create(['execution_id' => $execution->getKey(), 'remote_operation_id' => $operation->getKey(),
                        'stream' => 'SYSTEM', 'level' => 'INFO', 'message' => $event->message,
                        'context' => ['operation_uuid' => $operation->operation_uuid], 'logged_at' => now()->utc()]);
                }
            }
            if ($result['health'] !== 'OK' && $result['health'] !== $cursor->reader_health) {
                $this->events->record($execution, 'collector.observation_warning', 'collection', EventSeverity::WARNING,
                    message: 'La lectura verificable del progreso está pendiente; se conserva la evidencia de la operación.',
                    payload: ['reader_state' => $result['health']], operation: $lockedOperation);
            }
            $next = $result['cursor'];
            $cursor->forceFill(['stdout_offset' => $next['offset'], 'last_wire_sequence' => $next['wire_sequence'],
                'file_device' => $next['device'], 'file_inode' => $next['inode'], 'prefix_sha256' => $next['prefix_sha256'],
                'discarding' => $next['discarding'], 'reader_health' => $result['health'], 'read_complete' => $result['complete']])->save();

            return $cursor->refresh();
        }, attempts: 3);
    }
}
