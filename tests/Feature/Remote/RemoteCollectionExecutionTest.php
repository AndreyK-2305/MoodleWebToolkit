<?php

namespace Tests\Feature\Remote;

use App\Domain\Remote\RemoteAgent;
use App\Domain\Remote\RemoteCollectionPreparation;
use App\Domain\Remote\RemoteCollectorExport;
use App\Domain\Remote\RemoteServerConnections;
use App\Domain\Remote\RemoteSourcePackagePublisher;
use App\Domain\Remote\S3ClientFactory;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Jobs\ReconcileRemoteAgentOperation;
use App\Jobs\RunExecutionUnit;
use App\Models\Execution;
use App\Models\Project;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\SourcePackage;
use App\Models\ToolDistribution;
use App\Models\User;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Database\Seeders\ToolCatalogSeeder;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Domain\DomainTestCase;
use Tests\Support\QueuedRemoteTransport;

/**
 * A COLLECT project in REMOTE mode runs the Collector on its source server and
 * reaches REVIEW only with a package the platform verified in S3 itself.
 */
class RemoteCollectionExecutionTest extends DomainTestCase
{
    private const BUCKET = 'mwt-test-packages';

    private QueuedRemoteTransport $transport;

    private MockHandler $s3;

    private User $admin;

    private Project $project;

    private Server $server;

    private string $sha;

    protected function setUp(): void
    {
        parent::setUp();
        config(['toolkit.features.remote_agent.enabled' => true, 'toolkit.features.recolector_742.enabled' => true, 'toolkit.remote.package_bucket' => self::BUCKET,
            'toolkit.remote.package_region' => 'us-east-2', 'toolkit.workspaces.root' => Storage::fake('remote-collection')->path('workspaces')]);
        $this->transport = QueuedRemoteTransport::bind($this->app);
        $this->bindS3();
        $this->seed(ToolCatalogSeeder::class);
        $this->sha = hash('sha256', 'package bytes');
        $this->admin = $this->user(UserRole::ADMIN);
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->post(route('projects.store'), ['name' => 'Recolección remota', 'type' => 'COLLECT'])->assertRedirect();
        $this->project = Project::query()->sole();
        $distribution = ToolDistribution::query()->with('toolVersion')->where('key', RemoteCollectorExport::DISTRIBUTION_KEY)->sole();
        // The catalog authorizes the version explicitly, as for any real execution.
        $distribution->toolVersion->update(['enabled' => true]);
        $this->server = $this->project->servers()->create(['name' => 'Origen EC2', 'role' => ServerRole::SOURCE, 'host' => 'origen.example.edu',
            'metadata' => ['remote_tools' => [RemoteCollectorExport::DISTRIBUTION_KEY => ['tree_sha256' => $distribution->distribution_sha256]],
                'remote_sources' => ['synthetic-lab' => ['ready' => true]]]]);
        app(RemoteServerConnections::class)->configure($this->server, ConnectionType::AWS_SSM,
            ['instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-2'], null, null)->update(['status' => ConnectionStatus::VALID]);
        $this->server->refresh();
    }

    public function test_configuration_accepts_only_a_source_server_of_the_project(): void
    {
        $destination = $this->project->servers()->create(['name' => 'Destino', 'role' => ServerRole::DESTINATION, 'host' => 'destino.example.edu']);
        $foreign = $this->project()->servers()->create(['name' => 'Ajeno', 'role' => ServerRole::SOURCE, 'host' => 'ajeno.example.edu']);
        foreach ([$destination->uuid, $foreign->uuid] as $uuid) {
            $this->put(route('projects.collector.remote-configuration', $this->project), [...$this->input(), 'server_uuid' => $uuid])
                ->assertSessionHasErrors('collector');
        }
        $this->put(route('projects.collector.remote-configuration', $this->project), [...$this->input(), 'path' => '/etc/passwd'])->assertStatus(422);

        $this->put(route('projects.collector.remote-configuration', $this->project), $this->input())->assertRedirect()->assertSessionHasNoErrors();
        $options = $this->project->fresh()->configuration->settings['options'];
        $this->assertSame(['REMOTE', $this->server->uuid, 'synthetic-lab', 2, 'origen-lab'],
            [$options['mode'], $options['server_uuid'], $options['source_profile'], $options['workers'], $options['package_name']]);
    }

    public function test_preflight_blocks_confirmation_until_the_server_is_ready(): void
    {
        $this->server->update(['metadata' => [...$this->server->metadata, 'remote_sources' => ['synthetic-lab' => ['ready' => false]]]]);
        $this->put(route('projects.collector.remote-configuration', $this->project), $this->input())->assertRedirect();
        $this->post(route('projects.wizard.preflight', $this->project))->assertRedirect();
        $checks = collect($this->project->fresh()->configuration->settings['preflight']['checks'])->pluck('result', 'id');

        $this->assertSame('ERROR', $checks['remote.source']);
        $this->assertSame('SUCCESS', $checks['remote.connection']);
        $this->assertSame('SUCCESS', $checks['collector.distribution']);
        $this->post(route('projects.wizard.confirm', $this->project), ['configuration_version' => 2, 'accepted_warning_ids' => ['collector.remote_source']])
            ->assertSessionHasErrors();
        $this->assertNotSame(ProjectStatus::READY, $this->project->fresh()->status);
    }

    public function test_execution_runs_the_export_publishes_and_reaches_review(): void
    {
        $execution = $this->startExecution();
        $binding = $execution->toolBinding;
        $this->assertSame(RemoteCollectionPreparation::ADAPTER_KEY, $binding->adapter_key);
        $this->assertSame('remote-agent', $binding->provider_key);
        $this->assertSame(ExecutionStatus::RUNNING, $execution->status);
        $export = $execution->remoteOperations()->sole();
        $this->assertSame(RemoteCollectorExport::COMMAND, $export->command_key);
        $this->assertSame(['synthetic-lab', 'origen-lab', '2'], [$export->evidence['parameters']['source'],
            $export->evidence['parameters']['package'], $export->evidence['parameters']['workers']]);

        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: true);
        $this->queueReport(['state' => 'running', 'stage' => 'export', 'exit_code' => 0, 'source_id' => 'origen-lab'],
            ['stage' => 'courses', 'total_courses' => 4, 'completed_courses' => 1, 'failed_courses' => 0]);
        dispatch_sync(new ReconcileRemoteAgentOperation($export->id));
        $this->assertSame(25, $execution->fresh()->progress);
        $this->assertSame(1, $execution->events()->where('type', 'collector.progress')->count());

        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-20T12:00:00Z']);
        $this->queueReport(['state' => 'success', 'stage' => 'completed', 'exit_code' => 0, 'source_id' => 'origen-lab'],
            ['stage' => 'completed', 'total_courses' => 4, 'completed_courses' => 4, 'failed_courses' => 0],
            [['name' => 'origen-lab.zip', 'size' => 4096]], "{$this->sha}  origen-lab.zip");
        $this->queueLaunch(RemoteSourcePackagePublisher::COMMAND);
        dispatch_sync(new ReconcileRemoteAgentOperation($export->id));
        $execution->refresh();
        $this->assertSame(ExecutionStatus::VERIFYING, $execution->status);
        $upload = $execution->remoteOperations()->where('command_key', RemoteSourcePackagePublisher::COMMAND)->sole();
        $this->assertSame($export->operation_uuid, $upload->evidence['parameters']['collector']);

        // The scheduler keeps the terminated export due while the Execution verifies; it never relaunches the upload.
        $this->artisan('executions:reconcile-remote-operations')->assertSuccessful();
        $this->assertSame(1, $execution->remoteOperations()->where('command_key', RemoteSourcePackagePublisher::COMMAND)->count());

        $this->queueStatus(RemoteSourcePackagePublisher::COMMAND, alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-20T12:05:00Z']);
        $this->queueUploadReport($upload);
        $this->queueS3Package();
        dispatch_sync(new ReconcileRemoteAgentOperation($upload->id));

        $execution->refresh();
        $this->assertSame(ExecutionStatus::REVIEW, $execution->status);
        $this->assertSame(ProjectStatus::REVIEW, $this->project->fresh()->status);
        $this->assertNull($execution->progress);
        $package = SourcePackage::query()->sole();
        $this->assertSame('VALID', $package->validation_state);
        $this->assertSame($execution->id, $package->producer_execution_id);
        $this->assertTrue($execution->verifications()->sole()->approved);
        $this->assertSame(0, $this->transport->pending());

        $page = $this->getJson(route('projects.executions.events', [$this->project, $execution]))->assertOk();
        $page->assertJsonPath('execution.collector.mode', 'REMOTE')->assertJsonPath('execution.collector.server', 'Origen EC2')
            ->assertJsonPath('review.source_packages.0.sha256', $package->sha256);
        $this->assertSame(['collector.operation_registered', 'collector.progress', 'collector.export_completed',
            'collector.publication_requested', 'collector.package_registered'],
            $execution->events()->whereIn('type', ['collector.operation_registered', 'collector.progress', 'collector.export_completed',
                'collector.publication_requested', 'collector.package_registered'])->orderBy('sequence')->pluck('type')->unique()->values()->all());

        // Final confirmation of a remote package is a later stage; it is refused before any command exists.
        $this->post(route('projects.executions.finalize', [$this->project, $execution]), [], ['Idempotency-Key' => 'finalize-remote'])
            ->assertSessionHasErrors('execution');
        $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
        $this->assertSame(0, $execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->count());

        // REVIEW is a resting state: the scheduler no longer touches the operations.
        $this->artisan('executions:reconcile-remote-operations')->assertSuccessful();
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_export_that_fails_closes_the_execution_without_publishing(): void
    {
        $execution = $this->startExecution();
        $export = $execution->remoteOperations()->sole();
        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: false, exit: ['exit_code' => 3, 'cancel_requested' => false, 'finished_at' => '2026-10-20T12:00:00Z']);
        $this->queueReport(['state' => 'error', 'stage' => 'export', 'exit_code' => 3, 'source_id' => 'origen-lab'], ['stage' => 'courses']);
        dispatch_sync(new ReconcileRemoteAgentOperation($export->id));

        $execution->refresh();
        $this->assertSame(ExecutionStatus::FAILED, $execution->status);
        $this->assertSame(1, $execution->events()->where('type', 'collector.failed')->count());
        $this->assertSame(0, $execution->remoteOperations()->where('command_key', RemoteSourcePackagePublisher::COMMAND)->count());
        $this->assertSame(0, SourcePackage::query()->count());
    }

    public function test_successful_exit_without_final_report_waits_instead_of_failing(): void
    {
        $execution = $this->startExecution();
        $export = $execution->remoteOperations()->sole();
        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-20T12:00:00Z']);
        $this->transport->unreachable();
        dispatch_sync(new ReconcileRemoteAgentOperation($export->id));

        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertSame('REMOTE_REPORT_PENDING', $export->fresh()->last_reconcile_error);
        $this->assertSame(1, $execution->events()->where('type', 'collector.reconciliation_pending')->count());
    }

    public function test_cancellation_reaches_the_server_and_closes_once_the_export_ends(): void
    {
        $execution = $this->startExecution();
        $export = $execution->remoteOperations()->sole();
        $this->transport->okWith('cancel', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script),
            'signalled' => true, 'already_finished' => false]);
        $this->postJson(route('projects.executions.cancel', [$this->project, $execution]), [], ['Idempotency-Key' => 'cancel-remote'])->assertSuccessful();
        $this->assertNotNull($execution->commands()->where('command_type', ExecutionCommandType::CANCEL)->sole()->processed_at);
        $this->assertArrayHasKey('cancel_requested_at', $export->fresh()->evidence);
        $this->assertSame(ExecutionStatus::CANCELLING, $execution->fresh()->status);

        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: false, exit: ['exit_code' => 143, 'cancel_requested' => true, 'finished_at' => '2026-10-20T12:00:00Z']);
        $this->queueReport(['state' => 'cancelled', 'stage' => 'export', 'source_id' => 'origen-lab'], ['stage' => 'courses']);
        dispatch_sync(new ReconcileRemoteAgentOperation($export->id));

        $this->assertSame(ExecutionStatus::CANCELLED, $execution->fresh()->status);
        $this->assertSame(1, $execution->events()->where('type', 'collector.cancelled')->count());
    }

    public function test_remote_package_is_not_finalized_through_the_simulated_flow(): void
    {
        $execution = $this->startExecution();
        $this->post(route('projects.executions.validate', [$this->project, $execution]), [], ['Idempotency-Key' => 'validate-remote'])
            ->assertSessionHasErrors('verification');
        $this->assertSame(0, $execution->verifications()->count());
    }

    private function startExecution(): Execution
    {
        $this->put(route('projects.collector.remote-configuration', $this->project), $this->input())->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('projects.wizard.preflight', $this->project))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('projects.wizard.confirm', $this->project), ['configuration_version' => 2, 'accepted_warning_ids' => ['collector.remote_source']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ProjectStatus::READY, $this->project->fresh()->status);
        // The START command runs on the synchronous test queue as soon as the request commits.
        $this->queueLaunch(RemoteCollectorExport::COMMAND);
        $this->postJson(route('projects.executions.store', $this->project), ['configuration_version' => 2], ['Idempotency-Key' => 'remote-start'])->assertCreated();
        $execution = $this->project->executions()->sole();
        // A repeated START never launches a second export.
        $this->app->call([new RunExecutionUnit($execution->commands()->where('command_type', ExecutionCommandType::START)->sole()->id), 'handle']);
        $this->assertSame(1, RemoteOperation::query()->count());

        return $execution->fresh();
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['server_uuid' => $this->server->uuid, 'source_profile' => 'synthetic-lab', 'workers' => 2, 'package_name' => 'origen-lab'];
    }

    private function queueLaunch(string $command): void
    {
        $this->transport->okWith('launch', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script),
            'created' => true, 'launch' => $this->launchEvidence(QueuedRemoteTransport::operationUuid($script), $command)]);
    }

    /** @param array<string, mixed>|null $exit */
    private function queueStatus(string $command, bool $alive, ?array $exit = null): void
    {
        $this->transport->okWith('status', function (string $script) use ($command, $alive, $exit): array {
            $uuid = QueuedRemoteTransport::operationUuid($script);
            $now = now()->utc()->toIso8601String();

            return ['operation_uuid' => $uuid, 'launch' => $this->launchEvidence($uuid, $command), 'run' => ['pid' => 7, 'started_at' => $now],
                'exit' => $exit, 'cancel_requested' => ($exit['cancel_requested'] ?? false) === true, 'process_alive' => $alive, 'heartbeat_at' => $now,
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

    private function queueUploadReport(RemoteOperation $upload): void
    {
        $result = ['bucket' => self::BUCKET, 'region' => 'us-east-2', 'prefix' => 'mwt/'.$this->project->uuid.'/synthetic-lab/'.$this->sha,
            'package' => 'origen-lab', 'source' => 'synthetic-lab', 'sha256' => $this->sha, 'size' => 4096,
            'manifest_sha256' => hash('sha256', $this->manifest()), 'validation_sha256' => hash('sha256', $this->validation()),
            'uploaded_at' => '2026-10-20T12:05:00Z'];
        $this->transport->ok('upload-report', ['operation_uuid' => $upload->operation_uuid, 'result_base64' => base64_encode((string) json_encode($result))]);
    }

    private function queueS3Package(): void
    {
        $head = fn () => new Result(['ContentLength' => 4096, 'Metadata' => ['mwt-sha256' => $this->sha],
            'ChecksumSHA256' => base64_encode((string) hex2bin($this->sha)), 'ChecksumType' => 'FULL_OBJECT']);
        $this->s3->append($head(), new Result(['ContentLength' => strlen($this->manifest()), 'Body' => Utils::streamFor($this->manifest())]),
            new Result(['ContentLength' => strlen($this->validation()), 'Body' => Utils::streamFor($this->validation())]), $head());
    }

    private function manifest(): string
    {
        return (string) json_encode(['collector_version' => '7.4.2-linux', 'schema_version' => '1.0',
            'package_type' => 'moodle-consolidation-source', 'package_status' => 'sealed', 'source_write_performed' => false,
            'destination_write_performed' => false, 'source_id' => 'origen-lab', 'courses_expected' => 4,
            'capabilities' => ['theme_inventory' => '1.0'], 'source_name' => 'Origen de laboratorio']);
    }

    private function validation(): string
    {
        return (string) json_encode(['result' => 'ok', 'failures' => [], 'warnings' => [],
            'outer_zip' => ['sha256' => $this->sha, 'bytes' => 4096, 'sidecar_status' => 'ok'], 'counts' => ['files' => 10, 'courses' => 4]]);
    }

    /** @return array<string, mixed> */
    private function launchEvidence(string $uuid, string $command): array
    {
        $distribution = app(RemoteAgent::class)->distribution();

        return ['operation_uuid' => $uuid, 'command_key' => $command, 'launcher' => 'systemd', 'unit' => 'mwt-op-'.$uuid,
            'agent_version' => $distribution['version'], 'agent_sha256' => $distribution['sha256'], 'argv_sha256' => str_repeat('b', 64),
            'launched_at' => now()->utc()->toIso8601String(), 'parameters' => []];
    }

    private function bindS3(): void
    {
        $this->s3 = new MockHandler;
        $client = new S3Client(['region' => 'us-east-2', 'version' => '2006-03-01', 'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => fn (CommandInterface $command, $request) => ($this->s3)($command, $request)]);
        $this->app->instance(S3ClientFactory::class, new class($client) extends S3ClientFactory
        {
            public function __construct(private readonly S3Client $client) {}

            public function make(string $region): S3Client
            {
                return $this->client;
            }
        });
    }
}
