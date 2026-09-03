<?php

namespace App\Domain\Executions;

use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\EventSeverity;
use App\Events\ExecutionEventBroadcast;
use App\Models\Execution;
use App\Models\ExecutionEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class ExecutionEventRecorder
{
    public function recordNormalized(Execution $execution, NormalizedToolEvent $event): ExecutionEvent
    {
        return $this->record(
            $execution,
            $event->type,
            $event->stepKey,
            $event->severity,
            $event->progress,
            $event->message,
            $event->payload,
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
    ): ExecutionEvent {
        if ($progress !== null && ($progress < 0 || $progress > 100)) {
            throw new InvalidArgumentException('El progreso debe ser null o estar entre 0 y 100.');
        }

        return DB::transaction(function () use ($execution, $type, $stepKey, $severity, $progress, $message, $payload): ExecutionEvent {
            $lockedExecution = Execution::query()->lockForUpdate()->findOrFail((int) $execution->getKey());
            $sequence = ((int) $lockedExecution->last_event_sequence) + 1;

            $lockedExecution->last_event_sequence = $sequence;
            $lockedExecution->save();

            $event = $lockedExecution->events()->create([
                'sequence' => $sequence,
                'type' => $type,
                'step_key' => $stepKey,
                'severity' => $severity,
                'progress' => $progress,
                'message' => $message,
                'payload' => $payload,
            ]);

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
