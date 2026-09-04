<?php

namespace App\Events;

use App\Domain\Realtime\ProjectSessionChannels;
use App\Models\ExecutionEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ExecutionEventBroadcast implements ShouldBroadcastNow
{
    public function __construct(public readonly ExecutionEvent $event) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $this->event->loadMissing('execution.project');
        $project = $this->event->execution->project;

        return array_map(
            fn (string $channel): PrivateChannel => new PrivateChannel($channel),
            app(ProjectSessionChannels::class)->authorized($project),
        );
    }

    public function broadcastAs(): string
    {
        return 'execution.event';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'execution_uuid' => $this->event->execution->uuid,
            'event' => [
                'sequence' => $this->event->sequence,
                'type' => $this->event->type,
                'step_key' => $this->event->step_key,
                'severity' => $this->event->severity->value,
                'progress' => $this->event->progress,
                'message' => $this->event->message,
                'payload' => $this->event->payload,
                'created_at' => $this->event->created_at->toIso8601String(),
            ],
        ];
    }
}
