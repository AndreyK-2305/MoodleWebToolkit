<?php

namespace App\Domain\Projects;

use App\Enums\MoodleInstanceRole;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\MoodleInstance;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectConfiguration;
use App\Models\Server;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectWizard
{
    public function __construct(private readonly SimulatedPreflight $preflight) {}

    /** @param array{name: string, type: string, description?: string|null} $data */
    public function create(User $actor, array $data): Project
    {
        if (! $actor->can('create', Project::class)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $data): Project {
            $project = Project::query()->create([
                'name' => trim($data['name']),
                'type' => ProjectType::from($data['type']),
                'status' => ProjectStatus::CONFIGURING,
                'description' => $this->nullableTrim($data['description'] ?? null),
                'created_by' => $actor->getKey(),
            ]);

            $project->configuration()->create([
                'version' => 1,
                'settings' => $this->initialSettings(),
            ]);

            if ($actor->role === UserRole::OPERATOR) {
                ProjectAssignment::query()->create([
                    'project_id' => $project->getKey(),
                    'user_id' => $actor->getKey(),
                    'assigned_by' => $actor->getKey(),
                ]);
            }

            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'project_id' => $project->getKey(),
                'action' => 'PROJECT_CREATED',
                'auditable_type' => Project::class,
                'auditable_id' => $project->getKey(),
                'payload' => ['type' => $project->type->value],
            ]);

            return $project->fresh(['configuration', 'assignments']) ?? $project;
        });
    }

    /** @param array{name: string, type: string, description?: string|null} $data */
    public function saveBasics(Project $project, User $actor, array $data): Project
    {
        return DB::transaction(function () use ($project, $actor, $data): Project {
            $locked = $this->lockedEditableProject($project, $actor);
            $configuration = $this->lockedConfiguration($locked);
            $type = ProjectType::from($data['type']);
            $attributes = [
                'name' => trim($data['name']),
                'type' => $type,
                'description' => $this->nullableTrim($data['description'] ?? null),
            ];
            $typeChanged = $locked->type !== $type;
            $changed = $typeChanged
                || $locked->name !== $attributes['name']
                || $locked->description !== $attributes['description'];

            if ($typeChanged) {
                $this->removeAllInstances($locked);
            }

            if ($changed) {
                $locked->fill($attributes);
                $this->markConfiguring($locked);
                $locked->save();
                $this->updateSettings($configuration, 2, true, $typeChanged);

                $this->audit($actor, $locked, 'PROJECT_BASICS_UPDATED', [
                    'configuration_version' => $configuration->version,
                    'type' => $locked->type->value,
                ]);
            } else {
                $this->updateSettings($configuration, 2, false);
            }

            return $locked->fresh(['configuration', 'moodleInstances.server']) ?? $locked;
        });
    }

    /**
     * @param list<array{
     *     uuid?: string|null,
     *     server_uuid?: string|null,
     *     role: string,
     *     server_name: string,
     *     server_host: string,
     *     name: string,
     *     base_url: string,
     *     moodle_version: string,
     *     validated: bool,
     *     destination_kind?: string|null
     * }> $instances
     */
    public function saveInstances(Project $project, User $actor, array $instances): Project
    {
        return DB::transaction(function () use ($project, $actor, $instances): Project {
            $locked = $this->lockedEditableProject($project, $actor);
            $configuration = $this->lockedConfiguration($locked);
            $normalized = array_map(fn (array $instance): array => $this->normalizeInstance($instance), $instances);

            $this->validateInstanceComposition($locked->type, $normalized);
            $this->synchronizeInstances($locked, $normalized);
            $this->markConfiguring($locked);
            $locked->save();
            $this->updateSettings($configuration, 3, true);

            $this->audit($actor, $locked, 'PROJECT_INSTANCES_UPDATED', [
                'configuration_version' => $configuration->version,
                'source_count' => collect($normalized)->where('role', MoodleInstanceRole::SOURCE->value)->count(),
                'destination_count' => collect($normalized)->where('role', MoodleInstanceRole::DESTINATION->value)->count(),
                'simulated' => true,
            ]);

            return $locked->fresh(['configuration', 'moodleInstances.server']) ?? $locked;
        });
    }

    /** @param array<string, mixed> $options */
    public function saveOptions(Project $project, User $actor, array $options): Project
    {
        return DB::transaction(function () use ($project, $actor, $options): Project {
            $locked = $this->lockedEditableProject($project, $actor);
            $configuration = $this->lockedConfiguration($locked);
            $settings = $this->settings($configuration);
            $normalized = $this->normalizeOptions($locked->type, $options);
            $changed = $settings['options'] !== $normalized;
            $settings['options'] = $normalized;

            if ($changed) {
                $this->markConfiguring($locked);
                $locked->save();
                $this->updateSettings($configuration, 4, true, settings: $settings);

                $this->audit($actor, $locked, 'PROJECT_CONFIGURATION_UPDATED', [
                    'configuration_version' => $configuration->version,
                    'simulation_scenario' => $normalized['simulation_scenario'] ?? null,
                ]);
            } else {
                $this->updateSettings($configuration, 4, false, settings: $settings);
            }

            return $locked->fresh(['configuration', 'moodleInstances.server']) ?? $locked;
        });
    }

    /** @return list<array{id: string, description: string, result: string, detail: string}> */
    public function runPreflight(Project $project, User $actor): array
    {
        return DB::transaction(function () use ($project, $actor): array {
            $locked = $this->lockedEditableProject($project, $actor);
            $configuration = $this->lockedConfiguration($locked);
            $locked->load(['moodleInstances.server']);
            $checks = $this->preflight->evaluate($locked, $configuration);
            $settings = $this->settings($configuration);
            $settings['wizard_step'] = 5;
            $settings['preflight'] = [
                'configuration_version' => $configuration->version,
                'configuration_hash' => $this->preflight->fingerprint($locked, $configuration),
                'checked_at' => now()->toIso8601String(),
                'checks' => $checks,
            ];
            if ($locked->status !== ProjectStatus::READY) {
                $settings['confirmation'] = null;
            }
            $configuration->settings = $settings;
            $configuration->save();

            $this->audit($actor, $locked, 'PROJECT_PREFLIGHT_COMPLETED', [
                'configuration_version' => $configuration->version,
                'results' => collect($checks)->countBy('result')->all(),
                'simulated' => true,
            ]);

            return $checks;
        });
    }

    /**
     * @param  list<string>  $acceptedWarningIds
     */
    public function confirm(
        Project $project,
        User $actor,
        int $configurationVersion,
        array $acceptedWarningIds,
    ): Project {
        return DB::transaction(function () use ($project, $actor, $configurationVersion, $acceptedWarningIds): Project {
            $locked = $this->lockedEditableProject($project, $actor);
            $configuration = $this->lockedConfiguration($locked);
            $locked->load(['moodleInstances.server']);
            $settings = $this->settings($configuration);
            $storedPreflight = $settings['preflight'];

            if ($configuration->version !== $configurationVersion) {
                throw ValidationException::withMessages([
                    'configuration_version' => 'La configuración cambió. Ejecute nuevamente el preflight antes de confirmar.',
                ]);
            }

            if (! is_array($storedPreflight)
                || ($storedPreflight['configuration_version'] ?? null) !== $configuration->version
                || ($storedPreflight['configuration_hash'] ?? null) !== $this->preflight->fingerprint($locked, $configuration)
            ) {
                throw ValidationException::withMessages([
                    'preflight' => 'El preflight no está vigente para esta configuración.',
                ]);
            }

            $checks = $storedPreflight['checks'] ?? [];

            if (! is_array($checks)) {
                throw ValidationException::withMessages(['preflight' => 'El resultado del preflight no es válido.']);
            }

            if ($this->preflight->configurationErrors($locked, $configuration) !== []) {
                throw ValidationException::withMessages([
                    'configuration' => 'La configuración está incompleta o dejó de ser válida.',
                ]);
            }

            $hasErrors = collect($checks)->contains(
                fn (mixed $check): bool => is_array($check) && ($check['result'] ?? null) === 'ERROR',
            );

            if ($hasErrors) {
                throw ValidationException::withMessages([
                    'preflight' => 'Los errores del preflight impiden confirmar la configuración.',
                ]);
            }

            $warningIds = collect($checks)
                ->filter(fn (mixed $check): bool => is_array($check) && ($check['result'] ?? null) === 'WARNING')
                ->map(fn (array $check): string => (string) $check['id'])
                ->sort()
                ->values()
                ->all();
            $accepted = collect($acceptedWarningIds)
                ->map(fn (mixed $id): string => (string) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($warningIds !== $accepted) {
                throw ValidationException::withMessages([
                    'accepted_warning_ids' => 'Debe aceptar explícitamente todas las advertencias vigentes y sólo esas advertencias.',
                ]);
            }

            if ($locked->status === ProjectStatus::READY) {
                return $locked;
            }

            if ($warningIds !== []) {
                $this->audit($actor, $locked, 'PROJECT_WARNINGS_ACCEPTED', [
                    'configuration_version' => $configuration->version,
                    'checks' => $warningIds,
                ]);
            }

            $this->audit($actor, $locked, 'PROJECT_CONFIGURATION_CONFIRMED', [
                'configuration_version' => $configuration->version,
                'accepted_warning_checks' => $warningIds,
            ]);

            $settings['wizard_step'] = 5;
            $settings['confirmation'] = [
                'configuration_version' => $configuration->version,
                'confirmed_at' => now()->toIso8601String(),
                'accepted_warning_ids' => $warningIds,
            ];
            $configuration->settings = $settings;
            $configuration->save();
            $locked->transitionTo(ProjectStatus::READY);

            return $locked->fresh(['configuration', 'moodleInstances.server']) ?? $locked;
        });
    }

    /** @return array<string, mixed> */
    public function settings(ProjectConfiguration $configuration): array
    {
        $settings = is_array($configuration->settings) ? $configuration->settings : [];

        return [
            'schema_version' => 1,
            'wizard_step' => max(1, min(5, (int) ($settings['wizard_step'] ?? 1))),
            'options' => is_array($settings['options'] ?? null) ? $settings['options'] : [],
            'preflight' => is_array($settings['preflight'] ?? null) ? $settings['preflight'] : null,
            'confirmation' => is_array($settings['confirmation'] ?? null) ? $settings['confirmation'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function initialSettings(): array
    {
        return [
            'schema_version' => 1,
            'wizard_step' => 2,
            'options' => [],
            'preflight' => null,
            'confirmation' => null,
        ];
    }

    private function lockedEditableProject(Project $project, User $actor): Project
    {
        $locked = Project::query()->lockForUpdate()->findOrFail((int) $project->getKey());

        if (! $actor->can('update', $locked)) {
            throw new AuthorizationException;
        }

        if (! $actor->isAdmin()) {
            $assigned = ProjectAssignment::query()
                ->where('project_id', $locked->getKey())
                ->where('user_id', $actor->getKey())
                ->lockForUpdate()
                ->exists();

            if (! $assigned) {
                throw new AuthorizationException;
            }
        }

        if (! in_array($locked->status, [ProjectStatus::DRAFT, ProjectStatus::CONFIGURING, ProjectStatus::READY], true)) {
            throw ValidationException::withMessages([
                'project' => 'El estado actual del proyecto no permite modificar ni confirmar el wizard.',
            ]);
        }

        return $locked;
    }

    private function lockedConfiguration(Project $project): ProjectConfiguration
    {
        $configuration = ProjectConfiguration::query()
            ->where('project_id', $project->getKey())
            ->lockForUpdate()
            ->first();

        if ($configuration === null) {
            $configuration = ProjectConfiguration::query()->create([
                'project_id' => $project->getKey(),
                'version' => 1,
                'settings' => $this->initialSettings(),
            ]);
        }

        return $configuration;
    }

    private function markConfiguring(Project $project): void
    {
        if ($project->status !== ProjectStatus::CONFIGURING) {
            $project->status = ProjectStatus::CONFIGURING;
        }
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    private function updateSettings(
        ProjectConfiguration $configuration,
        int $wizardStep,
        bool $relevantChange,
        bool $clearOptions = false,
        ?array $settings = null,
    ): void {
        $settings ??= $this->settings($configuration);
        $settings['wizard_step'] = $wizardStep;

        if ($relevantChange) {
            $settings['preflight'] = null;
            $settings['confirmation'] = null;
            $configuration->version++;
        }

        if ($clearOptions) {
            $settings['options'] = [];
        }

        $configuration->settings = $settings;
        $configuration->save();
    }

    /**
     * @param  array<string, mixed>  $instance
     * @return array<string, mixed>
     */
    private function normalizeInstance(array $instance): array
    {
        return [
            'uuid' => $this->nullableTrim($instance['uuid'] ?? null),
            'server_uuid' => $this->nullableTrim($instance['server_uuid'] ?? null),
            'role' => (string) $instance['role'],
            'server_name' => trim((string) $instance['server_name']),
            'server_host' => mb_strtolower(trim((string) $instance['server_host'])),
            'name' => trim((string) $instance['name']),
            'base_url' => rtrim(trim((string) $instance['base_url']), '/'),
            'moodle_version' => trim((string) $instance['moodle_version']),
            'validated' => (bool) $instance['validated'],
            'destination_kind' => $this->nullableTrim($instance['destination_kind'] ?? null),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $instances
     */
    private function validateInstanceComposition(ProjectType $type, array $instances): void
    {
        $sources = collect($instances)->where('role', MoodleInstanceRole::SOURCE->value);
        $destinations = collect($instances)->where('role', MoodleInstanceRole::DESTINATION->value);
        $messages = [];

        if ($type === ProjectType::COLLECT && ($sources->count() > 1 || $destinations->isNotEmpty())) {
            $messages['instances'] = 'Recolectar admite como máximo una instancia origen y no admite destino.';
        }

        if ($type === ProjectType::CONSOLIDATE && $destinations->count() > 1) {
            $messages['instances'] = 'Consolidar admite una sola instancia destino.';
        }

        if ($type === ProjectType::INTEGRATE && ($sources->count() > 1 || $destinations->count() > 1)) {
            $messages['instances'] = 'Integrar admite como máximo una instancia origen y una instancia destino.';
        }

        foreach ($instances as $index => $instance) {
            $role = MoodleInstanceRole::tryFrom((string) $instance['role']);

            if ($role === null) {
                $messages["instances.{$index}.role"] = 'El rol de la instancia no es válido.';

                continue;
            }

            if ($role === MoodleInstanceRole::SOURCE && $instance['destination_kind'] !== null) {
                $messages["instances.{$index}.destination_kind"] = 'Una instancia origen no puede declarar un tipo de destino.';
            }

            if ($role === MoodleInstanceRole::DESTINATION) {
                $expected = $type === ProjectType::CONSOLIDATE
                    ? 'PREPARED'
                    : ($type === ProjectType::INTEGRATE ? 'EXISTING_CONSOLIDATED' : null);

                if ($expected === null || $instance['destination_kind'] !== $expected) {
                    $messages["instances.{$index}.destination_kind"] = $type === ProjectType::CONSOLIDATE
                        ? 'La consolidación requiere un destino preparado.'
                        : 'La integración requiere un Moodle consolidado existente.';
                }
            }

            if (($instance['uuid'] === null) !== ($instance['server_uuid'] === null)) {
                $messages["instances.{$index}.uuid"] = 'Las referencias de instancia y servidor deben enviarse juntas.';
            }
        }

        foreach (['name', 'server_name', 'server_host', 'base_url'] as $field) {
            $values = collect($instances)->map(fn (array $instance): string => mb_strtolower((string) $instance[$field]));

            if ($values->duplicates()->isNotEmpty()) {
                $messages['instances'] = 'Los nombres, hosts y URL simulados deben ser únicos dentro del proyecto.';
            }
        }

        $referencedUuids = collect($instances)->pluck('uuid')->filter();

        if ($referencedUuids->duplicates()->isNotEmpty()) {
            $messages['instances'] = 'Una instancia simulada no puede aparecer más de una vez.';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $instances
     */
    private function synchronizeInstances(Project $project, array $instances): void
    {
        $existing = MoodleInstance::query()
            ->with('server')
            ->where('project_id', $project->getKey())
            ->lockForUpdate()
            ->get()
            ->keyBy('uuid');
        $keptInstanceIds = [];
        $keptServerIds = [];

        foreach ($instances as $index => $data) {
            $instance = null;
            $server = null;

            if ($data['uuid'] !== null) {
                $instance = MoodleInstance::query()
                    ->with('server')
                    ->where('uuid', $data['uuid'])
                    ->lockForUpdate()
                    ->first();

                if ($instance === null) {
                    throw ValidationException::withMessages([
                        "instances.{$index}.uuid" => 'La instancia simulada indicada ya no existe.',
                    ]);
                }

                if ($instance->project_id !== $project->getKey()) {
                    throw ValidationException::withMessages([
                        "instances.{$index}.uuid" => 'La instancia simulada pertenece a otro proyecto.',
                    ]);
                }

                $server = Server::query()->where('uuid', $data['server_uuid'])->lockForUpdate()->first();

                if ($server === null || $server->project_id !== $project->getKey() || $instance->server_id !== $server->getKey()) {
                    throw ValidationException::withMessages([
                        "instances.{$index}.server_uuid" => 'El servidor simulado no pertenece a esta instancia y proyecto.',
                    ]);
                }
            }

            $role = MoodleInstanceRole::from((string) $data['role']);
            $serverRole = $role === MoodleInstanceRole::SOURCE ? ServerRole::SOURCE : ServerRole::DESTINATION;
            $serverAttributes = [
                'name' => $data['server_name'],
                'role' => $serverRole,
                'host' => $data['server_host'],
                'port' => null,
                'metadata' => [
                    'simulated' => true,
                    'access_mode' => $role === MoodleInstanceRole::SOURCE ? 'READ_ONLY' : 'REFERENCE_ONLY',
                    'destination_kind' => $data['destination_kind'],
                ],
            ];

            if ($server === null) {
                $server = $project->servers()->create($serverAttributes);
            } else {
                $server->update($serverAttributes);
            }

            $instanceAttributes = [
                'name' => $data['name'],
                'role' => $role,
                'base_url' => $data['base_url'],
                'moodle_version' => $data['moodle_version'],
                'database_name' => null,
                'validated' => $data['validated'],
                'metadata' => [
                    'simulated' => true,
                    'destination_kind' => $data['destination_kind'],
                ],
            ];

            if ($instance === null) {
                $instance = $project->moodleInstances()->create([
                    ...$instanceAttributes,
                    'server_id' => $server->getKey(),
                ]);
            } else {
                $instance->update($instanceAttributes);
            }

            $keptInstanceIds[] = (int) $instance->getKey();
            $keptServerIds[] = (int) $server->getKey();
        }

        $removed = $existing->reject(fn (MoodleInstance $instance): bool => in_array($instance->getKey(), $keptInstanceIds, true));

        foreach ($removed as $instance) {
            $server = $instance->server;
            $instance->delete();

            if ($server !== null && ! in_array($server->getKey(), $keptServerIds, true)) {
                $server->delete();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function normalizeOptions(ProjectType $type, array $options): array
    {
        $normalized = [
            'simulation_scenario' => $this->nullableTrim($options['simulation_scenario'] ?? null),
        ];

        return match ($type) {
            ProjectType::COLLECT => [
                ...$normalized,
                'artifact_name' => $this->nullableTrim($options['artifact_name'] ?? null),
            ],
            ProjectType::CONSOLIDATE => [
                ...$normalized,
                'category_strategy' => $this->nullableTrim($options['category_strategy'] ?? null),
                'user_conflict_strategy' => $this->nullableTrim($options['user_conflict_strategy'] ?? null),
                'admin_strategy' => $this->nullableTrim($options['admin_strategy'] ?? null),
                'include_archived_courses' => (bool) ($options['include_archived_courses'] ?? false),
            ],
            ProjectType::INTEGRATE => [
                ...$normalized,
                'conflict_strategy' => $this->nullableTrim($options['conflict_strategy'] ?? null),
                'preserve_destination_admins' => (bool) ($options['preserve_destination_admins'] ?? false),
            ],
        };
    }

    private function removeAllInstances(Project $project): void
    {
        $project->load('moodleInstances.server');

        foreach ($project->moodleInstances as $instance) {
            $server = $instance->server;
            $instance->delete();
            $server?->delete();
        }
    }

    /** @param array<string, mixed> $payload */
    private function audit(User $actor, Project $project, string $action, array $payload): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->getKey(),
            'project_id' => $project->getKey(),
            'action' => $action,
            'auditable_type' => Project::class,
            'auditable_id' => $project->getKey(),
            'payload' => $payload,
        ]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
