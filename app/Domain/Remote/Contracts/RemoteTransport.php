<?php

namespace App\Domain\Remote\Contracts;

use App\Domain\Remote\DTOs\RemoteTarget;
use App\Domain\Remote\DTOs\TransportResult;

interface RemoteTransport
{
    public function key(): string;

    /**
     * Runs a bash script built by the backend from fixed templates. Calls are
     * short and bounded; long work is launched detached by the remote agent.
     */
    public function run(RemoteTarget $target, string $script, int $timeoutSeconds): TransportResult;
}
