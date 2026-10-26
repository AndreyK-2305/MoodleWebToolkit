<?php

namespace Tests\Feature\Remote;

use App\Domain\Executions\ExecutionLifecycle;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Remote\RemoteConsolidationCopias;
use App\Domain\Remote\RemoteConsolidationSources;
use App\Domain\Remote\RemoteDestinationPackages;
use App\Domain\Remote\RemoteServerConnections;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Models\Artifact;
use App\Models\CollectorConfigurationRevision;
use App\Models\Execution;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Server;
use App\Models\SourcePackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\Feature\Domain\DomainTestCase;

/**
 * F6: a consolidation takes its sources either from a completed collection or
 * from a collection it creates and links («Recolectar ahora»); the chosen
 * packages travel to copias/ on their own. INTEGRATE is no longer offered.
 */
class ConsolidationSourcesTest extends DomainTestCase
{
    private User $admin;

    private Project $consolidation;

    private Server $server;

    private int $attempts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['toolkit.features.remote_agent.enabled' => true]);
        $this->admin = $this->user(UserRole::ADMIN);
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->consolidation = app(ProjectWizard::class)->create($this->admin, ['name' => 'Campus unificado', 'type' => 'CONSOLIDATE']);
        $this->server = $this->destination($this->consolidation, []);
    }

    public function test_new_projects_choose_only_collection_or_consolidation(): void
    {
        $this->post(route('projects.store'), ['name' => 'Integración', 'type' => 'INTEGRATE'])->assertSessionHasErrors('type');
        $this->assertSame(0, Project::query()->where('type', 'INTEGRATE')->count());
        $this->post(route('projects.store'), ['name' => 'Recolección', 'type' => 'COLLECT'])->assertSessionHasNoErrors();

        // No project switches to INTEGRATE...
        $collect = Project::query()->where('type', 'COLLECT')->latest('id')->firstOrFail();
        $this->get(route('projects.show', $collect->uuid))->assertInertia(fn (Assert $page) => $page
            ->where('projectTypes', [['value' => 'COLLECT', 'label' => 'Recolectar'], ['value' => 'CONSOLIDATE', 'label' => 'Consolidar']]));
        $this->patch(route('projects.wizard.basics', $collect->uuid), ['name' => 'Recolección', 'type' => 'INTEGRATE'])
            ->assertSessionHasErrors('type');
        $this->assertSame(ProjectType::COLLECT, $collect->fresh()->type);

        // ...but an existing INTEGRATE project keeps working with its operation.
        $legacy = app(ProjectWizard::class)->create($this->admin, ['name' => 'Integración previa', 'type' => 'INTEGRATE']);
        $this->patch(route('projects.wizard.basics', $legacy->uuid), ['name' => 'Integración renombrada', 'type' => 'INTEGRATE'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Integración renombrada', $legacy->fresh()->name);
        $this->get(route('projects.show', $legacy->uuid))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('projectTypes', 3)->where('projectTypes.2.value', 'INTEGRATE'));
    }

    public function test_sources_from_a_completed_collection_are_validated_and_linked(): void
    {
        $this->mockTransfers();
        [$collection, [$pregrado, $posgrado]] = $this->collection('Recolección 2026', ['pregrado', 'posgrado']);
        [, [$other]] = $this->collection('Otra recolección', ['continua']);
        $url = route('projects.consolidation.remote-configuration', $this->consolidation);

        // One package per source, as the collection sealed it.
        $offered = collect(app(RemoteConsolidationSources::class)->completedCollections($this->admin))->keyBy('uuid');
        $this->assertSame([[$posgrado->uuid, 0], [$pregrado->uuid, 0]],
            array_map(fn (array $package): array => [$package['uuid'], $package['older']], $offered[$collection->uuid]['packages']));
        $this->get(route('projects.show', $this->consolidation->uuid))->assertInertia(fn (Assert $page) => $page
            ->has('consolidationSources.collections', 2));

        // Every package must belong to the chosen collection.
        $this->put($url, $this->input([$pregrado, $other], $collection))->assertSessionHasErrors('consolidation');
        // The collection must be a COLLECT project that has completed.
        $this->put($url, $this->input([$pregrado, $posgrado], $this->consolidation))->assertSessionHasErrors('consolidation');
        $running = app(ProjectWizard::class)->create($this->admin, ['name' => 'En curso', 'type' => 'COLLECT']);
        $this->put($url, $this->input([$pregrado, $posgrado], $running))->assertSessionHasErrors('consolidation');

        $this->put($url, $this->input([$pregrado, $posgrado], $collection))->assertSessionHasNoErrors();
        $options = $this->consolidation->fresh()->configuration->settings['options'];
        $this->assertSame($collection->uuid, $options['source_project_uuid']);
        $this->assertEqualsCanonicalizing([$pregrado->sha256, $posgrado->sha256], array_column($options['source_packages'], 'sha256'));
        $this->assertSame($collection->getKey(), $this->consolidation->fresh()->source_project_id);

        // The «Avanzado» list mixes collections and drops the link.
        $this->put($url, $this->input([$pregrado, $other]))->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('source_project_uuid', $this->consolidation->fresh()->configuration->settings['options']);
        $this->assertNull($this->consolidation->fresh()->source_project_id);
    }

    public function test_a_collection_the_actor_cannot_view_is_refused(): void
    {
        $this->mockTransfers();
        $operator = $this->user(UserRole::OPERATOR);
        ProjectAssignment::query()->create(['project_id' => $this->consolidation->getKey(), 'user_id' => $operator->getKey(), 'assigned_by' => $this->admin->getKey()]);
        [$collection, $packages] = $this->collection('Ajena', ['pregrado', 'posgrado']);

        $this->actingAs($operator)->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->put(route('projects.consolidation.remote-configuration', $this->consolidation), $this->input($packages, $collection))
            ->assertSessionHasErrors('consolidation');
        $this->assertArrayNotHasKey('mode', $this->consolidation->fresh()->configuration->settings['options']);
    }

    public function test_collect_now_creates_a_linked_collection_with_the_same_people(): void
    {
        $operator = $this->user(UserRole::OPERATOR);
        ProjectAssignment::query()->create(['project_id' => $this->consolidation->getKey(), 'user_id' => $operator->getKey(), 'assigned_by' => $this->admin->getKey()]);
        $url = route('projects.consolidation.linked-collection', $this->consolidation);

        $this->post($url, [...$this->linkedInput(), 'sources' => [$this->linkedSource(1)]])->assertSessionHasErrors('sources');
        $this->post($url, [...$this->linkedInput(), 'path' => '/srv'])->assertStatus(422);
        $this->post($url, [...$this->linkedInput(), 'sources' => [$this->linkedSource(1), $this->linkedSource(1)]])
            ->assertSessionHasErrors(['sources.1.instance_id', 'sources.1.package_name']);
        $this->post($url, [...$this->linkedInput(), 'sources' => [$this->linkedSource(1), [...$this->linkedSource(2), 'instance_id' => 'servidor']]])
            ->assertSessionHasErrors('sources.1.instance_id');
        $this->assertSame(0, Project::query()->where('type', 'COLLECT')->count());

        $this->post($url, $this->linkedInput())->assertRedirect(route('projects.show', $this->consolidation->uuid))->assertSessionHasNoErrors();
        $collection = Project::query()->where('type', 'COLLECT')->sole();
        $consolidation = $this->consolidation->fresh();
        $this->assertSame('Campus unificado · Recolección', $collection->name);
        $this->assertSame($collection->getKey(), $consolidation->source_project_id);
        $this->assertSame(['COLLECT_NOW', 'campus', 'auto', 2, null], [$consolidation->source_link['mode'], $consolidation->source_link['destination_profile'],
            $consolidation->source_link['workers'], $consolidation->source_link['sources'], $consolidation->source_link['picked_up_at']]);
        $this->assertTrue($operator->can('view', $collection));
        $servers = $collection->servers()->with('connections')->orderBy('name')->get();
        $this->assertSame(['Origen 1', 'Origen 2'], $servers->pluck('name')->all());
        $this->assertSame([ServerRole::SOURCE, 'aws-ssm', 'i-00000000000000001'],
            [$servers[0]->role, $servers[0]->connections->sole()->type, $servers[0]->connections->sole()->configuration['instance_id']]);
        $options = $collection->configuration->settings['options'];
        $this->assertSame(['REMOTE', 2], [$options['mode'], $options['workers']]);
        $this->assertSame(['origen-1', 'origen-2'], array_column($options['sources'], 'source_profile'));
        // The consolidation itself is not configured yet: it waits for the packages.
        $this->assertArrayNotHasKey('mode', $consolidation->configuration->settings['options']);

        $this->get(route('projects.show', $this->consolidation->uuid))->assertInertia(fn (Assert $page) => $page
            ->where('consolidationSources.link.mode', 'COLLECT_NOW')
            ->where('consolidationSources.link.project.uuid', $collection->uuid)
            ->where('consolidationSources.link.sources_total', 2)
            ->where('consolidationSources.link.sources_collected', 0)
            ->where('consolidationSources.link.waiting', 'Esperando a que se inicie la recolección vinculada.'));
        $this->get(route('projects.show', $collection->uuid))->assertInertia(fn (Assert $page) => $page
            ->where('linkedConsolidations.0.uuid', $this->consolidation->uuid));
        // A second linked collection is refused while the first one is in progress.
        $this->post($url, $this->linkedInput())->assertSessionHasErrors('linked_collection');
    }

    public function test_the_consolidation_picks_up_the_packages_when_the_linked_collection_completes(): void
    {
        $this->mockTransfers(fetches: 1);
        $this->post(route('projects.consolidation.linked-collection', $this->consolidation), $this->linkedInput())->assertSessionHasNoErrors();
        $collection = Project::query()->where('type', 'COLLECT')->sole();
        $execution = $this->execution($collection, ExecutionStatus::REVIEW, ++$this->attempts);
        $pregrado = $this->package($collection, 'pregrado', $execution);
        $posgrado = $this->package($collection, 'posgrado', $execution);
        DB::table('projects')->where('id', $collection->getKey())->update(['status' => ProjectStatus::REVIEW->value]);

        $this->get(route('projects.show', $this->consolidation->uuid))->assertInertia(fn (Assert $page) => $page
            ->where('consolidationSources.link.sources_collected', 2)
            ->where('consolidationSources.link.waiting', 'La recolección espera su revisión y cierre para entregar los paquetes.'));

        app(ExecutionLifecycle::class)->transitionForWorker($execution, ExecutionStatus::COMPLETED);

        $consolidation = $this->consolidation->fresh();
        $options = $consolidation->configuration->settings['options'];
        $this->assertSame(['REMOTE_CONSOLIDATION', $collection->uuid, 'campus'], [$options['mode'], $options['source_project_uuid'], $options['destination_profile']]);
        $this->assertEqualsCanonicalizing([$pregrado->uuid, $posgrado->uuid], array_column($options['source_packages'], 'uuid'));
        $this->assertIsString($consolidation->source_link['picked_up_at']);
        $this->assertNull($consolidation->source_link['pickup_error']);
        $this->assertDatabaseHas('audit_logs', ['project_id' => $consolidation->getKey(), 'action' => 'CONSOLIDATION_PACKAGES_PICKED_UP']);
        $revisions = CollectorConfigurationRevision::query()->where('project_id', $consolidation->getKey())->count();

        // Picking up again changes nothing.
        app(RemoteConsolidationSources::class)->pickUp($collection->fresh());
        $this->assertSame($revisions, CollectorConfigurationRevision::query()->where('project_id', $consolidation->getKey())->count());
        $this->assertSame($options, $this->consolidation->fresh()->configuration->settings['options']);
        $this->get(route('projects.show', $this->consolidation->uuid))->assertInertia(fn (Assert $page) => $page
            ->where('consolidationSources.link.waiting', null)
            ->where('consolidationSources.copias.state', 'FETCHING'));
    }

    public function test_sources_and_destination_are_saved_in_separate_steps(): void
    {
        $this->mockTransfers();
        [$collection, [$pregrado, $posgrado]] = $this->collection('Recolección 2026', ['pregrado', 'posgrado']);
        [, [$other]] = $this->collection('Otra recolección', ['continua']);
        $sources = route('projects.consolidation.sources', $this->consolidation);
        $destination = route('projects.consolidation.remote-configuration', $this->consolidation);
        $only = ['server_uuid' => $this->server->uuid, 'destination_profile' => 'campus', 'workers' => '2'];

        // The destination step needs the sources first.
        $this->put($destination, $only)->assertSessionHasErrors(['consolidation' => 'Elija primero de dónde salen los orígenes (paso 2).']);

        $this->put($sources, ['source_packages' => [$pregrado->uuid]])->assertSessionHasErrors('source_packages');
        $this->put($sources, ['source_packages' => [$pregrado->uuid, $other->uuid], 'source_project' => $collection->uuid])
            ->assertSessionHasErrors('consolidation');
        $this->put($sources, ['source_packages' => [$pregrado->uuid, $posgrado->uuid], 'source_project' => $collection->uuid, 'path' => '/srv'])
            ->assertStatus(422);
        $this->put($sources, ['source_packages' => [$pregrado->uuid, $posgrado->uuid], 'source_project' => $collection->uuid])
            ->assertSessionHasNoErrors();
        $consolidation = $this->consolidation->fresh();
        $this->assertArrayNotHasKey('mode', $consolidation->configuration->settings['options']);
        $this->assertSame(['SELECTION', $collection->getKey()], [$consolidation->source_link['mode'], $consolidation->source_project_id]);
        $this->get(route('projects.show', $this->consolidation->uuid))->assertInertia(fn (Assert $page) => $page
            ->where('consolidationSources.selection.source_project_uuid', $collection->uuid)
            ->has('consolidationSources.selection.packages', 2)
            ->where('consolidationSources.link.mode', 'PROJECT')
            ->where('consolidationSources.link.waiting', null));

        // Saving the destination completes the configuration with those packages.
        $this->put($destination, $only)->assertSessionHasNoErrors();
        $options = $this->consolidation->fresh()->configuration->settings['options'];
        $this->assertSame(['REMOTE_CONSOLIDATION', 'campus', '2', $collection->uuid],
            [$options['mode'], $options['destination_profile'], $options['workers'], $options['source_project_uuid']]);
        $this->assertEqualsCanonicalizing([$pregrado->uuid, $posgrado->uuid], array_column($options['source_packages'], 'uuid'));

        // New sources afterwards keep the saved destination.
        $this->put($sources, ['source_packages' => [$pregrado->uuid, $other->uuid]])->assertSessionHasNoErrors();
        $options = $this->consolidation->fresh()->configuration->settings['options'];
        $this->assertSame(['campus', '2'], [$options['destination_profile'], $options['workers']]);
        $this->assertEqualsCanonicalizing([$pregrado->uuid, $other->uuid], array_column($options['source_packages'], 'uuid'));
        $this->assertArrayNotHasKey('source_project_uuid', $options);
        $this->assertNull($this->consolidation->fresh()->source_project_id);
    }

    public function test_collect_now_may_start_before_the_destination(): void
    {
        $this->mockTransfers(fetches: 1);
        $this->post(route('projects.consolidation.linked-collection', $this->consolidation),
            ['collection_workers' => 2, 'sources' => [$this->linkedSource(1), $this->linkedSource(2)]])->assertSessionHasNoErrors();
        $collection = Project::query()->where('type', 'COLLECT')->sole();
        $this->assertSame([null, null], [$this->consolidation->fresh()->source_link['server_uuid'], $this->consolidation->fresh()->source_link['destination_profile']]);

        // The collection completes before the destination is chosen: its packages wait.
        $execution = $this->execution($collection, ExecutionStatus::REVIEW, ++$this->attempts);
        $pregrado = $this->package($collection, 'pregrado', $execution);
        $posgrado = $this->package($collection, 'posgrado', $execution);
        DB::table('projects')->where('id', $collection->getKey())->update(['status' => ProjectStatus::REVIEW->value]);
        app(ExecutionLifecycle::class)->transitionForWorker($execution, ExecutionStatus::COMPLETED);
        $consolidation = $this->consolidation->fresh();
        $this->assertArrayNotHasKey('mode', $consolidation->configuration->settings['options']);
        $this->assertSame([null, null], [$consolidation->source_link['picked_up_at'], $consolidation->source_link['pickup_error']]);
        $this->get(route('projects.show', $this->consolidation->uuid))->assertInertia(fn (Assert $page) => $page
            ->where('consolidationSources.link.destination_chosen', false)
            ->where('consolidationSources.link.waiting', 'La recolección terminó. Guarde el destino (paso 3) para tomar sus paquetes.'));

        // Saving the destination picks the packages up at once.
        $this->put(route('projects.consolidation.remote-configuration', $this->consolidation),
            ['server_uuid' => $this->server->uuid, 'destination_profile' => 'campus', 'workers' => 'auto'])->assertSessionHasNoErrors();
        $consolidation = $this->consolidation->fresh();
        $options = $consolidation->configuration->settings['options'];
        $this->assertSame(['REMOTE_CONSOLIDATION', $collection->uuid], [$options['mode'], $options['source_project_uuid']]);
        $this->assertEqualsCanonicalizing([$pregrado->uuid, $posgrado->uuid], array_column($options['source_packages'], 'uuid'));
        $this->assertIsString($consolidation->source_link['picked_up_at']);
        $this->assertSame([$this->server->uuid, 'COLLECT_NOW'], [$consolidation->source_link['server_uuid'], $consolidation->source_link['mode']]);
    }

    public function test_only_the_configured_packages_travel_to_copias_one_at_a_time(): void
    {
        [$collection, [$pregrado, $posgrado]] = $this->collection('Recolección', ['pregrado', 'posgrado']);
        $this->setListing([['name' => 'pregrado.zip', 'size' => 4096, 'sidecar_sha256' => $pregrado->sha256]]);
        $transfers = $this->mockTransfers(fetches: 0);
        $this->put(route('projects.consolidation.remote-configuration', $this->consolidation), $this->input([$pregrado, $posgrado], $collection))
            ->assertSessionHasNoErrors();
        $this->assertSame(['state' => 'FETCHING', 'ready' => 1, 'total' => 2, 'current' => 'posgrado'],
            array_intersect_key(app(RemoteConsolidationCopias::class)->progress($this->consolidation), array_flip(['state', 'ready', 'total', 'current'])));
        $this->assertSame([$posgrado->uuid], $transfers->fetched);

        // While a transfer runs, nothing else starts.
        app(RemoteConsolidationCopias::class)->advance($this->consolidation, $this->admin);
        $this->assertSame([$posgrado->uuid], $transfers->fetched);

        // Once copias/ holds both, the progress is ready and the preflight check passes.
        $this->setListing([['name' => 'pregrado.zip', 'size' => 4096, 'sidecar_sha256' => $pregrado->sha256],
            ['name' => 'posgrado.zip', 'size' => 4096, 'sidecar_sha256' => $posgrado->sha256]]);
        $this->assertSame('READY', app(RemoteConsolidationCopias::class)->advance($this->consolidation, $this->admin)['state']);
        $this->assertSame([$posgrado->uuid], $transfers->fetched);
        $this->post(route('projects.wizard.preflight', $this->consolidation))->assertRedirect();
        $checks = collect($this->consolidation->fresh()->configuration->settings['preflight']['checks'])->keyBy('id');
        $this->assertSame('SUCCESS', $checks['destination.copias']['result']);
    }

    /** A recording stand-in for the agent transfers; each fetch marks the package as moving. */
    private function mockTransfers(int $fetches = 0): object
    {
        $recorder = new class
        {
            /** @var list<string> */
            public array $fetched = [];
        };
        $this->mock(RemoteDestinationPackages::class, function (MockInterface $mock) use ($recorder): void {
            $mock->shouldReceive('fetch')->andReturnUsing(function (Server $server, string $profile, SourcePackage $package) use ($recorder): array {
                $recorder->fetched[] = $package->uuid;
                $locked = Server::query()->findOrFail($server->getKey());
                $metadata = $locked->metadata ?? [];
                $metadata['remote_destination_fetches'][$profile][$package->uuid] = ['state' => 'RUNNING', 'package_name' => $package->name,
                    'sha256' => $package->sha256, 'launched_at' => now()->toIso8601String()];
                $locked->update(['metadata' => $metadata]);

                return $metadata['remote_destination_fetches'][$profile][$package->uuid];
            });
            $mock->shouldReceive('list')->andReturnUsing(fn () => ['listed' => true, 'error_code' => null, 'write_locked' => false, 'packages' => []]);
        });

        return $recorder;
    }

    /** @param list<array<string, mixed>> $packages */
    private function destination(Project $project, array $packages): Server
    {
        $server = $project->servers()->create(['name' => 'Destino EC2', 'role' => ServerRole::DESTINATION, 'host' => 'campus.example.edu',
            'metadata' => ['remote_destinations' => ['campus' => ['ready' => true, 'report' => ['target_running' => true, 'destination_written' => false]]],
                'remote_destination_preparations' => ['campus' => ['state' => 'SUCCEEDED']],
                'remote_destination_copias' => ['campus' => ['write_locked' => false, 'packages' => $packages]]]]);
        app(RemoteServerConnections::class)->configure($server, ConnectionType::AWS_SSM,
            ['instance_id' => 'i-0d69555bdfa275322', 'region' => 'us-east-2'], null, null)->update(['status' => ConnectionStatus::VALID]);

        return $server->refresh();
    }

    /** @param list<array<string, mixed>> $packages */
    private function setListing(array $packages): void
    {
        $metadata = $this->server->fresh()->metadata;
        $metadata['remote_destination_copias']['campus']['packages'] = $packages;
        $this->server->update(['metadata' => $metadata]);
    }

    /**
     * A completed COLLECT project with one package per given source, oldest first.
     * The packages come first: a COMPLETED project accepts no new rows.
     *
     * @param  list<string>  $sourceIds
     * @return array{Project, list<SourcePackage>}
     */
    private function collection(string $name, array $sourceIds): array
    {
        $project = app(ProjectWizard::class)->create($this->admin, ['name' => $name, 'type' => 'COLLECT']);
        $packages = array_map(fn (string $sourceId): SourcePackage => $this->package($project, $sourceId), $sourceIds);
        DB::table('projects')->where('id', $project->getKey())->update(['status' => ProjectStatus::COMPLETED->value]);

        return [$project->fresh(), $packages];
    }

    private function package(Project $project, string $sourceId, ?Execution $execution = null): SourcePackage
    {
        $execution ??= Execution::query()->forceCreate(['project_id' => $project->getKey(), 'attempt' => ++$this->attempts,
            'status' => ExecutionStatus::COMPLETED, 'created_by' => $project->created_by]);
        $sha = hash('sha256', $sourceId.Str::uuid());
        $key = 'mwt/'.$project->uuid.'/'.$sourceId.'/'.$sha.'.zip';
        $artifact = Artifact::query()->forceCreate(['execution_id' => $execution->getKey(), 'type' => 'source_package', 'category' => 'SOURCE_PACKAGE',
            'storage_mode' => 'REFERENCE', 'disk' => 's3', 'path' => 'kevin-moodle-backups-2026/'.$key, 'filename' => $sourceId.'.zip',
            'mime_type' => 'application/zip', 'size' => 4096, 'sha256' => $sha,
            'metadata' => ['s3' => ['region' => 'us-east-2', 'bucket' => 'kevin-moodle-backups-2026', 'key' => $key]]]);

        return SourcePackage::query()->forceCreate(['uuid' => (string) Str::uuid(), 'project_id' => $project->getKey(),
            'producer_execution_id' => $execution->getKey(), 'artifact_id' => $artifact->getKey(), 'producer_tool_version' => '7.4.2-linux',
            'schema_version' => '1.0', 'source_id' => $sourceId, 'name' => $sourceId, 'size_bytes' => 4096, 'sha256' => $sha,
            'validation_state' => 'VALID', 'validated_at' => now(), 'availability' => 'AVAILABLE', 'sensitivity' => 'CONFIDENTIAL']);
    }

    /**
     * @param  list<SourcePackage>  $packages
     * @return array<string, mixed>
     */
    private function input(array $packages, ?Project $source = null): array
    {
        return ['server_uuid' => $this->server->uuid, 'destination_profile' => 'campus', 'workers' => 'auto',
            'source_packages' => array_map(fn (SourcePackage $package): string => $package->uuid, $packages),
            ...($source === null ? [] : ['source_project' => $source->uuid])];
    }

    /** @return array<string, mixed> */
    private function linkedInput(): array
    {
        return ['server_uuid' => $this->server->uuid, 'destination_profile' => 'campus', 'workers' => 'auto', 'collection_workers' => 2,
            'sources' => [$this->linkedSource(1), $this->linkedSource(2)]];
    }

    /** @return array<string, string> */
    private function linkedSource(int $index): array
    {
        return ['name' => 'Origen '.$index, 'instance_id' => 'i-0000000000000000'.$index, 'region' => 'us-east-2',
            'source_profile' => 'origen-'.$index, 'package_name' => 'origen-'.$index];
    }
}
