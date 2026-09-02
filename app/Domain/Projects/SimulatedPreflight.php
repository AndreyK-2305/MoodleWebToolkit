<?php

namespace App\Domain\Projects;

use App\Enums\MoodleInstanceRole;
use App\Enums\PreflightResult;
use App\Enums\ProjectType;
use App\Models\MoodleInstance;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use Illuminate\Support\Collection;

class SimulatedPreflight
{
    /**
     * @return list<array{id: string, description: string, result: string, detail: string}>
     */
    public function evaluate(Project $project, ProjectConfiguration $configuration): array
    {
        $project->loadMissing(['moodleInstances.server']);
        $settings = $this->settings($configuration);
        $instances = $project->moodleInstances;
        $errors = $this->configurationErrors($project, $configuration);
        $scenario = $settings['options']['simulation_scenario'] ?? null;

        $checks = [
            $this->check(
                'configuration.complete',
                'Configuración completa',
                $errors === [] ? PreflightResult::SUCCESS : PreflightResult::ERROR,
                $errors === []
                    ? 'Los datos básicos, instancias y opciones requeridas están completos.'
                    : implode(' ', $errors),
            ),
            $this->cardinalityCheck($project->type, $instances),
            $this->destinationCheck($project->type, $instances),
            $this->check(
                'resources.project_scope',
                'Recursos aislados por proyecto',
                $this->resourcesBelongToProject($project, $instances)
                    ? PreflightResult::SUCCESS
                    : PreflightResult::ERROR,
                $this->resourcesBelongToProject($project, $instances)
                    ? 'Todos los servidores e instancias simulados pertenecen a este proyecto.'
                    : 'Se detectaron referencias a recursos que no pertenecen al proyecto.',
            ),
            $this->check(
                'simulation.connectivity',
                'Conectividad simulada',
                $scenario === 'ERROR' ? PreflightResult::ERROR : PreflightResult::SUCCESS,
                $scenario === 'ERROR'
                    ? 'El escenario determinista simula un destino inaccesible.'
                    : 'La comprobación simulada de conectividad fue satisfactoria; no se realizó ninguna conexión real.',
            ),
            $this->check(
                'simulation.capacity',
                'Capacidad simulada del destino',
                $scenario === 'WARNING' ? PreflightResult::WARNING : PreflightResult::SUCCESS,
                $scenario === 'WARNING'
                    ? 'El escenario determinista simula espacio libre ajustado. Debe aceptar esta advertencia para confirmar.'
                    : 'La capacidad simulada es suficiente para la operación configurada.',
            ),
            $this->check(
                'simulation.no_secrets',
                'Configuración sin secretos reales',
                PreflightResult::SUCCESS,
                'El wizard sólo usa metadatos simulados y no solicita credenciales, tokens ni claves privadas.',
            ),
        ];

        return $checks;
    }

    /** @return list<string> */
    public function configurationErrors(Project $project, ProjectConfiguration $configuration): array
    {
        $project->loadMissing(['moodleInstances.server']);
        $settings = $this->settings($configuration);
        $options = $settings['options'];
        $instances = $project->moodleInstances;
        $sources = $instances->where('role', MoodleInstanceRole::SOURCE);
        $destinations = $instances->where('role', MoodleInstanceRole::DESTINATION);
        $errors = [];

        if (trim((string) $project->name) === '') {
            $errors[] = 'El proyecto necesita un nombre.';
        }

        if (! $this->hasValidCardinality($project->type, $sources->count(), $destinations->count())) {
            $errors[] = match ($project->type) {
                ProjectType::COLLECT => 'Recolectar exige exactamente una instancia origen y ninguna instancia destino.',
                ProjectType::CONSOLIDATE => 'Consolidar exige al menos dos instancias origen y exactamente una instancia destino.',
                ProjectType::INTEGRATE => 'Integrar exige exactamente una instancia origen y exactamente una instancia destino.',
            };
        }

        foreach ($instances as $instance) {
            if (
                trim((string) $instance->name) === ''
                || trim((string) $instance->base_url) === ''
                || trim((string) $instance->moodle_version) === ''
                || $instance->server === null
                || trim((string) $instance->server->name) === ''
                || trim((string) $instance->server->host) === ''
            ) {
                $errors[] = "La instancia {$instance->name} tiene datos simulados incompletos.";
            }

            if (! $instance->validated) {
                $errors[] = "La instancia {$instance->name} no está validada en la simulación.";
            }
        }

        $destination = $destinations->first();
        $destinationKind = is_array($destination?->metadata)
            ? ($destination->metadata['destination_kind'] ?? null)
            : null;

        if ($project->type === ProjectType::CONSOLIDATE && $destinationKind !== 'PREPARED') {
            $errors[] = 'El destino de una consolidación debe ser un Moodle preparado y validado.';
        }

        if ($project->type === ProjectType::INTEGRATE && $destinationKind !== 'EXISTING_CONSOLIDATED') {
            $errors[] = 'El destino de una integración debe ser un Moodle consolidado existente.';
        }

        if (! in_array($options['simulation_scenario'] ?? null, ['SUCCESS', 'WARNING', 'ERROR'], true)) {
            $errors[] = 'Seleccione un escenario de simulación.';
        }

        switch ($project->type) {
            case ProjectType::COLLECT:
                $this->requireOption($errors, $options, 'artifact_name', 'Indique el nombre del paquete estructurado.');
                break;
            case ProjectType::CONSOLIDATE:
                $this->validateConsolidationOptions($errors, $options);
                break;
            case ProjectType::INTEGRATE:
                $this->validateIntegrationOptions($errors, $options);
                break;
        }

        return array_values(array_unique($errors));
    }

    public function fingerprint(Project $project, ProjectConfiguration $configuration): string
    {
        $project->loadMissing(['moodleInstances.server']);
        $instances = $project->moodleInstances
            ->sortBy('uuid')
            ->map(fn (MoodleInstance $instance): array => [
                'uuid' => $instance->uuid,
                'role' => $instance->role->value,
                'name' => $instance->name,
                'base_url' => $instance->base_url,
                'moodle_version' => $instance->moodle_version,
                'validated' => $instance->validated,
                'metadata' => $instance->metadata,
                'server' => [
                    'uuid' => $instance->server?->uuid,
                    'name' => $instance->server?->name,
                    'role' => $instance->server?->role->value,
                    'host' => $instance->server?->host,
                    'metadata' => $instance->server?->metadata,
                ],
            ])
            ->values()
            ->all();

        $payload = [
            'project' => [
                'name' => $project->name,
                'description' => $project->description,
                'type' => $project->type->value,
            ],
            'instances' => $instances,
            'options' => $this->settings($configuration)['options'],
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  Collection<int, MoodleInstance>  $instances
     * @return array{id: string, description: string, result: string, detail: string}
     */
    private function cardinalityCheck(ProjectType $type, Collection $instances): array
    {
        $sources = $instances->where('role', MoodleInstanceRole::SOURCE)->count();
        $destinations = $instances->where('role', MoodleInstanceRole::DESTINATION)->count();
        $valid = $this->hasValidCardinality($type, $sources, $destinations);

        return $this->check(
            'instances.cardinality',
            'Cantidad de instancias',
            $valid ? PreflightResult::SUCCESS : PreflightResult::ERROR,
            $valid
                ? "La cardinalidad es válida: {$sources} origen(es) y {$destinations} destino(s)."
                : "La cardinalidad no corresponde a {$type->label()}: {$sources} origen(es) y {$destinations} destino(s).",
        );
    }

    /**
     * @param  Collection<int, MoodleInstance>  $instances
     * @return array{id: string, description: string, result: string, detail: string}
     */
    private function destinationCheck(ProjectType $type, Collection $instances): array
    {
        if ($type === ProjectType::COLLECT) {
            return $this->check(
                'destination.readiness',
                'Destino preparado o existente',
                PreflightResult::SUCCESS,
                'Recolectar no necesita una instancia Moodle destino.',
            );
        }

        $destination = $instances->firstWhere('role', MoodleInstanceRole::DESTINATION);
        $expectedKind = $type === ProjectType::CONSOLIDATE ? 'PREPARED' : 'EXISTING_CONSOLIDATED';
        $metadata = is_array($destination?->metadata) ? $destination->metadata : [];
        $valid = $destination !== null
            && $destination->validated
            && ($metadata['destination_kind'] ?? null) === $expectedKind;

        return $this->check(
            'destination.readiness',
            'Destino preparado o existente',
            $valid ? PreflightResult::SUCCESS : PreflightResult::ERROR,
            $valid
                ? 'La referencia simulada al destino cumple el estado requerido; la plataforma no intentará crearlo.'
                : 'El destino debe existir previamente, tener el tipo esperado y estar validado en la simulación.',
        );
    }

    /**
     * @param  Collection<int, MoodleInstance>  $instances
     */
    private function resourcesBelongToProject(Project $project, Collection $instances): bool
    {
        return $instances->every(fn (MoodleInstance $instance): bool => $instance->project_id === $project->getKey()
            && $instance->server !== null
            && $instance->server->project_id === $project->getKey());
    }

    private function hasValidCardinality(ProjectType $type, int $sources, int $destinations): bool
    {
        return match ($type) {
            ProjectType::COLLECT => $sources === 1 && $destinations === 0,
            ProjectType::CONSOLIDATE => $sources >= 2 && $destinations === 1,
            ProjectType::INTEGRATE => $sources === 1 && $destinations === 1,
        };
    }

    /** @return array{options: array<string, mixed>} */
    private function settings(ProjectConfiguration $configuration): array
    {
        $settings = is_array($configuration->settings) ? $configuration->settings : [];
        $options = $settings['options'] ?? [];

        return ['options' => is_array($options) ? $options : []];
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $options
     */
    private function requireOption(array &$errors, array $options, string $key, string $message): void
    {
        if (trim((string) ($options[$key] ?? '')) === '') {
            $errors[] = $message;
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $options
     */
    private function validateConsolidationOptions(array &$errors, array $options): void
    {
        if (! in_array($options['category_strategy'] ?? null, ['PRESERVE', 'PREFIX_SOURCE'], true)) {
            $errors[] = 'Seleccione la estrategia de categorías.';
        }

        if (! in_array($options['user_conflict_strategy'] ?? null, ['KEEP_DESTINATION', 'REVIEW'], true)) {
            $errors[] = 'Seleccione la estrategia para conflictos de usuarios.';
        }

        if (($options['admin_strategy'] ?? null) !== 'EXCLUDE_SOURCE_ADMINS') {
            $errors[] = 'Los administradores de origen deben excluirse del privilegio global en destino.';
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $options
     */
    private function validateIntegrationOptions(array &$errors, array $options): void
    {
        if (($options['conflict_strategy'] ?? null) !== 'REVIEW') {
            $errors[] = 'La integración debe enviar los conflictos a revisión.';
        }

        if (($options['preserve_destination_admins'] ?? null) !== true) {
            $errors[] = 'La integración debe preservar los administradores del destino consolidado.';
        }
    }

    /**
     * @return array{id: string, description: string, result: string, detail: string}
     */
    private function check(
        string $id,
        string $description,
        PreflightResult $result,
        string $detail,
    ): array {
        return [
            'id' => $id,
            'description' => $description,
            'result' => $result->value,
            'detail' => $detail,
        ];
    }
}
