<?php

namespace Tests\Feature\Tools;

use App\Domain\Executions\LocalProcessInspector;
use App\Domain\Executions\RemoteOperationCoordinator;
use App\Domain\Processes\RegisteredCommandRegistry;
use App\Domain\Processes\RegisteredCommandRunner;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\Execution;
use App\Models\RemoteOperation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Domain\DomainFixtures;
use Tests\TestCase;

class StreamingCommandRunnerTest extends TestCase
{
    use DatabaseMigrations;
    use DomainFixtures;

    private string $workspaceRoot;

    private array $executions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->workspaceRoot = storage_path('framework/streaming-tests/'.Str::uuid());
        config([
            'toolkit.features.local_runner.enabled' => true,
            'toolkit.workspaces.root' => $this->workspaceRoot,
            'toolkit.runner.max_output_bytes' => 1024,
            'toolkit.runner.durable_log_max_bytes' => 1024,
            'toolkit.runner.enforce_os_limits' => false,
            'toolkit.runner.synthetic_profile' => true,
            'toolkit.runner.commands' => require base_path('tests/Support/registered-command-fixtures.php'),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->executions as $execution) {
            foreach ($execution->remoteOperations()->get() as $operation) {
                app(LocalProcessInspector::class)->terminate($operation);
            }
        }
        if (isset($this->workspaceRoot) && is_dir($this->workspaceRoot)) {
            File::deleteDirectory($this->workspaceRoot);
        }
        parent::tearDown();
    }

    #[DataProvider('fragmentedSecrets')]
    public function test_fragmented_secret_never_reaches_the_result_or_durable_streams(string $scenario): void
    {
        $this->requireLinux();
        $execution = $this->approvedExecution();
        $uuid = (string) Str::uuid();
        $result = app(RegisteredCommandRunner::class)->run($execution, 'platform_streaming', ['scenario' => $scenario], operationUuid: $uuid);

        $this->assertTrue($result->successful());
        $this->assertStringNotContainsString('private-value', $result->stdout.$result->stderr);
        foreach (['stdout', 'stderr'] as $stream) {
            $path = app(ExecutionWorkspaceManager::class)->operationLogPath($execution, $uuid, $stream);
            $this->assertStringNotContainsString('private-value', (string) file_get_contents($path), 'Durable '.$stream.' exposed a fragmented secret.');
        }
        $this->assertNoSecret($execution, $uuid);
    }

    public static function fragmentedSecrets(): array
    {
        return array_map(fn (string $scenario): array => [$scenario], ['split-key', 'split-value', 'authorization', 'url', 'pem', 'independent', 'pending', 'json', 'cookie', 'unterminated-pem', 'unframed', 'ambiguous-quoted', 'ambiguous-structured', 'ambiguous-empty', 'ambiguous-overflow', 'ambiguous-pem']);
    }

    #[DataProvider('fragmentedSecrets')]
    public function test_fragmented_secret_is_absent_from_terminal_evidence_and_derived_logs(string $scenario): void
    {
        $this->requireLinux();
        $execution = $this->approvedExecution();
        $operation = $this->awaitTerminal($this->launch($execution, $scenario));
        $this->assertSame('SUCCEEDED', $operation->functional_state->value);
        $this->assertNoSecret($execution, $operation->operation_uuid);
    }

    public function test_fast_burst_is_durably_bounded_without_waiting_for_a_heartbeat(): void
    {
        $this->requireLinux();
        $execution = $this->approvedExecution();
        $uuid = (string) Str::uuid();
        $heartbeats = 0;
        $result = app(RegisteredCommandRunner::class)->run(
            $execution, 'platform_streaming', ['scenario' => 'burst'], operationUuid: $uuid,
            onHeartbeat: function () use (&$heartbeats): void {
                $heartbeats++;
            },
        );
        $this->assertTrue($result->successful());
        $this->assertTrue($result->outputTruncated);
        $this->assertLessThanOrEqual(1024, strlen($result->stdout));
        $this->assertSame(0, $heartbeats);
        $path = app(ExecutionWorkspaceManager::class)->operationLogPath($execution, $uuid, 'stdout');
        $this->assertLessThanOrEqual(1024, filesize($path), 'The durable file must be bounded before the periodic heartbeat.');
    }

    #[DataProvider('boundaryScenarios')]
    public function test_file_boundary_accounting_and_hashes_match_exact_persisted_bytes(string $scenario, int $observed): void
    {
        $this->requireLinux();
        $execution = $this->approvedExecution();
        $uuid = (string) Str::uuid();
        $result = app(RegisteredCommandRunner::class)->run($execution, 'platform_streaming', ['scenario' => $scenario], operationUuid: $uuid);
        $this->assertTrue($result->successful());
        $this->assertSame($observed, $result->stdoutObservedBytes);
        $this->assertSame(1024, $result->durableOutputLimitBytes);
        foreach (['stdout', 'stderr'] as $stream) {
            $path = app(ExecutionWorkspaceManager::class)->operationLogPath($execution, $uuid, $stream);
            $this->assertSame(filesize($path), $result->{$stream.'PersistedBytes'});
            $this->assertSame(hash_file('sha256', $path), $result->{$stream.'Sha256'});
            $this->assertLessThanOrEqual(1024, filesize($path));
        }
        $contents = (string) file_get_contents(app(ExecutionWorkspaceManager::class)->operationLogPath($execution, $uuid, 'stdout'));
        $this->assertSame($observed > 1024 ? 1 : 0, substr_count($contents, '[OUTPUT TRUNCATED: durable limit reached]'));
    }

    public static function boundaryScenarios(): array
    {
        return [['limit-below', 1023], ['limit-equal', 1024], ['limit-over', 1025], ['burst', 8192]];
    }

    public function test_both_pipes_keep_draining_and_finish_after_truncation_and_tampering_is_rejected(): void
    {
        $this->requireLinux();
        $execution = $this->approvedExecution();
        $operation = $this->awaitTerminal($this->launch($execution, 'burst-both'));
        $exit = $operation->evidence['exit_evidence'];
        $this->assertSame('SUCCEEDED', $operation->functional_state->value);
        foreach (['stdout', 'stderr'] as $stream) {
            $path = app(ExecutionWorkspaceManager::class)->operationLogPath($execution, $operation->operation_uuid, $stream);
            $this->assertSame(262144, $exit[$stream.'_observed_bytes']);
            $this->assertSame(1024, $exit[$stream.'_persisted_bytes']);
            $this->assertTrue($exit[$stream.'_truncated']);
            $this->assertSame(hash_file('sha256', $path), $exit[$stream.'_sha256']);
            $this->assertSame(1, substr_count((string) file_get_contents($path), '[OUTPUT TRUNCATED: durable limit reached]'));
        }
        $this->assertFileExists($this->marker($execution, $operation, 'completed'));
        $stdout = app(ExecutionWorkspaceManager::class)->operationLogPath($execution, $operation->operation_uuid, 'stdout');
        file_put_contents($stdout, 'altered');
        $operation->forceFill(['communication_state' => 'UNREACHABLE', 'functional_state' => 'UNKNOWN', 'terminated_at' => null])->save();
        $operation = app(RemoteOperationCoordinator::class)->reconcile($operation);
        $this->assertSame('UNREACHABLE', $operation->communication_state->value);
        $this->assertSame('UNKNOWN', $operation->functional_state->value);
    }

    public function test_previous_exit_evidence_without_accounting_fields_can_still_reconcile(): void
    {
        $this->requireLinux();
        $execution = $this->approvedExecution();
        $operation = $this->awaitTerminal($this->launch($execution, 'pending'));
        $path = app(ExecutionWorkspaceManager::class)->operationEvidencePath($execution, $operation->operation_uuid, 'exit.json');
        $exit = json_decode((string) file_get_contents($path), true);
        $exit['schema_version'] = 'remote-operation-exit.v1';
        foreach (['stdout_observed_bytes', 'stderr_observed_bytes', 'stdout_persisted_bytes', 'stderr_persisted_bytes', 'stdout_truncated', 'stderr_truncated', 'durable_output_limit_bytes'] as $key) {
            unset($exit[$key]);
        }
        file_put_contents($path, json_encode($exit));
        $operation->forceFill(['communication_state' => 'UNREACHABLE', 'functional_state' => 'UNKNOWN', 'terminated_at' => null])->save();
        $recovered = app(RemoteOperationCoordinator::class)->reconcile($operation);
        $this->assertSame('SUCCEEDED', $recovered->functional_state->value);
        $this->assertSame('TERMINATED', $recovered->communication_state->value);
    }

    public function test_two_concurrent_operations_keep_buffers_and_limits_independent(): void
    {
        $this->requireLinux();
        $execution = $this->approvedExecution();
        $first = $this->launch($execution, 'held-fragment');
        $this->awaitMarker($execution, $first);
        config(['toolkit.runner.durable_log_max_bytes' => 2048]);
        $second = $this->launch($execution, 'held-fragment');
        $this->awaitMarker($execution, $second);
        $this->assertTrue(app(LocalProcessInspector::class)->isRunning($first->fresh()));
        $this->assertTrue(app(LocalProcessInspector::class)->isRunning($second->fresh()));
        file_put_contents($this->marker($execution, $first, 'release'), 'go');
        file_put_contents($this->marker($execution, $second, 'release'), 'go');
        foreach ([[$first, 1024], [$second, 2048]] as [$operation, $limit]) {
            $finished = $this->awaitTerminal($operation);
            $this->assertSame('SUCCEEDED', $finished->functional_state->value);
            $this->assertSame($limit, $finished->evidence['exit_evidence']['durable_output_limit_bytes']);
            $this->assertNoSecret($execution, $operation->operation_uuid);
        }
    }

    public function test_cancel_after_both_streams_truncate_preserves_safe_terminal_evidence(): void
    {
        $this->requireLinux();
        $execution = $this->approvedExecution();
        $operation = $this->launch($execution, 'held-after-limit');
        $this->awaitMarker($execution, $operation);
        app(RemoteOperationCoordinator::class)->cancel($operation->fresh());
        $operation = $this->awaitTerminal($operation);
        $this->assertSame('CANCELLED', $operation->functional_state->value);
        $this->assertTrue($operation->evidence['exit_evidence']['stdout_truncated']);
        $this->assertTrue($operation->evidence['exit_evidence']['stderr_truncated']);
        $this->assertNoSecret($execution, $operation->operation_uuid);
    }

    public function test_truncated_streams_reconcile_after_the_launch_worker_is_killed(): void
    {
        $this->requireLinux();
        $execution = $this->approvedExecution();
        $operation = app(RemoteOperationCoordinator::class)->schedule($execution, 'killed-stream-worker', 'platform_streaming', ['scenario' => 'held-after-limit']);
        DB::purge();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            DB::reconnect();
            app(RemoteOperationCoordinator::class)->runScheduled((int) $operation->getKey(), 'platform_streaming', ['scenario' => 'held-after-limit'], '');
            posix_kill(getmypid(), SIGKILL);
            exit(1);
        }
        pcntl_waitpid($pid, $status);
        DB::reconnect();
        $this->assertTrue(pcntl_wifsignaled($status));
        $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        $this->awaitMarker($execution, $operation);
        file_put_contents($this->marker($execution, $operation, 'release'), 'go');
        $operation = $this->awaitTerminal($operation);
        $this->assertSame('SUCCEEDED', $operation->functional_state->value);
        $this->assertTrue($operation->evidence['exit_evidence']['stdout_truncated']);
        $this->assertTrue($operation->evidence['exit_evidence']['stderr_truncated']);
    }

    public function test_capacity_limits_and_signed_policy_cannot_be_bypassed(): void
    {
        $execution = $this->approvedExecution(128 * 1024);
        config(['toolkit.runner.durable_log_max_bytes' => 65536]);
        $manager = app(ExecutionWorkspaceManager::class);
        $workspace = $manager->prepare($execution);
        // The approval adds the fixture's explicit 10% margin to its estimate.
        $this->assertSame(144180, (int) $workspace->quota_bytes);
        $this->assertSame(0, $manager->measure($execution));
        $this->assertSame(39322, $manager->durableOutputLimit($execution, 65536, 65536));
        $manager->writeAtomic($execution, 'output', 'existing.bin', str_repeat('x', 1024));
        $this->assertSame(1024, $manager->measure($execution));
        $this->assertSame(38810, $manager->durableOutputLimit($execution, 65536, 65536));
        try {
            $manager->durableOutputLimit($execution, 256 * 1024, 256 * 1024);
            $this->fail('A command limit exceeding approved capacity must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('capacidad aprobada', $exception->getMessage());
        }
        app(RemoteOperationCoordinator::class)->schedule($execution, 'policy-bound', 'platform_streaming', ['scenario' => 'pending']);
        config(['toolkit.runner.durable_log_max_bytes' => 1024]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('otro comando');
        app(RemoteOperationCoordinator::class)->schedule($execution, 'policy-bound', 'platform_streaming', ['scenario' => 'pending']);
    }

    #[DataProvider('failureStages')]
    public function test_write_sync_and_terminal_evidence_errors_fail_closed(string $stage): void
    {
        $this->requireLinux();
        $execution = $this->approvedExecution();
        $manager = Mockery::mock(ExecutionWorkspaceManager::class)->makePartial();
        if ($stage === 'terminal') {
            $manager->shouldReceive('writeOperationEvidence')->withArgs(fn ($execution, $uuid, $name) => $name === 'exit.json')->andThrow(new RuntimeException('Synthetic terminal persistence failure.'));
        } else {
            $manager->shouldReceive('writeDurableLog')->andThrow(new RuntimeException('Synthetic '.$stage.' persistence failure.'));
        }
        app()->instance(ExecutionWorkspaceManager::class, $manager);
        $coordinator = app(RemoteOperationCoordinator::class);
        $operation = $coordinator->schedule($execution, 'write-error', 'platform_streaming', ['scenario' => 'independent']);
        try {
            $coordinator->runDetached((int) $operation->getKey(), 'platform_streaming', ['scenario' => 'independent'], '', app(RegisteredCommandRegistry::class)->resolve('platform_streaming', ['scenario' => 'independent']));
            $this->fail('A durable persistence failure must block success.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('private-value', $exception->getMessage());
            $this->assertSame('UNKNOWN', $operation->fresh()->functional_state->value);
            $this->assertFalse(app(LocalProcessInspector::class)->isRunning($operation->fresh()));
            $this->assertNoSecret($execution, $operation->operation_uuid);
        }
    }

    public static function failureStages(): array
    {
        return [['write'], ['sync'], ['terminal']];
    }

    public function test_nonzero_exit_keeps_secret_failure_logs_and_last_error_sanitized(): void
    {
        $this->requireLinux();
        $execution = $this->approvedExecution();
        $operation = $this->awaitTerminal($this->launch($execution, 'secret-failure'));
        $this->assertSame('FAILED', $operation->functional_state->value);
        $this->assertNoSecret($execution, $operation->operation_uuid);
    }

    public function test_log_creation_failure_keeps_the_identity_gate_closed(): void
    {
        $this->requireLinux();
        $execution = $this->approvedExecution();
        $uuid = (string) Str::uuid();
        $path = app(ExecutionWorkspaceManager::class)->operationLogPath($execution, $uuid, 'stdout');
        file_put_contents($path, 'existing safe data');
        $started = false;
        try {
            app(RegisteredCommandRunner::class)->run($execution, 'platform_streaming', ['scenario' => 'burst'], operationUuid: $uuid,
                onStarted: function () use (&$started): void {
                    $started = true;
                },
            );
            $this->fail('A durable log cannot overwrite an existing file.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('crear el log durable', $exception->getMessage());
            $this->assertFalse($started);
            $this->assertSame('existing safe data', file_get_contents($path));
        }
    }

    public function test_capacity_consumed_after_start_blocks_writing_and_stops_the_group(): void
    {
        $this->requireLinux();
        $quota = 128 * 1024;
        $execution = $this->approvedExecution($quota);
        $manager = app(ExecutionWorkspaceManager::class);
        $pid = null;
        $uuid = (string) Str::uuid();
        $result = app(RegisteredCommandRunner::class)->run($execution, 'platform_streaming', ['scenario' => 'burst'], operationUuid: $uuid,
            onStarted: function (int $processId) use ($execution, $manager, $quota, &$pid): void {
                $pid = $processId;
                $manager->writeAtomic($execution, 'output', 'competing-write.bin', str_repeat('x', $quota - $manager->measure($execution) - 32));
            },
        );
        $this->assertNotNull($pid);
        $this->assertFalse(is_file('/proc/'.$pid.'/stat'));
        $this->assertFalse($result->successful());
        $this->assertTrue($result->resourceLimitExceeded);
        $this->assertSame(125, $result->exitCode);
        $this->assertSame(0, $result->stdoutPersistedBytes);
        $this->assertSame(0, $result->stderrPersistedBytes);
        foreach (['stdout', 'stderr'] as $stream) {
            $path = $manager->operationLogPath($execution, $uuid, $stream);
            $this->assertSame('', file_get_contents($path));
            $this->assertSame(hash_file('sha256', $path), $stream === 'stdout' ? $result->stdoutSha256 : $result->stderrSha256);
        }
    }

    public function test_v2_evidence_cannot_fall_back_to_legacy_validation_when_fields_are_missing(): void
    {
        $this->requireLinux();
        $execution = $this->approvedExecution();
        $operation = $this->awaitTerminal($this->launch($execution, 'pending'));
        $path = app(ExecutionWorkspaceManager::class)->operationEvidencePath($execution, $operation->operation_uuid, 'exit.json');
        $exit = json_decode((string) file_get_contents($path), true);
        unset($exit['stdout_observed_bytes']);
        file_put_contents($path, json_encode($exit));
        $operation->forceFill(['communication_state' => 'UNREACHABLE', 'functional_state' => 'UNKNOWN', 'terminated_at' => null])->save();
        $operation = app(RemoteOperationCoordinator::class)->reconcile($operation);
        $this->assertSame('UNKNOWN', $operation->functional_state->value);
        $this->assertSame('UNREACHABLE', $operation->communication_state->value);
    }

    private function launch(Execution $execution, string $scenario): RemoteOperation
    {
        $operation = app(RemoteOperationCoordinator::class)->schedule($execution, 'stream-'.Str::uuid(), 'platform_streaming', ['scenario' => $scenario]);

        return app(RemoteOperationCoordinator::class)->runScheduled((int) $operation->getKey(), 'platform_streaming', ['scenario' => $scenario], '');
    }

    private function awaitTerminal(RemoteOperation $operation): RemoteOperation
    {
        $deadline = microtime(true) + 20;
        do {
            $operation = app(RemoteOperationCoordinator::class)->reconcile($operation->fresh());
            if ($operation->communication_state->value === 'TERMINATED') {
                return $operation;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        $this->fail('No terminal evidence: '.$operation->fresh()->last_error);
    }

    private function marker(Execution $execution, RemoteOperation $operation, string $name): string
    {
        return app(ExecutionWorkspaceManager::class)->resolve($execution, 'temporary', $name.'-'.$operation->operation_uuid);
    }

    private function awaitMarker(Execution $execution, RemoteOperation $operation): void
    {
        $path = $this->marker($execution, $operation, 'ready');
        $deadline = microtime(true) + 10;
        do {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return;
            }
            usleep(25_000);
        } while (microtime(true) < $deadline);
        $this->fail('The synthetic process did not reach its output barrier.');
    }

    private function assertNoSecret(Execution $execution, string $uuid): void
    {
        $modelEvidence = json_encode([$execution->logs()->get()->toArray(), $execution->events()->get()->toArray(), $execution->remoteOperations()->get()->toArray()]);
        $this->assertStringNotContainsString('private-value', $modelEvidence);
        $manager = app(ExecutionWorkspaceManager::class);
        foreach (['launch.json', 'heartbeat.json', 'exit.json', 'request.json'] as $name) {
            $path = $manager->operationEvidencePath($execution, $uuid, $name);
            if (is_file($path)) {
                $this->assertStringNotContainsString('private-value', (string) file_get_contents($path));
            }
        }
        foreach (['stdout', 'stderr'] as $name) {
            $path = $manager->operationLogPath($execution, $uuid, $name);
            if (is_file($path)) {
                $this->assertStringNotContainsString('private-value', (string) file_get_contents($path));
            }
        }
    }

    private function approvedExecution(int $quota = 32 * 1024 * 1024): Execution
    {
        $execution = $this->execution($this->project());
        app(ApproveExecutionCapacity::class)->approve($execution, $quota, 10, $execution->creator);
        $this->executions[] = $execution;

        return $execution;
    }

    private function requireLinux(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Streaming and durable pipe regressions require the Linux runtime.');
        }
    }
}
