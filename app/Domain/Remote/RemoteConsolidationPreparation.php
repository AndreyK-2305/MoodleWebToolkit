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
 * Pins a remote consolidation to the verified Consolidator V8 distribution.
 * The consolidation runs on the destination server; the platform keeps only a
 * small control-plane workspace, so its approved quota is fixed and small.
 * The steps are the Consolidator's own stages, with their real identifiers.
 */
final class RemoteConsolidationPreparation
{
    public const ADAPTER_KEY = 'consolidator-800-remote';

    public const CONTROL_PLANE_BYTES = 16_777_216;

    /** The assistant's stages in the order it runs them, as in consolidation-wizard.ps1. */
    public const STAGES = [
        '01-importar-paquetes' => 'Importar y vincular paquetes de origen',
        '02-oauth2' => 'Configurar y validar Google OAuth2',
        '03-plugins' => 'Validar compatibilidad e instalar plugins',
        '03b-oauth2-final' => 'Confirmar OAuth y proxy antes de conciliar identidades',
        '04-identidades' => 'Conciliar identidades, roles y matrículas',
        '05-plan-usuarios' => 'Simular usuarios canónicos',
        '05a-mapa-categorias' => 'Revisar el mapa de categorías del destino',
        '05b-readiness' => 'Readiness',
        '06-aplicar-usuarios' => 'Aplicar usuarios y linked logins',
        '07-verificar-usuarios' => 'Verificar usuarios y linked logins',
        '07b-categorias-piloto' => 'Crear las categorías del curso piloto',
        '08-plan-piloto' => 'Preparar y simular el curso piloto',
        '09-aplicar-piloto' => 'Restaurar el piloto',
        '09b-themes-piloto' => 'Temas del piloto',
        '10-verificar-piloto' => 'Verificar el piloto',
        '11-plan-lote' => 'Simular el lote consolidado',
        '12-preparar-lote' => 'Preparar el manifiesto del lote',
        '13-aplicar-lote' => 'Restaurar el lote con workers',
        '13b-themes-lote' => 'Temas del lote',
        '14-verificar-lote' => 'Verificar la consolidación',
        '15-cierre' => 'Consolidar evidencias y cerrar',
        '16-paquete-sitio' => 'Generar y sellar la copia integral del sitio',
    ];

    public function __construct(
        private readonly RemoteConsolidationConfiguration $configurations,
        private readonly ApproveExecutionCapacity $capacity,
        private readonly BindExecutionTool $bindings,
    ) {}

    /** @return list<ExecutionStepDefinition> */
    public function plan(): array
    {
        $steps = [];
        $position = 1;
        foreach (self::STAGES as $key => $name) {
            $steps[] = new ExecutionStepDefinition($key, $name, $position++);
        }
        $steps[] = new ExecutionStepDefinition('finalization', 'Confirmar la consolidación', $position);

        return $steps;
    }

    public function prepare(Execution $execution, ProjectConfiguration $configuration, User $actor): ExecutionToolBinding
    {
        if (! config('toolkit.features.remote_agent.enabled') || ! $this->configurations->selected($configuration)) {
            throw new ToolOperationBlocked('La consolidación remota requiere su configuración y el agente remoto habilitado.');
        }
        $settings = $this->configurations->settings($configuration);
        $distribution = ToolDistribution::query()->with('toolVersion')->where('key', $settings['distribution_key'])->sole();
        $this->capacity->approve($execution, self::CONTROL_PLANE_BYTES, 0, $actor);

        return $this->bindings->bind($execution, $distribution->toolVersion, $distribution, RemoteConsolidationConfiguration::WORKFLOW,
            self::ADAPTER_KEY, RemoteAgentOperations::PROVIDER, $settings);
    }

    public static function selected(?ExecutionToolBinding $binding): bool
    {
        return $binding?->adapter_key === self::ADAPTER_KEY;
    }
}
