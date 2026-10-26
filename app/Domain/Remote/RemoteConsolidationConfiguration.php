<?php

namespace App\Domain\Remote;

use App\Domain\Collector\CollectorConfiguration;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\ServerRole;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\Server;
use App\Models\SourcePackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * CONSOLIDATE configuration that runs the Consolidator V8 on the project's
 * destination server through its agent. It stores identifiers only: the
 * destination server and its administrator-owned profile, the sealed source
 * packages (by uuid and SHA-256) and the restore workers. The packages may
 * come from other projects; who configures must be able to view them.
 *
 * When the sources come from one completed COLLECT project, its uuid is kept
 * as `source_project_uuid` and every package must belong to it. Without it
 * (the «Avanzado» list) the key is absent, so earlier revisions hash the same.
 *
 * @phpstan-type ConsolidationPackage array{uuid: string, sha256: string, source_id: string, name: string}
 * @phpstan-type ConsolidationSettings array{
 *   schema_version: string, mode: string, server_uuid: string, destination_profile: string,
 *   source_packages: list<ConsolidationPackage>, workers: string, distribution_key: string, workflow_key: string,
 *   source_project_uuid?: string
 * }
 */
final class RemoteConsolidationConfiguration
{
    public const SCHEMA = 'consolidator-remote.v1';

    public const MODE = 'REMOTE_CONSOLIDATION';

    public const WORKFLOW = 'moodle.consolidation.v8';

    public const MIN_PACKAGES = 2;

    public const MAX_PACKAGES = 32;

    public const INPUT = ['server_uuid', 'destination_profile', 'source_packages', 'workers', 'source_project'];

    public function __construct(private readonly CollectorConfiguration $revisions) {}

    public function selected(?ProjectConfiguration $configuration): bool
    {
        return ($configuration?->settings['options']['mode'] ?? null) === self::MODE;
    }

    /** @param array<string, mixed> $input */
    public function save(Project $project, User $actor, array $input): Project
    {
        $options = $this->normalize($project, $input);
        foreach ($options['source_packages'] as $package) {
            $model = SourcePackage::query()->with('project')->where('uuid', $package['uuid'])->firstOrFail();
            if ($model->project === null || ! $actor->can('view', $model->project)) {
                throw ValidationException::withMessages(['consolidation' => 'Uno de los paquetes pertenece a un proyecto que no puede ver.']);
            }
        }
        $source = isset($options['source_project_uuid']) ? Project::query()->where('uuid', $options['source_project_uuid'])->first() : null;
        if ($source !== null && (! $actor->can('view', $source) || $source->status !== ProjectStatus::COMPLETED)) {
            throw ValidationException::withMessages(['consolidation' => 'La recolección elegida debe estar completada y usted debe poder verla.']);
        }

        return DB::transaction(function () use ($project, $actor, $options, $source): Project {
            $saved = $this->revisions->persist($project, $actor, $options);
            // A choice made here replaces a pending «Recolectar ahora» unless it is that same collection.
            $locked = Project::query()->lockForUpdate()->findOrFail((int) $project->getKey());
            $keepLink = $source !== null && $locked->source_project_id === $source->getKey() && is_array($locked->source_link);
            $locked->forceFill(['source_project_id' => $source?->getKey(), 'source_link' => $keepLink ? $locked->source_link : null])->save();

            return $saved->refresh()->load('configuration');
        });
    }

    /** @return ConsolidationSettings */
    public function settings(ProjectConfiguration $configuration): array
    {
        $options = $configuration->settings['options'] ?? null;
        if (! is_array($options) || ($options['mode'] ?? null) !== self::MODE || ($options['schema_version'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('La configuración de consolidación remota está incompleta.');
        }
        $project = Project::query()->findOrFail($configuration->project_id);
        try {
            $normalized = $this->normalize($project, [
                'server_uuid' => $options['server_uuid'] ?? null, 'destination_profile' => $options['destination_profile'] ?? null,
                'source_packages' => array_column(is_array($options['source_packages'] ?? null) ? $options['source_packages'] : [], 'uuid'),
                'workers' => $options['workers'] ?? null,
                ...(isset($options['source_project_uuid']) ? ['source_project' => $options['source_project_uuid']] : []),
            ]);
        } catch (ValidationException) {
            throw new RuntimeException('El destino o un paquete de la consolidación dejó de ser válido.');
        }
        if (! hash_equals($this->revisions->hash($normalized), $this->revisions->hash($options))) {
            throw new RuntimeException('Un paquete cambió después de configurarse; se requiere una nueva revisión.');
        }
        $this->revisions->assertRevision($configuration, $options);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return ConsolidationSettings
     */
    private function normalize(Project $project, array $input): array
    {
        $server = is_string($input['server_uuid'] ?? null) ? $project->servers()->where('uuid', $input['server_uuid'])->first() : null;
        $uuids = $input['source_packages'] ?? null;
        $workers = $input['workers'] ?? null;
        $workers = is_int($workers) ? (string) $workers : $workers;
        if (array_diff(array_keys($input), self::INPUT) !== [] || ! $server instanceof Server || $server->role !== ServerRole::DESTINATION
            || ! is_string($input['destination_profile'] ?? null) || preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/D', $input['destination_profile']) !== 1
            || ! is_string($workers) || preg_match('/^(auto|[1-4])$/D', $workers) !== 1
            || ! is_array($uuids) || ! array_is_list($uuids) || count($uuids) < self::MIN_PACKAGES || count($uuids) > self::MAX_PACKAGES
            || count(array_unique($uuids, SORT_REGULAR)) !== count($uuids)) {
            throw ValidationException::withMessages(['consolidation' => 'La consolidación remota requiere el servidor de destino del proyecto, un perfil, de '
                .self::MIN_PACKAGES.' a '.self::MAX_PACKAGES.' paquetes distintos y workers auto o de 1 a 4.']);
        }
        $source = null;
        if (($input['source_project'] ?? null) !== null) {
            $source = is_string($input['source_project']) ? Project::query()->where('uuid', $input['source_project'])->first() : null;
            if ($source === null || $source->type !== ProjectType::COLLECT) {
                throw ValidationException::withMessages(['consolidation' => 'Los orígenes deben salir de un proyecto de recolección.']);
            }
        }
        $packages = [];
        foreach ($uuids as $uuid) {
            $package = is_string($uuid) ? SourcePackage::query()->with('artifact')->where('uuid', $uuid)->first() : null;
            if ($package === null || $package->validation_state !== 'VALID' || $package->availability !== 'AVAILABLE' || $package->revoked_at !== null
                || $package->artifact?->storage_mode !== 'REFERENCE' || $package->artifact->disk !== 's3') {
                throw ValidationException::withMessages(['consolidation' => 'Cada paquete debe ser un paquete sellado vigente guardado en S3.']);
            }
            if ($source !== null && $package->project_id !== $source->getKey()) {
                throw ValidationException::withMessages(['consolidation' => 'Cada paquete debe pertenecer a la recolección elegida.']);
            }
            $packages[] = ['uuid' => $package->uuid, 'sha256' => $package->sha256, 'source_id' => $package->source_id, 'name' => $package->name];
        }
        // The Consolidator requires one package per source: a source_id cannot repeat.
        if (count(array_unique(array_column($packages, 'source_id'))) !== count($packages) || count(array_unique(array_column($packages, 'sha256'))) !== count($packages)) {
            throw ValidationException::withMessages(['consolidation' => 'Cada paquete debe corresponder a un origen distinto.']);
        }
        usort($packages, fn (array $left, array $right): int => strcmp($left['uuid'], $right['uuid']));

        return [
            'schema_version' => self::SCHEMA, 'mode' => self::MODE, 'server_uuid' => $server->uuid,
            'destination_profile' => $input['destination_profile'], 'source_packages' => $packages, 'workers' => $workers,
            'distribution_key' => CheckRemoteDestination::DISTRIBUTION_KEY, 'workflow_key' => self::WORKFLOW,
            ...($source === null ? [] : ['source_project_uuid' => $source->uuid]),
        ];
    }
}
