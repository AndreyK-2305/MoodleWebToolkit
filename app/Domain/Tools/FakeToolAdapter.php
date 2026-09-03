<?php

namespace App\Domain\Tools;

use App\Domain\Tools\Contracts\ToolAdapter;
use App\Domain\Tools\DTOs\ExecutionStepDefinition;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\ProjectType;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Project;

class FakeToolAdapter implements ToolAdapter
{
    public function key(): string
    {
        return 'fake';
    }

    public function capabilities(): array
    {
        return ['resume' => false, 'retry' => false, 'cancel' => false, 'pause' => false];
    }

    public function plan(Project $project): array
    {
        $operation = match ($project->type) {
            ProjectType::COLLECT => 'Recolección simulada',
            ProjectType::CONSOLIDATE => 'Consolidación simulada',
            ProjectType::INTEGRATE => 'Integración incremental simulada',
        };

        return [
            new ExecutionStepDefinition('prepare', 'Preparación simulada', 1),
            new ExecutionStepDefinition('operation', $operation, 2),
            new ExecutionStepDefinition('verification', 'Verificación simulada', 3),
            new ExecutionStepDefinition('finalization', 'Finalización', 4),
        ];
    }

    public function executeUnit(Execution $execution, ExecutionStep $step): iterable
    {
        yield new NormalizedToolEvent(
            'phase.started',
            $step->step_key,
            progress: null,
            message: 'La unidad simulada comenzó sin invocar procesos ni herramientas reales.',
            payload: ['adapter' => $this->key(), 'attempt' => $execution->attempt],
        );
        yield new NormalizedToolEvent(
            'progress',
            $step->step_key,
            progress: 10,
            message: 'Validando el contrato de ejecución simulado.',
        );
        yield new NormalizedToolEvent(
            'progress',
            $step->step_key,
            progress: 25,
            message: 'La unidad acotada de 1D fue procesada.',
        );
        yield new NormalizedToolEvent(
            'phase.completed',
            $step->step_key,
            progress: 25,
            message: 'Preparación simulada completada.',
        );
    }
}
