<?php

namespace Tests\Feature\Domain;

use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Exceptions\ExecutionAlreadyActive;
use App\Models\Execution;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

    /**
     * @param  list<array{0: string, 1: int, 2: int|null}>  $workers
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
            foreach ($workers as $index => [$mode, $resourceId, $actorId]) {
                $marker = "{$prefix}:{$index}";
                $command = [
                    PHP_BINARY,
                    base_path('tests/Support/concurrent-domain-worker.php'),
                    $mode,
                    (string) $barrierKey,
                    $marker,
                    (string) $resourceId,
                    (string) ($actorId ?? 0),
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
