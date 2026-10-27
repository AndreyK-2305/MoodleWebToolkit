<?php

namespace Tests\Feature\Remote;

use App\Domain\Remote\CheckRemoteSource;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Domain\Remote\RemoteAgent;
use App\Domain\Remote\RemoteAgentInstaller;
use App\Domain\Remote\RemoteServerConnections;
use App\Domain\Remote\RemoteServerPreparation;
use App\Domain\Remote\RemoteToolDeployer;
use App\Domain\Remote\S3ClientFactory;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Exceptions\ToolOperationBlocked;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ToolDistribution;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use Database\Seeders\ToolCatalogSeeder;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Domain\DomainTestCase;

/**
 * Deploys the real Collector 7.4.2 tree through the test-only local transport
 * and checks that the agent installs it only when its canonical hash matches.
 */
class RemoteToolDeploymentTest extends DomainTestCase
{
    private const KEY = 'moodle-recolector-7.4.2-linux-tree';

    private string $root;

    private Server $server;

    private ToolDistribution $distribution;

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v setsid')) === '') {
            $this->markTestSkipped('El agente requiere Linux con setsid.');
        }
        $this->root = sys_get_temp_dir().'/mwt-tools-'.Str::uuid()->toString();
        mkdir($this->root.'/sources', 0755, true);
        config([
            'toolkit.features.remote_agent.enabled' => true,
            'toolkit.remote.local_transport' => true,
            'toolkit.remote.agent_install_root' => $this->root.'/opt',
            'toolkit.remote.agent_state_dir' => $this->root.'/state',
            'toolkit.remote.agent_tools_dir' => $this->root.'/tools',
            'toolkit.remote.agent_sources_dir' => $this->root.'/sources',
        ]);
        $this->seed(ToolCatalogSeeder::class);
        $this->distribution = ToolDistribution::query()->where('key', self::KEY)->sole();
        $this->server = $this->project()->servers()->create(['name' => 'Origen', 'role' => ServerRole::SOURCE, 'host' => 'origen.example.edu']);
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

    public function test_verified_tree_is_installed_once_and_matches_the_catalog_hash(): void
    {
        $result = app(RemoteToolDeployer::class)->deploy($this->server, $this->distribution, null);

        $this->assertTrue($result['installed'], 'Error: '.$result['error_code']);
        $this->assertFalse($result['already_present']);
        $this->assertGreaterThan(1, $result['chunks'], 'La distribución debe viajar en varios fragmentos.');
        $this->assertSame($this->distribution->distribution_sha256, $result['tree_sha256']);
        $status = app(RemoteToolDeployer::class)->status($this->server, $this->distribution);
        $this->assertTrue($status['matches']);
        $this->assertSame($this->distribution->file_count, $status['file_count']);
        $this->assertSame(0, $status['unsafe_entries']);
        $this->assertDirectoryDoesNotExist($this->root.'/state/staging/'.Str::uuid());
        $this->assertSame([], glob($this->root.'/state/staging/*') ?: []);
        $this->assertSame($result['tree_sha256'], $this->server->refresh()->metadata['remote_tools'][self::KEY]['tree_sha256']);

        $again = app(RemoteToolDeployer::class)->deploy($this->server, $this->distribution, null);
        $this->assertTrue($again['already_present']);
        $this->assertSame(2, AuditLog::query()->where('action', 'REMOTE_TOOL_DEPLOYED')->count());
    }

    public function test_tampered_tree_is_reported_and_never_launched(): void
    {
        app(RemoteToolDeployer::class)->deploy($this->server, $this->distribution, null);
        $installed = $this->root.'/tools/'.self::KEY.'/'.$this->distribution->distribution_sha256;
        file_put_contents($installed.'/EXPORTAR-ORIGEN.sh', "\n# alterado\n", FILE_APPEND);
        $this->writeSource('lab', ['MOODLE_CONFIG='.$this->root.'/config.php', 'RUNTIME=native', 'RUN_AS='.$this->whoami()]);

        $this->assertFalse(app(RemoteToolDeployer::class)->status($this->server, $this->distribution)['matches']);
        $launch = $this->agent()->call($this->target(), 'launch', [(string) Str::uuid(), 'collector.742',
            'distribution='.$this->distribution->distribution_sha256, 'source=lab', 'package=origen-lab', 'workers=1']);
        $this->assertSame('tool_hash_mismatch', $launch->errorCode);
    }

    public function test_bundle_with_wrong_hash_or_unsafe_members_is_rejected(): void
    {
        $sha = $this->distribution->distribution_sha256;
        $staging = $this->stage(base64_encode('not a tarball'));
        $this->assertSame('bundle_hash_mismatch', $this->agent()->call($this->target(), 'tool-commit', [self::KEY, $sha, $staging, str_repeat('0', 64)])->errorCode);

        // -P keeps "../" in member names; plain tar would strip it while creating the archive.
        $evil = $this->root.'/evil';
        mkdir($evil.'/inner', 0755, true);
        file_put_contents($evil.'/escape.txt', 'outside');
        (new Process(['tar', '-P', '-czf', $this->root.'/escape.tgz', '-C', $evil.'/inner', '../escape.txt']))->mustRun();
        $bundle = (string) file_get_contents($this->root.'/escape.tgz');
        $staging = $this->stage(base64_encode($bundle));
        $this->assertSame('unsafe_entries', $this->agent()->call($this->target(), 'tool-commit', [self::KEY, $sha, $staging, hash('sha256', $bundle)])->errorCode);

        symlink('/etc/passwd', $evil.'/inner/passwd');
        (new Process(['tar', '-czf', $this->root.'/link.tgz', '-C', $evil.'/inner', '.']))->mustRun();
        $bundle = (string) file_get_contents($this->root.'/link.tgz');
        $staging = $this->stage(base64_encode($bundle));
        $this->assertSame('unsafe_entries', $this->agent()->call($this->target(), 'tool-commit', [self::KEY, $sha, $staging, hash('sha256', $bundle)])->errorCode);
        $this->assertDirectoryDoesNotExist($this->root.'/tools/'.self::KEY.'/'.$sha);
    }

    public function test_source_profile_is_validated_and_reported_without_paths(): void
    {
        $missing = app(CheckRemoteSource::class)->handle($this->server, 'missing-source', null);
        $this->assertFalse($missing['ready']);
        $this->assertSame('source_not_found', $missing['error_code']);

        file_put_contents($this->root.'/config.php', "<?php\n");
        $this->writeSource('lab', ['MOODLE_CONFIG='.$this->root.'/config.php', 'RUNTIME=native', 'RUN_AS='.$this->whoami()]);
        $report = app(CheckRemoteSource::class)->handle($this->server, 'lab', null);
        $this->assertNull($report['error_code']);
        $this->assertSame(PHP_VERSION, $report['report']['php_version']);
        $this->assertTrue($report['report']['config_readable']);
        $this->assertStringNotContainsString($this->root, (string) json_encode($report));
        $this->assertSame(PHP_VERSION_ID < 80400 && $report['report']['zip'] && $report['report']['dom'], $report['ready'],
            'Moodle 4.5 solo admite PHP 8.1 a 8.3.');

        chmod($this->root.'/sources/lab.conf', 0666);
        $this->assertSame('source_profile_unsafe', app(CheckRemoteSource::class)->handle($this->server, 'lab', null)['error_code']);
        $this->writeSource('lab', ['MOODLE_CONFIG='.$this->root.'/../etc/config.php', 'RUNTIME=native', 'RUN_AS='.$this->whoami()]);
        $this->assertSame('source_profile_invalid', app(CheckRemoteSource::class)->handle($this->server, 'lab', null)['error_code']);
    }

    public function test_collector_operation_runs_the_verified_entrypoint_with_the_profile(): void
    {
        app(RemoteToolDeployer::class)->deploy($this->server, $this->distribution, null);
        file_put_contents($this->root.'/config.php', "<?php\n");
        $this->writeSource('lab', ['MOODLE_CONFIG='.$this->root.'/config.php', 'RUNTIME=native', 'RUN_AS='.$this->whoami()]);
        $uuid = (string) Str::uuid();

        $launch = $this->agent()->call($this->target(), 'launch', [$uuid, 'collector.742',
            'distribution='.$this->distribution->distribution_sha256, 'source=lab', 'package=origen-lab', 'workers=1']);
        $this->assertTrue($launch->ok, 'Error: '.$launch->errorCode);

        $deadline = hrtime(true) + 60_000_000_000;
        do {
            $status = $this->agent()->call($this->target(), 'status', [$uuid])->data;
            usleep(250_000);
        } while (($status['exit'] === null || $status['process_alive']) && hrtime(true) < $deadline);

        // The fake config.php is not a Moodle site: the real entrypoint starts and fails closed.
        $this->assertNotNull($status['exit']);
        $this->assertNotSame(0, $status['exit']['exit_code']);
        $this->assertDirectoryExists($this->root.'/state/ops/'.$uuid.'/work/output');
        $this->assertDirectoryExists($this->root.'/state/ops/'.$uuid.'/work/tmp');
        $this->assertGreaterThan(0, $status['stdout_bytes'] + $status['stderr_bytes'], 'El Recolector debe haber producido salida propia.');
        $this->assertSame(['distribution' => $this->distribution->distribution_sha256, 'package' => 'origen-lab',
            'source' => 'lab', 'workers' => '1'], $status['launch']['parameters']);

        $report = $this->agent()->call($this->target(), 'collector-report', [$uuid]);
        $this->assertTrue($report->ok, 'Error: '.$report->errorCode);
        $this->assertSame('origen-lab', $report->data['package']);
        $this->assertNull($report->data['sidecar']);
        $collectorStatus = json_decode((string) base64_decode((string) $report->data['status_base64'], true), true);
        $this->assertSame('error', $collectorStatus['state'], 'El estado proviene del documento que escribe el Recolector.');
        $this->assertSame('operation_not_found', $this->agent()->call($this->target(), 'collector-report', [(string) Str::uuid()])->errorCode);
    }

    public function test_the_agent_detects_moodle_by_presence_and_creates_the_profile_from_the_web(): void
    {
        // Two installations (3.x-4.x layout and the 5.1+ public/ layout) and a config.php that is not Moodle.
        foreach (['www/moodle/lib', 'www/otro', 'opt/m5/public/lib'] as $directory) {
            mkdir($this->root.'/'.$directory, 0755, true);
        }
        foreach (['www/moodle/config.php', 'www/moodle/version.php', 'www/moodle/lib/setup.php', 'www/otro/config.php',
            'opt/m5/config.php', 'opt/m5/public/version.php', 'opt/m5/public/lib/setup.php'] as $file) {
            file_put_contents($this->root.'/'.$file, "<?php\n// \$CFG->dbpass = 'secreto';\n");
        }
        $this->fakeAws();
        $this->environment(['MWT_AGENT_DETECT_ROOTS' => $this->root.'/www '.$this->root.'/opt']);
        $preparation = app(RemoteServerPreparation::class);
        $admin = $this->user(UserRole::ADMIN);
        try {
            $detected = $preparation->detectMoodle($this->server, $admin);
            $this->assertTrue($detected['detected'], (string) $detected['error_code']);
            $this->assertSame([$this->root.'/opt/m5/config.php', $this->root.'/www/moodle/config.php'],
                collect($detected['candidates'])->where('runtime', 'native')->pluck('config_path')->sort()->values()->all());
            $this->assertStringNotContainsString('secreto', (string) json_encode($this->server->refresh()->metadata));

            $input = ['source' => 'lab', 'config_path' => $this->root.'/www/moodle/config.php', 'runtime' => 'native', 'run_as' => $this->whoami()];
            $created = $preparation->createSource($this->server, $admin, $input);
            $this->assertTrue($created['created'], (string) $created['error_code']);
            $this->assertFalse($created['replaced']);
            $profile = $this->root.'/sources/lab.conf';
            $this->assertSame('0600', substr(sprintf('%o', fileperms($profile)), -4));
            $this->assertStringContainsString('MOODLE_CONFIG='.$this->root."/www/moodle/config.php\nRUNTIME=native\nRUN_AS=".$this->whoami()."\n",
                (string) file_get_contents($profile));
            // The check the agent ran right after: the CPUs and AWS CLI v2 for the workers and the upload.
            $report = $this->server->refresh()->metadata['remote_sources']['lab']['report'];
            $this->assertSame([(int) trim((string) shell_exec('getconf _NPROCESSORS_ONLN')), '2.17.1', true],
                [$report['cpus'], $report['aws_cli_version'], $report['aws_cli_v2']]);
            $this->assertStringNotContainsString($this->root, (string) json_encode(AuditLog::query()->get()->toArray()));

            // Never overwritten without force; only a real Moodle tree; never a link.
            $this->assertSame('source_profile_exists', $preparation->createSource($this->server, $admin, $input)['error_code']);
            $this->assertTrue($preparation->createSource($this->server, $admin, [...$input, 'force' => true])['replaced']);
            $this->assertSame('not_a_moodle_config', $preparation->createSource($this->server, $admin,
                [...$input, 'source' => 'otro', 'config_path' => $this->root.'/www/otro/config.php'])['error_code']);
            $this->assertSame('config_missing', $preparation->createSource($this->server, $admin,
                [...$input, 'source' => 'falta', 'config_path' => $this->root.'/www/falta/config.php'])['error_code']);
            $this->assertSame('run_as_missing', $preparation->createSource($this->server, $admin,
                [...$input, 'source' => 'nadie', 'run_as' => 'mwt-no-existe'])['error_code']);
            $this->assertSame('invalid_arguments', $this->agent()->call($this->target(), 'source-profile-create',
                ['lab', 'config='.RemoteAgent::text($this->root.'/www/moodle/config.php'), 'shell=1'])->errorCode);
            $this->assertSame('invalid_config_path', $this->agent()->call($this->target(), 'source-profile-create',
                ['lab', 'config='.RemoteAgent::text('/etc/../etc/config.php')])->errorCode);
            $this->assertFileDoesNotExist($this->root.'/sources/otro.conf');
        } finally {
            $this->environment(['MWT_AGENT_DETECT_ROOTS' => null, 'PATH' => $this->path, 'MWT_FAKE_S3' => null]);
        }
    }

    public function test_the_upload_probe_writes_with_the_instance_role_and_the_platform_confirms_it(): void
    {
        $this->fakeAws();
        config(['toolkit.remote.package_bucket' => 'mwt-test-packages', 'toolkit.remote.package_region' => 'us-east-2']);
        // The platform's S3 identity reads the same local directory the fake AWS CLI writes.
        $deleted = [];
        $client = new S3Client(['region' => 'us-east-2', 'version' => '2006-03-01', 'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => function (CommandInterface $command) use (&$deleted) {
                $file = $this->root.'/s3/'.$command['Bucket'].'/'.$command['Key'];
                if ($command->getName() === 'DeleteObject') {
                    $deleted[] = $command['Key'];

                    return Create::promiseFor(new Result([]));
                }

                return Create::promiseFor(new Result(['ContentLength' => filesize($file), 'Metadata' => ['mwt-sha256' => hash_file('sha256', $file)]]));
            }]);
        $this->app->instance(S3ClientFactory::class, new class($client) extends S3ClientFactory
        {
            public function __construct(private readonly S3Client $client) {}

            public function make(string $region): S3Client
            {
                return $this->client;
            }
        });
        try {
            $result = app(RemoteServerPreparation::class)->probeStorage($this->server, null);

            $this->assertTrue($result['ok'], (string) $result['error_code']);
            $probe = $this->server->refresh()->metadata['remote_storage_probe'];
            $this->assertMatchesRegularExpression('#^mwt/_pruebas/i-0123456789abcdef0/[0-9a-f]{16}$#', $probe['key']);
            $this->assertSame([$probe['key']], $deleted);
            $this->assertSame('invalid_arguments', $this->agent()->call($this->target(), 's3-upload-probe', ['Bucket_Malo', 'us-east-2'])->errorCode);
            $this->assertSame('invalid_arguments', $this->agent()->call($this->target(), 's3-upload-probe', ['mwt-test-packages', 'us-east-2', 'delete'])->errorCode);
            // A role that cannot write the bucket.
            $this->environment(['MWT_FAKE_S3_DENY' => '1']);
            $this->assertSame('storage_put_failed', app(RemoteServerPreparation::class)->probeStorage($this->server, null)['error_code']);
        } finally {
            $this->environment(['PATH' => $this->path, 'MWT_FAKE_S3' => null, 'MWT_FAKE_S3_DENY' => null]);
        }
    }

    public function test_deployment_requires_a_tested_connection(): void
    {
        $this->server->connections()->update(['status' => ConnectionStatus::INVALID->value]);

        $this->expectException(ToolOperationBlocked::class);
        app(RemoteToolDeployer::class)->deploy($this->server, $this->distribution, null);
    }

    private function stage(string $base64): string
    {
        $staging = (string) Str::uuid();
        mkdir($this->root.'/state/staging/'.$staging, 0700, true);
        file_put_contents($this->root.'/state/staging/'.$staging.'/part-000000', $base64);

        return $staging;
    }

    /** @param list<string> $lines */
    private function writeSource(string $id, array $lines): void
    {
        $path = $this->root.'/sources/'.$id.'.conf';
        file_put_contents($path, implode("\n", $lines)."\n");
        chmod($path, 0644);
    }

    /** A test double of the AWS CLI v2 that reads and writes a local directory instead of S3. */
    private function fakeAws(): void
    {
        mkdir($this->root.'/bin', 0755, true);
        file_put_contents($this->root.'/bin/aws', implode("\n", ['#!/bin/bash', 'set -e',
            'if [[ $1 == --version ]]; then echo "aws-cli/2.17.1 Python/3.11.9 Linux/6.1 exe/x86_64"; exit; fi',
            'if [[ $1 == s3 && $2 == cp ]]; then',
            '    if [[ $3 == s3://* ]]; then cp "$MWT_FAKE_S3/${3#s3://}" "$4"; exit; fi',
            '    [[ -z ${MWT_FAKE_S3_DENY:-} ]] || exit 1',
            '    mkdir -p "$(dirname "$MWT_FAKE_S3/${4#s3://}")"; cp "$3" "$MWT_FAKE_S3/${4#s3://}"; exit',
            'fi', 'exit 2', '']));
        chmod($this->root.'/bin/aws', 0755);
        $this->path = (string) getenv('PATH');
        // Symfony Process inherits $_SERVER/$_ENV, so the double is placed there as well as in the environment.
        $this->environment(['PATH' => $this->root.'/bin:'.$this->path, 'MWT_FAKE_S3' => $this->root.'/s3']);
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

    private function whoami(): string
    {
        return trim((string) shell_exec('id -un'));
    }

    private function agent(): RemoteAgent
    {
        return app(RemoteAgent::class);
    }

    private function target(): RemoteTarget
    {
        return app(RemoteServerConnections::class)->target($this->server);
    }
}
