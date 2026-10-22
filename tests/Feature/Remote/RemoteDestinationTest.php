<?php

namespace Tests\Feature\Remote;

use App\Domain\Projects\ProjectWizard;
use App\Domain\Remote\CheckRemoteDestination;
use App\Domain\Remote\DetectRemoteDestinations;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Domain\Remote\PrepareRemoteDestination;
use App\Domain\Remote\RemoteAgent;
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
        $this->assertDirectoryExists($runtime.'/managed-config');
        // Inside the runtime: the assistant's container writes only there.
        $this->assertStringContainsString('MOODLE_MANAGED_CONFIG_DIR='.$runtime."/managed-config\n", $env);
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
        $this->assertSame([], array_diff($check['blockers'], ['docker_unavailable', 'compose_v2_missing', 'flock_missing', 'python3_missing', 'run_as_not_docker']));
        // The runtime belongs to the unprivileged user the helper created; the Consolidator refuses root.
        $this->assertSame(['mwt-campus', true], [$report['run_as'], $report['runtime_owned']]);
        $this->assertSame('mwt-campus', posix_getpwuid(fileowner($runtime.'/.env'))['name']);
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
        file_put_contents($this->root.'/destinations/campus.conf', "RUNTIME_DIR={$runtime}\nPUBLIC_URL=".self::URL."\nTOOL_TREE=".str_repeat('a', 64)."\nRUN_AS=mwt-campus\n");
        $check = app(CheckRemoteDestination::class)->handle($this->server, 'campus', null);
        $this->assertSame([], array_diff(['tool_missing', 'tool_tree_mismatch'], $check['blockers']));

        // A runtime file left to root keeps the Consolidator from running as its user.
        touch($runtime.'/de-root');
        $this->assertContains('runtime_not_owned', app(CheckRemoteDestination::class)->handle($this->server, 'campus', null)['blockers']);
        unlink($runtime.'/de-root');
        // Neither root nor a missing user can run the Consolidator.
        foreach (['root' => 'destination_user_invalid', 'mwt-nadie' => 'destination_user_missing'] as $user => $code) {
            file_put_contents($this->root.'/destinations/campus.conf', "RUNTIME_DIR={$runtime}\nPUBLIC_URL=".self::URL."\nTOOL_TREE=".str_repeat('a', 64)."\nRUN_AS={$user}\n");
            $this->assertSame($code, app(CheckRemoteDestination::class)->handle($this->server, 'campus', null)['error_code']);
        }

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

    public function test_the_real_fetch_moves_only_a_verified_package_into_copias(): void
    {
        $this->requireRoot();
        DeployRemoteTool::dispatchSync($this->server->getKey(), $this->distribution->getKey(), null);
        $runtime = $this->root.'/srv/campus';
        $this->assertSame(0, $this->helper(['campus', '--public-url', self::URL, '--admin-email', 'admin@example.edu', '--runtime-dir', $runtime])->getExitCode());
        // A test double of the AWS CLI reads objects from a local directory instead of S3.
        $s3 = $this->root.'/s3';
        mkdir($this->root.'/bin', 0755, true);
        file_put_contents($this->root.'/bin/aws', implode("\n", ['#!/bin/bash', 'set -e',
            'if [[ $1 == s3 && $2 == cp ]]; then src=${3#s3://}; src=${src#*/}; cp "$MWT_FAKE_S3/$src" "$4"; exit; fi',
            'if [[ $1 == s3api && $2 == head-object ]]; then shift 2; while (($#)); do case $1 in --key) key=$2; shift 2 ;; *) shift ;; esac; done',
            '    [[ -f $MWT_FAKE_S3/$key ]] || exit 254; stat -c %s "$MWT_FAKE_S3/$key"; exit; fi', 'exit 2', '']));
        chmod($this->root.'/bin/aws', 0755);
        $path = (string) getenv('PATH');
        // Symfony Process inherits $_SERVER/$_ENV, so the double is placed there as well as in the environment.
        $this->environment(['PATH' => $this->root.'/bin:'.$path, 'MWT_FAKE_S3' => $s3]);
        try {
            $project = (string) Str::uuid();
            $zip = random_bytes(5000);
            $sha = hash('sha256', $zip);
            mkdir("{$s3}/mwt/{$project}/pregrado", 0755, true);
            file_put_contents("{$s3}/mwt/{$project}/pregrado/{$sha}.zip", $zip);
            file_put_contents("{$s3}/mwt/{$project}/pregrado/{$sha}.zip.sha256", "{$sha}  pregrado.zip\n");
            $parameters = fn (string $hash): array => ['bucket=kevin-moodle-backups-2026', 'destination=campus', 'project='.$project,
                'region=us-east-2', 'sha='.$hash, 'source=pregrado'];

            $first = $this->fetchWithAgent($parameters($sha));
            $stderr = $this->agent()->call($this->target(), 'tail', [$first['operation_uuid'], 'stderr', '0', '4096'])->data['data_base64'] ?? '';
            $this->assertSame(0, $first['exit']['exit_code'], (string) base64_decode((string) $stderr));
            $this->assertSame($zip, file_get_contents($runtime.'/copias/pregrado.zip'));
            $this->assertSame("{$sha}  pregrado.zip\n", file_get_contents($runtime.'/copias/pregrado.zip.sha256'));
            $this->assertSame([], glob($runtime.'/copias/.incoming-*') ?: [], 'El staging no queda en copias/.');
            // The Consolidator moves and extracts it as the profile's user.
            $this->assertSame('mwt-campus', posix_getpwuid(fileowner($runtime.'/copias/pregrado.zip'))['name']);
            $report = json_decode((string) base64_decode((string) $this->agent()->call($this->target(), 'fetch-report', [$first['operation_uuid']])->data['result_base64']), true);
            $this->assertSame(['pregrado.zip', $sha, false], [$report['name'], $report['sha256'], $report['already_present']]);
            $this->assertTrue(json_decode((string) base64_decode((string) $this->agent()->call($this->target(), 'fetch-report',
                [$this->fetchWithAgent($parameters($sha))['operation_uuid']])->data['result_base64']), true)['already_present']);

            // A sidecar that declares another hash never reaches copias/.
            $other = hash('sha256', 'otro');
            copy("{$s3}/mwt/{$project}/pregrado/{$sha}.zip", "{$s3}/mwt/{$project}/pregrado/{$other}.zip");
            file_put_contents("{$s3}/mwt/{$project}/pregrado/{$other}.zip.sha256", "{$sha}  otro.zip\n");
            $this->assertSame(53, $this->fetchWithAgent($parameters($other))['exit']['exit_code']);
            // Right sidecar, wrong bytes: the downloaded ZIP is discarded.
            file_put_contents("{$s3}/mwt/{$project}/pregrado/{$other}.zip.sha256", "{$other}  otro.zip\n");
            $this->assertSame(58, $this->fetchWithAgent($parameters($other))['exit']['exit_code']);
            $this->assertFileDoesNotExist($runtime.'/copias/otro.zip');

            $list = $this->agent()->call($this->target(), 'copias-list', ['campus']);
            $this->assertSame([['name' => 'pregrado.zip', 'size' => 5000, 'sidecar_sha256' => $sha]], $list->data['packages']);
            $this->assertStringNotContainsString($runtime, $list->transport->stdout);

            // Once the Consolidator wrote to the site, copias/ is fixed.
            is_dir($runtime.'/reports') || mkdir($runtime.'/reports', 0700, true);
            file_put_contents($runtime.'/reports/destination-write.lock.json', '{}');
            $this->assertSame('destination_already_written', $this->agent()->call($this->target(), 'copias-remove', ['campus', 'pregrado.zip'])->errorCode);
            $this->assertSame('destination_already_written', $this->agent()->call($this->target(), 'launch',
                [(string) Str::uuid(), 'package.fetch', ...$parameters($sha)])->errorCode);
            unlink($runtime.'/reports/destination-write.lock.json');
            $this->assertTrue($this->agent()->call($this->target(), 'copias-remove', ['campus', 'pregrado.zip'])->data['removed']);
            $this->assertFileDoesNotExist($runtime.'/copias/pregrado.zip.sha256');
        } finally {
            $this->environment(['PATH' => $path, 'MWT_FAKE_S3' => null]);
        }
    }

    public function test_readiness_blocks_on_missing_facts_and_warns_on_capacity(): void
    {
        $tree = str_repeat('b', 64);
        $report = ['public_url' => self::URL, 'env_present' => true, 'env_protected' => true, 'env_complete' => true, 'env_matches_url' => true,
            'tool_tree' => $tree, 'tool_present' => true, 'engine_intact' => true, 'docker_version' => '27.3.1', 'compose_version' => '2.29.7',
            'flock' => true, 'python3' => true, 'memory_bytes' => 2_000_000_000, 'runtime_free_bytes' => 10 * 1073741824, 'destination_written' => false,
            'run_as' => 'mwt-campus', 'run_as_docker' => true, 'runtime_owned' => true];

        $this->assertSame([[], ['low_memory', 'low_disk']], CheckRemoteDestination::assess($report, $tree));
        $this->assertSame([['runtime_not_owned', 'run_as_not_docker'], ['low_memory', 'low_disk']],
            CheckRemoteDestination::assess([...$report, 'runtime_owned' => false, 'run_as_docker' => false], $tree));
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

    /**
     * Launches package.fetch with the real agent and waits for its exit.
     *
     * @param  list<string>  $parameters
     * @return array<string, mixed>
     */
    private function fetchWithAgent(array $parameters): array
    {
        $uuid = (string) Str::uuid();
        $launch = $this->agent()->call($this->target(), 'launch', [$uuid, 'package.fetch', ...$parameters]);
        $this->assertTrue($launch->ok, 'Error: '.$launch->errorCode);
        $deadline = hrtime(true) + 30_000_000_000;
        do {
            usleep(200_000);
            $status = $this->agent()->call($this->target(), 'status', [$uuid])->data;
        } while ($status['exit'] === null && hrtime(true) < $deadline);
        $this->assertNotNull($status['exit'], 'La transferencia no terminó a tiempo.');

        return [...$status, 'operation_uuid' => $uuid];
    }

    private function agent(): RemoteAgent
    {
        return app(RemoteAgent::class);
    }

    private function target(): RemoteTarget
    {
        return app(RemoteServerConnections::class)->target($this->server);
    }

    /** @param array<string, string|null> $variables */
    private function environment(array $variables): void
    {
        foreach ($variables as $name => $value) {
            if ($value === null) {
                putenv($name);
                unset($_SERVER[$name], $_ENV[$name]);
            } else {
                putenv($name.'='.$value);
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
        }
    }

    private function installedTree(): string
    {
        return $this->root.'/tools/'.CheckRemoteDestination::DISTRIBUTION_KEY.'/'.$this->distribution->distribution_sha256;
    }
}
