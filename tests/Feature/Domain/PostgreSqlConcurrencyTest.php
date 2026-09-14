<?php

namespace Tests\Feature\Domain;

use App\Domain\Academic\ProposeAcademicChange;
use App\Domain\Artifacts\ArtifactStreamVerifier;
use App\Domain\Artifacts\DTOs\StoredArtifact;
use App\Domain\Artifacts\FinalizationGarbageCollector;
use App\Domain\Artifacts\GenerateFinalArtifacts;
use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\ExecutionCommandLease;
use App\Domain\Executions\ExecutionFailureCloser;
use App\Domain\Executions\ExecutionUnitState;
use App\Domain\Executions\ProcessExecutionFinalization;
use App\Domain\Executions\RequestExecutionFinalization;
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
use App\Exceptions\ExecutionCommandLeaseLost;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\Process\Process;
use Tests\Support\QualityFixtures;
use Tests\TestCase;

class PostgreSqlConcurrencyTest extends TestCase
{
    public function test_two_confirmations_only_persist_one_ready_confirmation(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $project = $this->readyCollectionProject($admin);
        $wizard = app(ProjectWizard::class);
        $wizard->saveBasics($project, $admin, ['name' => 'Nueva confirmación 1G', 'type' => 'COLLECT']);
        $wizard->runPreflight($project, $admin);
        $before = AuditLog::query()->where('project_id', $project->id)->where('action', 'PROJECT_CONFIGURATION_CONFIRMED')->count();
        $results = $this->runConcurrently(array_fill(0, 2, ['confirm-http', $project->id, $admin->id]));
        $this->assertSame([302, 302], array_column($results, 'http_status'));
        $this->assertSame(ProjectStatus::READY, $project->fresh()->status);
        $this->assertSame(0, $project->executions()->count());
        $this->assertSame($before + 1, AuditLog::query()->where('project_id', $project->id)->where('action', 'PROJECT_CONFIGURATION_CONFIRMED')->count());
    }

    public function test_double_resolution_and_resume_with_same_and_different_keys_have_one_effect(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        foreach (['resolve-http' => 'WARNING', 'resume-http' => 'FAILURE'] as $mode => $scenario) {
            foreach ([true, false] as $sameKey) {
                $project = QualityFixtures::ready($admin, scenario: $scenario);
                $execution = app(StartProjectExecution::class)->start($project, $admin, 'start-'.Str::uuid(), $project->configuration->version)->execution;
                foreach (['START', 'CONTINUE'] as $type) {
                    $command = $execution->commands()->where('command_type', $type)->sole();
                    (new RunExecutionUnit($command->id))->handle(app(ExecutionProvider::class), app(ToolAdapter::class));
                }
                $conflict = $execution->conflicts()->first();
                $payload = $mode === 'resolve-http'
                    ? ['decision' => 'ACCEPT', 'conflict_version' => $conflict->version]
                    : ['checkpoint_id' => $execution->checkpoints()->sole()->id];
                $workers = [];
                foreach ([0, 1] as $index) {
                    $workers[] = [$mode, $execution->id, $admin->id, json_encode([
                        'key' => $sameKey ? 'same-quality-key' : 'quality-key-'.$index,
                        'payload' => $payload, 'conflict_id' => $conflict?->id,
                    ], JSON_THROW_ON_ERROR)];
                }
                $results = $this->runConcurrently($workers);
                $statuses = array_column($results, 'http_status');
                sort($statuses);
                $this->assertSame($mode === 'resolve-http'
                    ? ($sameKey ? [200, 200] : [200, 422])
                    : ($sameKey ? [200, 201] : [201, 422]), $statuses);
                if ($mode === 'resolve-http') {
                    $this->assertSame(1, $execution->commands()->where('command_type', 'RESOLVE_CONFLICT')->count());
                    $this->assertSame(1, $execution->events()->where('type', 'conflict.resolved')->count());
                } else {
                    $this->assertSame(2, $project->executions()->count());
                    $resumed = $project->executions()->where('attempt', 2)->sole();
                    $this->assertSame($execution->id, $resumed->resumed_from_execution_id);
                    $this->assertNotSame($execution->workspace_key, $resumed->workspace_key);
                    $this->assertSame(1, $resumed->events()->min('sequence'));
                }
            }
        }
    }

    public function test_two_cancellation_requests_preserve_one_cooperative_transition(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $project = $this->readyCollectionProject($admin);
        $execution = app(StartProjectExecution::class)->start($project, $admin, 'cancel-race-start', $project->configuration->version)->execution;
        $results = $this->runConcurrently(array_fill(0, 2, ['cancel-deferred-http', $execution->id, $admin->id, 'cancel-race-same-key']));
        $statuses = array_column($results, 'http_status');
        sort($statuses);
        $this->assertSame([200, 202], $statuses);
        $this->assertSame(ExecutionStatus::CANCELLING, $execution->fresh()->status);
        $cancel = $execution->commands()->where('command_type', 'CANCEL')->sole();
        foreach ([1, 2] as $_) {
            (new RunExecutionUnit($cancel->id))->handle(app(ExecutionProvider::class), app(ToolAdapter::class));
        }
        $this->assertSame(ExecutionStatus::CANCELLED, $execution->fresh()->status);
        $this->assertSame(1, $execution->events()->where('type', 'execution.cancelled')->count());
    }

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
        $this->assertSame(3, DB::table('execution_commands')->count());
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
        $this->assertSame(3, DB::table('execution_commands')->count());
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
        $this->assertSame(ProjectStatus::REVIEW, $project->fresh()->status);
        $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
        $this->assertSame(75, $execution->fresh()->progress);
        $this->assertSame(ExecutionStepStatus::SUCCESS, $execution->steps()->orderBy('position')->firstOrFail()->status);
        $this->assertSame(1, $execution->steps()->where('status', ExecutionStepStatus::PENDING)->count());
        $this->assertSame(2, $execution->events()->where('type', 'phase.completed')->count());
        $this->assertSame(1, $execution->events()->where('type', 'execution.command_queued')->count());
        $this->assertSame(1, $execution->events()->where('type', 'verification.completed')->count());
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

    public function test_two_validation_requests_create_one_validation_effect(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        [$project, $execution] = $this->reviewedCollectionExecution($admin);
        app(ProposeAcademicChange::class)->propose($execution, $admin, [
            'operation' => 'RENAME_CATEGORY',
            'node_id' => 'cat:collection-academic',
            'value' => 'Oferta concurrente',
            'expected_version' => 0,
            'base_fingerprint' => (string) $execution->review_fingerprint,
        ], 'concurrent-proposal-seed');

        $results = $this->runConcurrently([
            ['validate-http', (int) $execution->getKey(), (int) $admin->getKey(), 'concurrent-validate-a'],
            ['validate-http', (int) $execution->getKey(), (int) $admin->getKey(), 'concurrent-validate-b'],
        ]);

        $this->assertSame([], array_values(array_filter($results, fn (array $result): bool => $result['status'] !== 'ok')));
        $this->assertEqualsCanonicalizing([200, 202], collect($results)->pluck('http_status')->all());
        $this->assertSame(1, $execution->verifications()->where('proposal_version', 1)->count());
        $this->assertSame(1, $execution->commands()->where('command_type', 'VALIDATE')->where('attempt', 2)->count());
        $this->assertSame(2, DB::table('idempotency_receipts')
            ->where('execution_id', $execution->getKey())
            ->where('action', 'VALIDATE')
            ->whereIn('idempotency_key', ['concurrent-validate-a', 'concurrent-validate-b'])
            ->count());
        $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
        $this->assertSame(ProjectStatus::REVIEW, $project->fresh()->status);

        $losingIndex = collect($results)->search(fn (array $result): bool => $result['http_status'] === 200);
        $this->assertIsInt($losingIndex);
        $losingKey = $losingIndex === 0 ? 'concurrent-validate-a' : 'concurrent-validate-b';
        $execution->refresh();
        app(ProposeAcademicChange::class)->propose($execution, $admin, [
            'operation' => 'RENAME_CATEGORY',
            'node_id' => 'cat:collection-archive',
            'value' => 'Archivo posterior',
            'expected_version' => 1,
            'base_fingerprint' => (string) $execution->review_fingerprint,
        ], 'concurrent-proposal-next-version');

        $this->actingAs($admin)->postJson(
            route('projects.executions.validate', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => $losingKey],
        )->assertConflict();
    }

    public function test_two_finalization_requests_create_one_atomic_closure(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        [$project, $execution] = $this->reviewedCollectionExecution($admin);

        try {
            $results = $this->runConcurrently([
                ['finalize-http', (int) $execution->getKey(), (int) $admin->getKey(), 'concurrent-finalize-a'],
                ['finalize-http', (int) $execution->getKey(), (int) $admin->getKey(), 'concurrent-finalize-b'],
            ]);

            $this->assertSame([], array_values(array_filter($results, fn (array $result): bool => $result['status'] !== 'ok')));
            $this->assertEqualsCanonicalizing([200, 202], collect($results)->pluck('http_status')->all());
            $this->assertSame(ExecutionStatus::COMPLETED, $execution->fresh()->status);
            $this->assertSame(ProjectStatus::COMPLETED, $project->fresh()->status);
            $this->assertSame(1, $execution->commands()->where('command_type', 'FINALIZE')->count());
            $this->assertSame(2, DB::table('idempotency_receipts')
                ->where('execution_id', $execution->getKey())
                ->where('action', 'FINALIZE')
                ->whereIn('idempotency_key', ['concurrent-finalize-a', 'concurrent-finalize-b'])
                ->count());
            $this->assertSame(1, $execution->events()->where('type', 'execution.completed')->count());
            $this->assertSame(4, $execution->artifacts()->count());
        } finally {
            Storage::disk('local')->deleteDirectory("executions/{$execution->workspace_key}");
        }
    }

    public function test_expired_finalization_worker_cannot_remove_the_later_winners_artifacts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        [$project, $execution] = $this->reviewedCollectionExecution($admin);
        app(RequestExecutionFinalization::class)->request($execution, $admin, 'stale-finalization-request');
        $command = $execution->commands()->where('command_type', 'FINALIZE')->sole();
        $leases = app(ExecutionCommandLease::class);
        $workerA = $leases->claim((int) $command->getKey());
        $this->assertNotNull($workerA);
        $completionAt = now()->utc()->toImmutable();
        $stagingA = app(GenerateFinalArtifacts::class)->stage(
            $execution,
            $admin,
            (int) $command->getKey(),
            $workerA->owner,
            $command->created_at,
            $completionAt,
        );

        $command->update(['lease_expires_at' => now()->utc()->subSecond()]);
        $this->assertTrue(app(ExecutionFailureCloser::class)->closeAbandoned((int) $command->getKey()));
        $workerB = $leases->claim((int) $command->getKey());
        $this->assertNotNull($workerB);
        app(ProcessExecutionFinalization::class)->process((int) $command->getKey(), $workerB->owner);

        try {
            app(ProcessExecutionFinalization::class)->process((int) $command->getKey(), $workerA->owner);
            $this->fail('El worker A debía perder definitivamente su lease.');
        } catch (ExecutionCommandLeaseLost) {
            $this->assertTrue(true);
        }

        while ($command->fresh()->processed_at === null) {
            (new RunExecutionUnit((int) $command->getKey()))->handle(
                app(ExecutionProvider::class),
                app(ToolAdapter::class),
            );
        }

        app(GenerateFinalArtifacts::class)->cleanup($stagingA);
        app(FinalizationGarbageCollector::class)->collect(0);
        $execution->refresh();
        $this->assertSame(ExecutionStatus::COMPLETED, $execution->status);
        $this->assertSame(ProjectStatus::COMPLETED, $project->fresh()->status);
        $this->assertSame(4, $execution->artifacts()->count());
        $this->assertSame(1, $execution->events()->where('type', 'execution.completed')->count());
        $this->assertCount(4, Storage::disk('local')->allFiles("executions/{$execution->workspace_key}"));

        foreach ($execution->artifacts as $artifact) {
            $stored = new StoredArtifact($artifact->disk, $artifact->path, $artifact->size, $artifact->sha256);
            app(ArtifactStreamVerifier::class)->verify($stored);
        }

        Storage::disk('local')->deleteDirectory("executions/{$execution->workspace_key}");
    }

    public function test_concurrent_proposals_conflict_on_the_locked_version(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        [, $execution] = $this->reviewedCollectionExecution($admin);
        $base = [
            'operation' => 'RENAME_CATEGORY',
            'expected_version' => 0,
            'base_fingerprint' => $execution->review_fingerprint,
        ];
        $results = $this->runConcurrently([
            ['proposal-http', (int) $execution->getKey(), (int) $admin->getKey(), json_encode([
                'idempotency_key' => 'concurrent-proposal-a',
                'payload' => [...$base, 'node_id' => 'cat:collection-academic', 'value' => 'Nombre A'],
            ], JSON_THROW_ON_ERROR)],
            ['proposal-http', (int) $execution->getKey(), (int) $admin->getKey(), json_encode([
                'idempotency_key' => 'concurrent-proposal-b',
                'payload' => [...$base, 'node_id' => 'cat:collection-archive', 'value' => 'Nombre B'],
            ], JSON_THROW_ON_ERROR)],
        ]);

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['http_status'] === 201));
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['http_status'] === 422));
        $this->assertSame(1, $execution->academicProposals()->count());
        $this->assertSame(1, $execution->fresh()->proposal_version);
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

    /** @return array{Project, Execution} */
    private function reviewedCollectionExecution(User $admin): array
    {
        Queue::fake();
        $project = $this->readyCollectionProject($admin);
        $result = app(StartProjectExecution::class)->start(
            $project,
            $admin,
            'concurrent-review-'.Str::uuid(),
            $project->configuration->version,
        );
        $execution = $result->execution;

        foreach ([
            $execution->commands()->where('command_type', 'START')->sole(),
            null,
            null,
        ] as $index => $command) {
            if ($index === 1) {
                $command = $execution->commands()->where('command_type', 'CONTINUE')->sole();
            } elseif ($index === 2) {
                $command = $execution->commands()->where('command_type', 'VALIDATE')->sole();
            }

            (new RunExecutionUnit((int) $command->getKey()))->handle(
                app(ExecutionProvider::class),
                app(ToolAdapter::class),
            );
        }

        $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);

        return [$project, $execution->fresh()];
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
