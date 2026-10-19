<?php

namespace App\Domain\Remote;

use App\Domain\Remote\Contracts\RemoteTransport;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Domain\Remote\Transports\LocalBashRemoteTransport;
use App\Domain\Remote\Transports\SshRemoteTransport;
use App\Domain\Remote\Transports\SsmRemoteTransport;
use App\Enums\ConnectionType;

class RemoteTransportResolver
{
    public function for(RemoteTarget $target): RemoteTransport
    {
        if ((bool) config('toolkit.remote.local_transport', false)) {
            return app(LocalBashRemoteTransport::class);
        }

        return match ($target->type) {
            ConnectionType::AWS_SSM => app(SsmRemoteTransport::class),
            ConnectionType::SSH => app(SshRemoteTransport::class),
        };
    }
}
