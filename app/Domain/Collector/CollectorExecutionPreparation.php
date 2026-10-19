<?php

namespace App\Domain\Collector;

use App\Domain\Tools\BindExecutionTool;
use App\Domain\Tools\DTOs\ExecutionStepDefinition;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Execution;
use App\Models\ExecutionToolBinding;
use App\Models\ProjectConfiguration;
use App\Models\ToolDistribution;
use App\Models\User;

final class CollectorExecutionPreparation
{
    public const ADAPTER_KEY = 'collector-742-lab';

    public function __construct(private readonly CollectorConfiguration $configurations,
        private readonly ApproveExecutionCapacity $capacity, private readonly CollectorRuntimeConfiguration $runtime,
        private readonly BindExecutionTool $bindings) {}

    /** @return list<ExecutionStepDefinition> */
    public function plan(): array
    {
        return [new ExecutionStepDefinition('preparation', 'Preparar distribución y configuración', 1),
            new ExecutionStepDefinition('collection', 'Recolectar cursos de Moodle', 2),
            new ExecutionStepDefinition('verification', 'Auditar paquete fuente', 3),
            new ExecutionStepDefinition('finalization', 'Confirmar paquete validado', 4)];
    }

    public function prepare(Execution $execution, ProjectConfiguration $configuration, User $actor): ExecutionToolBinding
    {
        if (! config('toolkit.features.recolector_742.enabled') || ! config('toolkit.features.local_runner.enabled')
            || ! $this->configurations->selected($configuration)) {
            throw new ToolOperationBlocked('COLLECT LAB requiere configuración real y habilitación explícita.');
        }
        $settings = $this->configurations->settings($configuration);
        $distribution = ToolDistribution::query()->with('toolVersion')->where('key', $settings['distribution_key'])->sole();
        $this->capacity->approve($execution, $settings['capacity_bytes'], $settings['safety_margin_percent'], $actor);
        $this->runtime->approve($execution, $configuration, $distribution, $actor);

        return $this->bindings->bind($execution, $distribution->toolVersion, $distribution, 'moodle.source.export',
            self::ADAPTER_KEY, 'local-registered-process', $settings);
    }
}
