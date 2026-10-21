<?php

namespace Tests\Feature\Remote;

use App\Domain\Remote\DTOs\RemoteTarget;
use App\Domain\Remote\RemoteAgent;
use App\Domain\Remote\RemoteAgentInstaller;
use App\Enums\ConnectionType;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Exercises the real mwt-agent through the test-only local bash transport:
 * the same scripts that SSM or SSH would carry, without AWS or a server.
 */
class RemoteAgentLifecycleTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $launched = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v setsid')) === '') {
            $this->markTestSkipped('El ciclo de vida del agente requiere Linux con setsid.');
        }
        $this->root = sys_get_temp_dir().'/mwt-agent-test-'.Str::uuid()->toString();
        mkdir($this->root, 0700, true);
        config([
            'toolkit.remote.local_transport' => true,
            'toolkit.remote.agent_install_root' => $this->root.'/opt',
            'toolkit.remote.agent_state_dir' => $this->root.'/state',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            foreach ($this->launched as $uuid) {
                try {
                    $this->agent()->call($this->target(), 'cancel', [$uuid]);
                } catch (RuntimeException) {
                    // Best effort: the temporary tree is removed below.
                }
            }
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_agent_is_reported_missing_until_installed_and_verified_by_hash(): void
    {
        $missing = $this->agent()->call($this->target(), 'version');
        $this->assertFalse($missing->ok);
        $this->assertSame('agent_not_installed', $missing->errorCode);
        $this->assertSame(127, $missing->transport->exitCode);

        $installed = app(RemoteAgentInstaller::class)->install($this->target());
        $distribution = $this->agent()->distribution();
        $this->assertTrue($installed->ok);
        $this->assertSame($distribution['version'], $installed->agentVersion);
        $this->assertSame($distribution['sha256'], $installed->data['agent_sha256']);
        $this->assertSame($distribution['sha256'], hash_file('sha256', $this->agent()->installedPath()));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->root.'/state')), -4));

        // Reinstalling the same version is idempotent.
        $this->assertTrue(app(RemoteAgentInstaller::class)->install($this->target())->ok);

        $probe = $this->agent()->call($this->target(), 'probe');
        $this->assertTrue($probe->ok);
        $this->assertSame('nohup', $probe->data['launcher']);
        $this->assertIsInt($probe->data['disk_free_bytes']);
        $this->assertSame($distribution['sha256'], $probe->data['agent_sha256']);
    }

    public function test_detached_operation_survives_the_call_and_cancellation_is_recorded(): void
    {
        app(RemoteAgentInstaller::class)->install($this->target());
        $uuid = $this->launch('diagnostic.sleep', ['seconds=60']);

        $first = $this->agent()->call($this->target(), 'launch', [$uuid, 'diagnostic.sleep', 'seconds=60']);
        $this->assertTrue($first->ok);
        $this->assertFalse($first->data['created'], 'Un lanzamiento repetido no debe crear otro proceso.');
        $conflict = $this->agent()->call($this->target(), 'launch', [$uuid, 'diagnostic.fail', 'code=3']);
        $this->assertSame('operation_conflict', $conflict->errorCode);

        $running = $this->waitForStatus($uuid, fn (array $data): bool => $data['process_alive'] === true && $data['heartbeat_at'] !== null);
        $this->assertNull($running['exit']);
        $this->assertSame('nohup', $running['launch']['launcher']);
        $this->assertIsInt($running['run']['pid']);

        $cancel = $this->agent()->call($this->target(), 'cancel', [$uuid]);
        $this->assertTrue($cancel->data['signalled']);
        // exit.json is written just before the supervisor process ends; wait for both.
        $finished = $this->waitForStatus($uuid, fn (array $data): bool => $data['exit'] !== null && $data['process_alive'] === false);
        $this->assertSame(143, $finished['exit']['exit_code']);
        $this->assertTrue($finished['exit']['cancel_requested']);

        $again = $this->agent()->call($this->target(), 'cancel', [$uuid]);
        $this->assertTrue($again->data['already_finished']);
    }

    public function test_exit_code_and_logs_come_from_durable_files(): void
    {
        app(RemoteAgentInstaller::class)->install($this->target());
        $uuid = $this->launch('diagnostic.fail', ['code=3']);

        $finished = $this->waitForStatus($uuid, fn (array $data): bool => $data['exit'] !== null);
        $this->assertSame(3, $finished['exit']['exit_code']);
        $this->assertFalse($finished['exit']['cancel_requested']);
        $this->assertSame(hash('sha256', ''), $finished['exit']['stdout_sha256']);

        $tail = $this->agent()->call($this->target(), 'tail', [$uuid, 'stdout', '0', '4096']);
        $this->assertTrue($tail->ok);
        $this->assertSame(0, $tail->data['size']);
        $this->assertSame(0, $tail->data['next_offset']);
    }

    public function test_unregistered_operations_and_unsafe_arguments_are_rejected(): void
    {
        app(RemoteAgentInstaller::class)->install($this->target());
        $uuid = Str::uuid()->toString();

        foreach ([
            [[$uuid, 'shell.exec', 'cmd=id'], 'unregistered_command'],
            [[$uuid, 'diagnostic.sleep', 'seconds=0'], 'invalid_parameter'],
            [[$uuid, 'diagnostic.sleep', 'seconds=10', 'extra=1'], 'invalid_parameter'],
            [['not-a-uuid', 'diagnostic.sleep', 'seconds=1'], 'invalid_operation_uuid'],
        ] as [$arguments, $code]) {
            $response = $this->agent()->call($this->target(), 'launch', $arguments);
            $this->assertFalse($response->ok);
            $this->assertSame($code, $response->errorCode);
        }
        $this->assertSame('operation_not_found', $this->agent()->call($this->target(), 'status', [$uuid])->errorCode);
        $this->assertDirectoryDoesNotExist($this->root.'/state/ops/'.$uuid);

        $this->expectException(RuntimeException::class);
        $this->agent()->call($this->target(), 'launch', [$uuid, 'diagnostic.sleep', 'seconds=1;id']);
    }

    public function test_cleanup_frees_only_heavy_outputs_of_a_finished_operation(): void
    {
        app(RemoteAgentInstaller::class)->install($this->target());
        $finished = $this->collectorOperation('pkg');
        $dir = $this->root.'/state/ops/'.$finished;

        $cleanup = $this->agent()->call($this->target(), 'cleanup', [$finished]);
        $this->assertTrue($cleanup->ok, (string) $cleanup->errorCode);
        $this->assertFalse($cleanup->data['already_clean']);
        $this->assertGreaterThanOrEqual(6_000_000, $cleanup->data['cleanup']['freed_bytes']);
        foreach (['work/tmp', 'work/output/pkg.zip', 'work/output/.moodle-collector-work-pkg'] as $removed) {
            $this->assertFileDoesNotExist($dir.'/'.$removed);
        }
        foreach (['launch.json', 'exit.json', 'stdout.log', 'cleanup.json', 'work/output/pkg.zip.sha256', 'work/output/pkg.status.json'] as $kept) {
            $this->assertFileExists($dir.'/'.$kept);
        }
        $again = $this->agent()->call($this->target(), 'cleanup', [$finished]);
        $this->assertTrue($again->data['already_clean']);

        // A running operation and a link to the outside are never touched.
        $running = $this->launch('diagnostic.sleep', ['seconds=30']);
        $this->waitForStatus($running, fn (array $data): bool => $data['process_alive'] === true);
        $this->assertSame('operation_active', $this->agent()->call($this->target(), 'cleanup', [$running])->errorCode);
        $linked = $this->collectorOperation('pkg');
        $outside = $this->root.'/outside';
        mkdir($outside);
        file_put_contents($outside.'/keep', 'keep');
        unlink($this->root.'/state/ops/'.$linked.'/work/output/pkg.zip');
        symlink($outside.'/keep', $this->root.'/state/ops/'.$linked.'/work/output/pkg.zip');
        $this->assertSame('cleanup_unsafe_path', $this->agent()->call($this->target(), 'cleanup', [$linked])->errorCode);
        $this->assertFileExists($outside.'/keep');
        $this->assertDirectoryExists($this->root.'/state/ops/'.$linked.'/work/tmp');
    }

    /** A finished collector.742 operation as the agent leaves it on disk. */
    private function collectorOperation(string $package): string
    {
        $uuid = Str::uuid()->toString();
        $dir = $this->root.'/state/ops/'.$uuid;
        mkdir($dir.'/work/output/.moodle-collector-work-'.$package.'/backups', 0700, true);
        mkdir($dir.'/work/tmp', 0700, true);
        file_put_contents($dir.'/launch.json', json_encode(['operation_uuid' => $uuid, 'command_key' => 'collector.742',
            'launcher' => 'nohup', 'parameters' => ['package' => $package, 'source' => 'lab-src']]));
        file_put_contents($dir.'/exit.json', '{"exit_code":0,"cancel_requested":false}');
        file_put_contents($dir.'/stdout.log', "fin\n");
        file_put_contents($dir.'/work/output/'.$package.'.zip', str_repeat("\0", 3_000_000));
        file_put_contents($dir.'/work/output/'.$package.'.zip.sha256', str_repeat('a', 64).'  '.$package.".zip\n");
        file_put_contents($dir.'/work/output/'.$package.'.status.json', '{}');
        file_put_contents($dir.'/work/output/.moodle-collector-work-'.$package.'/backups/c1.mbz', str_repeat("\0", 2_000_000));
        file_put_contents($dir.'/work/tmp/t', str_repeat("\0", 1_000_000));

        return $uuid;
    }

    /** @param list<string> $parameters */
    private function launch(string $key, array $parameters): string
    {
        $uuid = Str::uuid()->toString();
        $response = $this->agent()->call($this->target(), 'launch', [$uuid, $key, ...$parameters]);
        $this->assertTrue($response->ok, 'El agente rechazó el lanzamiento: '.$response->errorCode);
        $this->assertTrue($response->data['created']);
        $this->launched[] = $uuid;

        return $uuid;
    }

    /**
     * @param  callable(array<string, mixed>): bool  $condition
     * @return array<string, mixed>
     */
    private function waitForStatus(string $uuid, callable $condition, int $seconds = 20): array
    {
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        do {
            $status = $this->agent()->call($this->target(), 'status', [$uuid]);
            $this->assertTrue($status->ok);
            if ($condition($status->data)) {
                return $status->data;
            }
            usleep(200_000);
        } while (hrtime(true) < $deadline);
        $this->fail('El estado esperado de la operación no se observó a tiempo.');
    }

    private function agent(): RemoteAgent
    {
        return app(RemoteAgent::class);
    }

    private function target(): RemoteTarget
    {
        return new RemoteTarget(ConnectionType::AWS_SSM, '00000000-0000-4000-8000-000000000002', instanceId: 'i-0123456789abcdef0', region: 'us-east-1');
    }
}
