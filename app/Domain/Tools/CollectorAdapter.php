<?php

namespace App\Domain\Tools;

use App\Domain\Collector\CollectorExecutionPreparation;
use App\Domain\Collector\CollectorWorkflow;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Project;

final class CollectorAdapter implements ToolAdapter
{
    // Installed integration; the two LAB flags and catalog authorization still apply.
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
        if ($step->execution_id !== $execution->id || $step->step_key !== 'collection') {
            throw new ToolOperationBlocked('Esta unidad no inicia una recolección real.');
        }
        $operation = app(CollectorWorkflow::class)->start($execution);
        yield new NormalizedToolEvent('collector.operation_registered', 'collection',
            message: 'La operación real está registrada y continuará en el runner.',
            payload: ['operation_uuid' => $operation->operation_uuid, 'command_sha256' => $operation->command_sha256]);
    }
}
