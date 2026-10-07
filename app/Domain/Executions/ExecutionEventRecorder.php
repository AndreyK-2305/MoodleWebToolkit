<?php

namespace App\Domain\Executions;

use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\EventSeverity;
use App\Events\ExecutionEventBroadcast;
use App\Models\Execution;
use App\Models\ExecutionEvent;
use App\Models\RemoteOperation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class ExecutionEventRecorder
{
    public function recordNormalized(Execution $execution, NormalizedToolEvent $event, ?CarbonInterface $createdAt = null): ExecutionEvent
    {
        return $this->record(
            $execution,
            $event->type,
            $event->stepKey,
            $event->severity,
            $event->progress,
            $event->message,
            $event->payload,
            $createdAt,
        );
    }

    /** @param array<string, mixed>|null $payload */
    public function record(
        Execution $execution,
        string $type,
        ?string $stepKey = null,
        EventSeverity $severity = EventSeverity::INFO,
        ?int $progress = null,
        ?string $message = null,
        ?array $payload = null,
        ?CarbonInterface $createdAt = null,
        ?RemoteOperation $operation = null,
    ): ExecutionEvent {
        if ($progress !== null && ($progress < 0 || $progress > 100)) {
            throw new InvalidArgumentException('El progreso debe ser null o estar entre 0 y 100.');
        }

        return DB::transaction(function () use ($execution, $type, $stepKey, $severity, $progress, $message, $payload, $createdAt, $operation): ExecutionEvent {
            $lockedExecution = Execution::query()->lockForUpdate()->findOrFail((int) $execution->getKey());
            if ($operation !== null && (int) $operation->execution_id !== (int) $lockedExecution->getKey()) {
                throw new InvalidArgumentException('El evento remoto debe pertenecer a la misma ejecución.');
            }
            $sequence = ((int) $lockedExecution->last_event_sequence) + 1;

            $lockedExecution->last_event_sequence = $sequence;
            $lockedExecution->save();

            $attributes = [
                'sequence' => $sequence,
                'type' => $type,
                'step_key' => $stepKey,
                'severity' => $severity,
                'progress' => $progress,
                'message' => $message,
                'payload' => $payload,
                'remote_operation_id' => $operation?->getKey(),
            ];

            if ($createdAt !== null) {
                $attributes['created_at'] = $createdAt;
            }

            $event = $lockedExecution->events()->create($attributes);

            $eventId = (int) $event->getKey();
            DB::afterCommit(function () use ($eventId): void {
                try {
                    $persisted = ExecutionEvent::query()
                        ->with('execution.project')
                        ->find($eventId);

                    if ($persisted !== null) {
                        broadcast(new ExecutionEventBroadcast($persisted));
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

            return $event;
        }, attempts: 3);
    }
}
