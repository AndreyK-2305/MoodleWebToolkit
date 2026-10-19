<?php

use App\Domain\Collector\CollectorExecutionPreparation;
use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Executions\ExecutionPresenter;
use App\Domain\Executions\RequestExecutionFinalization;
use App\Domain\Executions\StartProjectExecution;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\UserRole;
use App\Jobs\RunExecutionUnit;
use App\Models\Execution;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\QualityFixtures;

require __DIR__.'/quality-bootstrap.php';
$action = $argv[1] ?? '';
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
$result = [];
switch ($action) {
    case 'worker-health':
        exit(Redis::exists('quality:worker:heartbeat') ? 0 : 1);
    case 'reset':
        // Redis belongs exclusively to this quality project. Do not carry login
        // rate limits or session-channel cache across independent browser cases.
        Cache::flush();
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        Artisan::call('queue:clear', ['connection' => 'redis', '--queue' => 'executions', '--force' => true]);
        $password = $input['password'];
        $status = Artisan::call('app:create-admin', ['--name' => 'Quality ADMIN', '--email' => 'admin@quality.test', '--password' => $password]);
        if ($status !== 0) {
            throw new RuntimeException('Initial administrator creation failed.');
        }
        foreach (['operator' => UserRole::OPERATOR, 'outsider' => UserRole::OPERATOR, 'auditor' => UserRole::AUDITOR, 'inactive' => UserRole::OPERATOR, 'temporary' => UserRole::OPERATOR] as $name => $role) {
            User::query()->create([
                'name' => 'Quality '.$name, 'email' => $name.'@quality.test',
                'password' => $password, 'role' => $role,
                'is_active' => $name !== 'inactive', 'must_change_password' => $name === 'temporary',
                'email_verified_at' => now(),
            ]);
        }
        if (config('toolkit.features.recolector_742.enabled') && config('collector.profiles.synthetic-moodle')) {
            if (Artisan::call('tools:catalog:sync') !== 0 || Artisan::call('collector:enable-laboratory') !== 0) {
                throw new RuntimeException('Synthetic collector catalog authorization failed.');
            }
        }
        $result = ['users' => User::query()->count()];
        break;
    case 'ready':
        $actor = User::query()->where('email', 'admin@quality.test')->sole();
        $project = QualityFixtures::ready($actor, $input['type'] ?? 'COLLECT', $input['scenario'] ?? 'SUCCESS');
        foreach (['operator', 'auditor'] as $role) {
            $project->assignments()->create(['user_id' => User::query()->where('email', $role.'@quality.test')->sole()->id, 'assigned_by' => $actor->id]);
        }
        $result = ['uuid' => $project->uuid, 'version' => $project->configuration->version];
        break;
    case 'worker':
        $id = (string) Str::uuid();
        Redis::rpush('quality:worker:requests', json_encode(['id' => $id, 'mode' => $input['mode'] ?? 'drain', 'clock' => $input['clock'] ?? ''], JSON_THROW_ON_ERROR));
        $response = Redis::blpop(['quality:worker:result:'.$id], 50);
        if (! is_array($response)) {
            throw new RuntimeException('Worker did not acknowledge the bounded unit.');
        }
        $result = json_decode($response[1], true, flags: JSON_THROW_ON_ERROR);
        break;
    case 'snapshot':
        $project = Project::query()->where('uuid', $input['project'])->sole();
        $execution = isset($input['execution']) ? $project->executions()->where('uuid', $input['execution'])->sole() : $project->executions()->latest('attempt')->first();
        $result = [
            'status' => $project->status->value, 'configuration' => $project->configuration,
            'execution_count' => $project->executions()->count(),
            'audit' => DB::table('audit_logs')->where('project_id', $project->id)->get(),
            'instances' => $project->moodleInstances()->with('server')->get(),
        ];
        if ($execution !== null) {
            $result += [
                'execution' => app(ExecutionPresenter::class)->execution($execution),
                'review' => app(ExecutionPresenter::class)->review($execution),
                'workspace' => $execution->workspace_key,
                'events' => $execution->events()->orderBy('sequence')->get(),
                'commands' => $execution->commands()->get(),
                'finalization' => $execution->finalization,
                'project_updated_at' => $project->updated_at?->toIso8601String(),
            ];
        }
        break;
    case 'start':
        $project = Project::query()->where('uuid', $input['project'])->sole();
        $started = app(StartProjectExecution::class)->start($project, User::query()->where('email', 'admin@quality.test')->sole(), 'quality-'.Str::uuid(), $project->configuration->version);
        $result = ['uuid' => $started->execution->uuid];
        break;
    case 'finalize':
        $execution = Execution::query()->where('uuid', $input['execution'])->sole();
        app(RequestExecutionFinalization::class)->request($execution, User::query()->where('email', 'admin@quality.test')->sole(), 'quality-finalize-'.Str::uuid());
        break;
    case 'revoke':
        $user = User::query()->where('email', $input['user'].'@quality.test')->sole();
        match ($input['kind']) {
            'assignment' => $user->projectAssignments()->delete(),
            'role' => $user->update(['role' => UserRole::OPERATOR]),
            'inactive' => $user->update(['is_active' => false]),
        };
        break;
    case 'replay':
        $execution = Execution::query()->where('uuid', $input['execution'])->sole();
        foreach ($execution->commands as $command) {
            // Deliberately redeliver even processed jobs through the real queue.
            RunExecutionUnit::dispatch($command->id)->onQueue('executions');
        }
        break;
    case 'lose-queue':
        // Simulate loss of Redis queue data while keeping the PostgreSQL outbox.
        Artisan::call('queue:clear', ['connection' => 'redis', '--queue' => 'executions', '--force' => true]);
        break;
    case 'recover':
        if (isset($input['clock'])) {
            Carbon::setTestNow($input['clock']);
        }
        $result = ['exit' => Artisan::call('executions:recover-dispatches', ['--stale' => 1])];
        break;
    case 'reverb-restart':
        Artisan::call('reverb:restart');
        break;
    case 'lab-capacity-fault':
        if (! config('toolkit.features.recolector_742.enabled') || ! config('collector.profiles.synthetic-moodle')) {
            throw new RuntimeException('LAB-only capacity fault.');
        }
        $execution = Execution::query()->where('uuid', $input['execution'])->sole();
        if ($execution->toolBinding?->adapter_key !== CollectorExecutionPreparation::ADAPTER_KEY || ! $execution->status->isActive()) {
            throw new RuntimeException('Capacity fault requires an active real LAB execution.');
        }
        $path = app(ExecutionWorkspaceManager::class)->resolve($execution, 'temporary', 'quality-capacity-fault');
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Capacity fault already exists.');
        }
        try {
            chmod($path, 0600);
            if (! ftruncate($handle, 536870912)) {
                throw new RuntimeException('Cannot simulate capacity consumption.');
            }
        } finally {
            fclose($handle);
        }
        $result = ['injected' => true];
        break;
    case 'event':
        $execution = Execution::query()->where('uuid', $input['execution'])->sole();
        $event = app(ExecutionEventRecorder::class)->record($execution, 'quality.observation', message: $input['message'] ?? 'Evento persistido de calidad');
        $result = ['sequence' => $event->sequence];
        break;
    default:
        throw new InvalidArgumentException('Unknown quality control action.');
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
