<?php

use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Executions\ExecutionUnitState;
use App\Domain\Projects\ProjectExecutionManager;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $mode, $barrierKey, $marker, $resourceId, $actorId, $extra] = array_pad($argv, 7, null);

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
        'recover-abandoned' => (function (): array {
            $exitCode = Artisan::call('executions:recover-dispatches');

            return [
                'status' => $exitCode === 0 ? 'ok' : 'error',
                'exit_code' => $exitCode,
            ];
        })(),
        'finish-command' => (function () use ($resourceId, $extra): array {
            $command = ExecutionCommand::query()->with('execution')->findOrFail((int) $resourceId);
            $step = $command->execution->steps()->where('status', 'RUNNING')->sole();
            $completed = app(ExecutionUnitState::class)->applyEvent(
                (int) $command->getKey(),
                (string) $extra,
                (int) $step->getKey(),
                new NormalizedToolEvent('phase.completed', $step->step_key, progress: 25),
            );

            return ['status' => 'ok', 'completed' => $completed];
        })(),
        'start-http' => (function () use ($app, $resourceId, $actorId, $extra): array {
            $project = Project::query()->with('configuration')->findOrFail((int) $resourceId);
            $actor = User::query()->findOrFail((int) $actorId);
            $app->make('auth')->guard('web')->setUser($actor);
            $request = Request::create(
                "/projects/{$project->uuid}/executions",
                'POST',
                ['configuration_version' => $project->configuration->version],
                server: [
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_IDEMPOTENCY_KEY' => (string) $extra,
                ],
            );
            $session = $app->make('session')->driver();
            $session->start();
            $session->put('auth.password_confirmed_at', (int) now()->timestamp);
            $request->setLaravelSession($session);
            $request->setUserResolver(fn () => $actor);
            $kernel = $app->make(HttpKernel::class);
            $response = $kernel->handle($request);
            $decoded = json_decode((string) $response->getContent(), true);
            $kernel->terminate($request, $response);

            return [
                'status' => $response->getStatusCode() < 400 ? 'ok' : 'error',
                'http_status' => $response->getStatusCode(),
                'body' => is_array($decoded) ? $decoded : [],
            ];
        })(),
        'cancel-http' => (function () use ($app, $resourceId, $actorId, $extra): array {
            $execution = Execution::query()->with('project')->findOrFail((int) $resourceId);
            $actor = User::query()->findOrFail((int) $actorId);
            $app->make('auth')->guard('web')->setUser($actor);
            $request = Request::create(
                "/projects/{$execution->project->uuid}/executions/{$execution->uuid}/cancel",
                'POST',
                server: [
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_IDEMPOTENCY_KEY' => (string) $extra,
                ],
            );
            $session = $app->make('session')->driver();
            $session->start();
            $session->put('auth.password_confirmed_at', (int) now()->timestamp);
            $request->setLaravelSession($session);
            $request->setUserResolver(fn () => $actor);
            $kernel = $app->make(HttpKernel::class);
            $response = $kernel->handle($request);
            $decoded = json_decode((string) $response->getContent(), true);
            $kernel->terminate($request, $response);

            return [
                'status' => $response->getStatusCode() < 400 ? 'ok' : 'error',
                'http_status' => $response->getStatusCode(),
                'body' => is_array($decoded) ? $decoded : [],
            ];
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
