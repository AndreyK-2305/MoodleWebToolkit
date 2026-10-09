<?php

namespace App\Domain\Realtime\Contracts;

use App\Models\ExecutionEvent;

interface ExecutionEventTransport
{
    public function publish(ExecutionEvent $event): void;
}
