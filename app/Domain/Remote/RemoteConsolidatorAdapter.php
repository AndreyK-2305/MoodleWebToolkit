<?php

namespace App\Domain\Remote;

use App\Domain\Tools\Contracts\ToolAdapter;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Project;

/** Consolidator V8 executed on the project's destination server through its agent. */
final class RemoteConsolidatorAdapter implements ToolAdapter
{
    public function key(): string
    {
        return RemoteConsolidationPreparation::ADAPTER_KEY;
    }

    public function capabilities(): array
    {
        return ['resume' => true, 'retry' => false, 'cancel' => true, 'pause' => false];
    }

    public function plan(Project $project): array
    {
        return app(RemoteConsolidationPreparation::class)->plan();
    }

    public function executeUnit(Execution $execution, ExecutionStep $step): iterable
    {
        throw new ToolOperationBlocked('La consolidación remota se inicia por su provider, no por unidades.');
    }
}
