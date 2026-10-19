<?php

namespace App\Domain\Remote\Transports;

use App\Domain\Remote\Contracts\RemoteTransport;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Domain\Remote\DTOs\TransportResult;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Test-only transport: runs the same backend-built scripts with the local
 * bash so the real agent can be exercised without AWS or an SSH server.
 */
final class LocalBashRemoteTransport implements RemoteTransport
{
    public function key(): string
    {
        return 'local-bash';
    }

    public function run(RemoteTarget $target, string $script, int $timeoutSeconds): TransportResult
    {
        if (! (bool) config('toolkit.remote.local_transport', false)) {
            throw new RuntimeException('El transporte local solo está disponible en la suite de pruebas aislada.');
        }
        $process = new Process(['bash', '-s'], null, [
            'LC_ALL' => 'C',
            'MWT_AGENT_ALLOW_NOHUP' => '1',
        ], $script, max(5, min($timeoutSeconds, 600)));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new TransportResult(TransportResult::TIMED_OUT, null, '', '', 'local_call_timed_out');
        }

        return new TransportResult(TransportResult::COMPLETED, $process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }
}
