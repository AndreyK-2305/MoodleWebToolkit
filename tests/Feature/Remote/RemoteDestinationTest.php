<?php

namespace Tests\Feature\Remote;

use App\Domain\Projects\ProjectWizard;
use App\Domain\Remote\CheckRemoteDestination;
use App\Domain\Remote\DetectRemoteDestinations;
use App\Domain\Remote\PrepareRemoteDestination;
use App\Domain\Remote\RemoteAgentInstaller;
use App\Domain\Remote\RemoteServerConnections;
use App\Domain\Remote\RemoteToolDeployer;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Jobs\DeployRemoteTool;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ToolDistribution;
use Database\Seeders\ToolCatalogSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\Feature\Domain\DomainTestCase;

/**
 * The Consolidator on a destination server: the real tree deployed through
 * the test-only local transport, the administrator's profile helper and the
 * agent's destination checks, which never return a value of the site's .env.
 */
class RemoteDestinationTest extends DomainTestCase
{
    private const URL = 'https://campus.example.edu';

    private string $root;

    private Server $server;

    private ToolDistribution $distribution;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v setsid')) === '') {
            $this->markTestSkipped('El agente requiere Linux con setsid.');
        }
        $this->root = sys_get_temp_dir().'/mwt-destination-'.Str::uuid()->toString();
        mkdir($this->root, 0755, true);
        config([
            'toolkit.features.remote_agent.enabled' => true,
            'toolkit.remote.local_transport' => true,
            'toolkit.remote.agent_install_root' => $this->root.'/opt',
            'toolkit.remote.agent_state_dir' => $this->root.'/state',
            'toolkit.remote.agent_tools_dir' => $this->root.'/tools',
            'toolkit.remote.agent_sources_dir' => $this->root.'/sources',
            'toolkit.remote.agent_destinations_dir' => $this->root.'/destinations',
        ]);
        $this->seed(ToolCatalogSeeder::class);
        $this->distribution = ToolDistribution::query()->where('key', CheckRemoteDestination::DISTRIBUTION_KEY)->sole();
        $this->server = $this->project()->servers()->create(['name' => 'Destino', 'role' => ServerRole::DESTINATION, 'host' => 'campus.example.edu']);
        $connections = app(RemoteServerConnections::class);
        $connections->configure($this->server, ConnectionType::AWS_SSM, ['instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-2'], null, null)
            ->update(['status' => ConnectionStatus::VALID]);
        $this->assertTrue(app(RemoteAgentInstaller::class)->install($connections->target($this->server))->ok);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_the_whole_consolidator_tree_is_deployed_in_the_background_and_matches_the_catalog(): void
    {
        DeployRemoteTool::dispatchSync($this->server->getKey(), $this->distribution->getKey(), null);

        $metadata = $this->server->refresh()->metadata;
        $this->assertSame('DEPLOYED', $metadata['remote_tool_deployments'][CheckRemoteDestination::DISTRIBUTION_KEY]['state']);
        $this->assertSame($this->distribution->distribution_sha256, $metadata['remote_tools'][CheckRemoteDestination::DISTRIBUTION_KEY]['tree_sha256']);
        $status = app(RemoteToolDeployer::class)->status($this->server, $this->distribution);
        $this->assertTrue($status['matches']);
        // Excluded and mutable files travel too: the copy is the catalog tree, byte for byte.
        $this->assertSame($this->distribution->file_count, $status['file_count']);
        $this->assertFileExists($this->installedTree().'/config/oauth2.json');
        $this->assertFileExists($this->installedTree().'/FILES.sha256');
    }

    public function test_a_failed_deployment_is_recorded_without_leaving_the_tree(): void
    {
        $this->server->connections()->update(['status' => ConnectionStatus::INVALID->value]);

        DeployRemoteTool::dispatchSync($this->server->getKey(), $this->distribution->getKey(), null);

        $deployment = $this->server->refresh()->metadata['remote_tool_deployments'][CheckRemoteDestination::DISTRIBUTION_KEY];
        $this->assertSame(['FAILED', 'deployment_blocked'], [$deployment['state'], $deployment['error_code']]);
        $this->assertArrayNotHasKey('remote_tools', $this->server->metadata);
    }

    public function test_the_profile_helper_creates_a_runtime_whose_check_reports_closed_facts_only(): void
    {
        $this->requireRoot();
        DeployRemoteTool::dispatchSync($this->server->getKey(), $this->distribution->getKey(), null);
        $runtime = $this->root.'/srv/campus';

        $created = $this->helper(['campus', '--public-url', self::URL.'/', '--admin-email', 'admin@example.edu', '--runtime-dir', $runtime]);

        $this->assertSame(0, $created->getExitCode(), $created->getErrorOutput());
        $env = (string) file_get_contents($runtime.'/.env');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($runtime.'/.env')), -4));
        $this->assertStringContainsString('MOODLE_PUBLIC_URL='.self::URL."\n", $env);
        $this->assertStringContainsString("MOODLE_REVERSE_PROXY=1\nMOODLE_SSL_PROXY=1\n", $env);
        $this->assertDirectoryExists($runtime.'-managed-config');
        preg_match('/^MOODLE_ADMIN_PASSWORD=(.+)$/m', $env, $password);
        $this->assertStringContainsString($password[1], $created->getOutput(), 'La clave se muestra una vez al administrador.');

        $detected = app(DetectRemoteDestinations::class)->handle($this->server, null);
        $this->assertSame([['id' => 'campus', 'valid' => true, 'public_url' => self::URL, 'error_code' => null]], $detected['destinations']);
        $this->assertStringNotContainsString($runtime, (string) json_encode($this->server->refresh()->metadata));

        $check = app(CheckRemoteDestination::class)->handle($this->server, 'campus', null);
        $this->assertNull($check['error_code']);
        $report = $check['report'];
        foreach (['env_present', 'env_protected', 'env_complete', 'env_matches_url', 'tool_present', 'engine_intact'] as $field) {
            $this->assertTrue($report[$field], $field);
        }
        $this->assertSame($this->distribution->distribution_sha256, $report['tool_tree']);
        $this->assertFalse($report['destination_written']);
        // This test host has no Docker daemon: only the Docker facts may block.
        $this->assertSame([], array_diff($check['blockers'], ['docker_unavailable', 'compose_v2_missing', 'flock_missing', 'python3_missing']));
        $this->assertSame($check['blockers'] === [], $check['ready']);
        foreach (['MOODLE_ADMIN_PASSWORD', 'MOODLE_DB_PASSWORD', 'MARIADB_ROOT_PASSWORD'] as $secret) {
            preg_match('/^'.$secret.'=(.+)$/m', $env, $value);
            $this->assertStringNotContainsString($value[1], (string) json_encode($check));
            $this->assertStringNotContainsString($value[1], (string) json_encode(AuditLog::query()->get()));
        }
        $this->assertStringNotContainsString($runtime, (string) json_encode($check));

        // An existing runtime is kept: without --force the helper refuses, with it the URL must match.
        $this->assertSame(2, $this->helper(['campus', '--public-url', self::URL, '--admin-email', 'admin@example.edu', '--runtime-dir', $runtime])->getExitCode());
        $this->assertSame(2, $this->helper(['campus', '--public-url', 'https://otro.example.edu', '--runtime-dir', $runtime, '--force'])->getExitCode());
        $this->assertSame(0, $this->helper(['campus', '--public-url', self::URL, '--runtime-dir', $runtime, '--force'])->getExitCode());
        $this->assertSame($env, file_get_contents($runtime.'/.env'));
    }

    public function test_a_tampered_or_exposed_runtime_is_not_ready(): void
    {
        $this->requireRoot();
        DeployRemoteTool::dispatchSync($this->server->getKey(), $this->distribution->getKey(), null);
        $runtime = $this->root.'/srv/campus';
        $this->assertSame(0, $this->helper(['campus', '--public-url', self::URL, '--admin-email', 'admin@example.edu', '--runtime-dir', $runtime])->getExitCode());

        file_put_contents($runtime.'/scripts/phase6-lib.php', "\n// alterado\n", FILE_APPEND);
        chmod($runtime.'/.env', 0644);
        file_put_contents($runtime.'/.env', str_replace('MOODLE_PUBLIC_URL='.self::URL, 'MOODLE_PUBLIC_URL=https://otro.example.edu', (string) file_get_contents($runtime.'/.env')));
        $check = app(CheckRemoteDestination::class)->handle($this->server, 'campus', null);
        $this->assertFalse($check['ready']);
        $this->assertSame([], array_diff(['engine_modified', 'env_unprotected', 'env_url_mismatch'], $check['blockers']));

        // A profile that names another tree than the deployed one.
        file_put_contents($this->root.'/destinations/campus.conf', "RUNTIME_DIR={$runtime}\nPUBLIC_URL=".self::URL."\nTOOL_TREE=".str_repeat('a', 64)."\n");
        $check = app(CheckRemoteDestination::class)->handle($this->server, 'campus', null);
        $this->assertSame([], array_diff(['tool_missing', 'tool_tree_mismatch'], $check['blockers']));

        chmod($this->root.'/destinations/campus.conf', 0666);
        $this->assertSame('destination_profile_unsafe', app(CheckRemoteDestination::class)->handle($this->server, 'campus', null)['error_code']);
        file_put_contents($this->root.'/destinations/campus.conf', "RUNTIME_DIR={$runtime}\nPUBLIC_URL=http://campus.example.edu\nTOOL_TREE=".str_repeat('a', 64)."\n");
        chmod($this->root.'/destinations/campus.conf', 0600);
        $this->assertSame('destination_profile_invalid', app(CheckRemoteDestination::class)->handle($this->server, 'campus', null)['error_code']);
        $this->assertSame('destination_not_found', app(CheckRemoteDestination::class)->handle($this->server, 'otro', null)['error_code']);
    }

    public function test_the_real_preparation_runs_the_consolidator_from_the_verified_runtime(): void
    {
        $this->requireRoot();
        DeployRemoteTool::dispatchSync($this->server->getKey(), $this->distribution->getKey(), null);
        $runtime = $this->root.'/srv/campus';
        $this->assertSame(0, $this->helper(['campus', '--public-url', self::URL, '--admin-email', 'admin@example.edu', '--runtime-dir', $runtime])->getExitCode());
        app(CheckRemoteDestination::class)->handle($this->server, 'campus', null);
        // This host has no Docker: mark the check ready so the real launch path runs to the Consolidator itself.
        $metadata = $this->server->refresh()->metadata;
        $metadata['remote_destinations']['campus']['ready'] = true;
        $this->server->update(['metadata' => $metadata]);

        $started = app(PrepareRemoteDestination::class)->start($this->server->refresh(), 'campus', null);
        $this->assertSame('RUNNING', $started['state'], (string) ($started['error_code'] ?? ''));
        $deadline = hrtime(true) + 60_000_000_000;
        do {
            usleep(500_000);
            $observed = app(PrepareRemoteDestination::class)->observe($this->server->refresh(), 'campus');
        } while (in_array($observed['state'], PrepareRemoteDestination::ACTIVE, true) && hrtime(true) < $deadline);

        // The Consolidator's own preflight refuses to continue without Docker, and says so.
        $this->assertSame(['FAILED', 'prepare_failed'], [$observed['state'], $observed['error_code']]);
        $this->assertStringContainsString('Docker', (string) $observed['last_error_line']);
        $this->assertDirectoryDoesNotExist($runtime.'/reports/destination-write.lock.json');

        // A modified runtime is refused by the agent before any process starts.
        file_put_contents($runtime.'/scripts/phase6-lib.php', "\n// alterado\n", FILE_APPEND);
        $refused = app(PrepareRemoteDestination::class)->start($this->server->refresh(), 'campus', null);
        $this->assertSame(['FAILED', 'engine_modified'], [$refused['state'], $refused['error_code']]);
    }

    public function test_readiness_blocks_on_missing_facts_and_warns_on_capacity(): void
    {
        $tree = str_repeat('b', 64);
        $report = ['public_url' => self::URL, 'env_present' => true, 'env_protected' => true, 'env_complete' => true, 'env_matches_url' => true,
            'tool_tree' => $tree, 'tool_present' => true, 'engine_intact' => true, 'docker_version' => '27.3.1', 'compose_version' => '2.29.7',
            'flock' => true, 'python3' => true, 'memory_bytes' => 2_000_000_000, 'runtime_free_bytes' => 10 * 1073741824, 'destination_written' => false];

        $this->assertSame([[], ['low_memory', 'low_disk']], CheckRemoteDestination::assess($report, $tree));
        $this->assertSame([['tool_tree_mismatch'], ['low_memory', 'low_disk']], CheckRemoteDestination::assess($report, null));
        [$blockers] = CheckRemoteDestination::assess([...$report, 'compose_version' => '1.29.2', 'engine_intact' => false], $tree);
        $this->assertSame(['engine_modified', 'compose_v2_missing'], $blockers);
        // After the first write the Consolidator's own resolutions may differ from the shipped files.
        $this->assertSame([[], ['destination_written']], CheckRemoteDestination::assess([...$report, 'engine_intact' => false,
            'destination_written' => true, 'memory_bytes' => 8_000_000_000, 'runtime_free_bytes' => 100 * 1073741824], $tree));
    }

    public function test_the_endpoints_serve_destination_servers_only_and_queue_the_deployment(): void
    {
        Queue::fake();
        $admin = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($admin, ['name' => 'Consolidación', 'type' => 'CONSOLIDATE']);
        $this->server->update(['project_id' => $project->getKey()]);
        $source = $project->servers()->create(['name' => 'Origen', 'role' => ServerRole::SOURCE, 'host' => 'origen.example.edu']);

        $this->actingAs($admin)->post(route('projects.servers.consolidator.deploy', [$project->uuid, $source->uuid]))->assertStatus(422);
        $this->actingAs($admin)->post(route('projects.servers.destinations.detect', [$project->uuid, $source->uuid]))->assertStatus(422);
        $this->actingAs($this->user())->post(route('projects.servers.consolidator.deploy', [$project->uuid, $this->server->uuid]))->assertForbidden();
        Queue::assertNothingPushed();

        $this->actingAs($admin)->post(route('projects.servers.consolidator.deploy', [$project->uuid, $this->server->uuid]))
            ->assertRedirect(route('projects.show', $project->uuid));
        Queue::assertPushed(DeployRemoteTool::class, fn (DeployRemoteTool $job): bool => $job->serverId === $this->server->getKey()
            && $job->distributionId === $this->distribution->getKey());
        $this->actingAs($admin)->post(route('projects.servers.destinations.check', [$project->uuid, $this->server->uuid]), ['destination' => '../x'])
            ->assertSessionHasErrors('destination');

        $this->actingAs($admin)->get(route('projects.show', $project->uuid))->assertInertia(fn (Assert $page) => $page
            ->where('remoteAccess.servers.0.uuid', $this->server->uuid)
            ->where('remoteAccess.servers.0.consolidator.deployed', false)
            ->where('remoteAccess.servers.0.consolidator.deployment_state', 'QUEUED')
            ->where('remoteAccess.servers.0.destinations', []));
    }

    private function requireRoot(): void
    {
        if (function_exists('posix_geteuid') ? posix_geteuid() !== 0 : trim((string) shell_exec('id -u')) !== '0') {
            $this->markTestSkipped('El helper del administrador se ejecuta como root.');
        }
    }

    /** @param list<string> $arguments */
    private function helper(array $arguments): Process
    {
        $process = new Process(['bash', base_path('remote-agent/mwt-destination-profile'), ...$arguments], null, [
            'MWT_AGENT_DESTINATIONS_DIR' => $this->root.'/destinations', 'MWT_AGENT_TOOLS_DIR' => $this->root.'/tools',
        ], null, 120);
        $process->run();

        return $process;
    }

    private function installedTree(): string
    {
        return $this->root.'/tools/'.CheckRemoteDestination::DISTRIBUTION_KEY.'/'.$this->distribution->distribution_sha256;
    }
}
