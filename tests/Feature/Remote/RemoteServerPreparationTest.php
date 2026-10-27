<?php

namespace Tests\Feature\Remote;

use App\Domain\Projects\ProjectWizard;
use App\Domain\Remote\CheckRemoteDestination;
use App\Domain\Remote\RemoteAgent;
use App\Domain\Remote\RemoteCollectorExport;
use App\Domain\Remote\RemoteServerConnections;
use App\Domain\Remote\RemoteToolDeployer;
use App\Domain\Remote\S3ClientFactory;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Jobs\DeployRemoteTool;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Server;
use App\Models\ToolDistribution;
use App\Models\User;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Database\Seeders\ToolCatalogSeeder;
use Mockery\MockInterface;
use Tests\Feature\Domain\DomainTestCase;
use Tests\Support\QueuedRemoteTransport;

/**
 * «Preparar origen» and «Preparar destino» from the web: the endpoints, who may
 * create a profile, what is kept and audited, the S3 probe confirmed with the
 * platform's identity, the per-source workers and the re-pointing of profiles
 * after a Consolidator deployment. The agent is scripted here; the real agent
 * runs in RemoteToolDeploymentTest and RemoteDestinationTest.
 */
class RemoteServerPreparationTest extends DomainTestCase
{
    private const CONFIG = '/var/www/moodle/config.php';

    private QueuedRemoteTransport $transport;

    private MockHandler $s3;

    private User $admin;

    private Project $project;

    private Server $source;

    protected function setUp(): void
    {
        parent::setUp();
        config(['toolkit.features.remote_agent.enabled' => true, 'toolkit.remote.package_bucket' => 'mwt-test-packages',
            'toolkit.remote.package_region' => 'us-east-2']);
        $this->transport = QueuedRemoteTransport::bind($this->app);
        $this->s3 = new MockHandler;
        $client = new S3Client(['region' => 'us-east-2', 'version' => '2006-03-01', 'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $this->s3]);
        $this->app->instance(S3ClientFactory::class, new class($client) extends S3ClientFactory
        {
            public function __construct(private readonly S3Client $client) {}

            public function make(string $region): S3Client
            {
                return $this->client;
            }
        });
        $this->seed(ToolCatalogSeeder::class);
        $this->admin = $this->user(UserRole::ADMIN);
        $this->project = app(ProjectWizard::class)->create($this->admin, ['name' => 'Consolidación', 'type' => 'CONSOLIDATE']);
        $this->source = $this->server('Pregrado', ServerRole::SOURCE);
    }

    public function test_only_a_confirmed_administrator_creates_a_source_profile_and_paths_are_audited_hashed(): void
    {
        $operator = $this->user();
        ProjectAssignment::query()->create(['project_id' => $this->project->getKey(), 'user_id' => $operator->getKey(), 'assigned_by' => $this->admin->getKey()]);
        $this->actingAs($operator)->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post($this->route('sources/create'), $this->sourceInput())->assertForbidden();
        // Without a recent password confirmation nothing reaches the server.
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => 0])->post($this->route('sources/create'), $this->sourceInput())
            ->assertSessionHas('action_confirmation_required');
        $this->assertSame([], $this->transport->scripts);

        $this->transport->ok('source-profile-create', ['source' => 'pregrado', 'created' => true, 'replaced' => false,
            'check' => $this->document('source-check', $this->checkReport(4))]);
        $this->confirmed()->post($this->route('sources/create'), $this->sourceInput())->assertRedirect()->assertSessionHasNoErrors();

        $script = $this->transport->scripts[0];
        $this->assertStringContainsString('config='.RemoteAgent::text(self::CONFIG), $script);
        $this->assertStringNotContainsString(self::CONFIG, $script, 'La ruta viaja codificada, dentro del alfabeto cerrado de argumentos.');
        $check = $this->source->refresh()->metadata['remote_sources']['pregrado'];
        $this->assertTrue($check['ready']);
        $this->assertSame(4, $check['report']['cpus']);
        $audit = AuditLog::query()->where('action', 'REMOTE_SOURCE_PROFILE_CREATED')->sole();
        $this->assertSame(hash('sha256', self::CONFIG), $audit->payload['config_sha256']);
        $this->assertStringNotContainsString(self::CONFIG, (string) json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_paths_and_refusals_are_reported_next_to_the_form(): void
    {
        $this->confirmed()->post($this->route('sources/create'), [...$this->sourceInput(), 'config_path' => '/var/www/../etc/config.php'])
            ->assertSessionHasErrors('config_path');
        $this->confirmed()->post($this->route('sources/create'), [...$this->sourceInput(), 'config_path' => '/var/www/moodle/setup.php'])
            ->assertSessionHasErrors('config_path');
        $this->confirmed()->post($this->route('sources/create'), [...$this->sourceInput(), 'runtime' => 'compose'])
            ->assertSessionHasErrors(['compose_dir', 'compose_service']);
        $this->assertSame([], $this->transport->scripts);

        $this->transport->error('source-profile-create', 'source_profile_exists');
        $this->confirmed()->post($this->route('sources/create'), $this->sourceInput())->assertSessionHasErrors('source_profile');
        $this->assertArrayNotHasKey('remote_sources', $this->source->refresh()->metadata ?? []);
        // A destination server has no source profiles.
        $destination = $this->server('Destino', ServerRole::DESTINATION);
        $this->confirmed()->post($this->route('sources/create', $destination), $this->sourceInput())->assertStatus(422);
    }

    public function test_detection_keeps_valid_candidates_for_the_administrator_only(): void
    {
        $this->transport->ok('moodle-detect', ['php_present' => true, 'docker_checked' => true, 'candidates' => [
            ['config_path' => self::CONFIG, 'runtime' => 'native', 'compose_dir' => null, 'compose_service' => null, 'compose_profile' => null,
                'run_as' => 'www-data', 'config_owner' => 'root', 'issue' => null],
            ['config_path' => '/srv/moodle/html/config.php', 'runtime' => 'compose', 'compose_dir' => '/srv/moodle', 'compose_service' => 'php',
                'compose_profile' => null, 'run_as' => 'www-data', 'config_owner' => 'www-data', 'issue' => null],
            ['config_path' => '/var/www/../x/config.php', 'runtime' => 'native', 'compose_dir' => null, 'compose_service' => null,
                'compose_profile' => null, 'run_as' => 'www-data', 'config_owner' => 'root', 'issue' => null],
        ]]);

        $this->confirmed()->post($this->route('sources/moodle-detect'))->assertRedirect();

        $detected = $this->source->refresh()->metadata['remote_moodle_detected'];
        $this->assertSame([self::CONFIG, '/srv/moodle/html/config.php'], array_column($detected['candidates'], 'config_path'));
        $this->assertSame('php', $detected['candidates'][1]['compose_service']);
        $audit = AuditLog::query()->where('action', 'REMOTE_MOODLE_DETECTED')->sole();
        $this->assertStringNotContainsString('/var/www', (string) json_encode($audit->payload));
    }

    public function test_the_storage_probe_is_confirmed_and_removed_with_the_platforms_identity(): void
    {
        $sha = hash('sha256', 'probe');
        $key = 'mwt/_pruebas/i-0123456789abcdef0/0123456789abcdef';
        $this->transport->ok('s3-upload-probe', ['bucket' => 'mwt-test-packages', 'region' => 'us-east-2', 'key' => $key, 'sha256' => $sha,
            'size' => 40, 'mode' => 'write', 'read_back' => null]);
        $this->s3->append(function (CommandInterface $command) use ($key, $sha): Result {
            $this->assertSame(['HeadObject', $key], [$command->getName(), $command['Key']]);

            return new Result(['ContentLength' => 40, 'Metadata' => ['mwt-sha256' => $sha]]);
        });
        $this->s3->append(new Result([]));

        $this->confirmed()->post($this->route('storage/probe'))->assertRedirect();

        $this->assertStringContainsString("'write'", str_replace('"', "'", $this->transport->scripts[0]));
        $probe = $this->source->refresh()->metadata['remote_storage_probe'];
        $this->assertSame([true, true, true, null], [$probe['ok'], $probe['confirmed'], $probe['deleted'], $probe['error_code']]);

        // An object that does not match, or a platform identity without delete, is reported as such.
        $this->transport->ok('s3-upload-probe', ['key' => $key, 'sha256' => $sha, 'size' => 40, 'mode' => 'write', 'read_back' => null]);
        $this->s3->append(new Result(['ContentLength' => 40, 'Metadata' => ['mwt-sha256' => hash('sha256', 'otro')]]));
        $this->s3->append(fn (CommandInterface $command) => new AwsException('denied', $command, ['code' => 'AccessDenied']));
        $this->confirmed()->post($this->route('storage/probe'))->assertRedirect();
        $probe = $this->source->refresh()->metadata['remote_storage_probe'];
        $this->assertSame([false, false, 'storage_probe_mismatch'], [$probe['ok'], $probe['deleted'], $probe['error_code']]);

        $this->transport->error('s3-upload-probe', 'storage_put_failed');
        $this->confirmed()->post($this->route('storage/probe'))->assertRedirect();
        $this->assertSame('storage_put_failed', $this->source->refresh()->metadata['remote_storage_probe']['error_code']);
    }

    public function test_a_destination_reads_back_and_its_profile_never_returns_the_password(): void
    {
        $tree = ToolDistribution::query()->where('key', CheckRemoteDestination::DISTRIBUTION_KEY)->sole()->distribution_sha256;
        $destination = $this->server('Destino', ServerRole::DESTINATION, ['remote_tools' => [CheckRemoteDestination::DISTRIBUTION_KEY => ['tree_sha256' => $tree]]]);
        $input = ['destination' => 'campus', 'public_url' => 'https://campus.example.edu/', 'admin_email' => 'admin@example.edu'];

        $this->confirmed()->post($this->route('destinations/create', $destination), [...$input, 'public_url' => 'http://campus.example.edu'])
            ->assertSessionHasErrors('public_url');
        $this->confirmed()->post($this->route('destinations/create', $destination), [...$input, 'admin_email' => 'no'])->assertSessionHasErrors('admin_email');
        $this->assertSame([], $this->transport->scripts);

        $this->transport->ok('destination-profile-create', ['destination' => 'campus', 'created' => true, 'replaced' => false, 'runtime_created' => true,
            'user_created' => true, 'run_as' => 'mwt-campus', 'docker_group' => true, 'tool_tree' => $tree, 'admin_user' => 'admin',
            'admin_password_location' => '/srv/mwt-destinations/campus/.env (MOODLE_ADMIN_PASSWORD)',
            'check' => $this->document('destination-check', ['destination' => 'campus', 'public_url' => 'https://campus.example.edu', 'tool_tree' => $tree])]);
        $this->confirmed()->post($this->route('destinations/create', $destination), $input)->assertRedirect()->assertSessionHasNoErrors();

        $script = $this->transport->scripts[0];
        $this->assertStringContainsString('public_url='.RemoteAgent::text('https://campus.example.edu'), $script);
        $this->assertStringContainsString('tree='.$tree, $script);
        $metadata = $destination->refresh()->metadata;
        $this->assertSame('/srv/mwt-destinations/campus/.env (MOODLE_ADMIN_PASSWORD)', $metadata['remote_destination_created']['campus']['admin_password_location']);
        $this->assertArrayHasKey('campus', $metadata['remote_destinations']);
        $audit = AuditLog::query()->where('action', 'REMOTE_DESTINATION_PROFILE_CREATED')->sole();
        $this->assertSame(hash('sha256', 'admin@example.edu'), $audit->payload['admin_email_sha256']);
        $this->assertStringNotContainsString('admin@example.edu', (string) json_encode($audit->payload));

        $this->transport->ok('s3-upload-probe', ['key' => 'mwt/_pruebas/i-0123456789abcdef0/0123456789abcdef', 'sha256' => hash('sha256', 'p'),
            'size' => 4, 'mode' => 'readwrite', 'read_back' => true]);
        $this->s3->append(new Result(['ContentLength' => 4, 'Metadata' => ['mwt-sha256' => hash('sha256', 'p')]]));
        $this->s3->append(new Result([]));
        $this->confirmed()->post($this->route('storage/probe', $destination))->assertRedirect();
        $this->assertStringContainsString('readwrite', $this->transport->scripts[1]);
        $this->assertTrue($destination->refresh()->metadata['remote_storage_probe']['ok']);
    }

    public function test_a_destination_profile_needs_the_deployed_consolidator(): void
    {
        $destination = $this->server('Destino', ServerRole::DESTINATION);

        $this->confirmed()->post($this->route('destinations/create', $destination),
            ['destination' => 'campus', 'public_url' => 'https://campus.example.edu', 'admin_email' => 'admin@example.edu'])
            ->assertSessionHasErrors('destination_profile');
        $this->assertSame([], $this->transport->scripts);
    }

    public function test_each_source_chooses_its_workers_within_its_reported_cpus(): void
    {
        $this->confirmed()->post(route('projects.store'), ['name' => 'Recolección', 'type' => 'COLLECT'])->assertRedirect();
        $collection = Project::query()->where('name', 'Recolección')->sole();
        $server = $collection->servers()->create(['name' => 'Pregrado', 'role' => ServerRole::SOURCE, 'host' => null,
            'metadata' => ['remote_sources' => ['pregrado' => ['ready' => true, 'report' => ['cpus' => 4]], 'viejo' => ['ready' => true, 'report' => []]]]]);
        $row = fn (string $profile, string $workers): array => ['server_uuid' => $server->uuid, 'source_profile' => $profile, 'package_name' => $profile, 'workers' => $workers];
        $save = fn (array $rows) => $this->confirmed()->put(route('projects.collector.remote-configuration', $collection), ['sources' => $rows]);

        $save([$row('pregrado', '4')])->assertSessionHasErrors('sources.0.workers');
        $save([$row('pregrado', '3'), $row('viejo', '2')])->assertSessionHasErrors('sources.1.workers');
        $save([$row('pregrado', 'x')])->assertSessionHasErrors('sources.0.workers');
        $save([$row('pregrado', '3'), $row('viejo', 'auto')])->assertSessionHasNoErrors();

        $options = $collection->fresh()->configuration->settings['options'];
        $this->assertSame('collector-remote.v3', $options['schema_version']);
        $this->assertArrayNotHasKey('workers', $options);
        $this->assertSame(['3', 'auto'], array_column($options['sources'], 'workers'));
    }

    public function test_a_consolidator_deployment_re_points_the_profiles_that_were_not_written(): void
    {
        $distribution = ToolDistribution::query()->where('key', CheckRemoteDestination::DISTRIBUTION_KEY)->sole();
        $destination = $this->server('Destino', ServerRole::DESTINATION);
        $tree = $distribution->distribution_sha256;
        $this->mock(RemoteToolDeployer::class, function (MockInterface $mock) use ($destination, $tree): void {
            $mock->shouldReceive('deploy')->once()->andReturnUsing(function () use ($destination, $tree): array {
                $destination->update(['metadata' => ['remote_tools' => [CheckRemoteDestination::DISTRIBUTION_KEY => ['tree_sha256' => $tree]]]]);

                return ['installed' => true, 'already_present' => false, 'key' => CheckRemoteDestination::DISTRIBUTION_KEY, 'tree_sha256' => $tree,
                    'chunks' => 1, 'error_code' => null];
            });
        });
        $this->transport->ok('destination-repoint', ['tree' => $tree, 'destinations' => [
            ['id' => 'campus', 'repointed' => true, 'reason' => 'repointed'],
            ['id' => 'viejo', 'repointed' => false, 'reason' => 'destination_written'],
        ]]);
        $this->transport->ok('destination-check', ['destination' => 'campus', 'public_url' => 'https://campus.example.edu', 'tool_tree' => $tree]);

        DeployRemoteTool::dispatchSync($destination->getKey(), $distribution->getKey(), $this->admin->getKey());

        $metadata = $destination->refresh()->metadata;
        $this->assertSame('DEPLOYED', $metadata['remote_tool_deployments'][CheckRemoteDestination::DISTRIBUTION_KEY]['state']);
        $this->assertSame([true, false], array_column($metadata['remote_destination_repoint']['destinations'], 'repointed'));
        $this->assertSame('destination_written', $metadata['remote_destination_repoint']['destinations'][1]['reason']);
        // The re-pointed profile is checked again against the new tree; the written one is not touched.
        $this->assertSame(['campus'], array_keys($metadata['remote_destinations']));
        $this->assertStringContainsString('destination-repoint', $this->transport->scripts[0]);
        $this->assertTrue(AuditLog::query()->where('action', 'REMOTE_DESTINATIONS_REPOINTED')->exists());
    }

    public function test_a_failed_re_point_never_undoes_the_deployment(): void
    {
        $distribution = ToolDistribution::query()->where('key', CheckRemoteDestination::DISTRIBUTION_KEY)->sole();
        $destination = $this->server('Destino', ServerRole::DESTINATION);
        $this->mock(RemoteToolDeployer::class, fn (MockInterface $mock) => $mock->shouldReceive('deploy')->once()->andReturn(['installed' => true,
            'already_present' => true, 'key' => $distribution->key, 'tree_sha256' => $distribution->distribution_sha256, 'chunks' => 0, 'error_code' => null]));
        $this->transport->unreachable();

        DeployRemoteTool::dispatchSync($destination->getKey(), $distribution->getKey(), null);

        $metadata = $destination->refresh()->metadata;
        $this->assertSame('DEPLOYED', $metadata['remote_tool_deployments'][CheckRemoteDestination::DISTRIBUTION_KEY]['state']);
        $this->assertSame('ssm_instance_not_managed', $metadata['remote_destination_repoint']['error_code']);
    }

    public function test_the_panel_presents_the_checklist_facts(): void
    {
        $this->source->update(['metadata' => [
            'remote_sources' => ['pregrado' => ['ready' => true, 'checked_at' => '2026-10-26T00:00:00+00:00', 'report' => ['cpus' => 8, 'aws_cli_v2' => true]]],
            'remote_storage_probe' => ['checked_at' => 'x', 'mode' => 'write', 'ok' => true, 'error_code' => null, 'key' => 'mwt/_pruebas/a/b', 'bucket' => 'b'],
            'remote_tools' => [RemoteCollectorExport::DISTRIBUTION_KEY => ['tree_sha256' => str_repeat('a', 64)]],
        ]]);

        $this->confirmed()->get(route('projects.show', $this->project->uuid))->assertOk()->assertInertia(fn ($page) => $page
            ->where('remoteAccess.agent_version', app(RemoteAgent::class)->distribution()['version'])
            ->where('remoteAccess.servers.0.sources.0.cpus', 8)
            ->where('remoteAccess.servers.0.sources.0.max_workers', 4)
            ->where('remoteAccess.servers.0.storage_probe.ok', true)
            ->missing('remoteAccess.servers.0.storage_probe.key')
            ->missing('remoteAccess.servers.0.storage_probe.bucket'));
    }

    /** @return array<string, mixed> */
    private function sourceInput(): array
    {
        return ['source' => 'pregrado', 'config_path' => self::CONFIG, 'runtime' => 'native', 'run_as' => 'www-data'];
    }

    /** @return array<string, mixed> */
    private function checkReport(int $cpus): array
    {
        return ['source' => 'pregrado', 'runtime' => 'native', 'run_as' => 'www-data', 'php_version' => '8.3.12', 'zip' => true, 'dom' => true,
            'config_readable' => true, 'moodle_release' => '4.5.2 (Build: 20250210)', 'moodle_version' => 2024100702, 'dataroot_free_bytes' => 10,
            'dataroot_writable' => true, 'state_free_bytes' => 10, 'cpus' => $cpus, 'aws_cli_version' => '2.17.1', 'aws_cli_v2' => true];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function document(string $command, array $data): array
    {
        return ['schema' => RemoteAgent::SCHEMA, 'ok' => true, 'agent_version' => '1.17.0', 'command' => $command, 'data' => $data];
    }

    /** @param array<string, mixed> $metadata */
    private function server(string $name, ServerRole $role, array $metadata = []): Server
    {
        $server = $this->project->servers()->create(['name' => $name, 'role' => $role, 'host' => null, 'metadata' => $metadata === [] ? null : $metadata]);
        app(RemoteServerConnections::class)->configure($server, ConnectionType::AWS_SSM, ['instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-2'], null, null)
            ->update(['status' => ConnectionStatus::VALID]);

        return $server->refresh();
    }

    private function route(string $path, ?Server $server = null): string
    {
        return '/projects/'.$this->project->uuid.'/servers/'.($server ?? $this->source)->uuid.'/'.$path;
    }

    private function confirmed(): self
    {
        return $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    }
}
