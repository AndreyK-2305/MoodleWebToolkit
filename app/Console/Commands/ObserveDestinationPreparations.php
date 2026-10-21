<?php

namespace App\Console\Commands;

use App\Domain\Remote\PrepareRemoteDestination;
use App\Enums\ServerRole;
use App\Jobs\ObserveDestinationPreparation;
use App\Models\Server;
use Illuminate\Console\Command;

/** Dispatches one observation for every destination preparation still active. */
final class ObserveDestinationPreparations extends Command
{
    protected $signature = 'destinations:observe-preparations {--limit=50}';

    protected $description = 'Observa las preparaciones de destino en curso en sus servidores.';

    public function handle(): int
    {
        $limit = min(500, max(1, (int) $this->option('limit')));
        $dispatched = 0;
        Server::query()->where('role', ServerRole::DESTINATION->value)->whereNotNull('metadata->remote_destination_preparations')
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
            });
        $this->line("Observaciones encoladas: {$dispatched}.");

        return self::SUCCESS;
    }
}
