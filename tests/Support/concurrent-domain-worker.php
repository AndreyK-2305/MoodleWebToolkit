<?php

use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Projects\ProjectExecutionManager;
use App\Models\Execution;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $mode, $barrierKey, $marker, $resourceId, $actorId] = array_pad($argv, 6, null);

if ($mode === null || $barrierKey === null || $marker === null || $resourceId === null) {
    fwrite(STDERR, "Invalid concurrent worker arguments.\n");
    exit(2);
}

$hasSharedLock = false;

try {
    DB::table('cache')->insert([
        'key' => $marker,
        'value' => $script ?? 'worker',
        'expiration' => time() + 120,
    ]);

    DB::select('SELECT pg_advisory_lock_shared(CAST(? AS bigint))', [(int) $barrierKey]);
    $hasSharedLock = true;

    $result = match ($mode) {
        'queue' => (function () use ($resourceId, $actorId): array {
            $project = Project::query()->findOrFail((int) $resourceId);
            $actor = User::query()->findOrFail((int) $actorId);
            $execution = app(ProjectExecutionManager::class)->queue($project, $actor);

            return ['status' => 'ok', 'id' => $execution->getKey()];
        })(),
        'event' => (function () use ($resourceId): array {
            $execution = Execution::query()->findOrFail((int) $resourceId);
            $event = app(ExecutionEventRecorder::class)->record($execution, 'CONCURRENT_EVENT');

            return ['status' => 'ok', 'id' => $event->getKey(), 'sequence' => $event->sequence];
        })(),
        default => throw new InvalidArgumentException("Unknown worker mode [{$mode}]."),
    };
} catch (Throwable $exception) {
    $result = [
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ];
} finally {
    if ($hasSharedLock) {
        DB::select('SELECT pg_advisory_unlock_shared(CAST(? AS bigint))', [(int) $barrierKey]);
    }
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
