<?php

namespace App\Jobs;

use App\Domain\Remote\CheckRemoteDestination;
use App\Domain\Remote\PrepareRemoteDestination;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One bounded observation of a destination preparation. The scheduler
 * dispatches it again while the preparation is active; a successful one
 * refreshes the destination check so the panel shows the running target.
 */
final class ObserveDestinationPreparation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 200;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $serverId, public readonly string $destination) {}

    public function uniqueId(): string
    {
        return 'destination-preparation:'.$this->serverId.':'.$this->destination;
    }

    public function handle(PrepareRemoteDestination $preparations, CheckRemoteDestination $checks): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            return;
        }
        $before = $preparations->current($server, $this->destination)['state'] ?? null;
        $after = $preparations->observe($server, $this->destination)['state'] ?? null;
        if ($before !== $after && $after === 'SUCCEEDED') {
            $checks->handle($server->refresh(), $this->destination, null);
        }
    }
}
