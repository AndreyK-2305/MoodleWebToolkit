<?php

namespace App\Listeners;

use App\Domain\Remote\RemoteConsolidationSources;
use App\Events\ExecutionCompleted;
use App\Models\Project;
use Throwable;

/**
 * When a collection completes, the consolidations created with «Recolectar
 * ahora» for it take its packages. The pick-up is idempotent and never fails
 * the completion that triggered it: an error is kept on the consolidation.
 */
final class PickUpLinkedCollectionPackages
{
    public function __construct(private readonly RemoteConsolidationSources $sources) {}

    public function handle(ExecutionCompleted $event): void
    {
        $project = Project::query()->find($event->projectId);
        if ($project === null) {
            return;
        }
        try {
            $this->sources->pickUp($project);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
