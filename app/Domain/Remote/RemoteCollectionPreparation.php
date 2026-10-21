<?php

namespace App\Domain\Remote;

use App\Domain\Tools\BindExecutionTool;
use App\Domain\Tools\DTOs\ExecutionStepDefinition;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Execution;
use App\Models\ExecutionToolBinding;
use App\Models\ProjectConfiguration;
use App\Models\ToolDistribution;
use App\Models\User;

/**
 * Pins a remote collection to the verified catalog distribution. The export
 * runs on the source server; the platform keeps only a small control-plane
 * workspace for state snapshots, so its approved quota is fixed and small.
 */
final class RemoteCollectionPreparation
{
    public const ADAPTER_KEY = 'collector-742-remote';

    public const CONTROL_PLANE_BYTES = 16_777_216;

    public function __construct(
        private readonly RemoteCollectionConfiguration $configurations,
        private readonly ApproveExecutionCapacity $capacity,
        private readonly BindExecutionTool $bindings,
    ) {}

    /** @return list<ExecutionStepDefinition> */
    public function plan(): array
    {
        return [new ExecutionStepDefinition('collection', 'Recolectar cursos en el servidor de origen', 1),
            new ExecutionStepDefinition('verification', 'Publicar y verificar el paquete fuente', 2),
            new ExecutionStepDefinition('finalization', 'Confirmar paquete validado', 3)];
    }

    public function prepare(Execution $execution, ProjectConfiguration $configuration, User $actor): ExecutionToolBinding
    {
        if (! config('toolkit.features.remote_agent.enabled') || ! $this->configurations->selected($configuration)) {
            throw new ToolOperationBlocked('La recolección remota requiere su configuración y el agente remoto habilitado.');
        }
        $settings = $this->configurations->settings($configuration);
        $distribution = ToolDistribution::query()->with('toolVersion')->where('key', $settings['distribution_key'])->sole();
        $this->capacity->approve($execution, self::CONTROL_PLANE_BYTES, 0, $actor);

        return $this->bindings->bind($execution, $distribution->toolVersion, $distribution, 'moodle.source.export',
            self::ADAPTER_KEY, RemoteAgentOperations::PROVIDER, $settings);
    }

    public static function selected(?ExecutionToolBinding $binding): bool
    {
        return $binding?->adapter_key === self::ADAPTER_KEY;
    }
}
