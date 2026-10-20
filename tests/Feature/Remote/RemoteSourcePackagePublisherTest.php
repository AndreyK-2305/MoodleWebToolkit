<?php

namespace Tests\Feature\Remote;

use App\Domain\Remote\RemoteAgent;
use App\Domain\Remote\RemoteCollectorExport;
use App\Domain\Remote\RemoteServerConnections;
use App\Domain\Remote\RemoteSourcePackagePublisher;
use App\Domain\Remote\S3ClientFactory;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\RemoteCommunicationState;
use App\Enums\RemoteFunctionalState;
use App\Enums\ServerRole;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Artifact;
use App\Models\Execution;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\SourcePackage;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Str;
use Tests\Feature\Domain\DomainTestCase;
use Tests\Support\QueuedRemoteTransport;

/**
 * Registration of a remote package: the platform trusts only what it reads
 * from S3 with its own identity, never the source server's word alone.
 */
class RemoteSourcePackagePublisherTest extends DomainTestCase
{
    private const BUCKET = 'mwt-packages-test';

    private QueuedRemoteTransport $transport;

    private MockHandler $s3;

    /** @var list<CommandInterface> */
    private array $s3Commands = [];

    private Server $server;

    private Execution $execution;

    private RemoteOperation $export;

    private string $sha;

    private string $manifest;

    private string $validation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['toolkit.features.remote_agent.enabled' => true, 'toolkit.remote.package_bucket' => self::BUCKET, 'toolkit.remote.package_region' => 'us-east-2']);
        $this->transport = QueuedRemoteTransport::bind($this->app);
        $this->bindS3();
        $project = $this->project();
        $this->execution = $this->execution($project);
        $this->server = $project->servers()->create(['name' => 'Origen', 'role' => ServerRole::SOURCE, 'host' => 'origen.example.edu']);
        app(RemoteServerConnections::class)->configure($this->server, ConnectionType::AWS_SSM,
            ['instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-2'], null, null)->update(['status' => ConnectionStatus::VALID]);
        $this->sha = hash('sha256', 'package bytes');
        $this->export = $this->finishedExport();
        $this->manifest = (string) json_encode(['collector_version' => '7.4.2-linux', 'schema_version' => '1.0',
            'package_type' => 'moodle-consolidation-source', 'package_status' => 'sealed', 'source_write_performed' => false,
            'destination_write_performed' => false, 'source_id' => 'origen-lab', 'courses_expected' => 2,
            'capabilities' => ['theme_inventory' => '1.0'], 'source_name' => 'Origen de laboratorio']);
        $this->validation = (string) json_encode(['result' => 'ok', 'failures' => [], 'warnings' => [],
            'outer_zip' => ['sha256' => $this->sha, 'bytes' => 4096, 'sidecar_status' => 'ok'], 'counts' => ['files' => 10, 'courses' => 2]]);
    }

    public function test_publication_requires_a_ready_export_and_a_bucket(): void
    {
        $evidence = $this->export->evidence;
        $evidence['collector']['package_ready'] = false;
        $this->export->forceFill(['evidence' => $evidence])->save();
        try {
            app(RemoteSourcePackagePublisher::class)->publish($this->export->refresh(), null);
            $this->fail('Se publicó una exportación no lista.');
        } catch (ToolOperationBlocked) {
        }

        config(['toolkit.remote.package_bucket' => null]);
        $this->expectException(ToolOperationBlocked::class);
        app(RemoteSourcePackagePublisher::class)->publish($this->finishedExport('origen-b'), null);
    }

    public function test_upload_carries_only_identifiers(): void
    {
        $this->queueLaunch();
        $upload = app(RemoteSourcePackagePublisher::class)->publish($this->export, null);

        $this->assertSame(RemoteSourcePackagePublisher::COMMAND, $upload->command_key);
        foreach (['collector='.$this->export->operation_uuid, 'project='.$this->execution->project->uuid, 'bucket='.self::BUCKET, 'region=us-east-2'] as $argument) {
            $this->assertStringContainsString($argument, $this->transport->scripts[0]);
        }
    }

    public function test_failed_upload_allows_a_new_attempt_and_active_one_is_reused(): void
    {
        $this->queueLaunch();
        $first = app(RemoteSourcePackagePublisher::class)->publish($this->export, null);
        $this->assertSame($first->id, app(RemoteSourcePackagePublisher::class)->publish($this->export, null)->id, 'Una subida activa se reutiliza.');

        $first->forceFill(['communication_state' => RemoteCommunicationState::TERMINATED, 'functional_state' => RemoteFunctionalState::FAILED,
            'exit_code' => 45, 'terminated_at' => now()->utc()])->save();
        $this->queueLaunch();
        $second = app(RemoteSourcePackagePublisher::class)->publish($this->export, null);

        $this->assertNotSame($first->id, $second->id);
        $this->assertStringEndsWith('-2', $second->idempotency_key);
        $this->assertCount(2, array_filter($this->transport->subcommands(), fn (string $command): bool => $command === 'launch'));
    }

    public function test_verified_objects_register_a_valid_package_by_reference(): void
    {
        $upload = $this->succeededUpload();
        $this->queueUploadReport($upload);
        $this->queueS3Package(size: 4096, metadataSha: $this->sha, checksum: base64_encode((string) hex2bin($this->sha)));

        $package = app(RemoteSourcePackagePublisher::class)->observe($upload);

        $this->assertInstanceOf(SourcePackage::class, $package);
        $this->assertSame('VALID', $package->validation_state);
        $this->assertSame($this->sha, $package->sha256);
        $this->assertSame('origen-lab', $package->source_id);
        $artifact = Artifact::query()->findOrFail($package->artifact_id);
        $this->assertSame(['REFERENCE', 's3'], [$artifact->storage_mode, $artifact->disk]);
        $this->assertSame(self::BUCKET.'/'.$this->prefix().'.zip', $artifact->path);
        $this->assertSame('collector-remote-audit.v1', $package->evidence['collector_audit']['validation_schema']);
        $this->assertSame('S3_FULL_OBJECT_SHA256', $package->evidence['collector_audit']['checksum_verification']);
        $this->assertSame(['HeadObject', 'GetObject', 'GetObject', 'HeadObject'], array_map(fn (CommandInterface $c) => $c->getName(), $this->s3Commands));

        $again = app(RemoteSourcePackagePublisher::class)->observe($upload->refresh());
        $this->assertSame($package->uuid, $again?->uuid);
        $this->assertSame(1, SourcePackage::query()->count());
    }

    public function test_object_that_differs_in_s3_is_never_registered(): void
    {
        foreach ([
            'tamaño' => [4095, $this->sha, base64_encode((string) hex2bin($this->sha))],
            'metadatos' => [4096, str_repeat('0', 64), base64_encode((string) hex2bin($this->sha))],
            'checksum de S3' => [4096, $this->sha, base64_encode(str_repeat("\0", 32))],
        ] as $label => [$size, $metadataSha, $checksum]) {
            RemoteOperation::query()->where('command_key', RemoteSourcePackagePublisher::COMMAND)->delete();
            $upload = $this->succeededUpload();
            $this->queueUploadReport($upload);
            $this->queueS3Package($size, $metadataSha, $checksum);
            try {
                app(RemoteSourcePackagePublisher::class)->observe($upload);
                $this->fail('Se registró un objeto alterado: '.$label);
            } catch (ToolOperationBlocked) {
                $this->assertSame(0, SourcePackage::query()->count(), $label);
                $this->assertSame(0, Artifact::query()->count(), $label);
            }
        }
    }

    public function test_failed_validation_or_foreign_manifest_blocks_registration(): void
    {
        $this->validation = (string) json_encode(['result' => 'error', 'failures' => ['course-1'], 'outer_zip' => ['sha256' => $this->sha, 'bytes' => 4096]]);
        $upload = $this->succeededUpload();
        $this->queueUploadReport($upload);
        $this->queueS3Package(4096, $this->sha, base64_encode((string) hex2bin($this->sha)));
        try {
            app(RemoteSourcePackagePublisher::class)->observe($upload);
            $this->fail('Se registró un paquete con validación fallida.');
        } catch (ToolOperationBlocked) {
        }

        RemoteOperation::query()->where('command_key', RemoteSourcePackagePublisher::COMMAND)->delete();
        $this->validation = (string) json_encode(['result' => 'ok', 'failures' => [], 'outer_zip' => ['sha256' => $this->sha, 'bytes' => 4096]]);
        $this->manifest = (string) json_encode([...json_decode($this->manifest, true), 'source_id' => 'otro-origen']);
        $upload = $this->succeededUpload();
        $this->queueUploadReport($upload);
        $this->queueS3Package(4096, $this->sha, base64_encode((string) hex2bin($this->sha)));
        $this->expectException(ToolOperationBlocked::class);
        app(RemoteSourcePackagePublisher::class)->observe($upload);
    }

    public function test_upload_result_for_another_location_is_rejected(): void
    {
        $upload = $this->succeededUpload();
        $this->queueUploadReport($upload, ['prefix' => 'mwt/otro-proyecto/synthetic-lab/'.$this->sha]);

        $this->expectException(ToolOperationBlocked::class);
        app(RemoteSourcePackagePublisher::class)->observe($upload);
    }

    private function prefix(): string
    {
        return 'mwt/'.$this->execution->project->uuid.'/synthetic-lab/'.$this->sha;
    }

    private function finishedExport(string $package = 'origen-lab'): RemoteOperation
    {
        return RemoteOperation::query()->create([
            'execution_id' => $this->execution->id, 'operation_uuid' => (string) Str::uuid(), 'idempotency_key' => 'collector-'.$package,
            'provider_key' => 'remote-agent', 'host_id' => $this->server->uuid, 'runtime_key' => 'mwt-agent-v1',
            'process_id' => 'mwt-op-'.Str::uuid(), 'command_key' => RemoteCollectorExport::COMMAND, 'command_sha256' => str_repeat('c', 64),
            'communication_state' => RemoteCommunicationState::TERMINATED, 'functional_state' => RemoteFunctionalState::SUCCEEDED,
            'terminated_at' => now()->utc(), 'exit_code' => 0,
            'evidence' => [
                'parameters' => ['distribution' => str_repeat('d', 64), 'package' => $package, 'source' => 'synthetic-lab', 'workers' => '1'],
                'exit' => ['exit_code' => 0, 'cancel_requested' => false],
                'collector' => ['package' => $package, 'package_ready' => true, 'package_sha256' => $this->sha, 'package_size' => 4096,
                    'status' => ['source_id' => 'origen-lab', 'state' => 'success', 'stage' => 'completed', 'exit_code' => 0]],
            ],
        ]);
    }

    private function succeededUpload(): RemoteOperation
    {
        return RemoteOperation::query()->create([
            'execution_id' => $this->execution->id, 'operation_uuid' => (string) Str::uuid(), 'idempotency_key' => 'upload-'.Str::random(6),
            'provider_key' => 'remote-agent', 'host_id' => $this->server->uuid, 'runtime_key' => 'mwt-agent-v1',
            'process_id' => 'mwt-op-'.Str::uuid(), 'command_key' => RemoteSourcePackagePublisher::COMMAND, 'command_sha256' => str_repeat('e', 64),
            'communication_state' => RemoteCommunicationState::TERMINATED, 'functional_state' => RemoteFunctionalState::SUCCEEDED,
            'terminated_at' => now()->utc(), 'exit_code' => 0,
            'evidence' => ['parameters' => ['collector' => $this->export->operation_uuid, 'project' => $this->execution->project->uuid,
                'bucket' => self::BUCKET, 'region' => 'us-east-2']],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function queueUploadReport(RemoteOperation $upload, array $overrides = []): void
    {
        $result = [...['bucket' => self::BUCKET, 'region' => 'us-east-2', 'prefix' => $this->prefix(), 'package' => 'origen-lab',
            'source' => 'synthetic-lab', 'sha256' => $this->sha, 'size' => 4096, 'manifest_sha256' => hash('sha256', $this->manifest),
            'validation_sha256' => hash('sha256', $this->validation), 'uploaded_at' => '2026-10-20T12:00:00Z'], ...$overrides];
        $this->transport->ok('upload-report', ['operation_uuid' => $upload->operation_uuid, 'result_base64' => base64_encode((string) json_encode($result))]);
    }

    private function queueS3Package(int $size, string $metadataSha, string $checksum): void
    {
        $head = fn () => new Result(['ContentLength' => $size, 'Metadata' => ['mwt-sha256' => $metadataSha], 'ChecksumSHA256' => $checksum, 'ChecksumType' => 'FULL_OBJECT']);
        $this->s3->append($head(), new Result(['ContentLength' => strlen($this->manifest), 'Body' => Utils::streamFor($this->manifest)]),
            new Result(['ContentLength' => strlen($this->validation), 'Body' => Utils::streamFor($this->validation)]), $head());
    }

    private function queueLaunch(): void
    {
        $this->transport->okWith('launch', function (string $script): array {
            $uuid = QueuedRemoteTransport::operationUuid($script);
            $distribution = app(RemoteAgent::class)->distribution();

            return ['operation_uuid' => $uuid, 'created' => true, 'launch' => ['operation_uuid' => $uuid, 'command_key' => RemoteSourcePackagePublisher::COMMAND,
                'launcher' => 'systemd', 'unit' => 'mwt-op-'.$uuid, 'agent_version' => $distribution['version'], 'agent_sha256' => $distribution['sha256'],
                'argv_sha256' => str_repeat('b', 64), 'launched_at' => now()->utc()->toIso8601String(), 'parameters' => []]];
        });
    }

    private function bindS3(): void
    {
        $this->s3 = new MockHandler;
        $handler = $this->s3;
        $client = new S3Client(['region' => 'us-east-2', 'version' => '2006-03-01', 'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => function (CommandInterface $command, $request) use ($handler) {
                $this->s3Commands[] = $command;

                return $handler($command, $request);
            }]);
        $this->app->instance(S3ClientFactory::class, new class($client) extends S3ClientFactory
        {
            public function __construct(private readonly S3Client $client) {}

            public function make(string $region): S3Client
            {
                return $this->client;
            }
        });
        unset($handler);
    }
}
