<?php

namespace Tests\Unit;

use App\Domain\Remote\RemoteConnectionConfiguration;
use App\Enums\ConnectionType;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RemoteConnectionConfigurationTest extends TestCase
{
    private const HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';

    public function test_ssm_configuration_is_normalized_with_schema(): void
    {
        $normalized = app(RemoteConnectionConfiguration::class)->normalize(ConnectionType::AWS_SSM, [
            'instance_id' => ' i-0123456789abcdef0 ', 'region' => 'us-east-1',
        ]);

        $this->assertSame(['schema' => 'remote-connection.v1', 'instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-1'], $normalized);
    }

    public function test_ssh_configuration_requires_a_pinned_host_key_and_normalizes_values(): void
    {
        $normalized = app(RemoteConnectionConfiguration::class)->normalize(ConnectionType::SSH, [
            'host' => 'Origen.Example.EDU', 'port' => '2222', 'user' => 'ubuntu', 'host_key' => self::HOST_KEY,
            'secret_version' => '1', 'use_sudo' => true,
        ]);

        $this->assertSame('origen.example.edu', $normalized['host']);
        $this->assertSame(2222, $normalized['port']);
        $this->assertTrue($normalized['use_sudo']);
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidConfigurations')]
    public function test_invalid_or_unknown_values_are_rejected(ConnectionType $type, array $input, string $field): void
    {
        try {
            app(RemoteConnectionConfiguration::class)->normalize($type, $input);
            $this->fail('La configuración inválida fue aceptada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    /** @return iterable<string, array{ConnectionType, array<string, mixed>, string}> */
    public static function invalidConfigurations(): iterable
    {
        $ssh = ['host' => 'origen.example.edu', 'port' => 22, 'user' => 'ubuntu', 'host_key' => self::HOST_KEY, 'secret_version' => '1'];

        yield 'ssm instance' => [ConnectionType::AWS_SSM, ['instance_id' => 'i-xyz', 'region' => 'us-east-1'], 'instance_id'];
        yield 'ssm region' => [ConnectionType::AWS_SSM, ['instance_id' => 'i-0123456789abcdef0', 'region' => 'us east'], 'region'];
        yield 'ssm unknown key' => [ConnectionType::AWS_SSM, ['instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-1', 'command' => 'id'], 'configuration'];
        yield 'ssh url host' => [ConnectionType::SSH, [...$ssh, 'host' => 'ssh://root@origen'], 'host'];
        yield 'ssh injected host' => [ConnectionType::SSH, [...$ssh, 'host' => 'origen;id'], 'host'];
        yield 'ssh option host' => [ConnectionType::SSH, [...$ssh, 'host' => '-oProxyCommand=id'], 'host'];
        yield 'ssh port' => [ConnectionType::SSH, [...$ssh, 'port' => 70000], 'port'];
        yield 'ssh user' => [ConnectionType::SSH, [...$ssh, 'user' => 'root;id'], 'user'];
        yield 'ssh missing host key' => [ConnectionType::SSH, [...$ssh, 'host_key' => ''], 'host_key'];
        yield 'ssh host key newline' => [ConnectionType::SSH, [...$ssh, 'host_key' => self::HOST_KEY."\nevil ssh-rsa AAAA"], 'host_key'];
        yield 'ssh secret version' => [ConnectionType::SSH, [...$ssh, 'secret_version' => '0'], 'secret_version'];
        yield 'ssh schema' => [ConnectionType::SSH, [...$ssh, 'schema' => 'remote-connection.v0'], 'configuration'];
    }

    public function test_ssm_rejects_stored_credentials_and_ssh_requires_a_key_reference(): void
    {
        $configuration = app(RemoteConnectionConfiguration::class);
        $configuration->assertSecretReference(ConnectionType::AWS_SSM, null);
        $configuration->assertSecretReference(ConnectionType::SSH, 'origen-ssh-key');

        foreach ([[ConnectionType::AWS_SSM, 'aws-key'], [ConnectionType::SSH, null], [ConnectionType::SSH, '../key']] as [$type, $reference]) {
            try {
                $configuration->assertSecretReference($type, $reference);
                $this->fail('Se aceptó una referencia de credencial no válida.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('secret_reference', $exception->errors());
            }
        }
    }
}
