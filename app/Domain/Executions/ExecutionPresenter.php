<?php

namespace App\Domain\Executions;

use App\Models\Checkpoint;
use App\Models\Conflict;
use App\Models\Execution;
use App\Models\ExecutionEvent;
use App\Models\ExecutionStep;

class ExecutionPresenter
{
    /** @return array<string, mixed> */
    public function execution(Execution $execution): array
    {
        $execution->loadMissing(['steps', 'conflicts', 'checkpoints', 'resumedFromExecution']);

        return [
            'uuid' => $execution->uuid,
            'attempt' => $execution->attempt,
            'resumed_from_execution_uuid' => $execution->resumedFromExecution?->uuid,
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
            'conflicts' => $execution->conflicts
                ->map(fn (Conflict $conflict): array => [
                    'id' => $conflict->getKey(),
                    'key' => $conflict->key,
                    'type' => $conflict->type,
                    'status' => $conflict->status->value,
                    'version' => $conflict->version,
                    'message' => $conflict->details['message'] ?? null,
                    'allowed_decisions' => is_array($conflict->details['allowed_decisions'] ?? null)
                        ? array_values($conflict->details['allowed_decisions'])
                        : [],
                    'resolved_at' => $conflict->resolved_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'checkpoints' => $execution->checkpoints
                ->map(fn (Checkpoint $checkpoint): array => [
                    'id' => $checkpoint->getKey(),
                    'step_key' => $checkpoint->step_key,
                    'type' => $checkpoint->type,
                    'validated' => $checkpoint->validated,
                    'created_at' => $checkpoint->created_at?->toIso8601String(),
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
