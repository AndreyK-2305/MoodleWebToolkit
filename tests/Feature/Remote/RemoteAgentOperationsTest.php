<?php

namespace Tests\Feature\Remote;

use App\Domain\Remote\RemoteAgent;
use App\Domain\Remote\RemoteAgentOperations;
use App\Domain\Remote\RemoteServerConnections;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\RemoteCommunicationState;
use App\Enums\RemoteFunctionalState;
use App\Enums\ServerRole;
use App\Exceptions\ToolOperationBlocked;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\ExecutionLog;
use App\Models\RemoteOperation;
use App\Models\Server;
use InvalidArgumentException;
use RuntimeException;
use Tests\Feature\Domain\DomainTestCase;
use Tests\Support\QueuedRemoteTransport;

/**
 * Fault handling of remote operations with a scripted transport: every
 * branch where the platform must not infer a result is exercised here.
 */
class RemoteAgentOperationsTest extends DomainTestCase
{
    private QueuedRemoteTransport $transport;

    private Server $server;

    private Execution $execution;

    protected function setUp(): void
    {
        parent::setUp();
        config(['toolkit.features.remote_agent.enabled' => true]);
        $this->transport = QueuedRemoteTransport::bind($this->app);
        $project = $this->project();
        $this->execution = $this->execution($project);
        $this->server = $project->servers()->create(['name' => 'Origen', 'role' => ServerRole::SOURCE, 'host' => 'origen.example.edu']);
        app(RemoteServerConnections::class)->configure($this->server, ConnectionType::AWS_SSM,
            ['instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-1'], null, null)->update(['status' => ConnectionStatus::VALID]);
    }

    public function test_launch_requires_a_tested_connection_of_the_same_project(): void
    {
        $this->server->connections()->update(['status' => ConnectionStatus::INVALID->value]);
        try {
            $this->launch();
            $this->fail('Se lanzó una operación sin conexión válida.');
        } catch (ToolOperationBlocked) {
            $this->assertSame(0, RemoteOperation::query()->count());
        }

        $this->server->connections()->update(['status' => ConnectionStatus::VALID->value]);
        $other = $this->execution($this->project());
        $this->expectException(ToolOperationBlocked::class);
        app(RemoteAgentOperations::class)->launch($other, $this->server, 'diag-1', 'diagnostic.sleep', ['seconds' => '60'], null);
    }

    public function test_unregistered_commands_and_parameters_never_reach_the_server(): void
    {
        foreach ([['shell.exec', ['cmd' => 'id']], ['diagnostic.sleep', ['seconds' => '60;id']], ['diagnostic.sleep', ['seconds' => '60', 'x' => '1']]] as [$key, $parameters]) {
            try {
                app(RemoteAgentOperations::class)->launch($this->execution, $this->server, 'diag-x', $key, $parameters, null);
                $this->fail('Se aceptó una operación no registrada.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame([], $this->transport->scripts);
    }

    public function test_confirmed_launch_records_durable_identity_and_is_idempotent(): void
    {
        $this->queueLaunch();
        $operation = $this->launch();

        $this->assertSame(RemoteAgentOperations::PROVIDER, $operation->provider_key);
        $this->assertSame($this->server->uuid, $operation->host_id);
        $this->assertSame('mwt-op-'.$operation->operation_uuid, $operation->process_id);
        $this->assertSame(RemoteCommunicationState::CONNECTED, $operation->communication_state);
        $this->assertSame(RemoteFunctionalState::RUNNING, $operation->functional_state);
        $this->assertNotNull($operation->next_poll_at);

        $again = $this->launch();
        $this->assertSame($operation->id, $again->id);
        $this->assertCount(1, $this->transport->scripts, 'Repetir la clave de idempotencia no vuelve a llamar al servidor.');

        $this->expectException(RuntimeException::class);
        app(RemoteAgentOperations::class)->launch($this->execution, $this->server, 'diag-1', 'diagnostic.sleep', ['seconds' => '30'], null);
    }

    public function test_lost_launch_response_is_recovered_without_a_second_process(): void
    {
        $this->transport->unreachable();
        $operation = $this->launch();
        $this->assertSame(RemoteCommunicationState::UNREACHABLE, $operation->communication_state);
        $this->assertSame(RemoteFunctionalState::UNKNOWN, $operation->functional_state);
        $this->assertNull($operation->process_id);

        // The launch reached the server even though the answer was lost: status reveals it.
        $this->transport->okWith('status', fn (string $script): array => $this->statusData(QueuedRemoteTransport::operationUuid($script), alive: true));
        $operation = app(RemoteAgentOperations::class)->reconcile($operation);
        $this->assertSame('mwt-op-'.$operation->operation_uuid, $operation->process_id);
        $this->assertSame(RemoteFunctionalState::RUNNING, $operation->functional_state);
        $this->assertSame(0, $operation->reconcile_attempts);
        $this->assertSame(['launch', 'status'], $this->transport->subcommands(), 'No se envía un segundo lanzamiento.');
    }

    public function test_launch_that_never_reached_the_server_is_repeated_by_uuid(): void
    {
        $this->transport->unreachable();
        $operation = $this->launch();

        $this->transport->error('status', 'operation_not_found', 4);
        $this->queueLaunch();
        $operation = app(RemoteAgentOperations::class)->reconcile($operation);

        $this->assertSame(RemoteFunctionalState::RUNNING, $operation->functional_state);
        $uuids = array_map(QueuedRemoteTransport::operationUuid(...), $this->transport->scripts);
        $this->assertSame([$operation->operation_uuid], array_values(array_unique($uuids)), 'El reintento reutiliza la misma identidad.');
    }

    public function test_loss_of_contact_keeps_polling_and_never_infers_failure(): void
    {
        $this->queueLaunch();
        $operation = $this->launch();

        for ($attempt = 1; $attempt <= 9; $attempt++) {
            $this->transport->unreachable();
            $operation = app(RemoteAgentOperations::class)->reconcile($operation);
        }

        $this->assertSame(RemoteCommunicationState::UNREACHABLE, $operation->communication_state);
        $this->assertSame(RemoteFunctionalState::UNKNOWN, $operation->functional_state);
        $this->assertTrue($operation->manual_intervention_required);
        $this->assertNotNull($operation->next_poll_at, 'Se sigue consultando: el proceso puede seguir vivo en el servidor.');
        $this->assertLessThanOrEqual(900, (int) now()->diffInSeconds($operation->next_poll_at, true) + 1);
        $this->assertNull($operation->terminated_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'REMOTE_OPERATION_MANUAL_INTERVENTION_REQUIRED')->count());

        // Contact returns and the process was alive all along.
        $this->transport->okWith('status', fn (string $script): array => $this->statusData(QueuedRemoteTransport::operationUuid($script), alive: true));
        $operation = app(RemoteAgentOperations::class)->reconcile($operation);
        $this->assertSame(RemoteCommunicationState::CONNECTED, $operation->communication_state);
        $this->assertSame(RemoteFunctionalState::RUNNING, $operation->functional_state);
        $this->assertFalse($operation->manual_intervention_required);
    }

    public function test_exit_evidence_decides_the_result(): void
    {
        foreach ([[0, false, RemoteFunctionalState::SUCCEEDED], [3, false, RemoteFunctionalState::FAILED], [143, true, RemoteFunctionalState::CANCELLED]] as $index => [$code, $cancelled, $expected]) {
            $this->queueLaunch();
            $operation = app(RemoteAgentOperations::class)->launch($this->execution, $this->server, 'diag-exit-'.$index, 'diagnostic.sleep', ['seconds' => '5'], null);
            $this->transport->okWith('status', fn (string $script): array => $this->statusData(QueuedRemoteTransport::operationUuid($script), alive: false,
                exit: ['exit_code' => $code, 'cancel_requested' => $cancelled, 'finished_at' => '2026-10-19T12:00:00Z']));
            $operation = app(RemoteAgentOperations::class)->reconcile($operation);

            $this->assertSame(RemoteCommunicationState::TERMINATED, $operation->communication_state);
            $this->assertSame($expected, $operation->functional_state);
            $this->assertSame($code, $operation->exit_code);
            $this->assertNull($operation->next_poll_at);
        }
    }

    public function test_vanished_process_or_missing_remote_state_leaves_the_result_unknown(): void
    {
        $this->queueLaunch();
        $vanished = $this->launch();
        RemoteOperation::query()->whereKey($vanished->id)->update(['started_at' => now()->subMinutes(10)]);
        $this->transport->okWith('status', fn (string $script): array => $this->statusData(QueuedRemoteTransport::operationUuid($script), alive: false, run: null));
        $vanished = app(RemoteAgentOperations::class)->reconcile($vanished);
        $this->assertSame(RemoteCommunicationState::TERMINATED, $vanished->communication_state);
        $this->assertSame(RemoteFunctionalState::UNKNOWN, $vanished->functional_state);
        $this->assertTrue($vanished->manual_intervention_required);
        $this->assertNull($vanished->exit_code);

        $this->queueLaunch();
        $missing = app(RemoteAgentOperations::class)->launch($this->execution, $this->server, 'diag-2', 'diagnostic.sleep', ['seconds' => '60'], null);
        $this->transport->error('status', 'operation_not_found', 4);
        $missing = app(RemoteAgentOperations::class)->reconcile($missing);
        $this->assertSame(RemoteFunctionalState::UNKNOWN, $missing->functional_state);
        $this->assertSame('remote_state_missing', $missing->last_error);
    }

    public function test_definitive_launch_rejection_and_foreign_launch_evidence(): void
    {
        $this->transport->error('launch', 'launcher_unavailable');
        $rejected = $this->launch();
        $this->assertSame(RemoteFunctionalState::FAILED, $rejected->functional_state);
        $this->assertSame('launch_rejected:launcher_unavailable', $rejected->last_error);

        $this->transport->okWith('launch', fn (string $script): array => ['created' => true,
            'launch' => [...$this->launchEvidence(QueuedRemoteTransport::operationUuid($script)), 'agent_sha256' => str_repeat('a', 64)]]);
        $foreign = app(RemoteAgentOperations::class)->launch($this->execution, $this->server, 'diag-3', 'diagnostic.sleep', ['seconds' => '60'], null);
        $this->assertSame(RemoteFunctionalState::UNKNOWN, $foreign->functional_state);
        $this->assertSame('launch_evidence_mismatch', $foreign->last_error);
        $this->assertNull($foreign->process_id);
    }

    public function test_cancellation_intent_is_durable_and_repeated_after_a_lost_call(): void
    {
        $this->queueLaunch();
        $operation = $this->launch();

        $this->transport->unreachable();
        $operation = app(RemoteAgentOperations::class)->cancel($operation, null);
        $this->assertIsString($operation->evidence['cancel_requested_at']);
        $this->assertSame(RemoteFunctionalState::RUNNING, $operation->functional_state, 'Pedir la cancelación no la da por hecha.');

        $this->transport->okWith('status', fn (string $script): array => $this->statusData(QueuedRemoteTransport::operationUuid($script), alive: true));
        $this->transport->okWith('cancel', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script), 'signalled' => true, 'already_finished' => false]);
        app(RemoteAgentOperations::class)->reconcile($operation);
        $this->assertSame(0, $this->transport->pending(), 'La reconciliación repite la cancelación pendiente.');
        $this->assertSame(1, AuditLog::query()->where('action', 'REMOTE_OPERATION_CANCEL_REQUESTED')->count());

        $this->queueLaunch('diagnostic.fail');
        $nonCancellable = app(RemoteAgentOperations::class)->launch($this->execution, $this->server, 'diag-fail', 'diagnostic.fail', ['code' => '3'], null);
        $this->expectException(ToolOperationBlocked::class);
        app(RemoteAgentOperations::class)->cancel($nonCancellable, null);
    }

    public function test_complete_log_lines_are_stored_once_with_redaction(): void
    {
        $this->queueLaunch('diagnostic.lines');
        $operation = app(RemoteAgentOperations::class)->launch($this->execution, $this->server, 'diag-lines', 'diagnostic.lines', ['count' => '3'], null);
        $stdout = "linea 1\npassword=hunter2\nincomple";
        $this->transport->okWith('status', fn (string $script): array => [...$this->statusData(QueuedRemoteTransport::operationUuid($script), alive: true), 'stdout_bytes' => strlen($stdout)]);
        $this->transport->okWith('tail', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script), 'stream' => 'stdout',
            'offset' => 0, 'next_offset' => strlen($stdout), 'size' => strlen($stdout), 'data_base64' => base64_encode($stdout)]);
        app(RemoteAgentOperations::class)->reconcile($operation);

        $messages = ExecutionLog::query()->where('remote_operation_id', $operation->id)->orderBy('id')->pluck('message')->all();
        $this->assertCount(2, $messages, 'La línea incompleta espera a la siguiente observación.');
        $this->assertSame('linea 1', $messages[0]);
        $this->assertStringNotContainsString('hunter2', $messages[1]);
        $this->assertSame(strlen("linea 1\npassword=hunter2\n"), $operation->fresh()->evidence['log_offsets']['stdout']);
    }

    public function test_scheduler_routes_remote_operations_to_the_agent(): void
    {
        $this->queueLaunch();
        $operation = $this->launch();
        RemoteOperation::query()->whereKey($operation->id)->update(['next_poll_at' => now()->subSecond()]);
        $this->transport->okWith('status', fn (string $script): array => $this->statusData(QueuedRemoteTransport::operationUuid($script), alive: false,
            exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-19T12:00:00Z']));

        $this->artisan('executions:reconcile-remote-operations')->assertExitCode(0);

        $this->assertSame(RemoteFunctionalState::SUCCEEDED, $operation->fresh()->functional_state);
    }

    private function launch(): RemoteOperation
    {
        return app(RemoteAgentOperations::class)->launch($this->execution, $this->server, 'diag-1', 'diagnostic.sleep', ['seconds' => '60'], null);
    }

    private function queueLaunch(string $command = 'diagnostic.sleep'): void
    {
        $this->transport->okWith('launch', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script),
            'created' => true, 'launch' => $this->launchEvidence(QueuedRemoteTransport::operationUuid($script), $command)]);
    }

    /** @return array<string, mixed> */
    private function launchEvidence(string $uuid, string $command = 'diagnostic.sleep'): array
    {
        $distribution = app(RemoteAgent::class)->distribution();

        return ['operation_uuid' => $uuid, 'command_key' => $command, 'launcher' => 'systemd', 'unit' => 'mwt-op-'.$uuid,
            'agent_version' => $distribution['version'], 'agent_sha256' => $distribution['sha256'],
            'argv_sha256' => str_repeat('b', 64), 'launched_at' => now()->utc()->toIso8601String()];
    }

    /**
     * @param  array<string, mixed>|null  $exit
     * @param  array<string, mixed>|null  $run
     * @return array<string, mixed>
     */
    private function statusData(string $uuid, bool $alive, ?array $exit = null, ?array $run = ['pid' => 4242, 'started_at' => '2026-10-19T11:59:00Z']): array
    {
        $now = now()->utc()->toIso8601String();

        return [
            'operation_uuid' => $uuid, 'launch' => $this->launchEvidence($uuid), 'run' => $run, 'exit' => $exit,
            'cancel_requested' => false, 'process_alive' => $alive,
            'unit' => ['load_state' => 'loaded', 'active_state' => $alive ? 'active' : 'inactive', 'sub_state' => $alive ? 'running' : 'dead', 'main_pid' => $alive ? 4242 : 0],
            'heartbeat_at' => $now, 'stdout_bytes' => 0, 'stderr_bytes' => 0, 'observed_at' => $now,
        ];
    }
}
