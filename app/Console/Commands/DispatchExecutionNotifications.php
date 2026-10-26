<?php

namespace App\Console\Commands;

use App\Domain\Notifications\ExecutionNotifications;
use Illuminate\Console\Command;

final class DispatchExecutionNotifications extends Command
{
    protected $signature = 'notifications:dispatch {--limit=50}';

    protected $description = 'Abre los resúmenes de avance y envía las notificaciones pendientes por correo';

    public function handle(ExecutionNotifications $notifications): int
    {
        $summaries = $notifications->captureProgress();
        $count = $notifications->dispatchPending((int) $this->option('limit'));
        $this->info("{$count} notificación(es) procesada(s); {$summaries} resumen(es) de avance nuevo(s).");

        return self::SUCCESS;
    }
}
