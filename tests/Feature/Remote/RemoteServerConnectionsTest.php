<?php

namespace Tests\Feature\Remote;

use App\Domain\Remote\DTOs\TransportResult;
use App\Domain\Remote\InstallRemoteAgent;
use App\Domain\Remote\RemoteAgent;
use App\Domain\Remote\RemoteServerConnections;
use App\Domain\Remote\TestRemoteConnection;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\ServerRole;
use App\Exceptions\ToolOperationBlocked;
use App\Models\AuditLog;
use App\Models\Server;
use Tests\Feature\Domain\DomainTestCase;
use Tests\Support\QueuedRemoteTransport;

class RemoteServerConnectionsTest extends DomainTestCase
{
    private const HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';

    private QueuedRemoteTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        config(['toolkit.features.remote_agent.enabled' => true]);
        $this->transport = QueuedRemoteTransport::bind($this->app);
    }

    public function test_remote_connections_are_closed_by_feature_flag(): void
    {
        config(['toolkit.features.remote_agent.enabled' => false]);

        $this->expectException(ToolOperationBlocked::class);
        app(RemoteServerConnections::class)->configure($this->server(), ConnectionType::AWS_SSM, $this->ssm(), null, null);
    }

    public function test_valid_connection_records_probe_evidence_and_audit(): void
    {
        $server = $this->server();
        $actor = $this->user();
        $connection = app(RemoteServerConnections::class)->configure($server, ConnectionType::AWS_SSM, $this->ssm(), null, $actor);
        $this->assertSame(ConnectionStatus::UNTESTED, $connection->status);
        $this->assertSame('i-0123456789abcdef0', $connection->configuration['instance_id']);

        $this->transport->queue($this->agentOk('version', ['agent_sha256' => $this->sha(), 'launcher' => 'systemd']));
        $this->transport->queue($this->agentOk('probe', $this->probe()));
        $result = app(TestRemoteConnection::class)->handle($server, $actor);

        $this->assertSame('VALID', $result['status']);
        $this->assertSame(['transport', 'agent', 'launcher', 'bash', 'disk'], array_column($result['checks'], 'key'));
        $this->assertSame(['SUCCESS'], array_values(array_unique(array_column($result['checks'], 'result'))));
        $this->assertSame(ConnectionStatus::VALID, $connection->refresh()->status);
        $evidence = $server->refresh()->metadata['remote_probe'];
        $this->assertSame('VALID', $evidence['status']);
        $this->assertSame('ubuntu', $evidence['probe']['os_id']);
        $this->assertArrayNotHasKey('unexpected', $evidence['probe']);
        $this->assertSame(1, AuditLog::query()->where('action', 'REMOTE_CONNECTION_TESTED')->where('actor_id', $actor->id)->count());
        $this->assertCount(2, $this->transport->scripts);
        $this->assertStringContainsString("'version'", $this->transport->scripts[0]);
        $this->assertStringContainsString("'probe'", $this->transport->scripts[1]);
    }

    public function test_missing_agent_or_unreachable_server_invalidate_the_connection(): void
    {
        $server = $this->server();
        app(RemoteServerConnections::class)->configure($server, ConnectionType::AWS_SSM, $this->ssm(), null, null);

        $this->transport->queue(new TransportResult(TransportResult::COMPLETED, 127, $this->document(false, 'version', ['code' => 'agent_not_installed']), ''));
        $missing = app(TestRemoteConnection::class)->handle($server, null);
        $this->assertSame('INVALID', $missing['status']);
        $this->assertSame(['transport' => 'SUCCESS', 'agent' => 'ERROR'], array_column($missing['checks'], 'result', 'key'));

        $this->transport->queue($this->agentOk('version', ['agent_sha256' => str_repeat('0', 64), 'launcher' => 'systemd']));
        $outdated = app(TestRemoteConnection::class)->handle($server, null);
        $this->assertSame('ERROR', array_column($outdated['checks'], 'result', 'key')['agent']);

        $this->transport->queue(new TransportResult(TransportResult::UNREACHABLE, null, '', 'raw remote text', 'ssm_instance_not_managed'));
        $unreachable = app(TestRemoteConnection::class)->handle($server, null);
        $this->assertSame('INVALID', $unreachable['status']);
        $this->assertStringContainsString('ssm_instance_not_managed', $unreachable['checks'][0]['message']);
        $this->assertStringNotContainsString('raw remote text', json_encode(AuditLog::query()->pluck('payload'), JSON_THROW_ON_ERROR));
        $this->assertSame(ConnectionStatus::INVALID, $server->connections()->firstOrFail()->status);
    }

    public function test_server_without_durable_launcher_fails_the_preflight(): void
    {
        $server = $this->server();
        app(RemoteServerConnections::class)->configure($server, ConnectionType::AWS_SSM, $this->ssm(), null, null);
        $this->transport->queue($this->agentOk('version', ['agent_sha256' => $this->sha(), 'launcher' => 'unavailable']));
        $this->transport->queue($this->agentOk('probe', [...$this->probe(), 'launcher' => 'unavailable', 'disk_free_bytes' => 1024]));

        $result = app(TestRemoteConnection::class)->handle($server, null);

        $this->assertSame('INVALID', $result['status']);
        $this->assertSame('ERROR', array_column($result['checks'], 'result', 'key')['launcher']);
        $this->assertSame('WARNING', array_column($result['checks'], 'result', 'key')['disk']);
    }

    public function test_reconfiguring_discards_previous_evidence_and_keeps_secret_reference_out_of_audit(): void
    {
        $server = $this->server();
        $connections = app(RemoteServerConnections::class);
        $connections->configure($server, ConnectionType::AWS_SSM, $this->ssm(), null, null);
        $this->transport->queue($this->agentOk('version', ['agent_sha256' => $this->sha(), 'launcher' => 'systemd']));
        $this->transport->queue($this->agentOk('probe', $this->probe()));
        app(TestRemoteConnection::class)->handle($server, null);

        $connection = $connections->configure($server, ConnectionType::SSH, [
            'host' => 'origen.example.edu', 'user' => 'ubuntu', 'host_key' => self::HOST_KEY, 'secret_version' => '1',
        ], 'origen-ssh-key', null);

        $this->assertSame(ConnectionStatus::UNTESTED, $connection->status);
        $this->assertSame('origen-ssh-key', $connection->secret_reference);
        $this->assertArrayNotHasKey('secret_reference', $connection->toArray());
        $this->assertNull($server->refresh()->metadata);
        $this->assertSame(1, $server->connections()->count());
        $audits = json_encode(AuditLog::query()->where('action', 'REMOTE_CONNECTION_CONFIGURED')->get(['old_values', 'new_values']), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('origen-ssh-key', $audits);
        $this->assertSame(2, AuditLog::query()->where('action', 'REMOTE_CONNECTION_CONFIGURED')->count());
    }

    public function test_agent_installation_is_explicit_and_audited(): void
    {
        $server = $this->server();
        app(RemoteServerConnections::class)->configure($server, ConnectionType::AWS_SSM, $this->ssm(), null, null);

        $this->transport->queue($this->agentOk('version', ['agent_sha256' => $this->sha(), 'launcher' => 'systemd']));
        $installed = app(InstallRemoteAgent::class)->handle($server, null);
        $this->assertTrue($installed['installed']);
        $this->assertStringContainsString(base64_encode((string) file_get_contents(app(RemoteAgent::class)->distribution()['path'])), $this->transport->scripts[0]);

        $this->transport->queue(new TransportResult(TransportResult::COMPLETED, 2, $this->document(false, 'version', ['code' => 'agent_install_hash_mismatch']), ''));
        $rejected = app(InstallRemoteAgent::class)->handle($server, null);
        $this->assertFalse($rejected['installed']);
        $this->assertSame('agent_install_hash_mismatch', $rejected['error_code']);

        $this->assertSame(['SUCCESS', 'ERROR'], AuditLog::query()->where('action', 'REMOTE_AGENT_INSTALLED')->orderBy('id')->get()
            ->map(fn (AuditLog $log): string => $log->payload['result'])->all());
    }

    private function server(): Server
    {
        return $this->project()->servers()->create(['name' => 'Origen Pregrado', 'role' => ServerRole::SOURCE, 'host' => 'origen.example.edu']);
    }

    /** @return array<string, string> */
    private function ssm(): array
    {
        return ['instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-1'];
    }

    private function sha(): string
    {
        return app(RemoteAgent::class)->distribution()['sha256'];
    }

    /** @return array<string, mixed> */
    private function probe(): array
    {
        return [
            'agent_sha256' => $this->sha(), 'hostname' => 'origen', 'os_id' => 'ubuntu', 'os_version' => '24.04', 'os_name' => 'Ubuntu 24.04',
            'kernel' => '6.8.0', 'arch' => 'x86_64', 'cpus' => 4, 'memory_bytes' => 16 * 1073741824, 'is_root' => true, 'systemd' => true,
            'systemd_version' => '255', 'launcher' => 'systemd', 'bash_version' => '5.2.21(1)-release', 'state_dir_exists' => true,
            'disk_total_bytes' => 500 * 1073741824, 'disk_free_bytes' => 300 * 1073741824, 'php_version' => '8.3.6',
            'docker_version' => null, 'pwsh_version' => null, 'aws_cli_version' => '2.17.0', 'observed_at' => '2026-10-19T00:00:00Z',
            'unexpected' => 'dropped',
        ];
    }

    /** @param array<string, mixed> $data */
    private function agentOk(string $command, array $data): TransportResult
    {
        return new TransportResult(TransportResult::COMPLETED, 0, $this->document(true, $command, $data), '');
    }

    /** @param array<string, mixed> $payload */
    private function document(bool $ok, string $command, array $payload): string
    {
        $document = ['schema' => 'mwt-agent.v1', 'ok' => $ok, 'agent_version' => app(RemoteAgent::class)->distribution()['version'], 'command' => $command];
        $document[$ok ? 'data' : 'error'] = $payload;

        return json_encode($document, JSON_THROW_ON_ERROR)."\n";
    }
}
