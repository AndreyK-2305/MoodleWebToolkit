<?php

namespace App\Domain\Executions;

use App\Enums\EventSeverity;
use App\Models\Execution;
use App\Models\ExecutionEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persists the monotonic sequence. Broadcasting after commit belongs to 1D.
 */
class ExecutionEventRecorder
{
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

            return $lockedExecution->events()->create([
                'sequence' => $sequence,
                'type' => $type,
                'step_key' => $stepKey,
                'severity' => $severity,
                'progress' => $progress,
                'message' => $message,
                'payload' => $payload,
            ]);
        }, attempts: 3);
    }
}
