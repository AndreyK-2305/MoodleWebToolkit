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
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\Project;
use App\Models\ProjectAssignment;
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
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /** Once the package is registered, S3 keeps answering HeadObject for the verified object. */
    private bool $objectStored = false;

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
        // The single-source shape is still accepted and stored as a list of one (jsonb may reorder keys).
        $this->assertEquals(['REMOTE', 'collector-remote.v2', 2, [['server_uuid' => $this->server->uuid, 'source_profile' => 'synthetic-lab', 'package_name' => 'origen-lab']]],
            [$options['mode'], $options['schema_version'], $options['workers'], $options['sources']]);
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

    public function test_a_disabled_recolector_is_named_as_the_cause_and_survives_a_re_seed(): void
    {
        $this->put(route('projects.collector.remote-configuration', $this->project), $this->input())->assertRedirect();
        // Re-seeding the catalog keeps the version the operator enabled.
        $this->seed(ToolCatalogSeeder::class);
        $this->post(route('projects.wizard.preflight', $this->project))->assertRedirect();
        $this->assertSame('SUCCESS', collect($this->project->fresh()->configuration->settings['preflight']['checks'])->pluck('result', 'id')['collector.distribution']);

        ToolDistribution::query()->with('toolVersion')->where('key', RemoteCollectorExport::DISTRIBUTION_KEY)->sole()->toolVersion->update(['enabled' => false]);
        $this->post(route('projects.wizard.preflight', $this->project))->assertRedirect();
        $check = collect($this->project->fresh()->configuration->settings['preflight']['checks'])->firstWhere('id', 'collector.distribution');
        $this->assertSame(['ERROR', 'Recolector 7.4.2 habilitado en la plataforma'], [$check['result'], $check['description']]);
        $this->assertStringContainsString('no está habilitado en el catálogo', $check['detail']);
        $this->assertStringNotContainsString('Despliega', $check['detail']);
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

        // REVIEW is a resting state: the scheduler no longer touches the operations.
        $this->artisan('executions:reconcile-remote-operations')->assertSuccessful();
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_review_is_confirmed_with_reports_and_the_package_left_for_the_administrator(): void
    {
        $execution = $this->reviewedExecution();
        $this->objectStored = true;

        $this->post(route('projects.executions.finalize', [$this->project, $execution]), [], ['Idempotency-Key' => 'finalize-remote'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $execution->refresh();
        $this->assertSame(ExecutionStatus::COMPLETED, $execution->status);
        $this->assertSame(ProjectStatus::COMPLETED, $this->project->fresh()->status);
        $this->assertSame(RemoteCollectionPreparation::ADAPTER_KEY, $execution->completion_summary['adapter']);
        $this->assertSame(5, $execution->artifacts()->count(), 'La referencia S3 y los cuatro informes de cierre.');
        $summary = $execution->artifacts()->where('type', 'FINAL_SUMMARY')->sole();
        $report = Storage::disk($summary->disk)->get($summary->path);
        $this->assertStringContainsString('ADMINISTRATOR', $report);
        $this->assertStringContainsString(self::BUCKET, $report);
        $this->assertSame('VALID', SourcePackage::query()->sole()->validation_state);
    }

    public function test_package_that_changed_in_s3_is_never_confirmed(): void
    {
        $execution = $this->reviewedExecution();
        $this->s3->append(new Result(['ContentLength' => 4096, 'Metadata' => ['mwt-sha256' => str_repeat('0', 64)]]));

        $this->post(route('projects.executions.finalize', [$this->project, $execution]), [], ['Idempotency-Key' => 'finalize-changed']);

        $this->assertNotSame(ExecutionStatus::COMPLETED, $execution->fresh()->status);
        $this->assertSame(0, $execution->artifacts()->where('type', 'FINAL_SUMMARY')->count());
    }

    public function test_only_an_administrator_gets_a_short_lived_link_to_the_package(): void
    {
        $execution = $this->reviewedExecution();
        $this->objectStored = true;
        $artifact = $execution->artifacts()->where('disk', 's3')->sole();
        $url = route('projects.executions.artifacts.download', [$this->project, $execution, $artifact]).'?key='.Str::uuid();

        $location = $this->get($url)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://'.self::BUCKET.'.s3.us-east-2.amazonaws.com/mwt/', (string) $location);
        $this->assertStringContainsString('X-Amz-Expires=900', (string) $location);
        $this->assertSame('S3_PRESIGNED', AuditLog::query()->where('action', 'ARTIFACT_DOWNLOADED')->sole()->payload['delivery']);

        $operator = $this->user(UserRole::OPERATOR);
        ProjectAssignment::query()->create(['project_id' => $this->project->id, 'user_id' => $operator->id, 'assigned_by' => $this->admin->id]);
        $this->actingAs($operator)->get(route('projects.executions.artifacts.download', [$this->project, $execution, $artifact]).'?key='.Str::uuid())
            ->assertForbidden();
        $this->assertSame(1, AuditLog::query()->where('action', 'ARTIFACT_DOWNLOADED')->count());
    }

    public function test_failed_attempt_is_exported_again_from_zero_keeping_its_evidence(): void
    {
        $failed = $this->startExecution();
        $export = $failed->remoteOperations()->sole();
        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: false, exit: ['exit_code' => 3, 'cancel_requested' => false, 'finished_at' => '2026-10-20T12:00:00Z']);
        $this->queueReport(['state' => 'error', 'stage' => 'export', 'exit_code' => 3, 'source_id' => 'origen-lab'], ['stage' => 'courses']);
        dispatch_sync(new ReconcileRemoteAgentOperation($export->id));
        $this->assertSame(ExecutionStatus::FAILED, $failed->fresh()->status);
        $this->getJson(route('projects.executions.events', [$this->project, $failed]))->assertJsonPath('execution.collector.can_fresh_export', true);

        $this->queueLaunch(RemoteCollectorExport::COMMAND);
        $this->postJson(route('projects.executions.fresh-export', [$this->project, $failed]), ['configuration_version' => 2, 'accept_laboratory' => true],
            ['Idempotency-Key' => 'remote-fresh-export'])->assertCreated();

        $retry = Execution::query()->where('retried_from_execution_id', $failed->id)->sole();
        $this->assertSame(2, $retry->attempt);
        $this->assertSame(ExecutionStatus::RUNNING, $retry->status);
        $this->assertSame(RemoteCollectionPreparation::ADAPTER_KEY, $retry->toolBinding->adapter_key);
        $this->assertSame(1, $retry->remoteOperations()->where('command_key', RemoteCollectorExport::COMMAND)->count());
        $this->assertSame(ExecutionStatus::FAILED, $failed->fresh()->status);
        $this->assertSame(1, $failed->remoteOperations()->count());
    }

    public function test_cancellation_during_publication_never_registers_a_package_and_a_new_attempt_reaches_review(): void
    {
        $cancelled = $this->startExecution();
        $export = $cancelled->remoteOperations()->sole();
        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-20T12:00:00Z']);
        $this->queueReport(['state' => 'success', 'stage' => 'completed', 'exit_code' => 0, 'source_id' => 'origen-lab'],
            ['stage' => 'completed', 'total_courses' => 4, 'completed_courses' => 4, 'failed_courses' => 0],
            [['name' => 'origen-lab.zip', 'size' => 4096]], "{$this->sha}  origen-lab.zip");
        $this->queueLaunch(RemoteSourcePackagePublisher::COMMAND);
        dispatch_sync(new ReconcileRemoteAgentOperation($export->id));
        $upload = $cancelled->remoteOperations()->where('command_key', RemoteSourcePackagePublisher::COMMAND)->sole();

        // The upload already finished on the server when the cancellation arrives.
        $this->transport->okWith('cancel', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script),
            'signalled' => false, 'already_finished' => true]);
        $this->postJson(route('projects.executions.cancel', [$this->project, $cancelled]), [], ['Idempotency-Key' => 'cancel-publishing'])->assertSuccessful();
        $this->queueStatus(RemoteSourcePackagePublisher::COMMAND, alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-20T12:05:00Z']);
        dispatch_sync(new ReconcileRemoteAgentOperation($upload->id));

        $this->assertSame(ExecutionStatus::CANCELLED, $cancelled->fresh()->status);
        $this->assertSame(0, SourcePackage::query()->count(), 'Una ejecución cancelada nunca registra el paquete subido.');
        $this->assertSame(0, $this->transport->pending());

        $this->queueLaunch(RemoteCollectorExport::COMMAND);
        $this->postJson(route('projects.executions.fresh-export', [$this->project, $cancelled]), ['configuration_version' => 2, 'accept_laboratory' => true],
            ['Idempotency-Key' => 'fresh-after-cancel'])->assertCreated();
        $retry = Execution::query()->where('retried_from_execution_id', $cancelled->id)->sole();
        $retryExport = $retry->remoteOperations()->sole();
        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-20T13:00:00Z']);
        $this->queueReport(['state' => 'success', 'stage' => 'completed', 'exit_code' => 0, 'source_id' => 'origen-lab'],
            ['stage' => 'completed', 'total_courses' => 4, 'completed_courses' => 4, 'failed_courses' => 0],
            [['name' => 'origen-lab.zip', 'size' => 4096]], "{$this->sha}  origen-lab.zip");
        $this->queueLaunch(RemoteSourcePackagePublisher::COMMAND);
        dispatch_sync(new ReconcileRemoteAgentOperation($retryExport->id));
        $retryUpload = $retry->remoteOperations()->where('command_key', RemoteSourcePackagePublisher::COMMAND)->sole();
        $this->queueStatus(RemoteSourcePackagePublisher::COMMAND, alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-20T13:05:00Z']);
        $this->queueUploadReport($retryUpload);
        $this->queueS3Package();
        dispatch_sync(new ReconcileRemoteAgentOperation($retryUpload->id));

        $this->assertSame(ExecutionStatus::REVIEW, $retry->fresh()->status);
        $this->assertSame($retry->id, SourcePackage::query()->sole()->producer_execution_id);
    }

    public function test_collector_deployment_and_source_check_run_from_the_interface(): void
    {
        $this->transport->ok('source-list', ['sources_dir_exists' => true, 'sources' => [
            ['id' => 'perfil-nuevo', 'valid' => true, 'runtime' => 'native', 'run_as' => 'www-data', 'error_code' => null],
            ['id' => 'abierto', 'valid' => false, 'runtime' => null, 'run_as' => null, 'error_code' => 'source_profile_unsafe'],
            ['id' => '../fuera', 'valid' => true, 'runtime' => 'native', 'run_as' => 'root', 'error_code' => null],
        ]]);
        $this->post(route('projects.servers.sources.detect', [$this->project, $this->server]))->assertRedirect();
        $this->assertSame(['perfil-nuevo', 'abierto'], array_column($this->server->fresh()->metadata['remote_sources_detected']['sources'], 'id'));

        // Readiness needs Moodle to load its configuration and a writable moodledata.
        $report = ['source' => 'perfil-nuevo', 'runtime' => 'native', 'run_as' => 'www-data', 'php_version' => '8.3.20', 'zip' => true,
            'dom' => true, 'config_readable' => true, 'moodle_release' => null, 'moodle_version' => null, 'dataroot_free_bytes' => null,
            'dataroot_writable' => false, 'state_free_bytes' => 9_000_000_000];
        $this->transport->ok('source-check', $report);
        $this->post(route('projects.servers.sources.check', [$this->project, $this->server]), ['source' => 'perfil-nuevo'])->assertRedirect();
        $this->assertFalse($this->server->fresh()->metadata['remote_sources']['perfil-nuevo']['ready']);
        $this->transport->ok('source-check', [...$report, 'moodle_release' => '4.5.10 (Build: 20250101)', 'moodle_version' => 2024100710,
            'dataroot_free_bytes' => 50_000_000_000, 'dataroot_writable' => true, 'path' => '/var/www/no-debe-guardarse']);
        $this->post(route('projects.servers.sources.check', [$this->project, $this->server]), ['source' => 'perfil-nuevo'])->assertRedirect();
        $this->assertTrue($this->server->fresh()->metadata['remote_sources']['perfil-nuevo']['ready']);
        $this->assertArrayNotHasKey('path', $this->server->fresh()->metadata['remote_sources']['perfil-nuevo']['report']);

        $profiles = collect($this->get(route('projects.show', $this->project))->viewData('page')['props']['remoteAccess']['servers'][0]['sources'])->keyBy('id');
        $this->assertSame('4.5.10 (Build: 20250101)', $profiles['perfil-nuevo']['moodle_release']);
        $this->assertTrue($profiles['perfil-nuevo']['ready']);
        $this->assertSame('source_profile_unsafe', $profiles['abierto']['profile_error']);
        $this->post(route('projects.servers.sources.check', [$this->project, $this->server]), ['source' => '../etc'])->assertSessionHasErrors('source');

        $deployed = $this->server->fresh()->metadata['remote_tools'];
        $this->transport->unreachable();
        $this->post(route('projects.servers.collector.deploy', [$this->project, $this->server]))->assertRedirect();
        $this->assertSame($deployed, $this->server->fresh()->metadata['remote_tools'], 'Un despliegue fallido no altera la evidencia previa.');
    }

    public function test_source_disk_is_freed_once_the_package_is_verified_in_s3(): void
    {
        $execution = $this->reviewedExecution(cleanup: true);

        $this->assertSame(['cleanup', 'cleanup'], array_slice($this->transport->subcommands(), -2));
        foreach ($execution->remoteOperations()->get() as $operation) {
            $this->assertArrayHasKey('cleanup', $operation->evidence);
        }
        $event = $execution->events()->where('type', 'collector.source_cleaned')->sole();
        $this->assertSame(3_002_048, $event->payload['freed_bytes']);
        $this->getJson(route('projects.executions.events', [$this->project, $execution]))
            ->assertJsonPath('execution.collector.source_cleanup.state', 'CLEANED')
            ->assertJsonPath('execution.collector.source_cleanup.freed_bytes', 3_002_048);

        // Nothing is cleaned twice.
        $this->artisan('executions:reconcile-remote-operations')->assertSuccessful();
        $this->assertSame(1, $execution->events()->where('type', 'collector.source_cleaned')->count());
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_unreachable_source_is_cleaned_later_with_backoff_without_blocking_review(): void
    {
        $execution = $this->reviewedExecution();
        $this->assertSame(ExecutionStatus::REVIEW, $execution->status);
        $export = $execution->remoteOperations()->where('command_key', RemoteCollectorExport::COMMAND)->sole();
        $this->assertSame(1, $export->evidence['cleanup_pending']['attempts']);
        $this->getJson(route('projects.executions.events', [$this->project, $execution]))
            ->assertJsonPath('execution.collector.source_cleanup.state', 'PENDING');

        // Before the backoff elapses the scheduler does not call the server.
        $calls = count($this->transport->subcommands());
        $this->artisan('executions:reconcile-remote-operations')->assertSuccessful();
        $this->assertCount($calls, $this->transport->subcommands());

        $this->travel(2)->minutes();
        $this->queueCleanup(3_000_000);
        $this->queueCleanup(0);
        $this->artisan('executions:reconcile-remote-operations')->assertSuccessful();
        $this->assertSame(3_000_000, $export->fresh()->evidence['cleanup']['freed_bytes']);
        $this->assertArrayNotHasKey('cleanup_pending', $export->fresh()->evidence);
        $this->assertSame(1, $execution->events()->where('type', 'collector.source_cleaned')->count());
    }

    public function test_two_sources_export_and_reach_review_with_one_package_each(): void
    {
        [$execution, $first, $second] = $this->startTwoSources();
        $this->assertSame(['synthetic-lab', 'posgrado'], [$first->evidence['parameters']['source'], $second->evidence['parameters']['source']]);
        $this->assertSame(2, $execution->events()->where('type', 'collector.operation_registered')->count());
        $shaTwo = hash('sha256', 'second package');

        // The first source finishes; the Execution waits for the second one before publishing.
        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:00:00Z']);
        $this->queueReport(['state' => 'success', 'stage' => 'completed', 'exit_code' => 0, 'source_id' => 'origen-lab'],
            ['stage' => 'completed', 'total_courses' => 4, 'completed_courses' => 4, 'failed_courses' => 0],
            [['name' => 'origen-lab.zip', 'size' => 4096]], "{$this->sha}  origen-lab.zip");
        dispatch_sync(new ReconcileRemoteAgentOperation($first->id));
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertNull($execution->fresh()->progress, 'Sin el total del segundo origen el progreso es desconocido.');

        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: true);
        $this->queueReport(['state' => 'running', 'stage' => 'export', 'exit_code' => 0, 'source_id' => 'origen-dos'],
            ['stage' => 'courses', 'total_courses' => 4, 'completed_courses' => 2, 'failed_courses' => 0], package: 'origen-dos');
        dispatch_sync(new ReconcileRemoteAgentOperation($second->id));
        $this->assertSame(75, $execution->fresh()->progress, '6 de 8 cursos entre los dos orígenes.');

        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:10:00Z']);
        $this->queueReport(['state' => 'success', 'stage' => 'completed', 'exit_code' => 0, 'source_id' => 'origen-dos'],
            ['stage' => 'completed', 'total_courses' => 4, 'completed_courses' => 4, 'failed_courses' => 0],
            [['name' => 'origen-dos.zip', 'size' => 4096]], "{$shaTwo}  origen-dos.zip", 'origen-dos');
        $this->queueLaunch(RemoteSourcePackagePublisher::COMMAND);
        $this->queueLaunch(RemoteSourcePackagePublisher::COMMAND);
        dispatch_sync(new ReconcileRemoteAgentOperation($second->id));
        $this->assertSame(ExecutionStatus::VERIFYING, $execution->fresh()->status);
        $uploads = $execution->remoteOperations()->where('command_key', RemoteSourcePackagePublisher::COMMAND)->orderBy('id')->get();
        $this->assertCount(2, $uploads);

        // One upload ending does not register anything until the other one ends too.
        $this->queueStatus(RemoteSourcePackagePublisher::COMMAND, alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:15:00Z']);
        dispatch_sync(new ReconcileRemoteAgentOperation($uploads[0]->id));
        $this->assertSame(0, SourcePackage::query()->count());

        $this->queueStatus(RemoteSourcePackagePublisher::COMMAND, alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:16:00Z']);
        $this->queueUploadReport($uploads[0]);
        $this->queueS3Package();
        $this->queueUploadReport($uploads[1], $shaTwo, 'posgrado', 'origen-dos', 'origen-dos');
        $this->queueS3Package($shaTwo, 'origen-dos');
        dispatch_sync(new ReconcileRemoteAgentOperation($uploads[1]->id));

        $execution->refresh();
        $this->assertSame(ExecutionStatus::REVIEW, $execution->status);
        $this->assertEqualsCanonicalizing(['origen-lab', 'origen-dos'], SourcePackage::query()->pluck('source_id')->all());
        $this->assertSame(2, $execution->events()->where('type', 'collector.package_registered')->count());
        $page = $this->getJson(route('projects.executions.events', [$this->project, $execution]))->assertOk();
        $page->assertJsonPath('execution.collector.sources.1.server', 'Origen Posgrado')->assertJsonCount(2, 'review.source_packages');

        // Final confirmation re-checks both objects in S3 and keeps one reference per source.
        $this->objectStored = true;
        $this->post(route('projects.executions.finalize', [$this->project, $execution]), [], ['Idempotency-Key' => 'finalize-two'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ExecutionStatus::COMPLETED, $execution->fresh()->status);
        $this->assertSame(6, $execution->artifacts()->count(), 'Dos referencias S3 y cuatro informes.');
    }

    public function test_a_failed_source_cancels_the_others_and_registers_no_package(): void
    {
        [$execution, $first, $second] = $this->startTwoSources();
        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: false, exit: ['exit_code' => 3, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:00:00Z']);
        $this->queueReport(['state' => 'error', 'stage' => 'export', 'exit_code' => 3, 'source_id' => 'origen-lab'], ['stage' => 'courses']);
        $this->transport->okWith('cancel', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script),
            'signalled' => true, 'already_finished' => false]);
        dispatch_sync(new ReconcileRemoteAgentOperation($first->id));

        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status, 'Espera a que termine el origen cancelado.');
        $this->assertArrayHasKey('cancel_requested_at', $second->fresh()->evidence);
        $this->assertSame(1, $execution->events()->where('type', 'collector.source_failed')->count());

        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: false, exit: ['exit_code' => 143, 'cancel_requested' => true, 'finished_at' => '2026-10-21T12:01:00Z']);
        $this->queueReport(['state' => 'cancelled', 'stage' => 'export', 'source_id' => 'origen-dos'], ['stage' => 'courses'], package: 'origen-dos');
        dispatch_sync(new ReconcileRemoteAgentOperation($second->id));

        $this->assertSame(ExecutionStatus::FAILED, $execution->fresh()->status);
        $this->assertSame(0, SourcePackage::query()->count());
        $this->assertSame(0, $execution->remoteOperations()->where('command_key', RemoteSourcePackagePublisher::COMMAND)->count());
    }

    public function test_sources_must_be_distinct_profiles_with_their_own_package(): void
    {
        $second = $this->secondSource();
        $row = fn (Server $server, string $profile, string $package): array => ['server_uuid' => $server->uuid, 'source_profile' => $profile, 'package_name' => $package];
        foreach ([
            [$row($this->server, 'synthetic-lab', 'uno'), $row($this->server, 'synthetic-lab', 'dos')],
            [$row($this->server, 'synthetic-lab', 'uno'), $row($second, 'posgrado', 'uno')],
            array_fill(0, 9, $row($this->server, 'synthetic-lab', 'uno')),
        ] as $sources) {
            $this->put(route('projects.collector.remote-configuration', $this->project), ['workers' => 1, 'sources' => $sources])
                ->assertSessionHasErrors();
        }
        $this->put(route('projects.collector.remote-configuration', $this->project), ['workers' => 2, 'sources' => [
            $row($this->server, 'synthetic-lab', 'uno'), $row($second, 'posgrado', 'dos')]])->assertRedirect()->assertSessionHasNoErrors();
        $options = $this->project->fresh()->configuration->settings['options'];
        $this->assertSame(['collector-remote.v2', 2, ['uno', 'dos']], [$options['schema_version'], $options['workers'], array_column($options['sources'], 'package_name')]);
    }

    public function test_export_that_fails_closes_the_execution_without_publishing(): void
    {
        $execution = $this->startExecution();
        $export = $execution->remoteOperations()->sole();
        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: false, exit: ['exit_code' => 3, 'cancel_requested' => false, 'finished_at' => '2026-10-20T12:00:00Z']);
        $this->queueReport(['state' => 'error', 'stage' => 'export', 'exit_code' => 3, 'source_id' => 'origen-lab'], ['stage' => 'courses']);
        $this->queueCleanup(1_500_000);
        dispatch_sync(new ReconcileRemoteAgentOperation($export->id));

        $execution->refresh();
        $this->assertSame(ExecutionStatus::FAILED, $execution->status);
        $this->assertSame(1, $execution->events()->where('type', 'collector.failed')->count());
        $this->assertSame(1_500_000, $export->fresh()->evidence['cleanup']['freed_bytes']);
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

    private function reviewedExecution(bool $cleanup = false): Execution
    {
        $execution = $this->startExecution();
        $export = $execution->remoteOperations()->sole();
        $this->queueStatus(RemoteCollectorExport::COMMAND, alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-20T12:00:00Z']);
        $this->queueReport(['state' => 'success', 'stage' => 'completed', 'exit_code' => 0, 'source_id' => 'origen-lab'],
            ['stage' => 'completed', 'total_courses' => 4, 'completed_courses' => 4, 'failed_courses' => 0],
            [['name' => 'origen-lab.zip', 'size' => 4096]], "{$this->sha}  origen-lab.zip");
        $this->queueLaunch(RemoteSourcePackagePublisher::COMMAND);
        dispatch_sync(new ReconcileRemoteAgentOperation($export->id));
        $upload = $execution->remoteOperations()->where('command_key', RemoteSourcePackagePublisher::COMMAND)->sole();
        $this->queueStatus(RemoteSourcePackagePublisher::COMMAND, alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-20T12:05:00Z']);
        $this->queueUploadReport($upload);
        $this->queueS3Package();
        if ($cleanup) {
            $this->queueCleanup(3_000_000);
            $this->queueCleanup(2048);
        }
        dispatch_sync(new ReconcileRemoteAgentOperation($upload->id));
        $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);

        return $execution->fresh();
    }

    private function secondSource(): Server
    {
        $server = $this->project->servers()->create(['name' => 'Origen Posgrado', 'role' => ServerRole::SOURCE, 'host' => 'posgrado.example.edu',
            'metadata' => ['remote_tools' => [RemoteCollectorExport::DISTRIBUTION_KEY => ['tree_sha256' => ToolDistribution::query()
                ->where('key', RemoteCollectorExport::DISTRIBUTION_KEY)->value('distribution_sha256')]],
                'remote_sources' => ['posgrado' => ['ready' => true]]]]);
        app(RemoteServerConnections::class)->configure($server, ConnectionType::AWS_SSM,
            ['instance_id' => 'i-0fedcba9876543210', 'region' => 'us-east-2'], null, null)->update(['status' => ConnectionStatus::VALID]);

        return $server->refresh();
    }

    /** @return array{0: Execution, 1: RemoteOperation, 2: RemoteOperation} */
    private function startTwoSources(): array
    {
        $second = $this->secondSource();
        $this->put(route('projects.collector.remote-configuration', $this->project), ['workers' => 1, 'sources' => [
            ['server_uuid' => $this->server->uuid, 'source_profile' => 'synthetic-lab', 'package_name' => 'origen-lab'],
            ['server_uuid' => $second->uuid, 'source_profile' => 'posgrado', 'package_name' => 'origen-dos'],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('projects.wizard.preflight', $this->project))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('projects.wizard.confirm', $this->project), ['configuration_version' => 2, 'accepted_warning_ids' => ['collector.remote_source']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->queueLaunch(RemoteCollectorExport::COMMAND);
        $this->queueLaunch(RemoteCollectorExport::COMMAND);
        $this->postJson(route('projects.executions.store', $this->project), ['configuration_version' => 2], ['Idempotency-Key' => 'two-sources'])->assertCreated();
        $execution = $this->project->executions()->sole();
        $exports = $execution->remoteOperations()->orderBy('id')->get();
        $this->assertCount(2, $exports);

        return [$execution->fresh(), $exports[0], $exports[1]];
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

    private function queueCleanup(int $freed): void
    {
        $this->transport->okWith('cleanup', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script),
            'already_clean' => false, 'cleanup' => ['cleaned_at' => '2026-10-21T12:00:00Z', 'freed_bytes' => $freed, 'command_key' => 'collector.742']]);
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
    private function queueReport(array $status, array $progress, array $outputs = [], ?string $sidecar = null, string $package = 'origen-lab'): void
    {
        $this->transport->okWith('collector-report', fn (string $script): array => [
            'operation_uuid' => QueuedRemoteTransport::operationUuid($script), 'package' => $package,
            'status_base64' => base64_encode((string) json_encode($status)), 'progress_base64' => base64_encode((string) json_encode($progress)),
            'outputs' => $outputs, 'sidecar' => $sidecar, 'observed_at' => now()->utc()->toIso8601String(),
        ]);
    }

    private function queueUploadReport(RemoteOperation $upload, ?string $sha = null, string $profile = 'synthetic-lab', string $package = 'origen-lab', string $sourceId = 'origen-lab'): void
    {
        $sha ??= $this->sha;
        $result = ['bucket' => self::BUCKET, 'region' => 'us-east-2', 'prefix' => 'mwt/'.$this->project->uuid.'/'.$profile.'/'.$sha,
            'package' => $package, 'source' => $profile, 'sha256' => $sha, 'size' => 4096,
            'manifest_sha256' => hash('sha256', $this->manifest($sourceId)), 'validation_sha256' => hash('sha256', $this->validation($sha)),
            'uploaded_at' => '2026-10-20T12:05:00Z'];
        $this->transport->ok('upload-report', ['operation_uuid' => $upload->operation_uuid, 'result_base64' => base64_encode((string) json_encode($result))]);
    }

    private function queueS3Package(?string $sha = null, string $sourceId = 'origen-lab'): void
    {
        $sha ??= $this->sha;
        $head = fn () => new Result(['ContentLength' => 4096, 'Metadata' => ['mwt-sha256' => $sha],
            'ChecksumSHA256' => base64_encode((string) hex2bin($sha)), 'ChecksumType' => 'FULL_OBJECT']);
        $this->s3->append($head(), new Result(['ContentLength' => strlen($this->manifest($sourceId)), 'Body' => Utils::streamFor($this->manifest($sourceId))]),
            new Result(['ContentLength' => strlen($this->validation($sha)), 'Body' => Utils::streamFor($this->validation($sha))]), $head());
    }

    private function manifest(string $sourceId = 'origen-lab'): string
    {
        return (string) json_encode(['collector_version' => '7.4.2-linux', 'schema_version' => '1.0',
            'package_type' => 'moodle-consolidation-source', 'package_status' => 'sealed', 'source_write_performed' => false,
            'destination_write_performed' => false, 'source_id' => $sourceId, 'courses_expected' => 4,
            'capabilities' => ['theme_inventory' => '1.0'], 'source_name' => 'Origen de laboratorio']);
    }

    private function validation(?string $sha = null): string
    {
        return (string) json_encode(['result' => 'ok', 'failures' => [], 'warnings' => [],
            'outer_zip' => ['sha256' => $sha ?? $this->sha, 'bytes' => 4096, 'sidecar_status' => 'ok'], 'counts' => ['files' => 10, 'courses' => 4]]);
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
            'handler' => function (CommandInterface $command, $request) {
                if ($this->objectStored && $command->getName() === 'HeadObject' && $this->s3->count() === 0) {
                    // Each stored object answers with its own hash: the key ends in <sha256>.zip.
                    $sha = basename((string) $command['Key'], '.zip');

                    return Create::promiseFor(new Result(['ContentLength' => 4096, 'Metadata' => ['mwt-sha256' => $sha],
                        'ChecksumSHA256' => base64_encode((string) hex2bin($sha)), 'ChecksumType' => 'FULL_OBJECT']));
                }

                return ($this->s3)($command, $request);
            }]);
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
