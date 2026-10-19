<?php

namespace Tests\Feature\Remote;

use App\Domain\Remote\RemoteAgentInstaller;
use App\Domain\Remote\RemoteAgentOperations;
use App\Domain\Remote\RemoteServerConnections;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\LogStream;
use App\Enums\RemoteCommunicationState;
use App\Enums\RemoteFunctionalState;
use App\Enums\ServerRole;
use App\Models\Execution;
use App\Models\ExecutionLog;
use App\Models\RemoteOperation;
use App\Models\Server;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\Domain\DomainTestCase;

/**
 * Remote operations against the real agent through the test-only local
 * transport: the platform launches, loses the call, observes and cancels.
 */
class RemoteAgentOperationsEndToEndTest extends DomainTestCase
{
    private string $root;

    private Server $server;

    private Execution $execution;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v setsid')) === '') {
            $this->markTestSkipped('Las operaciones reales del agente requieren Linux con setsid.');
        }
        $this->root = sys_get_temp_dir().'/mwt-agent-ops-'.Str::uuid()->toString();
        mkdir($this->root, 0700, true);
        config([
            'toolkit.features.remote_agent.enabled' => true,
            'toolkit.remote.local_transport' => true,
            'toolkit.remote.agent_install_root' => $this->root.'/opt',
            'toolkit.remote.agent_state_dir' => $this->root.'/state',
        ]);
        $project = $this->project();
        $this->execution = $this->execution($project);
        $this->server = $project->servers()->create(['name' => 'Destino', 'role' => ServerRole::DESTINATION, 'host' => 'destino.example.edu']);
        $connections = app(RemoteServerConnections::class);
        $connections->configure($this->server, ConnectionType::AWS_SSM, ['instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-1'], null, null)
            ->update(['status' => ConnectionStatus::VALID]);
        $this->assertTrue(app(RemoteAgentInstaller::class)->install($connections->target($this->server))->ok);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            foreach (RemoteOperation::query()->where('communication_state', '!=', 'TERMINATED')->get() as $operation) {
                rescue(fn () => app(RemoteAgentOperations::class)->cancel($operation, null), report: false);
            }
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_operation_output_and_success_are_observed_from_durable_state(): void
    {
        $operation = app(RemoteAgentOperations::class)->launch($this->execution, $this->server, 'lines-1', 'diagnostic.lines', ['count' => '3'], null);
        $this->assertStringStartsWith('nohup:', (string) $operation->process_id);

        $operation = $this->reconcileUntil($operation, fn (RemoteOperation $op): bool => $op->communication_state === RemoteCommunicationState::TERMINATED);

        $this->assertSame(RemoteFunctionalState::SUCCEEDED, $operation->functional_state);
        $this->assertSame(0, $operation->exit_code);
        $logs = ExecutionLog::query()->where('remote_operation_id', $operation->id)->orderBy('id')->get();
        $this->assertSame(['linea 1', 'linea 2', 'linea 3'], $logs->where('stream', LogStream::STDOUT)->pluck('message')->values()->all());
        $this->assertSame(['fin'], $logs->where('stream', LogStream::STDERR)->pluck('message')->values()->all());

        // Observing again neither duplicates logs nor changes the closed result.
        app(RemoteAgentOperations::class)->reconcile($operation);
        $this->assertSame(4, ExecutionLog::query()->where('remote_operation_id', $operation->id)->count());
    }

    public function test_long_operation_is_cancelled_on_the_server(): void
    {
        $operation = app(RemoteAgentOperations::class)->launch($this->execution, $this->server, 'sleep-1', 'diagnostic.sleep', ['seconds' => '120'], null);
        $operation = $this->reconcileUntil($operation, fn (RemoteOperation $op): bool => $op->last_heartbeat_at !== null);
        $this->assertSame(RemoteCommunicationState::CONNECTED, $operation->communication_state);
        $this->assertSame(RemoteFunctionalState::RUNNING, $operation->functional_state);

        app(RemoteAgentOperations::class)->cancel($operation, null);
        $operation = $this->reconcileUntil($operation, fn (RemoteOperation $op): bool => $op->communication_state === RemoteCommunicationState::TERMINATED);

        $this->assertSame(RemoteFunctionalState::CANCELLED, $operation->functional_state);
        $this->assertSame(143, $operation->exit_code);
    }

    public function test_failure_exit_code_is_preserved(): void
    {
        $operation = app(RemoteAgentOperations::class)->launch($this->execution, $this->server, 'fail-1', 'diagnostic.fail', ['code' => '7'], null);
        $operation = $this->reconcileUntil($operation, fn (RemoteOperation $op): bool => $op->communication_state === RemoteCommunicationState::TERMINATED);

        $this->assertSame(RemoteFunctionalState::FAILED, $operation->functional_state);
        $this->assertSame(7, $operation->exit_code);
    }

    /** @param callable(RemoteOperation): bool $condition */
    private function reconcileUntil(RemoteOperation $operation, callable $condition, int $seconds = 20): RemoteOperation
    {
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        do {
            $operation = app(RemoteAgentOperations::class)->reconcile($operation);
            if ($condition($operation)) {
                return $operation;
            }
            usleep(250_000);
        } while (hrtime(true) < $deadline);
        $this->fail('La operación remota no alcanzó el estado esperado: '.$operation->communication_state->value.'/'.$operation->functional_state->value.' '.$operation->last_error);
    }
}
