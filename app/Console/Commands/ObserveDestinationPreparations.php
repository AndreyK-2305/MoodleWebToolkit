<?php

namespace App\Console\Commands;

use App\Domain\Remote\PrepareRemoteDestination;
use App\Domain\Remote\RemoteDestinationPackages;
use App\Enums\ServerRole;
use App\Jobs\ObserveDestinationFetch;
use App\Jobs\ObserveDestinationPreparation;
use App\Models\Server;
use Illuminate\Console\Command;

/** Dispatches one observation for every destination preparation or package transfer still active. */
final class ObserveDestinationPreparations extends Command
{
    protected $signature = 'destinations:observe-preparations {--limit=50}';

    protected $description = 'Observa las preparaciones de destino y transferencias de paquetes en curso.';

    public function handle(): int
    {
        $limit = min(500, max(1, (int) $this->option('limit')));
        $dispatched = 0;
        Server::query()->where('role', ServerRole::DESTINATION->value)->where(fn ($query) => $query->whereNotNull('metadata->remote_destination_preparations')->orWhereNotNull('metadata->remote_destination_fetches'))
            ->orderBy('id')->limit($limit)->get()
            ->each(function (Server $server) use (&$dispatched): void {
                $preparations = $server->metadata['remote_destination_preparations'] ?? [];
                foreach (is_array($preparations) ? $preparations : [] as $destination => $preparation) {
                    if (is_string($destination) && is_array($preparation)
                        && in_array($preparation['state'] ?? null, PrepareRemoteDestination::ACTIVE, true)) {
                        ObserveDestinationPreparation::dispatch($server->getKey(), $destination);
                        $dispatched++;
                    }
                }
                $fetches = $server->metadata['remote_destination_fetches'] ?? [];
                foreach (is_array($fetches) ? $fetches : [] as $destination => $packages) {
                    foreach (is_string($destination) && is_array($packages) ? $packages : [] as $packageUuid => $fetch) {
                        if (is_string($packageUuid) && is_array($fetch) && in_array($fetch['state'] ?? null, RemoteDestinationPackages::ACTIVE, true)) {
                            ObserveDestinationFetch::dispatch($server->getKey(), $destination, $packageUuid);
                            $dispatched++;
                        }
                    }
                }
            });
        $this->line("Observaciones encoladas: {$dispatched}.");

        return self::SUCCESS;
    }
}
