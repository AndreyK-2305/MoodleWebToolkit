<?php

namespace App\Http\Controllers;

use App\Domain\Executions\ExecutionPresenter;
use App\Domain\Realtime\ProjectSessionChannels;
use App\Models\Execution;
use App\Models\ExecutionEvent;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ExecutionEventController extends Controller
{
    public function index(
        Request $request,
        Project $project,
        Execution $execution,
        ExecutionPresenter $presenter,
        ProjectSessionChannels $channels,
    ): JsonResponse {
        abort_unless($execution->project_id === $project->getKey(), 404);
        Gate::authorize('view', $execution);
        $validated = $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'],
        ]);
        $after = (int) ($validated['after'] ?? 0);
        $execution->refresh()->load('steps');
        $events = $execution->events()
            ->where('sequence', '>', $after)
            ->orderBy('sequence')
            ->limit(200)
            ->get();

        return response()->json([
            'execution' => $presenter->execution($execution),
            'events' => $events->map(fn (ExecutionEvent $event): array => $presenter->event($event))->values(),
            'has_more' => $events->count() === 200
                && (int) $events->last()?->sequence < $execution->last_event_sequence,
            'realtime_channel' => $channels->current($project, $request->session()->getId()),
        ]);
    }
}
