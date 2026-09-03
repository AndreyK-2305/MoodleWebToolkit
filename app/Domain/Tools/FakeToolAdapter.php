<?php

namespace App\Domain\Tools;

use App\Domain\Tools\Contracts\ToolAdapter;
use App\Domain\Tools\DTOs\ExecutionStepDefinition;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\ProjectType;
use App\Models\Checkpoint;
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
        return ['resume' => true, 'retry' => false, 'cancel' => true, 'pause' => true];
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
        $progress = $step->step_key === 'prepare' ? 25 : 50;
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
            progress: $step->step_key === 'prepare' ? 10 : 40,
            message: 'Validando el contrato de ejecución simulado.',
        );
        yield new NormalizedToolEvent(
            'progress',
            $step->step_key,
            progress: $progress,
            message: 'La unidad acotada de 1E fue procesada.',
        );
        yield new NormalizedToolEvent(
            'phase.completed',
            $step->step_key,
            progress: $progress,
            message: $step->step_key === 'prepare'
                ? 'Preparación simulada completada.'
                : 'Procesamiento simulado completado.',
        );
    }

    public function scenario(Execution $execution): string
    {
        $execution->loadMissing('project.configuration');
        $settings = $execution->project->configuration?->settings;
        $options = is_array($settings) && is_array($settings['options'] ?? null)
            ? $settings['options']
            : [];

        return in_array($options['processing_scenario'] ?? null, ['SUCCESS', 'WARNING', 'INTERVENTION', 'FAILURE'], true)
            ? $options['processing_scenario']
            : 'SUCCESS';
    }

    public function checkpointToken(Execution $execution, ExecutionStep $step): string
    {
        return hash_hmac(
            'sha256',
            "fake:{$execution->uuid}:{$execution->workspace_key}:{$step->step_key}",
            (string) config('app.key'),
        );
    }

    public function validatesCheckpoint(Checkpoint $checkpoint): bool
    {
        $checkpoint->loadMissing('execution');
        $step = $checkpoint->execution->steps()->where('step_key', $checkpoint->step_key)->first();

        return $checkpoint->validated
            && $checkpoint->adapter_key === $this->key()
            && $step !== null
            && hash_equals($this->checkpointToken($checkpoint->execution, $step), $checkpoint->resume_token);
    }
}
