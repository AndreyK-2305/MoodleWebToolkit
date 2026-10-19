<?php

namespace App\Domain\Collector;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\AuditLog;
use App\Models\CollectorConfigurationRevision;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectConfiguration;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * @phpstan-type CollectorSettings array{
 *   schema_version: string, mode: string, profile_id: string, profile_fingerprint: string,
 *   workers: int, package_name: string, distribution_key: string, producer_version: string,
 *   capacity_bytes: int, safety_margin_percent: int
 * }
 */
final class CollectorConfiguration
{
    public const SCHEMA = 'collector-lab.v1';

    public function __construct(private readonly LabMoodleProfiles $profiles) {}

    public function selected(?ProjectConfiguration $configuration): bool
    {
        return ($configuration?->settings['options']['mode'] ?? null) === 'LABORATORY';
    }

    /** @param array<string, mixed> $input */
    public function save(Project $project, User $actor, array $input): Project
    {
        $options = $this->normalize($input);

        return DB::transaction(function () use ($project, $actor, $options): Project {
            $locked = Project::query()->lockForUpdate()->findOrFail((int) $project->getKey());
            if (! $actor->can('update', $locked) || (! $actor->isAdmin() && ! ProjectAssignment::query()
                ->where('project_id', $locked->getKey())->where('user_id', $actor->getKey())->lockForUpdate()->exists())) {
                throw new AuthorizationException;
            }
            if ($locked->type !== ProjectType::COLLECT || ! in_array($locked->status, [ProjectStatus::DRAFT, ProjectStatus::CONFIGURING, ProjectStatus::READY], true)) {
                throw ValidationException::withMessages(['project' => 'El proyecto no permite configurar COLLECT LAB en su estado actual.']);
            }
            $configuration = ProjectConfiguration::query()->where('project_id', $locked->getKey())->lockForUpdate()->firstOrFail();
            $settings = $configuration->settings ?? [];
            if (is_array($settings['options'] ?? null) && hash_equals($this->hash($settings['options']), $this->hash($options))) {
                return $locked->load('configuration');
            }
            $configuration->version++;
            $configuration->settings = [...$settings, 'schema_version' => 1, 'wizard_step' => 4, 'options' => $options, 'preflight' => null, 'confirmation' => null];
            $configuration->save();
            $locked->status = ProjectStatus::CONFIGURING;
            $locked->save();
            $fingerprint = $this->hash($options);
            CollectorConfigurationRevision::query()->create([
                'project_id' => $locked->getKey(), 'configuration_version' => $configuration->version,
                'schema_version' => self::SCHEMA, 'snapshot' => $options, 'fingerprint' => $fingerprint,
                'created_by' => $actor->getKey(), 'created_at' => now()->utc(),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->getKey(), 'project_id' => $locked->getKey(), 'action' => 'COLLECTOR_CONFIGURATION_VERSIONED',
                'auditable_type' => $locked->getMorphClass(), 'auditable_id' => $locked->getKey(),
                'payload' => ['configuration_version' => $configuration->version, 'fingerprint' => $fingerprint, 'mode' => 'LABORATORY'],
            ]);

            return $locked->load('configuration');
        }, attempts: 3);
    }

    /** @return CollectorSettings */
    public function settings(ProjectConfiguration $configuration): array
    {
        $options = $configuration->settings['options'] ?? null;
        if (! is_array($options) || ($options['schema_version'] ?? null) !== self::SCHEMA
            || ($options['mode'] ?? null) !== 'LABORATORY') {
            throw new RuntimeException('La configuración COLLECT LAB está incompleta.');
        }
        $normalized = $this->normalize(array_intersect_key($options, array_flip(['profile_id', 'workers', 'package_name', 'capacity_bytes', 'safety_margin_percent'])));
        if (! hash_equals($this->hash($normalized), $this->hash($options))) {
            throw new RuntimeException('El perfil o esquema COLLECT LAB cambió; se requiere una nueva revisión.');
        }
        $revision = CollectorConfigurationRevision::query()->where('project_id', $configuration->project_id)
            ->where('configuration_version', $configuration->version)->first();
        if ($revision === null || ! hash_equals($this->hash($revision->snapshot), $this->hash($options)) || ! hash_equals($revision->fingerprint, $this->hash($options))) {
            throw new RuntimeException('La configuración no coincide con su revisión inmutable.');
        }

        return $normalized;
    }

    /** @param array<string, mixed> $input
     * @return CollectorSettings
     */
    private function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['profile_id', 'workers', 'package_name', 'capacity_bytes', 'safety_margin_percent']) !== []
            || ! is_string($input['profile_id'] ?? null) || ! is_int($input['workers'] ?? null)
            || $input['workers'] < 1 || $input['workers'] > 4 || ! is_string($input['package_name'] ?? null)
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D', $input['package_name']) !== 1
            || ! is_int($input['capacity_bytes'] ?? null) || $input['capacity_bytes'] < 16_777_216 || $input['capacity_bytes'] > 21_474_836_480
            || ! is_int($input['safety_margin_percent'] ?? null) || $input['safety_margin_percent'] < 10 || $input['safety_margin_percent'] > 100
        ) {
            throw ValidationException::withMessages(['collector' => 'Los parámetros LAB son inválidos; solo se aceptan perfil autorizado, workers, nombre y capacidad.']);
        }

        return [
            'schema_version' => self::SCHEMA, 'mode' => 'LABORATORY', 'profile_id' => $input['profile_id'],
            'profile_fingerprint' => $this->profiles->fingerprint($input['profile_id']), 'workers' => $input['workers'],
            'package_name' => $input['package_name'], 'distribution_key' => 'moodle-recolector-7.4.2-linux-tree',
            'producer_version' => '7.4.2-linux', 'capacity_bytes' => $input['capacity_bytes'], 'safety_margin_percent' => $input['safety_margin_percent'],
        ];
    }

    /** @param array<string, mixed> $options */
    public function hash(array $options): string
    {
        ksort($options, SORT_STRING);

        return hash('sha256', json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
