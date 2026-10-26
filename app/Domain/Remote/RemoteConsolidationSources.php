<?php

namespace App\Domain\Remote;

use App\Domain\Projects\ProjectWizard;
use App\Enums\ConnectionType;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\ServerRole;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\SourcePackage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Where the sources of a remote consolidation come from. Either an already
 * completed COLLECT project («De un proyecto de recolección completado»), or
 * a COLLECT project created for it and linked to it («Recolectar ahora»).
 *
 * The linked collection is an ordinary project with its own execution: this
 * class only creates it and, when that execution completes, saves its newest
 * packages as the consolidation's configuration. Collection and consolidation
 * keep separate life cycles.
 *
 * The link lives on the consolidation: `source_project_id` and, for
 * «Recolectar ahora», `source_link` with the consolidation choices made while
 * the collection runs and the outcome of the pick-up.
 */
class RemoteConsolidationSources
{
    public const MODE_COLLECT_NOW = 'COLLECT_NOW';

    public const MIN_SOURCES = RemoteConsolidationConfiguration::MIN_PACKAGES;

    public const MAX_SOURCES = RemoteCollectionConfiguration::MAX_SOURCES;

    public function __construct(
        private readonly ProjectWizard $wizard,
        private readonly RemoteServerConnections $connections,
        private readonly RemoteCollectionConfiguration $collections,
        private readonly RemoteConsolidationConfiguration $consolidations,
        private readonly RemoteConsolidationCopias $copias,
    ) {}

    /**
     * The packages a collection offers: per source the newest sealed package in S3, and how many older ones exist.
     *
     * @return list<array{uuid: string, name: string, source_id: string, sha256: string, size: int, created_at: string|null, older: int}>
     */
    public function packagesOf(Project $collection): array
    {
        $bySource = $this->eligible($collection)->groupBy('source_id');
        $packages = [];
        foreach ($bySource as $sourceId => $group) {
            /** @var SourcePackage $newest */
            $newest = $group->first();
            $packages[] = ['uuid' => $newest->uuid, 'name' => $newest->name, 'source_id' => (string) $sourceId, 'sha256' => $newest->sha256,
                'size' => $newest->size_bytes, 'created_at' => $newest->created_at?->toIso8601String(), 'older' => $group->count() - 1];
        }
        usort($packages, fn (array $left, array $right): int => strcmp($left['source_id'], $right['source_id']));

        return $packages;
    }

    /**
     * Completed COLLECT projects the viewer may consolidate from, with their packages.
     *
     * @return list<array<string, mixed>>
     */
    public function completedCollections(User $viewer): array
    {
        return array_values(Project::query()->visibleTo($viewer)->where('type', ProjectType::COLLECT->value)
            ->where('status', ProjectStatus::COMPLETED->value)->latest('updated_at')->limit(50)->get()
            ->filter(fn (Project $project): bool => Gate::forUser($viewer)->allows('view', $project))
            ->map(fn (Project $project): array => ['uuid' => $project->uuid, 'name' => $project->name,
                'completed_at' => $project->executions()->where('status', 'COMPLETED')->latest('finished_at')->value('finished_at')?->toIso8601String(),
                'packages' => $this->packagesOf($project)])
            ->filter(fn (array $project): bool => $project['packages'] !== [])
            ->values()->all());
    }

    /**
     * «Recolectar ahora»: creates the COLLECT project for these sources, with the
     * consolidation's owner and assignments, and links it to the consolidation.
     *
     * @param  array{server_uuid: string, destination_profile: string, workers: string, collection_workers: int,
     *   sources: list<array{name: string, instance_id: string, region: string, source_profile: string, package_name: string}>}  $input
     */
    public function createLinkedCollection(Project $consolidation, User $actor, array $input): Project
    {
        return DB::transaction(function () use ($consolidation, $actor, $input): Project {
            $locked = Project::query()->lockForUpdate()->findOrFail((int) $consolidation->getKey());
            if (! $actor->can('update', $locked) || ! $actor->can('create', Project::class) || (! $actor->isAdmin() && ! ProjectAssignment::query()
                ->where('project_id', $locked->getKey())->where('user_id', $actor->getKey())->exists())) {
                throw new AuthorizationException;
            }
            if ($locked->type !== ProjectType::CONSOLIDATE || ! in_array($locked->status, [ProjectStatus::DRAFT, ProjectStatus::CONFIGURING, ProjectStatus::READY], true)) {
                throw ValidationException::withMessages(['linked_collection' => 'La consolidación no admite cambios en su estado actual.']);
            }
            if (is_array($locked->source_link) && $locked->sourceProject !== null && ! $this->abandoned($locked->sourceProject)) {
                throw ValidationException::withMessages(['linked_collection' => 'Esta consolidación ya tiene una recolección vinculada en curso.']);
            }
            $destination = $locked->servers()->where('uuid', $input['server_uuid'])->first();
            if ($destination === null || $destination->role !== ServerRole::DESTINATION) {
                throw ValidationException::withMessages(['server_uuid' => 'Elija el servidor de destino de esta consolidación.']);
            }

            $collection = $this->wizard->create($actor, ['name' => mb_substr($locked->name.' · Recolección', 0, 160),
                'type' => ProjectType::COLLECT->value, 'description' => 'Recolección creada para la consolidación «'.$locked->name.'».']);
            // Same people as the consolidation: whoever may see one sees the other.
            foreach ($locked->assignments()->get() as $assignment) {
                ProjectAssignment::query()->firstOrCreate(['project_id' => $collection->getKey(), 'user_id' => $assignment->user_id],
                    ['assigned_by' => $actor->getKey()]);
            }
            $sources = [];
            foreach ($input['sources'] as $index => $source) {
                $server = $collection->servers()->create(['name' => $source['name'], 'role' => ServerRole::SOURCE, 'host' => null]);
                try {
                    $this->connections->configure($server, ConnectionType::AWS_SSM,
                        ['instance_id' => $source['instance_id'], 'region' => $source['region']], null, $actor);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())
                        ->mapWithKeys(fn (array $messages, string $key): array => ["sources.{$index}.{$key}" => $messages])->all());
                }
                $sources[] = ['server_uuid' => $server->uuid, 'source_profile' => $source['source_profile'], 'package_name' => $source['package_name']];
            }
            try {
                $this->collections->save($collection, $actor, ['sources' => $sources, 'workers' => $input['collection_workers']]);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['sources' => collect($exception->errors())->flatten()->all()]);
            }

            $locked->forceFill(['source_project_id' => $collection->getKey(), 'source_link' => [
                'mode' => self::MODE_COLLECT_NOW, 'requested_by' => $actor->getKey(), 'requested_at' => now()->utc()->toIso8601String(),
                'server_uuid' => $destination->uuid, 'destination_profile' => $input['destination_profile'], 'workers' => $input['workers'],
                'sources' => count($sources), 'picked_up_at' => null, 'pickup_error' => null,
            ]])->save();
            AuditLog::query()->create(['actor_id' => $actor->getKey(), 'project_id' => $locked->getKey(), 'action' => 'CONSOLIDATION_LINKED_COLLECTION_CREATED',
                'auditable_type' => $locked->getMorphClass(), 'auditable_id' => $locked->getKey(),
                'payload' => ['collection_project_uuid' => $collection->uuid, 'sources' => count($sources)]]);

            return $collection->refresh();
        }, attempts: 3);
    }

    /**
     * Called when an execution of a COLLECT project completes: every consolidation
     * waiting on it takes its newest packages. Running it again changes nothing.
     */
    public function pickUp(Project $collection): void
    {
        if ($collection->type !== ProjectType::COLLECT) {
            return;
        }
        Project::query()->where('source_project_id', $collection->getKey())->where('type', ProjectType::CONSOLIDATE->value)
            ->whereNotNull('source_link')->get()
            ->each(fn (Project $consolidation) => $this->pickUpFor($consolidation, $collection));
    }

    /**
     * What the consolidation page shows about the origin of its sources.
     *
     * @return array<string, mixed>|null
     */
    public function presentLink(Project $consolidation): ?array
    {
        $source = $consolidation->sourceProject;
        if ($consolidation->type !== ProjectType::CONSOLIDATE || $source === null) {
            return null;
        }
        $link = is_array($consolidation->source_link) ? $consolidation->source_link : null;
        $total = is_int($link['sources'] ?? null) ? $link['sources'] : count($this->collectionSources($source));
        $collected = count($this->packagesOf($source));
        $execution = $source->executions()->latest('attempt')->first();
        $pickedUp = is_string($link['picked_up_at'] ?? null);
        $waiting = match (true) {
            $link === null => null,
            is_string($link['pickup_error'] ?? null) => 'No se pudieron tomar los paquetes de la recolección: '.$link['pickup_error'],
            $pickedUp => null,
            $execution === null => 'Esperando a que se inicie la recolección vinculada.',
            $source->status === ProjectStatus::COMPLETED => 'La recolección terminó; tomando sus paquetes.',
            in_array($execution->status->value, ['FAILED', 'CANCELLED'], true) => 'La recolección vinculada terminó sin completarse; reintente desde su página.',
            $execution->status->value === 'REVIEW' => 'La recolección espera su revisión y cierre para entregar los paquetes.',
            default => 'Esperando a que la recolección vinculada termine ('.$collected.' de '.$total.' orígenes con paquete).',
        };

        return [
            'mode' => $link === null ? 'PROJECT' : ($link['mode'] ?? self::MODE_COLLECT_NOW),
            'project' => ['uuid' => $source->uuid, 'name' => $source->name, 'status' => $source->status->value, 'status_label' => $source->status->label()],
            'execution' => $execution === null ? null : ['uuid' => $execution->uuid, 'attempt' => $execution->attempt, 'status' => $execution->status->value],
            'sources_total' => $total,
            'sources_collected' => $collected,
            'picked_up_at' => $pickedUp ? $link['picked_up_at'] : null,
            'pickup_error' => is_string($link['pickup_error'] ?? null) ? $link['pickup_error'] : null,
            'waiting' => $waiting,
            'destination_profile' => is_string($link['destination_profile'] ?? null) ? $link['destination_profile'] : null,
        ];
    }

    private function pickUpFor(Project $consolidation, Project $collection): void
    {
        $link = $consolidation->source_link;
        if (! is_array($link) || ($link['mode'] ?? null) !== self::MODE_COLLECT_NOW || is_string($link['picked_up_at'] ?? null)) {
            return;
        }
        $actor = is_int($link['requested_by'] ?? null) ? User::query()->find($link['requested_by']) : null;
        $error = null;
        try {
            if (! $actor instanceof User || ! $actor->is_active) {
                throw ValidationException::withMessages(['consolidation' => 'quien pidió la recolección ya no está activo.']);
            }
            if ($collection->status !== ProjectStatus::COMPLETED) {
                return;
            }
            $this->consolidations->save($consolidation, $actor, ['server_uuid' => $link['server_uuid'] ?? null,
                'destination_profile' => $link['destination_profile'] ?? null, 'workers' => $link['workers'] ?? null,
                'source_packages' => array_column($this->packagesOf($collection), 'uuid'), 'source_project' => $collection->uuid]);
        } catch (ValidationException $exception) {
            $error = (string) collect($exception->errors())->flatten()->first();
        } catch (AuthorizationException) {
            $error = 'quien pidió la recolección ya no puede modificar esta consolidación.';
        }
        DB::transaction(function () use ($consolidation, $error): void {
            $locked = Project::query()->lockForUpdate()->findOrFail((int) $consolidation->getKey());
            if (! is_array($locked->source_link)) {
                return;
            }
            $locked->forceFill(['source_link' => [...$locked->source_link, 'picked_up_at' => $error === null ? now()->utc()->toIso8601String() : null,
                'pickup_error' => $error]])->save();
            AuditLog::query()->create(['actor_id' => null, 'project_id' => $locked->getKey(),
                'action' => $error === null ? 'CONSOLIDATION_PACKAGES_PICKED_UP' : 'CONSOLIDATION_PACKAGES_PICKUP_FAILED',
                'auditable_type' => $locked->getMorphClass(), 'auditable_id' => $locked->getKey(),
                'payload' => ['collection_project_id' => $locked->source_project_id, 'error' => $error]]);
        });
        if ($error === null) {
            try {
                $this->copias->advance($consolidation->refresh(), $actor);
            } catch (Throwable $exception) {
                // The preflight asks again; the pick-up itself already succeeded.
                report($exception);
            }
        }
    }

    /** A linked collection that ended without completing may be replaced by a new one. */
    private function abandoned(Project $collection): bool
    {
        return in_array($collection->status, [ProjectStatus::FAILED, ProjectStatus::CANCELLED], true);
    }

    /** @return list<array{server_uuid: string, source_profile: string, package_name: string}> */
    private function collectionSources(Project $collection): array
    {
        $options = $collection->configuration()->first()?->settings['options'] ?? null;

        return is_array($options) ? RemoteCollectionConfiguration::sources($options) : [];
    }

    /** @return Collection<int, SourcePackage> */
    private function eligible(Project $collection): Collection
    {
        return SourcePackage::query()->where('project_id', $collection->getKey())->where('validation_state', 'VALID')
            ->where('availability', 'AVAILABLE')->whereNull('revoked_at')
            ->whereHas('artifact', fn ($artifact) => $artifact->where('storage_mode', 'REFERENCE')->where('disk', 's3'))
            ->orderByDesc('created_at')->orderByDesc('id')->get();
    }
}
