<?php

namespace Tests\Feature\Remote;

use App\Domain\Remote\RemoteAgent;
use App\Domain\Remote\RemoteCollectorExport;
use App\Domain\Remote\RemoteServerConnections;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\RemoteFunctionalState;
use App\Enums\ServerRole;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Execution;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\ToolDistribution;
use Database\Seeders\ToolCatalogSeeder;
use Tests\Feature\Domain\DomainTestCase;
use Tests\Support\QueuedRemoteTransport;

class RemoteCollectorExportTest extends DomainTestCase
{
    private QueuedRemoteTransport $transport;

    private Server $server;

    private Execution $execution;

    private string $sha;

    protected function setUp(): void
    {
        parent::setUp();
        config(['toolkit.features.remote_agent.enabled' => true]);
        $this->transport = QueuedRemoteTransport::bind($this->app);
        $this->seed(ToolCatalogSeeder::class);
        $this->sha = ToolDistribution::query()->where('key', RemoteCollectorExport::DISTRIBUTION_KEY)->sole()->distribution_sha256;
        $project = $this->project();
        $this->execution = $this->execution($project);
        $this->server = $project->servers()->create(['name' => 'Origen', 'role' => ServerRole::SOURCE, 'host' => 'origen.example.edu',
            'metadata' => [
                'remote_tools' => [RemoteCollectorExport::DISTRIBUTION_KEY => ['tree_sha256' => $this->sha]],
                'remote_sources' => ['synthetic-lab' => ['ready' => true]],
            ]]);
        app(RemoteServerConnections::class)->configure($this->server, ConnectionType::AWS_SSM,
            ['instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-2'], null, null)->update(['status' => ConnectionStatus::VALID]);
        // Reconfiguring clears probe evidence only; tool and source evidence must survive.
        $this->server->refresh();
    }

    public function test_export_requires_the_verified_tool_and_a_ready_source(): void
    {
        $metadata = $this->server->metadata;
        $this->server->update(['metadata' => [...$metadata, 'remote_tools' => [RemoteCollectorExport::DISTRIBUTION_KEY => ['tree_sha256' => str_repeat('a', 64)]]]]);
        try {
            $this->start();
            $this->fail('Se lanzó con una distribución distinta a la del catálogo.');
        } catch (ToolOperationBlocked) {
        }

        $this->server->update(['metadata' => [...$metadata, 'remote_sources' => ['synthetic-lab' => ['ready' => false]]]]);
        try {
            $this->start();
            $this->fail('Se lanzó con un perfil de origen no listo.');
        } catch (ToolOperationBlocked) {
        }
        $this->assertSame([], $this->transport->scripts);
        $this->assertSame(0, RemoteOperation::query()->count());
    }

    public function test_launch_sends_only_identifiers(): void
    {
        $this->queueLaunch();
        $operation = $this->start();

        $this->assertSame(RemoteCollectorExport::COMMAND, $operation->command_key);
        $script = $this->transport->scripts[0];
        foreach (['distribution='.$this->sha, 'source=synthetic-lab', 'package=origen-lab', 'workers=2'] as $argument) {
            $this->assertStringContainsString($argument, $script);
        }
        $this->assertStringNotContainsString('config.php', $script);
    }

    public function test_running_export_keeps_real_progress_and_no_paths(): void
    {
        $this->queueLaunch();
        $operation = $this->start();
        $this->queueStatus(alive: true);
        $this->queueReport(
            status: ['state' => 'running', 'stage' => 'export', 'exit_code' => 0, 'output_directory' => '/var/lib/mwt-agent/ops/x/work/output',
                'log_file' => '/var/lib/mwt-agent/ops/x/work/output/logs/a.log', 'collector_version' => '7.4.2-linux'],
            progress: ['stage' => 'courses', 'total_courses' => 2, 'completed_courses' => 1, 'failed_courses' => 0],
        );

        $operation = app(RemoteCollectorExport::class)->observe($operation);

        $report = $operation->evidence['collector'];
        $this->assertEquals(50, $report['percent'], 'jsonb devuelve 50.0 como 50.');
        $this->assertSame('courses', $report['progress']['stage']);
        $this->assertFalse($report['package_ready']);
        $this->assertArrayNotHasKey('output_directory', $report['status']);
        $this->assertArrayNotHasKey('log_file', $report['status']);
        $this->assertStringNotContainsString('/var/lib/mwt-agent', (string) json_encode($operation->evidence));
    }

    public function test_package_is_ready_only_when_every_signal_agrees(): void
    {
        $zipSha = hash('sha256', 'zip');
        $cases = [
            'listo' => [0, 'success', 'completed', "$zipSha  origen-lab.zip", true],
            'sidecar de otro paquete' => [0, 'success', 'completed', "$zipSha  otro.zip", false],
            'estado de error' => [0, 'error', 'export', "$zipSha  origen-lab.zip", false],
            'salida distinta de cero' => [3, 'success', 'completed', "$zipSha  origen-lab.zip", false],
            'sin sidecar' => [0, 'success', 'completed', null, false],
        ];
        foreach ($cases as $label => [$exit, $state, $stage, $sidecar, $expected]) {
            RemoteOperation::query()->delete();
            $this->queueLaunch();
            $operation = $this->start();
            $this->queueStatus(alive: false, exit: ['exit_code' => $exit, 'cancel_requested' => false, 'finished_at' => '2026-10-20T12:00:00Z']);
            $this->queueReport(
                status: ['state' => $state, 'stage' => $stage, 'exit_code' => $exit],
                progress: ['stage' => 'completed', 'total_courses' => 0, 'completed_courses' => 0],
                outputs: [['name' => 'origen-lab.zip', 'size' => 4096], ['name' => 'origen-lab.zip.sha256', 'size' => 81]],
                sidecar: $sidecar,
            );

            $report = app(RemoteCollectorExport::class)->observe($operation)->evidence['collector'];

            $this->assertSame($expected, $report['package_ready'], $label);
            $this->assertNull($report['percent'], 'Sin total conocido el progreso es desconocido: '.$label);
            if ($expected) {
                $this->assertSame($zipSha, $report['package_sha256']);
                $this->assertSame(4096, $report['package_size']);
            }
        }
    }

    public function test_unreachable_report_keeps_the_previous_one(): void
    {
        $this->queueLaunch();
        $operation = $this->start();
        $this->queueStatus(alive: true);
        $this->queueReport(status: ['state' => 'running', 'stage' => 'export', 'exit_code' => 0],
            progress: ['stage' => 'courses', 'total_courses' => 4, 'completed_courses' => 1]);
        app(RemoteCollectorExport::class)->observe($operation);

        $this->queueStatus(alive: true);
        $this->transport->unreachable();
        $operation = app(RemoteCollectorExport::class)->observe($operation);

        $this->assertEquals(25, $operation->evidence['collector']['percent']);
        $this->assertSame(RemoteFunctionalState::RUNNING, $operation->functional_state);
    }

    private function start(): RemoteOperation
    {
        return app(RemoteCollectorExport::class)->start($this->execution, $this->server->refresh(), 'synthetic-lab', 'origen-lab', '2', null);
    }

    private function queueLaunch(): void
    {
        $this->transport->okWith('launch', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script),
            'created' => true, 'launch' => $this->launchEvidence(QueuedRemoteTransport::operationUuid($script))]);
    }

    /** @param array<string, mixed>|null $exit */
    private function queueStatus(bool $alive, ?array $exit = null): void
    {
        $this->transport->okWith('status', function (string $script) use ($alive, $exit): array {
            $uuid = QueuedRemoteTransport::operationUuid($script);
            $now = now()->utc()->toIso8601String();

            return ['operation_uuid' => $uuid, 'launch' => $this->launchEvidence($uuid), 'run' => ['pid' => 7, 'started_at' => $now],
                'exit' => $exit, 'cancel_requested' => false, 'process_alive' => $alive, 'heartbeat_at' => $now,
                'stdout_bytes' => 0, 'stderr_bytes' => 0, 'observed_at' => $now];
        });
    }

    /**
     * @param  array<string, mixed>  $status
     * @param  array<string, mixed>  $progress
     * @param  list<array{name: string, size: int}>  $outputs
     */
    private function queueReport(array $status, array $progress, array $outputs = [], ?string $sidecar = null): void
    {
        $this->transport->okWith('collector-report', fn (string $script): array => [
            'operation_uuid' => QueuedRemoteTransport::operationUuid($script), 'package' => 'origen-lab',
            'status_base64' => base64_encode((string) json_encode($status)), 'progress_base64' => base64_encode((string) json_encode($progress)),
            'outputs' => $outputs, 'sidecar' => $sidecar, 'observed_at' => now()->utc()->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    private function launchEvidence(string $uuid): array
    {
        $distribution = app(RemoteAgent::class)->distribution();

        return ['operation_uuid' => $uuid, 'command_key' => RemoteCollectorExport::COMMAND, 'launcher' => 'systemd', 'unit' => 'mwt-op-'.$uuid,
            'agent_version' => $distribution['version'], 'agent_sha256' => $distribution['sha256'], 'argv_sha256' => str_repeat('b', 64),
            'launched_at' => now()->utc()->toIso8601String(), 'parameters' => []];
    }
}
