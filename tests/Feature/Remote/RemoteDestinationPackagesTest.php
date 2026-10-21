<?php

namespace Tests\Feature\Remote;

use App\Domain\Projects\ProjectWizard;
use App\Domain\Remote\RemoteDestinationPackages;
use App\Domain\Remote\RemotePackageObjects;
use App\Domain\Remote\RemoteServerConnections;
use App\Domain\Remote\S3ClientFactory;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\ExecutionStatus;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Exceptions\ToolOperationBlocked;
use App\Jobs\ObserveDestinationFetch;
use App\Models\Artifact;
use App\Models\Project;
use App\Models\Server;
use App\Models\SourcePackage;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Domain\DomainTestCase;
use Tests\Support\QueuedRemoteTransport;

/**
 * Sealed packages brought from S3 into a destination's copias/ through a
 * scripted transport. The S3 object is verified with the platform's identity
 * first; success is only the hash the agent reports from the server.
 */
class RemoteDestinationPackagesTest extends DomainTestCase
{
    private QueuedRemoteTransport $transport;

    private User $admin;

    private Project $project;

    private Server $server;

    /** @var list<string> */
    private array $verified = [];

    private int $attempts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['toolkit.features.remote_agent.enabled' => true]);
        $this->transport = QueuedRemoteTransport::bind($this->app);
        $verified = &$this->verified;
        $this->app->instance(RemotePackageObjects::class, new class(app(S3ClientFactory::class), $verified) extends RemotePackageObjects
        {
            /** @param list<string> $verified */
            public function __construct(S3ClientFactory $clients, private array &$verified)
            {
                parent::__construct($clients);
            }

            public function verifyPackage(string $region, string $bucket, string $key, string $sha256, int $size): array
            {
                if (str_contains($key, 'alterado')) {
                    throw new ToolOperationBlocked('El objeto en S3 no coincide con el tamaño o el SHA-256 del paquete.');
                }
                $this->verified[] = $bucket.'/'.$key;

                return ['size' => $size, 'checksum_verification' => 'S3_FULL_OBJECT_SHA256'];
            }
        });
        $this->admin = $this->user(UserRole::ADMIN);
        $this->project = app(ProjectWizard::class)->create($this->admin, ['name' => 'Consolidación', 'type' => 'CONSOLIDATE']);
        $this->server = $this->project->servers()->create(['name' => 'Destino', 'role' => ServerRole::DESTINATION, 'host' => 'campus.example.edu']);
        app(RemoteServerConnections::class)->configure($this->server, ConnectionType::AWS_SSM,
            ['instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-2'], null, null)->update(['status' => ConnectionStatus::VALID]);
        $this->checked();
    }

    public function test_a_verified_package_reaches_copias_only_with_the_hash_the_platform_expects(): void
    {
        $package = $this->package('pregrado');
        $this->launch();
        $fetch = app(RemoteDestinationPackages::class)->fetch($this->server, 'campus', $package, $this->admin);

        $this->assertSame('RUNNING', $fetch['state']);
        $this->assertSame(['kevin-moodle-backups-2026/mwt/'.$package->project->uuid.'/pregrado/'.$package->sha256.'.zip'], $this->verified);
        $this->assertStringContainsString("'package.fetch' 'bucket=kevin-moodle-backups-2026' 'destination=campus' 'project={$package->project->uuid}'"
            ." 'region=us-east-2' 'sha={$package->sha256}' 'source=pregrado'", $this->transport->scripts[0]);

        // A second transfer waits for the first.
        try {
            app(RemoteDestinationPackages::class)->fetch($this->server->refresh(), 'campus', $this->package('posgrado'), $this->admin);
            $this->fail('Solo una transferencia a la vez.');
        } catch (ToolOperationBlocked) {
        }

        $this->transport->ok('status', $this->agentStatus(['exit_code' => 0, 'cancel_requested' => false]));
        $this->transport->ok('fetch-report', ['result_base64' => base64_encode((string) json_encode(['name' => 'pregrado.zip',
            'sha256' => $package->sha256, 'size' => 4096, 'already_present' => false]))]);
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => [
            ['name' => 'pregrado.zip', 'size' => 4096, 'sidecar_sha256' => $package->sha256],
            ['name' => 'otro.zip', 'size' => 10, 'sidecar_sha256' => null],
        ]]);
        $done = app(RemoteDestinationPackages::class)->observe($this->server->refresh(), 'campus', $package->uuid);

        $this->assertSame(['SUCCEEDED', 'pregrado.zip', false], [$done['state'], $done['name'], $done['already_present']]);
        $copias = $this->server->refresh()->metadata['remote_destination_copias']['campus'];
        $this->assertSame([$package->uuid, null], array_column($copias['packages'], 'source_package_uuid'));
    }

    public function test_a_reported_hash_other_than_the_expected_one_or_an_agent_failure_is_never_success(): void
    {
        $package = $this->package('pregrado');
        $this->launch();
        app(RemoteDestinationPackages::class)->fetch($this->server, 'campus', $package, $this->admin);
        $this->transport->ok('status', $this->agentStatus(['exit_code' => 0, 'cancel_requested' => false]));
        $this->transport->ok('fetch-report', ['result_base64' => base64_encode((string) json_encode(['name' => 'pregrado.zip', 'sha256' => str_repeat('e', 64)]))]);
        $this->assertSame(['FAILED', 'fetch_report_invalid'], $this->observed($package));

        $this->launch();
        app(RemoteDestinationPackages::class)->fetch($this->server->refresh(), 'campus', $package, $this->admin);
        $this->transport->ok('status', $this->agentStatus(['exit_code' => 58, 'cancel_requested' => false], 40));
        $this->transport->ok('tail', ['offset' => 0, 'next_offset' => 22, 'size' => 22, 'data_base64' => base64_encode("PACKAGE_HASH_MISMATCH\n")]);
        $this->assertSame(['FAILED', 'package_hash_mismatch'], $this->observed($package));
    }

    public function test_only_current_s3_packages_go_to_an_unwritten_checked_destination(): void
    {
        $this->assertBlocked($this->package('alterado'));
        $revoked = $this->package('revocado');
        SourcePackage::query()->whereKey($revoked->getKey())->update(['availability' => 'REVOKED', 'revoked_at' => now()]);
        $this->assertBlocked($revoked->refresh());
        $package = $this->package('pregrado');
        $this->checked(['destination_written' => true]);
        $this->assertBlocked($package);
        $this->checked();
        $metadata = $this->server->refresh()->metadata;
        $metadata['remote_destination_copias']['campus'] = ['write_locked' => true, 'packages' => []];
        $this->server->update(['metadata' => $metadata]);
        $this->assertBlocked($package);
        $this->assertSame([], $this->transport->scripts);
    }

    public function test_packages_are_listed_and_removed_through_the_agent_until_the_destination_is_written(): void
    {
        $this->transport->ok('copias-remove', ['removed' => true]);
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => []]);
        app(RemoteDestinationPackages::class)->remove($this->server, 'campus', 'pregrado.zip', $this->admin);
        $this->assertStringContainsString("'copias-remove' 'campus' 'pregrado.zip'", $this->transport->scripts[0]);

        $this->transport->ok('copias-list', ['write_locked' => true, 'packages' => [['name' => 'a.zip', 'size' => 1, 'sidecar_sha256' => str_repeat('f', 64)]]]);
        $this->assertTrue(app(RemoteDestinationPackages::class)->list($this->server->refresh(), 'campus')['write_locked']);
        $this->expectException(ToolOperationBlocked::class);
        app(RemoteDestinationPackages::class)->remove($this->server->refresh(), 'campus', 'a.zip', $this->admin);
    }

    public function test_the_endpoints_launch_transfers_and_the_scheduler_observes_them(): void
    {
        Queue::fake();
        $mine = $this->package('pregrado');

        $url = route('projects.servers.destinations.packages.fetch', [$this->project->uuid, $this->server->uuid]);
        $this->actingAs($this->admin)->post($url, ['destination' => 'campus', 'source_package' => (string) Str::uuid()])->assertNotFound();
        $this->launch();
        $this->actingAs($this->admin)->post($url, ['destination' => 'campus', 'source_package' => $mine->uuid])
            ->assertRedirect(route('projects.show', $this->project->uuid));
        $this->assertSame('RUNNING', $this->server->refresh()->metadata['remote_destination_fetches']['campus'][$mine->uuid]['state']);

        $this->artisan('destinations:observe-preparations')->assertSuccessful();
        Queue::assertPushed(ObserveDestinationFetch::class, fn (ObserveDestinationFetch $job): bool => $job->packageUuid === $mine->uuid);

        $this->actingAs($this->admin)->get(route('projects.show', $this->project->uuid))->assertInertia(fn (Assert $page) => $page
            ->where('remoteAccess.available_packages', fn ($packages): bool => collect($packages)->pluck('uuid')->contains($mine->uuid))
            ->where('remoteAccess.servers.0.destinations.0.fetches.0.state', 'RUNNING'));
    }

    /** @return array{string, string|null} */
    private function observed(SourcePackage $package): array
    {
        $result = app(RemoteDestinationPackages::class)->observe($this->server->refresh(), 'campus', $package->uuid);

        return [$result['state'], $result['error_code']];
    }

    private function assertBlocked(SourcePackage $package): void
    {
        try {
            app(RemoteDestinationPackages::class)->fetch($this->server->refresh(), 'campus', $package, $this->admin);
            $this->fail('La transferencia debía bloquearse.');
        } catch (ToolOperationBlocked) {
            $this->addToAssertionCount(1);
        }
    }

    private function launch(): void
    {
        $this->transport->okWith('launch', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script), 'created' => true]);
    }

    /** @param array<string, mixed> $report */
    private function checked(array $report = []): void
    {
        $metadata = $this->server->refresh()->metadata ?? [];
        $metadata['remote_destinations']['campus'] = ['ready' => true, 'report' => ['destination_written' => false, ...$report]];
        unset($metadata['remote_destination_copias']);
        $this->server->update(['metadata' => $metadata]);
    }

    /** A sealed package registered in S3, as a remote collection leaves it. */
    private function package(string $sourceId, ?Project $project = null): SourcePackage
    {
        $project ??= $this->project;
        $execution = $this->execution($project, ExecutionStatus::COMPLETED, ++$this->attempts);
        $sha = hash('sha256', $sourceId.Str::uuid());
        $key = 'mwt/'.$project->uuid.'/'.$sourceId.'/'.$sha.'.zip';
        $artifact = Artifact::query()->create(['execution_id' => $execution->getKey(), 'type' => 'source_package', 'category' => 'SOURCE_PACKAGE',
            'storage_mode' => 'REFERENCE', 'disk' => 's3', 'path' => 'kevin-moodle-backups-2026/'.$key, 'filename' => $sourceId.'.zip',
            'mime_type' => 'application/zip', 'size' => 4096, 'sha256' => $sha,
            'metadata' => ['s3' => ['region' => 'us-east-2', 'bucket' => 'kevin-moodle-backups-2026', 'key' => $key]]]);

        return SourcePackage::query()->forceCreate(['uuid' => (string) Str::uuid(), 'project_id' => $project->getKey(),
            'producer_execution_id' => $execution->getKey(), 'artifact_id' => $artifact->getKey(), 'producer_tool_version' => '7.4.2-linux',
            'schema_version' => '1.0', 'source_id' => $sourceId, 'name' => $sourceId, 'size_bytes' => 4096, 'sha256' => $sha,
            'validation_state' => 'VALID', 'validated_at' => now(), 'availability' => 'AVAILABLE', 'sensitivity' => 'CONFIDENTIAL'])->load('project');
    }

    /**
     * @param  array<string, mixed>  $exit
     * @return array<string, mixed>
     */
    private function agentStatus(array $exit, int $stderrBytes = 0): array
    {
        return ['exit' => $exit, 'process_alive' => false, 'heartbeat_at' => null, 'stdout_bytes' => 0, 'stderr_bytes' => $stderrBytes];
    }
}
