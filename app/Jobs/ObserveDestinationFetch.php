<?php

namespace App\Jobs;

use App\Domain\Remote\RemoteDestinationPackages;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** One bounded observation of a package transfer into a destination's copias/. */
final class ObserveDestinationFetch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public int $uniqueFor = 170;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $serverId, public readonly string $destination, public readonly string $packageUuid) {}

    public function uniqueId(): string
    {
        return 'destination-fetch:'.$this->serverId.':'.$this->destination.':'.$this->packageUuid;
    }

    public function handle(RemoteDestinationPackages $packages): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server !== null) {
            $packages->observe($server, $this->destination, $this->packageUuid);
        }
    }
}
