<?php

namespace App\Domain\Remote\Transports;

use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Remote\Contracts\RemoteTransport;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Domain\Remote\DTOs\TransportResult;
use App\Enums\ConnectionType;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Alternative transport for servers without SSM. The host key is pinned by
 * configuration and the private key exists on disk only during the call.
 */
final class SshRemoteTransport implements RemoteTransport
{
    public function __construct(private readonly SecretProvider $secrets) {}

    public function key(): string
    {
        return ConnectionType::SSH->value;
    }

    public function run(RemoteTarget $target, string $script, int $timeoutSeconds): TransportResult
    {
        if ($target->type !== ConnectionType::SSH || $target->host === null || $target->user === null
            || $target->hostKey === null || $target->secretReference === null || $target->secretVersion === null
        ) {
            throw new InvalidArgumentException('El destino no tiene una configuración SSH completa.');
        }
        $port = $target->port ?? 22;
        $timeoutSeconds = max(5, min($timeoutSeconds, 600));
        $directory = $this->scratchDirectory();

        try {
            return $this->secrets->consume($target->secretReference, $target->secretVersion,
                function (string $privateKey) use ($target, $port, $script, $timeoutSeconds, $directory): TransportResult {
                    $keyPath = $directory.'/identity';
                    $knownHostsPath = $directory.'/known_hosts';
                    $this->writePrivate($keyPath, rtrim($privateKey, "\n")."\n");
                    $hostPattern = $port === 22 ? $target->host : '['.$target->host.']:'.$port;
                    $this->writePrivate($knownHostsPath, $hostPattern.' '.$target->hostKey."\n");

                    $remoteCommand = $target->useSudo ? 'sudo -n bash -s' : 'bash -s';
                    $process = new Process([
                        (string) config('toolkit.remote.ssh.binary', '/usr/bin/ssh'),
                        '-F', '/dev/null', '-T',
                        '-o', 'BatchMode=yes',
                        '-o', 'StrictHostKeyChecking=yes',
                        '-o', 'UserKnownHostsFile='.$knownHostsPath,
                        '-o', 'GlobalKnownHostsFile=/dev/null',
                        '-o', 'IdentitiesOnly=yes',
                        '-o', 'IdentityFile='.$keyPath,
                        '-o', 'PasswordAuthentication=no',
                        '-o', 'KbdInteractiveAuthentication=no',
                        '-o', 'ConnectTimeout='.(int) config('toolkit.remote.ssh.connect_timeout_seconds', 10),
                        '-o', 'ServerAliveInterval=15',
                        '-o', 'ServerAliveCountMax=3',
                        '-o', 'LogLevel=ERROR',
                        '-p', (string) $port,
                        '-l', $target->user,
                        '--',
                        $target->host,
                        $remoteCommand,
                    ], null, ['LC_ALL' => 'C'], $script, $timeoutSeconds);

                    try {
                        $process->run();
                    } catch (ProcessTimedOutException) {
                        return new TransportResult(TransportResult::TIMED_OUT, null, '', '', 'ssh_call_timed_out');
                    }
                    $exit = $process->getExitCode();
                    if ($exit === 255) {
                        return new TransportResult(TransportResult::UNREACHABLE, null, '', '', $this->classify($process->getErrorOutput()));
                    }

                    return new TransportResult(TransportResult::COMPLETED, $exit, $process->getOutput(), $process->getErrorOutput());
                });
        } finally {
            foreach (['identity', 'known_hosts'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    @unlink($directory.'/'.$file);
                }
            }
            @rmdir($directory);
        }
    }

    private function classify(string $stderr): string
    {
        return match (true) {
            str_contains($stderr, 'Host key verification failed'), str_contains($stderr, 'REMOTE HOST IDENTIFICATION HAS CHANGED') => 'ssh_host_key_mismatch',
            str_contains($stderr, 'Permission denied') => 'ssh_authentication_failed',
            str_contains($stderr, 'Connection timed out'), str_contains($stderr, 'Operation timed out') => 'ssh_connect_timeout',
            str_contains($stderr, 'Connection refused') => 'ssh_connection_refused',
            str_contains($stderr, 'Could not resolve hostname') => 'ssh_unknown_host',
            default => 'ssh_unreachable',
        };
    }

    private function scratchDirectory(): string
    {
        $root = (string) config('toolkit.remote.ssh.scratch_root');
        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('No se pudo preparar el área privada de SSH.');
        }
        if (is_link($root)) {
            throw new RuntimeException('El área privada de SSH no puede ser un enlace simbólico.');
        }
        $directory = $root.'/'.Str::uuid()->toString();
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('No se pudo crear el directorio efímero de SSH.');
        }

        return $directory;
    }

    private function writePrivate(string $path, string $contents): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo materializar un archivo privado de SSH.');
        }
        try {
            chmod($path, 0600);
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('No se pudo escribir un archivo privado de SSH.');
            }
        } finally {
            fclose($handle);
        }
    }
}
