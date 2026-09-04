<?php

namespace Tests\Feature\Domain;

use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\ExecutionCommandLease;
use App\Domain\Executions\ExecutionUnitState;
use App\Domain\Executions\StartProjectExecution;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Exceptions\ExecutionAlreadyActive;
use App\Jobs\RunExecutionUnit;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\ExecutionLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgreSqlConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('La prueba de concurrencia requiere PostgreSQL.');
        }

        $this->assertSame(0, Artisan::call('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ]));
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Artisan::call('migrate:fresh', [
                '--force' => true,
                '--no-interaction' => true,
            ]);
        }

        parent::tearDown();
    }

    public function test_two_independent_processes_cannot_queue_two_active_executions(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $project = Project::query()->create([
            'name' => 'Concurrencia de ejecución',
            'type' => ProjectType::CONSOLIDATE,
            'status' => ProjectStatus::READY,
            'created_by' => $admin->getKey(),
        ]);

        $results = $this->runConcurrently([
            ['queue', (int) $project->getKey(), (int) $admin->getKey()],
            ['queue', (int) $project->getKey(), (int) $admin->getKey()],
        ]);

        $successful = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'ok'));
        $failed = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'error'));

        $this->assertCount(1, $successful);
        $this->assertCount(1, $failed);
        $this->assertSame(ExecutionAlreadyActive::class, $failed[0]['class']);
        $this->assertSame(1, Execution::query()->where('project_id', $project->getKey())->count());
        $this->assertSame(1, Execution::query()->where('project_id', $project->getKey())->whereIn('status', [
            ExecutionStatus::QUEUED,
            ExecutionStatus::RUNNING,
            ExecutionStatus::WAITING_USER_ACTION,
            ExecutionStatus::CANCELLING,
            ExecutionStatus::VERIFYING,
        ])->count());
        $this->assertSame(ProjectStatus::QUEUED, $project->fresh()->status);
    }

    public function test_independent_processes_persist_unique_monotonic_event_sequences(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $project = Project::query()->create([
            'name' => 'Concurrencia de eventos',
            'type' => ProjectType::CONSOLIDATE,
            'status' => ProjectStatus::QUEUED,
            'created_by' => $admin->getKey(),
        ]);
        $execution = Execution::query()->create([
            'project_id' => $project->getKey(),
            'attempt' => 1,
            'status' => ExecutionStatus::QUEUED,
            'created_by' => $admin->getKey(),
        ]);

        $workers = array_fill(0, 6, ['event', (int) $execution->getKey(), null]);
        $results = $this->runConcurrently($workers);

        $this->assertSame([], array_values(array_filter($results, fn (array $result): bool => $result['status'] !== 'ok')));

        $reportedSequences = array_map(fn (array $result): int => (int) $result['sequence'], $results);
        sort($reportedSequences);

        $this->assertSame(range(1, 6), $reportedSequences);
        $this->assertSame(range(1, 6), $execution->events()->orderBy('sequence')->pluck('sequence')->all());
        $this->assertSame(6, $execution->fresh()->last_event_sequence);
    }

    public function test_two_concurrent_http_requests_with_same_key_create_one_execution(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $project = $this->readyCollectionProject($admin);

        $results = $this->runConcurrently([
            ['start-http', (int) $project->getKey(), (int) $admin->getKey(), 'concurrent-same-key-0001'],
            ['start-http', (int) $project->getKey(), (int) $admin->getKey(), 'concurrent-same-key-0001'],
        ]);

        $this->assertSame([], array_values(array_filter($results, fn (array $result): bool => $result['status'] !== 'ok')));
        $this->assertSame([200, 201], collect($results)->pluck('http_status')->sort()->values()->all());
        $this->assertCount(1, collect($results)->pluck('body.execution_uuid')->unique());
        $this->assertSame(1, Execution::query()->where('project_id', $project->getKey())->count());
        $this->assertSame(2, DB::table('execution_commands')->count());
    }

    public function test_two_concurrent_http_requests_with_different_keys_keep_one_active_execution(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $project = $this->readyCollectionProject($admin);

        $results = $this->runConcurrently([
            ['start-http', (int) $project->getKey(), (int) $admin->getKey(), 'concurrent-key-a-0001'],
            ['start-http', (int) $project->getKey(), (int) $admin->getKey(), 'concurrent-key-b-0001'],
        ]);

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['status'] === 'ok'));
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['http_status'] === 409));
        $this->assertSame(1, Execution::query()->where('project_id', $project->getKey())->count());
        $this->assertSame(2, DB::table('execution_commands')->count());
    }

    public function test_two_reconcilers_close_one_abandoned_command_exactly_once(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        [$project, $execution, $command] = $this->claimedExecution($admin, expired: true);

        $results = $this->runConcurrently([
            ['recover-abandoned', (int) $command->getKey(), null],
            ['recover-abandoned', (int) $command->getKey(), null],
        ]);

        $this->assertSame([], array_values(array_filter($results, fn (array $result): bool => $result['status'] !== 'ok')));
        $this->assertSame([0, 0], collect($results)->pluck('exit_code')->all());
        $this->assertNotNull($command->fresh()->processed_at);
        $this->assertSame(ProjectStatus::FAILED, $project->fresh()->status);
        $this->assertSame(ExecutionStatus::FAILED, $execution->fresh()->status);
        $this->assertSame(ExecutionStepStatus::FAILED, $execution->steps()->orderBy('position')->firstOrFail()->status);
        $this->assertSame(3, $execution->steps()->where('status', ExecutionStepStatus::PENDING)->count());
        $this->assertSame(1, $execution->events()->where('type', 'execution.abandoned')->count());
        $this->assertSame(1, ExecutionLog::query()->where('execution_id', $execution->getKey())->count());
        $this->assertSame(1, AuditLog::query()
            ->where('execution_id', $execution->getKey())
            ->where('action', 'EXECUTION_ABANDONED')
            ->count());
    }

    public function test_reconciler_does_not_override_worker_finishing_with_an_active_lease(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        [$project, $execution, $command, $owner] = $this->claimedExecution($admin, expired: false);

        $results = $this->runConcurrently([
            ['recover-abandoned', (int) $command->getKey(), null],
            ['finish-command', (int) $command->getKey(), null, $owner],
        ]);

        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame(0, $results[0]['exit_code']);
        $this->assertSame('ok', $results[1]['status']);
        $this->assertTrue($results[1]['completed']);
        $this->assertNotNull($command->fresh()->processed_at);
        $this->assertNull($command->fresh()->lease_owner);
        $this->assertSame(ProjectStatus::RUNNING, $project->fresh()->status);
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertSame(50, $execution->fresh()->progress);
        $this->assertSame(ExecutionStepStatus::SUCCESS, $execution->steps()->orderBy('position')->firstOrFail()->status);
        $this->assertSame(2, $execution->steps()->where('status', ExecutionStepStatus::PENDING)->count());
        $this->assertSame(2, $execution->events()->where('type', 'phase.completed')->count());
        $this->assertSame(1, $execution->events()->where('type', 'execution.command_queued')->count());
        $this->assertSame(1, $execution->events()->where('type', 'iteration_1e.boundary')->count());
        $this->assertSame(0, $execution->events()->where('type', 'execution.abandoned')->count());
        $this->assertSame(0, ExecutionLog::query()->where('execution_id', $execution->getKey())->count());
    }

    public function test_cancellation_racing_a_continuation_prevents_late_work(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        [$project, $execution, $command, $owner] = $this->claimedExecution($admin, expired: false);

        $results = $this->runConcurrently([
            ['finish-command', (int) $command->getKey(), null, $owner],
            ['cancel-http', (int) $execution->getKey(), (int) $admin->getKey(), 'concurrent-cancel-0001'],
        ]);

        $this->assertSame('ok', $results[1]['status']);
        $this->assertContains($results[0]['status'], ['ok', 'error']);
        $cancel = $execution->commands()->where('command_type', 'CANCEL')->sole();

        if ($execution->fresh()->status === ExecutionStatus::CANCELLING) {
            (new RunExecutionUnit((int) $cancel->getKey()))->handle(
                app(ExecutionProvider::class),
                app(ToolAdapter::class),
            );
        }

        $this->assertSame(ExecutionStatus::CANCELLED, $execution->fresh()->status);
        $this->assertSame(ProjectStatus::CANCELLED, $project->fresh()->status);
        $this->assertSame(0, $execution->commands()->whereNull('processed_at')->count());

        $late = $execution->commands()->where('command_type', 'CONTINUE')->first();

        if ($late !== null) {
            (new RunExecutionUnit((int) $late->getKey()))->handle(
                app(ExecutionProvider::class),
                app(ToolAdapter::class),
            );
        }

        $this->assertSame(ExecutionStatus::CANCELLED, $execution->fresh()->status);
        $this->assertSame(1, $execution->events()->where('type', 'execution.cancelled')->count());
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int|null, 3?: string}>  $workers
     * @return list<array<string, mixed>>
     *
     * @throws JsonException
     */
    private function runConcurrently(array $workers): array
    {
        $barrierKey = random_int(100_000, 2_000_000_000);
        $prefix = 'concurrency:'.Str::uuid();
        $processes = [];
        $barrierReleased = false;

        DB::select('SELECT pg_advisory_lock(CAST(? AS bigint))', [$barrierKey]);

        try {
            foreach ($workers as $index => $worker) {
                [$mode, $resourceId, $actorId, $extra] = array_pad($worker, 4, null);
                $marker = "{$prefix}:{$index}";
                $command = [
                    PHP_BINARY,
                    base_path('tests/Support/concurrent-domain-worker.php'),
                    $mode,
                    (string) $barrierKey,
                    $marker,
                    (string) $resourceId,
                    (string) ($actorId ?? 0),
                    (string) ($extra ?? ''),
                ];
                $process = new Process($command, base_path(), timeout: 30);
                $process->start();
                $processes[] = $process;
            }

            $this->waitUntilWorkersReachBarrier($prefix, count($workers));

            $pids = array_map(fn (Process $process): ?int => $process->getPid(), $processes);
            $this->assertCount(count($workers), array_unique($pids));

            DB::select('SELECT pg_advisory_unlock(CAST(? AS bigint))', [$barrierKey]);
            $barrierReleased = true;

            $results = [];

            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
                $lastLine = end($lines);
                $this->assertIsString($lastLine);
                $decoded = json_decode($lastLine, true, flags: JSON_THROW_ON_ERROR);
                $this->assertIsArray($decoded);
                $results[] = $decoded;
            }

            return $results;
        } finally {
            if (! $barrierReleased) {
                DB::select('SELECT pg_advisory_unlock(CAST(? AS bigint))', [$barrierKey]);
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }

            DB::table('cache')->where('key', 'like', "{$prefix}%")->delete();
        }
    }

    private function readyCollectionProject(User $admin): Project
    {
        $wizard = app(ProjectWizard::class);
        $project = $wizard->create($admin, [
            'name' => 'Concurrencia HTTP 1D',
            'type' => ProjectType::COLLECT->value,
            'description' => 'Inicio concurrente real.',
        ]);
        $wizard->saveInstances($project, $admin, [[
            'uuid' => null,
            'server_uuid' => null,
            'role' => 'SOURCE',
            'server_name' => 'Servidor concurrente',
            'server_host' => 'concurrent.test',
            'name' => 'Moodle concurrente',
            'base_url' => 'https://concurrent.test',
            'moodle_version' => '4.5',
            'validated' => true,
            'destination_kind' => null,
        ]]);
        $wizard->saveOptions($project, $admin, [
            'simulation_scenario' => 'SUCCESS',
            'artifact_name' => 'concurrent-package',
        ]);
        $wizard->runPreflight($project, $admin);
        $configuration = $project->fresh('configuration')->configuration;
        $wizard->confirm($project, $admin, $configuration->version, []);

        return $project->fresh(['configuration', 'moodleInstances.server']);
    }

    /** @return array{Project, Execution, ExecutionCommand, string} */
    private function claimedExecution(User $admin, bool $expired): array
    {
        Queue::fake();
        $project = $this->readyCollectionProject($admin);
        $result = app(StartProjectExecution::class)->start(
            $project,
            $admin,
            'concurrent-recovery-'.Str::uuid(),
            $project->configuration->version,
        );
        $execution = $result->execution;
        $command = $execution->commands()->sole();
        $claimed = app(ExecutionCommandLease::class)->claim((int) $command->getKey());
        $this->assertNotNull($claimed);
        $owner = $claimed->owner;
        $running = app(ExecutionUnitState::class)->begin((int) $command->getKey(), $owner);
        $step = $running->steps()->orderBy('position')->firstOrFail();
        app(ExecutionUnitState::class)->applyEvent(
            (int) $command->getKey(),
            $owner,
            (int) $step->getKey(),
            new NormalizedToolEvent('phase.started', $step->step_key),
        );

        if ($expired) {
            $command->update(['lease_expires_at' => now()->utc()->subSecond()]);
        }

        return [$project, $execution, $command->fresh(), $owner];
    }

    private function waitUntilWorkersReachBarrier(string $prefix, int $expected): void
    {
        $deadline = microtime(true) + 10;

        do {
            if (DB::table('cache')->where('key', 'like', "{$prefix}%")->count() === $expected) {
                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail("Los {$expected} procesos no alcanzaron la barrera PostgreSQL.");
    }
}
