<?php

namespace App\Domain\Tools;

use App\Domain\Collector\CollectorExecutionPreparation;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Project;

final class CollectorAdapter implements ToolAdapter
{
    // Keep HTTP confirmation closed until controls and finalization are verified.
    public static function httpEnabled(): bool
    {
        return config('collector.integration_ready', false) === true;
    }

    public function key(): string
    {
        return CollectorExecutionPreparation::ADAPTER_KEY;
    }

    public function capabilities(): array
    {
        return ['resume' => false, 'retry' => true, 'cancel' => true, 'pause' => false];
    }

    public function plan(Project $project): array
    {
        return app(CollectorExecutionPreparation::class)->plan();
    }

    public function executeUnit(Execution $execution, ExecutionStep $step): iterable
    {
        throw new ToolOperationBlocked('COLLECT real se procesa mediante su operación registrada y observación durable.');
    }
}
