<?php

namespace App\Domain\Executions;

use App\Models\Execution;
use App\Models\ExecutionEvent;
use App\Models\ExecutionStep;

class ExecutionPresenter
{
    /** @return array<string, mixed> */
    public function execution(Execution $execution): array
    {
        $execution->loadMissing('steps');

        return [
            'uuid' => $execution->uuid,
            'attempt' => $execution->attempt,
            'status' => $execution->status->value,
            'progress' => $execution->progress,
            'started_at' => $execution->started_at?->toIso8601String(),
            'finished_at' => $execution->finished_at?->toIso8601String(),
            'last_event_sequence' => $execution->last_event_sequence,
            'steps' => $execution->steps
                ->map(fn (ExecutionStep $step): array => [
                    'key' => $step->step_key,
                    'name' => $step->name,
                    'position' => $step->position,
                    'status' => $step->status->value,
                    'progress' => $step->progress,
                    'started_at' => $step->started_at?->toIso8601String(),
                    'finished_at' => $step->finished_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function event(ExecutionEvent $event): array
    {
        return [
            'sequence' => $event->sequence,
            'type' => $event->type,
            'step_key' => $event->step_key,
            'severity' => $event->severity->value,
            'progress' => $event->progress,
            'message' => $event->message,
            'payload' => $event->payload,
            'created_at' => $event->created_at->toIso8601String(),
        ];
    }
}
