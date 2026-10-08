<?php

namespace App\Domain\Realtime;

use App\Domain\Realtime\Contracts\ExecutionEventTransport;
use App\Events\ExecutionEventBroadcast;
use App\Models\ExecutionEvent;

final class ReverbExecutionEventTransport implements ExecutionEventTransport
{
    public function publish(ExecutionEvent $event): void
    {
        broadcast(new ExecutionEventBroadcast($event));
    }
}
