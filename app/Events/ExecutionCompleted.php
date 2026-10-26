<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An Execution reached COMPLETED; dispatched once its transaction commits. */
final class ExecutionCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $executionId, public readonly int $projectId) {}
}
