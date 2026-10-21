<?php

namespace App\Domain\Remote;

use App\Domain\Tools\Contracts\ToolAdapter;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Project;

/** Collector 7.4.2 executed on the project's source server through its agent. */
final class RemoteCollectorAdapter implements ToolAdapter
{
    public function key(): string
    {
        return RemoteCollectionPreparation::ADAPTER_KEY;
    }

    public function capabilities(): array
    {
        return ['resume' => false, 'retry' => false, 'cancel' => true, 'pause' => false];
    }

    public function plan(Project $project): array
    {
        return app(RemoteCollectionPreparation::class)->plan();
    }

    public function executeUnit(Execution $execution, ExecutionStep $step): iterable
    {
        // The export is launched by RemoteCollectionProvider; its events are recorded by the workflow.
        throw new ToolOperationBlocked('La recolección remota se inicia por su provider, no por unidades.');
    }
}
