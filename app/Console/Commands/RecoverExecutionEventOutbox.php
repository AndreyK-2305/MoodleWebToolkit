<?php

namespace App\Console\Commands;

use App\Domain\Realtime\ExecutionEventOutboxPublisher;
use Illuminate\Console\Command;

final class RecoverExecutionEventOutbox extends Command
{
    protected $signature = 'executions:recover-event-outbox {--limit=20}';

    protected $description = 'Reintenta la entrega de eventos confirmados en PostgreSQL';

    public function handle(ExecutionEventOutboxPublisher $publisher): int
    {
        $count = $publisher->recover((int) $this->option('limit'));
        $this->info("{$count} evento(s) pendiente(s) publicados.");

        return self::SUCCESS;
    }
}
