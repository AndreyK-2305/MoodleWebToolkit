<?php

namespace Tests\Feature\Aws;

use App\Domain\Aws\AwsCredentials;
use App\Domain\Aws\RemoteStorageConfiguration;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Domain\Remote\DTOs\TransportResult;
use App\Domain\Remote\RemoteServerConnections;
use App\Domain\Remote\S3ClientFactory;
use App\Domain\Remote\SsmClientFactory;
use App\Domain\Remote\Transports\SsmRemoteTransport;
use App\Enums\AwsCredentialMode;
use App\Enums\ConnectionType;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Models\AwsAccount;
use App\Models\AwsStorageSettings;
use Aws\Credentials\Credentials;
use Aws\Exception\CredentialsException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Domain\DomainTestCase;

class AwsCredentialResolutionTest extends DomainTestCase
{
    public function test_without_accounts_the_default_sdk_chain_is_kept(): void
    {
        $credentials = app(AwsCredentials::class);

        $this->assertNull($credentials->resolve(null));
        $this->assertNull($credentials->forStorage());
        $this->assertSame([], $credentials->clientOptions(null, 'us-east-2'));
        $environment = $this->account('Entorno', AwsCredentialMode::ENVIRONMENT, default: true);
        $this->assertTrue($environment->is($credentials->resolve(null)));
        $this->assertSame([], $credentials->clientOptions($environment, 'us-east-2'));
    }

    public function test_a_connection_account_wins_over_the_default_one(): void
    {
        $default = $this->account('Predeterminada', AwsCredentialMode::ACCESS_KEYS, default: true, key: 'AKIAAAAAAAAAAAAAAAAA');
        $chosen = $this->account('Institución', AwsCredentialMode::ACCESS_KEYS, key: 'AKIABBBBBBBBBBBBBBBB');
        $factory = app(SsmClientFactory::class);

        $this->assertSame('AKIABBBBBBBBBBBBBBBB', $this->keyOf($factory->forConnection('us-east-2', $chosen->uuid)->getCredentials()->wait()));
        $this->assertSame('AKIAAAAAAAAAAAAAAAAA', $this->keyOf($factory->forConnection('us-east-2', null)->getCredentials()->wait()));
        $this->assertSame('AKIAAAAAAAAAAAAAAAAA', $this->keyOf($factory->make('us-east-2')->getCredentials()->wait()));

        // A connection that names a removed account fails instead of borrowing another identity.
        $this->expectException(CredentialsException::class);
        $factory->forConnection('us-east-2', '00000000-0000-4000-8000-000000000000');
    }

    public function test_s3_uses_the_storage_account_then_the_default_one(): void
    {
        $this->account('Predeterminada', AwsCredentialMode::ACCESS_KEYS, default: true, key: 'AKIAAAAAAAAAAAAAAAAA');
        $storage = $this->account('Almacenamiento', AwsCredentialMode::ACCESS_KEYS, key: 'AKIACCCCCCCCCCCCCCCC');
        $factory = app(S3ClientFactory::class);

        $this->assertSame('AKIAAAAAAAAAAAAAAAAA', $this->keyOf($factory->make('us-east-2')->getCredentials()->wait()));
        AwsStorageSettings::query()->create(['bucket' => 'paquetes', 'region' => 'us-east-2', 'aws_account_id' => $storage->id]);
        $this->assertSame('AKIACCCCCCCCCCCCCCCC', $this->keyOf($factory->make('us-east-2')->getCredentials()->wait()));
    }

    public function test_an_assumed_role_is_requested_with_its_external_id(): void
    {
        $account = AwsAccount::query()->create(['name' => 'Rol', 'mode' => AwsCredentialMode::ASSUME_ROLE,
            'role_arn' => 'arn:aws:iam::123456789012:role/MoodleWebToolkit', 'external_id' => 'secreto-compartido']);

        $options = app(AwsCredentials::class)->clientOptions($account, 'us-east-2');

        $this->assertIsCallable($options['credentials'] ?? null);
        $incomplete = AwsAccount::query()->create(['name' => 'Sin ARN', 'mode' => AwsCredentialMode::ASSUME_ROLE]);
        $this->expectException(CredentialsException::class);
        app(AwsCredentials::class)->clientOptions($incomplete, 'us-east-2');
    }

    public function test_saved_storage_overrides_the_environment_bucket_at_runtime(): void
    {
        config(['toolkit.remote.package_bucket' => 'bucket-del-entorno', 'toolkit.remote.package_region' => 'us-east-2']);
        $storage = app(RemoteStorageConfiguration::class);

        $storage->apply();
        $this->assertSame('bucket-del-entorno', config('toolkit.remote.package_bucket'));

        AwsStorageSettings::query()->create(['bucket' => 'paquetes-web', 'region' => 'sa-east-1']);
        $storage->apply();
        $this->assertSame('paquetes-web', config('toolkit.remote.package_bucket'));
        $this->assertSame('sa-east-1', config('toolkit.remote.package_region'));
    }

    public function test_an_ssm_connection_selects_its_account_and_the_transport_uses_it(): void
    {
        config(['toolkit.features.remote_agent.enabled' => true]);
        $admin = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($admin, ['name' => 'Consolidación', 'type' => 'CONSOLIDATE']);
        $server = $project->servers()->create(['name' => 'Origen', 'role' => ServerRole::SOURCE, 'host' => 'origen.example.edu']);
        $account = $this->account('Institución', AwsCredentialMode::ACCESS_KEYS, key: 'AKIABBBBBBBBBBBBBBBB');
        $url = route('projects.servers.connection.update', [$project->uuid, $server->uuid]);
        $ssm = ['type' => 'aws-ssm', 'instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-1'];

        $this->actingAs($admin)->putJson($url, [...$ssm, 'aws_account' => '00000000-0000-4000-8000-000000000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('aws_account');
        $this->actingAs($admin)->putJson($url, [...$ssm, 'aws_account' => $account->uuid])->assertRedirect();
        $this->assertSame($account->uuid, $server->connections()->sole()->configuration['aws_account']);
        $target = app(RemoteServerConnections::class)->target($server);
        $this->assertSame($account->uuid, $target->awsAccount);

        $this->actingAs($admin)->get(route('projects.show', $project->uuid))->assertInertia(fn (Assert $page) => $page
            ->where('remoteAccess.aws_accounts.0.uuid', $account->uuid)
            ->where('remoteAccess.aws_accounts.0.name', 'Institución')
            ->where('remoteAccess.servers.0.connection.aws_account', $account->uuid));

        // The account in use cannot be removed; leaving the select empty returns to the default identity.
        $this->actingAs($admin)->delete(route('admin.aws.accounts.destroy', $account->uuid))->assertSessionHasErrors('account');
        $this->actingAs($admin)->putJson($url, $ssm)->assertRedirect();
        $this->assertArrayNotHasKey('aws_account', $server->connections()->sole()->configuration);
    }

    public function test_the_transport_reports_a_missing_account_as_unavailable_credentials(): void
    {
        $transport = new SsmRemoteTransport(app(SsmClientFactory::class), static function (int $milliseconds): void {});
        $target = new RemoteTarget(ConnectionType::AWS_SSM, '00000000-0000-4000-8000-000000000001', instanceId: 'i-0123456789abcdef0',
            region: 'us-east-1', awsAccount: '00000000-0000-4000-8000-000000000000');

        $result = $transport->run($target, 'true', 30);

        $this->assertSame(TransportResult::UNREACHABLE, $result->status);
        $this->assertSame('aws_credentials_unavailable', $result->errorCode);
    }

    private function account(string $name, AwsCredentialMode $mode, bool $default = false, ?string $key = null): AwsAccount
    {
        return AwsAccount::query()->create(['name' => $name, 'mode' => $mode, 'is_default' => $default,
            'access_key_id' => $key, 'secret_access_key' => $key === null ? null : str_repeat('s', 40)]);
    }

    private function keyOf(mixed $credentials): string
    {
        $this->assertInstanceOf(Credentials::class, $credentials);

        return $credentials->getAccessKeyId();
    }
}
