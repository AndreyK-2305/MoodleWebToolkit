<?php

namespace Tests\Feature\Remote;

use App\Domain\Projects\ProjectWizard;
use App\Domain\Remote\CheckRemoteDestination;
use App\Domain\Remote\PrepareRemoteDestination;
use App\Domain\Remote\RemoteServerConnections;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Exceptions\ToolOperationBlocked;
use App\Jobs\ObserveDestinationPreparation;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Domain\DomainTestCase;
use Tests\Support\QueuedRemoteTransport;

/**
 * The destination preparation as a durable agent operation, driven through a
 * scripted transport: launch once, then bounded observations until the exit
 * evidence and the Consolidator's own markers settle the result.
 */
class RemoteDestinationPreparationTest extends DomainTestCase
{
    private QueuedRemoteTransport $transport;

    private User $admin;

    private Project $project;

    private Server $server;

    private string $tree;

    protected function setUp(): void
    {
        parent::setUp();
        config(['toolkit.features.remote_agent.enabled' => true]);
        $this->transport = QueuedRemoteTransport::bind($this->app);
        $this->admin = $this->user(UserRole::ADMIN);
        $this->project = app(ProjectWizard::class)->create($this->admin, ['name' => 'Consolidación', 'type' => 'CONSOLIDATE']);
        $this->server = $this->project->servers()->create(['name' => 'Destino', 'role' => ServerRole::DESTINATION, 'host' => 'campus.example.edu']);
        app(RemoteServerConnections::class)->configure($this->server, ConnectionType::AWS_SSM,
            ['instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-2'], null, null)->update(['status' => ConnectionStatus::VALID]);
        $this->tree = str_repeat('c', 64);
        $this->checked(true);
    }

    public function test_preparation_runs_on_the_server_and_succeeds_only_with_exit_zero_and_the_final_marker(): void
    {
        $this->transport->okWith('launch', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script), 'created' => true]);
        $started = $this->service()->start($this->server, 'campus', $this->admin);

        $this->assertSame('RUNNING', $started['state']);
        $this->assertStringContainsString("'consolidator.prepare' 'destination=campus' 'distribution={$this->tree}'", $this->transport->scripts[0]);

        // First observation: still running, part of the output.
        $this->transport->ok('status', $this->agentStatus(null, true));
        $first = "PREFLIGHT_OK\nConstruyendo Moodle 5.2.1...\n";
        $this->transport->ok('tail', $this->tail($first, 0));
        $running = $this->service()->observe($this->server->refresh(), 'campus');
        $this->assertSame(['RUNNING', ['PREFLIGHT_OK']], [$running['state'], $running['markers']]);
        $this->assertSame(strlen($first), $running['stdout_offset']);

        // A second request while it runs is refused under lock.
        try {
            $this->service()->start($this->server->refresh(), 'campus', $this->admin);
            $this->fail('Una segunda preparación no debe lanzarse.');
        } catch (ToolOperationBlocked) {
        }

        $this->transport->ok('status', $this->agentStatus(['exit_code' => 0, 'cancel_requested' => false], false));
        $this->transport->ok('tail', $this->tail("DESTINO_LISTO url_publica=https://campus.example.edu version=5.2.1\nEXPORT_TRANSPORT_OK owner_uid=0\n"
            ."TARGET_SHARED_EMAIL_POLICY_OK setting=allowaccountssameemail expected=1 actual=1 status=OK\n", strlen($first)));
        $done = $this->service()->observe($this->server->refresh(), 'campus');

        $this->assertSame('SUCCEEDED', $done['state']);
        $this->assertSame(PrepareRemoteDestination::MARKERS, $done['markers']);
        $this->assertSame(0, $done['exit_code']);
        $this->assertNull($done['error_code']);
        $this->assertSame(1, AuditLog::query()->where('action', 'REMOTE_DESTINATION_PREPARATION_FINISHED')->count());
        // Settled: further observations make no calls.
        $calls = count($this->transport->scripts);
        $this->service()->observe($this->server->refresh(), 'campus');
        $this->assertCount($calls, $this->transport->scripts);
    }

    public function test_a_failed_preparation_keeps_the_redacted_end_of_stderr_and_exit_zero_without_markers_is_not_success(): void
    {
        $this->launch();
        $this->transport->ok('status', $this->agentStatus(['exit_code' => 1, 'cancel_requested' => false], false, 120));
        $this->transport->ok('tail', $this->tail("PREFLIGHT_OK\n", 0));
        $this->transport->ok('tail', $this->tail("Construyendo...\nMOODLE_DB_PASSWORD=supersecreto123\nERROR: Falló la instalación inicial.\n", 0));
        $failed = $this->service()->observe($this->server->refresh(), 'campus');

        $this->assertSame(['FAILED', 'prepare_failed', 1], [$failed['state'], $failed['error_code'], $failed['exit_code']]);
        $this->assertSame('ERROR: Falló la instalación inicial.', $failed['last_error_line']);
        $this->assertStringNotContainsString('supersecreto123', (string) json_encode($this->server->refresh()->metadata));

        $this->launch();
        $this->transport->ok('status', $this->agentStatus(['exit_code' => 0, 'cancel_requested' => false], false));
        $this->transport->ok('tail', $this->tail("PREFLIGHT_OK\n", 0));
        $this->transport->ok('tail', $this->tail('', 0));
        $this->assertSame(['FAILED', 'markers_missing'], array_values(array_intersect_key(
            $this->service()->observe($this->server->refresh(), 'campus'), array_flip(['state', 'error_code']))));
    }

    public function test_a_lost_process_or_a_launch_that_never_arrived_is_settled_without_guessing_success(): void
    {
        $this->launch();
        $this->travel(5)->minutes();
        $this->transport->ok('status', $this->agentStatus(null, false));
        $this->transport->ok('tail', $this->tail('', 0));
        $this->assertSame(['UNKNOWN', 'process_lost'], array_values(array_intersect_key(
            $this->service()->observe($this->server->refresh(), 'campus'), array_flip(['state', 'error_code']))));

        // The launch call itself was lost: the preparation waits as STARTING until the agent can answer.
        $this->transport->unreachable();
        $this->assertSame('STARTING', $this->service()->start($this->server->refresh(), 'campus', $this->admin)['state']);
        $this->transport->unreachable();
        $observed = $this->service()->observe($this->server->refresh(), 'campus');
        $this->assertSame(['STARTING', 'ssm_instance_not_managed'], [$observed['state'], $observed['observe_error']]);
        $this->transport->error('status', 'operation_not_found', 4);
        $this->assertSame('STARTING', $this->service()->observe($this->server->refresh(), 'campus')['state'], 'Aún dentro del margen.');
        $this->travel(5)->minutes();
        $this->transport->error('status', 'operation_not_found', 4);
        $this->assertSame(['FAILED', 'launch_not_received'], array_values(array_intersect_key(
            $this->service()->observe($this->server->refresh(), 'campus'), array_flip(['state', 'error_code']))));
    }

    public function test_cancellation_is_signalled_and_settled_from_the_exit_evidence(): void
    {
        $this->launch();
        $this->transport->ok('cancel', ['signalled' => true, 'already_finished' => false]);
        $this->assertSame('CANCELLING', $this->service()->cancel($this->server->refresh(), 'campus', $this->admin)['state']);
        $this->transport->ok('status', $this->agentStatus(['exit_code' => 143, 'cancel_requested' => true], false));
        $this->transport->ok('tail', $this->tail('', 0));
        $this->transport->ok('tail', $this->tail('', 0));
        $this->assertSame(['CANCELLED', 'cancelled'], array_values(array_intersect_key(
            $this->service()->observe($this->server->refresh(), 'campus'), array_flip(['state', 'error_code']))));
        $this->expectException(ToolOperationBlocked::class);
        $this->service()->cancel($this->server->refresh(), 'campus', $this->admin);
    }

    public function test_only_a_checked_unwritten_destination_with_a_valid_connection_is_prepared(): void
    {
        $this->checked(false);
        $this->assertBlocked();
        $this->checked(true, ['destination_written' => true]);
        $this->assertBlocked();
        $this->checked(true);
        $this->server->connections()->update(['status' => ConnectionStatus::INVALID->value]);
        $this->assertBlocked();
        $this->assertSame([], $this->transport->scripts);

        $this->server->connections()->update(['status' => ConnectionStatus::VALID->value]);
        $this->transport->error('launch', 'engine_modified');
        $rejected = $this->service()->start($this->server->refresh(), 'campus', $this->admin);
        $this->assertSame(['FAILED', 'engine_modified'], [$rejected['state'], $rejected['error_code']]);
    }

    public function test_the_endpoints_launch_and_the_scheduler_observes_active_preparations(): void
    {
        Queue::fake();
        $source = $this->project->servers()->create(['name' => 'Origen', 'role' => ServerRole::SOURCE, 'host' => 'origen.example.edu']);
        $this->actingAs($this->admin)->post(route('projects.servers.destinations.prepare', [$this->project->uuid, $source->uuid]), ['destination' => 'campus'])
            ->assertStatus(422);
        $this->actingAs($this->user())->post(route('projects.servers.destinations.prepare', [$this->project->uuid, $this->server->uuid]), ['destination' => 'campus'])
            ->assertForbidden();

        $this->transport->okWith('launch', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script), 'created' => true]);
        $this->actingAs($this->admin)->post(route('projects.servers.destinations.prepare', [$this->project->uuid, $this->server->uuid]), ['destination' => 'campus'])
            ->assertRedirect(route('projects.show', $this->project->uuid));
        $this->assertSame('RUNNING', $this->server->refresh()->metadata['remote_destination_preparations']['campus']['state']);

        $this->artisan('destinations:observe-preparations')->assertSuccessful();
        Queue::assertPushed(ObserveDestinationPreparation::class, fn (ObserveDestinationPreparation $job): bool => $job->serverId === $this->server->getKey()
            && $job->destination === 'campus');

        $this->actingAs($this->admin)->get(route('projects.show', $this->project->uuid))->assertInertia(fn (Assert $page) => $page
            ->where('remoteAccess.servers.0.uuid', $this->server->uuid)
            ->where('remoteAccess.servers.0.destinations.0.id', 'campus')
            ->where('remoteAccess.servers.0.destinations.0.preparation.state', 'RUNNING')
            ->where('remoteAccess.servers.0.destinations.0.preparation.markers', []));

        $this->transport->ok('cancel', ['signalled' => true, 'already_finished' => false]);
        $this->actingAs($this->admin)->post(route('projects.servers.destinations.prepare.cancel', [$this->project->uuid, $this->server->uuid]), ['destination' => 'campus'])
            ->assertRedirect(route('projects.show', $this->project->uuid));
        $this->assertSame('CANCELLING', $this->server->refresh()->metadata['remote_destination_preparations']['campus']['state']);
    }

    private function launch(): void
    {
        $this->transport->okWith('launch', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script), 'created' => true]);
        $this->assertSame('RUNNING', $this->service()->start($this->server->refresh(), 'campus', $this->admin)['state']);
    }

    private function assertBlocked(): void
    {
        try {
            $this->service()->start($this->server->refresh(), 'campus', $this->admin);
            $this->fail('La preparación debía bloquearse.');
        } catch (ToolOperationBlocked) {
            $this->addToAssertionCount(1);
        }
    }

    /** @param array<string, mixed> $report */
    private function checked(bool $ready, array $report = []): void
    {
        $metadata = $this->server->refresh()->metadata ?? [];
        $metadata['remote_tools'][CheckRemoteDestination::DISTRIBUTION_KEY] = ['tree_sha256' => $this->tree];
        $metadata['remote_destinations']['campus'] = ['ready' => $ready, 'blockers' => [], 'warnings' => [],
            'report' => ['destination_written' => false, 'tool_tree' => $this->tree, ...$report]];
        $this->server->update(['metadata' => $metadata]);
    }

    /**
     * @param  array<string, mixed>|null  $exit
     * @return array<string, mixed>
     */
    private function agentStatus(?array $exit, bool $alive, int $stderrBytes = 0): array
    {
        return ['exit' => $exit, 'process_alive' => $alive, 'heartbeat_at' => $alive ? now()->utc()->toIso8601String() : null,
            'stdout_bytes' => 0, 'stderr_bytes' => $stderrBytes];
    }

    /** @return array<string, mixed> */
    private function tail(string $text, int $offset): array
    {
        return ['offset' => $offset, 'next_offset' => $offset + strlen($text), 'size' => $offset + strlen($text), 'data_base64' => base64_encode($text)];
    }

    private function service(): PrepareRemoteDestination
    {
        return app(PrepareRemoteDestination::class);
    }
}
