<?php

namespace Tests\Unit;

use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Domain\Remote\DTOs\TransportResult;
use App\Domain\Remote\Transports\SshRemoteTransport;
use App\Enums\ConnectionType;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class SshRemoteTransportTest extends TestCase
{
    private const HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';

    private const PRIVATE_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nsynthetic-test-key\n-----END OPENSSH PRIVATE KEY-----";

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('El binario SSH simulado requiere un shell POSIX.');
        }
        $this->root = sys_get_temp_dir().'/mwt-ssh-test-'.Str::uuid()->toString();
        mkdir($this->root.'/scratch', 0700, true);
        $fake = $this->root.'/ssh';
        file_put_contents($fake, <<<'SH'
            #!/usr/bin/env bash
            out=$(dirname "$0")
            printf '%s\n' "$@" > "$out/args"
            cat > "$out/stdin"
            for arg in "$@"; do
                case $arg in
                    IdentityFile=*) key=${arg#IdentityFile=}; stat -c %a "$key" > "$out/key_mode"; cat "$key" > "$out/key" ;;
                    UserKnownHostsFile=*) cat "${arg#UserKnownHostsFile=}" > "$out/known_hosts" ;;
                esac
            done
            cat "$out/fake_stdout"
            cat "$out/fake_stderr" >&2
            exit "$(cat "$out/fake_exit")"
            SH);
        chmod($fake, 0700);
        config(['toolkit.remote.ssh.binary' => $fake, 'toolkit.remote.ssh.scratch_root' => $this->root.'/scratch']);
        $this->app->instance(SecretProvider::class, new class(self::PRIVATE_KEY) implements SecretProvider
        {
            public function __construct(private readonly string $value) {}

            public function available(string $reference, string $version): bool
            {
                return $reference === 'origen-ssh-key' && $version === '3';
            }

            public function consume(string $reference, string $version, callable $consumer): mixed
            {
                return $consumer($this->value);
            }
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_host_key_is_pinned_and_key_material_is_private_and_ephemeral(): void
    {
        $this->fake('{"ok":true}', '', 0);

        $result = app(SshRemoteTransport::class)->run($this->target(port: 2222, sudo: true), "echo agente\n", 30);

        $this->assertSame(TransportResult::COMPLETED, $result->status);
        $this->assertSame(0, $result->exitCode);
        $this->assertSame('{"ok":true}', $result->stdout);
        $args = explode('
', rtrim((string) file_get_contents($this->root.'/args'), '
'));
        foreach (['BatchMode=yes', 'StrictHostKeyChecking=yes', 'GlobalKnownHostsFile=/dev/null', 'IdentitiesOnly=yes', 'PasswordAuthentication=no'] as $option) {
            $this->assertContains($option, $args);
        }
        $this->assertSame(['--', 'origen.example.edu', 'sudo -n bash -s'], array_slice($args, -3));
        $this->assertSame('2222', $args[array_search('-p', $args, true) + 1]);
        $this->assertSame('ubuntu', $args[array_search('-l', $args, true) + 1]);
        $this->assertSame("echo agente\n", file_get_contents($this->root.'/stdin'));
        $this->assertSame('600', trim((string) file_get_contents($this->root.'/key_mode')));
        $this->assertSame(self::PRIVATE_KEY."\n", file_get_contents($this->root.'/key'));
        $this->assertSame('[origen.example.edu]:2222 '.self::HOST_KEY."\n", file_get_contents($this->root.'/known_hosts'));
        $this->assertSame([], array_values(array_diff(scandir($this->root.'/scratch') ?: [], ['.', '..'])), 'La llave privada no debe quedar en disco.');
    }

    public function test_rejected_host_key_is_unreachable_without_remote_output(): void
    {
        $this->fake('', "@@@ WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED! @@@\nHost key verification failed.\n", 255);

        $result = app(SshRemoteTransport::class)->run($this->target(), 'true', 30);

        $this->assertSame(TransportResult::UNREACHABLE, $result->status);
        $this->assertSame('ssh_host_key_mismatch', $result->errorCode);
        $this->assertSame('', $result->stderr);
        $this->assertSame([], array_values(array_diff(scandir($this->root.'/scratch') ?: [], ['.', '..'])));
    }

    public function test_authentication_failure_is_classified(): void
    {
        $this->fake('', "ubuntu@origen.example.edu: Permission denied (publickey).\n", 255);

        $this->assertSame('ssh_authentication_failed', app(SshRemoteTransport::class)->run($this->target(), 'true', 30)->errorCode);
    }

    private function fake(string $stdout, string $stderr, int $exit): void
    {
        // The fake binary reads its answer from files: Process does not inherit putenv() values.
        file_put_contents($this->root.'/fake_stdout', $stdout);
        file_put_contents($this->root.'/fake_stderr', $stderr);
        file_put_contents($this->root.'/fake_exit', (string) $exit);
    }

    private function target(int $port = 22, bool $sudo = false): RemoteTarget
    {
        return new RemoteTarget(ConnectionType::SSH, '00000000-0000-4000-8000-000000000003', host: 'origen.example.edu', port: $port,
            user: 'ubuntu', hostKey: self::HOST_KEY, secretReference: 'origen-ssh-key', secretVersion: '3', useSudo: $sudo);
    }
}
