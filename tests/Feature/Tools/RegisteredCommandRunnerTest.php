<?php

namespace Tests\Feature\Tools;

use App\Domain\Processes\RegisteredCommandRunner;
use App\Domain\Processes\RegisteredCommandRegistry;
use App\Domain\Executions\RemoteOperationCoordinator;
use App\Jobs\RunRegisteredRemoteOperation;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Domain\Executions\LocalProcessInspector;
use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Executions\LocalToolExecutionProvider;
use App\Enums\EventSeverity;
use App\Enums\LogStream;
use App\Models\ExecutionLog;
use App\Models\RemoteOperation;
use InvalidArgumentException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Domain\DomainTestCase;

class RegisteredCommandRunnerTest extends DomainTestCase
{
    private string $workspaceRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaceRoot = storage_path('framework/runner-tests/'.Str::uuid());
        config([
            'toolkit.features.local_runner.enabled' => true,
            'toolkit.workspaces.root' => $this->workspaceRoot,
            'toolkit.runner.max_output_bytes' => 4096,
            'toolkit.runner.enforce_os_limits' => false,
            'toolkit.runner.commands' => [
                'platform_health' => [
                    'executable' => PHP_BINARY,
                    'fixed_arguments' => ['-r', 'fwrite(STDOUT, "pass"."word="."private-value");'],
                    'parameters' => [],
                    'timeout' => 5,
                    'cancellable' => true,
                ],
                'platform_long' => [
                    'executable' => PHP_BINARY,
                    'fixed_arguments' => ['-r', 'usleep(5000000);'],
                    'parameters' => [],
                    'timeout' => 15,
                    'cancellable' => true,
                ],
                'platform_export' => [
                    'executable' => PHP_BINARY,
                    'fixed_arguments' => ['-r', 'exit(0);'],
                    'parameters' => ['source_id' => ['pattern' => '/^[a-z0-9_-]{1,40}$/D']],
                    'timeout' => 5,
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspaceRoot) && is_dir($this->workspaceRoot)) {
            File::deleteDirectory($this->workspaceRoot);
        }
        parent::tearDown();
    }

    public function test_only_registered_argv_runs_and_secrets_are_redacted_from_output(): void
    {
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);
        $result = app(RegisteredCommandRunner::class)->run($execution, 'platform_health');

        $this->assertTrue($result->successful());
        $this->assertGreaterThan(0, $result->processId);
        $this->assertStringContainsString('password=[REDACTED]', $result->stdout);
        $this->assertStringNotContainsString('private-value', $result->stdout);
    }

    public function test_registered_argument_schema_rejects_shell_syntax(): void
    {
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);

        $this->expectException(InvalidArgumentException::class);
        app(RegisteredCommandRunner::class)->run($execution, 'platform_export', ['source_id' => 'valid; touch injected']);
    }

    public function test_artifact_descriptor_requires_a_typed_category_mime_size_and_optional_hash(): void
    {
        $expectedHash = hash('sha256', 'declared artifact');
        config(['toolkit.runner.commands.platform_artifacts' => [
            'executable' => PHP_BINARY,
            'fixed_arguments' => ['-r', 'exit(0);'],
            'parameters' => [],
            'artifact_descriptors' => [[
                'relative_path' => 'output/report.txt',
                'category' => 'REPORT',
                'name' => 'report.txt',
                'mime_types' => ['text/plain'],
                'max_size_bytes' => 1024,
                'expected_sha256' => $expectedHash,
                'required' => true,
                'sensitivity' => 'INTERNAL',
            ]],
        ]]);

        $resolved = app(RegisteredCommandRegistry::class)->resolve('platform_artifacts');
        $this->assertSame($expectedHash, $resolved['artifact_descriptors'][0]['expected_sha256']);

        config(['toolkit.runner.commands.platform_artifacts.artifact_descriptors.0.mime_types' => ['text/*']]);
        $this->expectException(InvalidArgumentException::class);
        app(RegisteredCommandRegistry::class)->resolve('platform_artifacts');
    }

    public function test_command_parameters_cannot_smuggle_a_secret_under_a_generic_name(): void
    {
        config(['toolkit.runner.commands.platform_generic_parameter' => [
            'executable' => PHP_BINARY,
            'fixed_arguments' => ['-r', 'exit(0);'],
            'parameters' => ['input' => ['pattern' => '/^.{1,80}$/D']],
        ]]);

        $this->expectException(InvalidArgumentException::class);
        app(RegisteredCommandRegistry::class)->resolve('platform_generic_parameter', ['input' => 'token=private-value']);
    }

    public function test_operation_identity_prevents_a_second_process_after_a_successful_command(): void
    {
        config(['queue.default' => 'sync']);
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);
        $coordinator = app(RemoteOperationCoordinator::class);
        $first = $coordinator->schedule($execution, 'start-health-check', 'platform_health');
        $first = $this->waitForTerminal($first);
        $second = $coordinator->schedule($execution, 'start-health-check', 'platform_health');

        $this->assertSame($first->operation_uuid, $second->operation_uuid);
        $this->assertSame(1, RemoteOperation::query()->where('execution_id', $execution->getKey())->count());
        $this->assertSame('TERMINATED', $second->communication_state->value);
        $this->assertSame('SUCCEEDED', $second->functional_state->value);
        $this->assertSame(0, $second->exit_code);
    }

    public function test_detached_supervisor_rejects_a_changed_registered_command_definition(): void
    {
        Queue::fake();
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);
        $coordinator = app(RemoteOperationCoordinator::class);
        $operation = $coordinator->schedule($execution, 'definition-pinned', 'platform_health');
        $definition = app(RegisteredCommandRegistry::class)->resolve('platform_health');
        $definition['argv'][0] = '/bin/true';

        try {
            $coordinator->runDetached((int) $operation->getKey(), 'platform_health', [], '', $definition);
            $this->fail('The detached supervisor must not execute a changed registry definition.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('rechazó una identidad', $exception->getMessage());
            $this->assertNull($operation->fresh()->process_id);
        }
    }

    public function test_cancelling_a_queued_operation_prevents_the_job_from_starting_it(): void
    {
        Queue::fake();
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);
        $coordinator = app(RemoteOperationCoordinator::class);
        $operation = $coordinator->schedule($execution, 'cancel-before-launch', 'platform_health');

        $cancelled = $coordinator->cancel($operation);
        (new RunRegisteredRemoteOperation((int) $operation->getKey(), 'platform_health', [], ''))
            ->handle($coordinator);

        $operation->refresh();
        $this->assertSame('TERMINATED', $cancelled->communication_state->value);
        $this->assertSame('CANCELLED', $operation->functional_state->value);
        $this->assertNull($operation->process_id);
        $this->assertArrayHasKey('cancel_requested_at', $operation->evidence);
    }

    public function test_non_cancellable_registered_operation_rejects_granular_cancel(): void
    {
        config(['toolkit.runner.commands.platform_health.cancellable' => false]);
        Queue::fake();
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);
        $operation = app(RemoteOperationCoordinator::class)->schedule($execution, 'non-cancellable', 'platform_health');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no es cancelable');
        app(RemoteOperationCoordinator::class)->cancel($operation);
    }

    public function test_linux_runner_applies_operating_system_resource_limits(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('prlimit solo se instala en el contenedor Linux del runner.');
        }

        config([
            'toolkit.runner.enforce_os_limits' => true,
            'toolkit.runner.limit_wrapper' => '/usr/bin/prlimit',
        ]);
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);
        $result = app(RegisteredCommandRunner::class)->run($execution, 'platform_health');

        $this->assertTrue($result->successful());
    }

    public function test_registered_command_timeout_kills_a_process_that_ignores_sigterm(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('pcntl_signal')) {
            $this->markTestSkipped('La regresión necesita señales POSIX.');
        }

        config([
            'toolkit.runner.allow_force_kill' => false,
            'toolkit.runner.commands.platform_ignores_term' => [
                'executable' => PHP_BINARY,
                'fixed_arguments' => ['-r', 'pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN); while (true) { usleep(100000); }'],
                'parameters' => [],
                'timeout' => 1,
            ],
        ]);
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);
        $startedAt = microtime(true);

        $result = app(RegisteredCommandRunner::class)->run($execution, 'platform_ignores_term');

        $this->assertTrue($result->timedOut);
        $this->assertSame(124, $result->exitCode);
        $this->assertLessThan(8, microtime(true) - $startedAt, 'A configured timeout must bound the supervisor even when the child ignores SIGTERM.');
        $this->assertFalse($this->processIsExecuting((int) $result->processId));
    }

    public function test_launch_job_is_short_and_returns_while_the_process_group_keeps_running(): void
    {
        $this->assertLessThanOrEqual(120, (new RunRegisteredRemoteOperation(1, 'platform_long', [], ''))->timeout);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('El supervisor local requiere Linux y setsid.');
        }

        Queue::fake();
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);
        $operation = app(RemoteOperationCoordinator::class)->schedule($execution, 'long-operation', 'platform_long');
        $startedAt = microtime(true);
        (new RunRegisteredRemoteOperation((int) $operation->getKey(), 'platform_long', [], ''))
            ->handle(app(RemoteOperationCoordinator::class));
        $elapsed = microtime(true) - $startedAt;

        $operation->refresh();
        $this->assertLessThan(4.5, $elapsed, 'Laravel must release its worker before the registered process finishes.');
        $this->assertSame('RUNNING', $operation->functional_state->value);
        $this->assertTrue(app(LocalProcessInspector::class)->isRunning($operation));
        $this->assertSame('TERMINATED', $this->waitForTerminal($operation, 15)->communication_state->value);
    }

    public function test_unreachable_operation_is_recovered_when_its_verified_process_is_alive(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('El supervisor local requiere Linux y setsid.');
        }
        Queue::fake();
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);
        $coordinator = app(RemoteOperationCoordinator::class);
        $operation = $coordinator->schedule($execution, 'unreachable-recovery', 'platform_long');
        (new RunRegisteredRemoteOperation((int) $operation->getKey(), 'platform_long', [], ''))->handle($coordinator);
        $operation->refresh()->forceFill([
            'communication_state' => 'UNREACHABLE',
            'functional_state' => 'UNKNOWN',
            'next_poll_at' => now()->utc(),
        ])->save();

        $recovered = $coordinator->reconcile($operation);

        $this->assertSame('CONNECTED', $recovered->communication_state->value);
        $this->assertSame('RUNNING', $recovered->functional_state->value);
        $coordinator->cancel($recovered);
        $this->waitForTerminal($recovered);
    }

    public function test_unreachable_operation_uses_valid_exit_evidence_to_recover_terminal_state(): void
    {
        Queue::fake();
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);
        $coordinator = app(RemoteOperationCoordinator::class);
        $operation = $coordinator->schedule($execution, 'exit-evidence-recovery', 'platform_health');
        (new RunRegisteredRemoteOperation((int) $operation->getKey(), 'platform_health', [], ''))->handle($coordinator);
        $operation = $this->waitForTerminal($operation);
        $exitPath = app(\App\Domain\Workspaces\ExecutionWorkspaceManager::class)
            ->operationEvidencePath($execution, $operation->operation_uuid, 'exit.json');
        $exitEvidence = json_decode((string) file_get_contents($exitPath), true);
        $this->assertIsArray($exitEvidence);
        $this->assertSame($operation->operation_uuid, $exitEvidence['operation_uuid']);
        $this->assertArrayHasKey('stdout_sha256', $exitEvidence);
        $operation->forceFill([
            'communication_state' => 'UNREACHABLE',
            'functional_state' => 'UNKNOWN',
            'next_poll_at' => now()->utc(),
        ])->save();

        $recovered = $coordinator->reconcile($operation);

        $this->assertSame('TERMINATED', $recovered->communication_state->value);
        $this->assertSame('SUCCEEDED', $recovered->functional_state->value);
    }

    public function test_events_and_logs_are_scoped_to_the_requested_remote_operation(): void
    {
        $execution = $this->execution($this->project());
        $first = $this->remoteOperation($execution, 'operation-one');
        $second = $this->remoteOperation($execution, 'operation-two');
        $recorder = app(ExecutionEventRecorder::class);
        $firstEvent = $recorder->record($execution, 'first', severity: EventSeverity::INFO, message: 'first event', operation: $first);
        $recorder->record($execution, 'second', severity: EventSeverity::INFO, message: 'second event', operation: $second);
        ExecutionLog::query()->create([
            'execution_id' => $execution->getKey(), 'remote_operation_id' => $first->getKey(),
            'stream' => LogStream::STDOUT, 'level' => 'INFO', 'message' => 'first log', 'logged_at' => now()->utc(),
        ]);
        ExecutionLog::query()->create([
            'execution_id' => $execution->getKey(), 'remote_operation_id' => $second->getKey(),
            'stream' => LogStream::STDOUT, 'level' => 'INFO', 'message' => 'second log', 'logged_at' => now()->utc(),
        ]);
        $provider = app(LocalToolExecutionProvider::class);

        $this->assertSame([$firstEvent->sequence], array_column($provider->readEvents($first), 'sequence'));
        $this->assertSame([], $provider->readEvents($first, $firstEvent->sequence));
        $this->assertSame(['first log'], array_column($provider->readLogs($first), 'message'));
    }

    public function test_supervisor_terminates_parent_and_child_in_the_registered_process_group(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('La regresión necesita pcntl y grupos POSIX.');
        }
        $pidPath = $this->workspaceRoot.DIRECTORY_SEPARATOR.'child-process.pid';
        $literalPath = var_export($pidPath, true);
        $code = '$child=pcntl_fork(); if($child===0){file_put_contents('.$literalPath.',(string)getmypid()); while(true){usleep(100000);}} while(true){usleep(100000);}';
        config(['toolkit.runner.commands.platform_tree' => [
            'executable' => PHP_BINARY,
            'fixed_arguments' => ['-r', $code],
            'parameters' => [],
            'timeout' => 20,
            'cancellable' => true,
        ]]);
        Queue::fake();
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution);
        $coordinator = app(RemoteOperationCoordinator::class);
        $operation = $coordinator->schedule($execution, 'parent-child-cancel', 'platform_tree');
        (new RunRegisteredRemoteOperation((int) $operation->getKey(), 'platform_tree', [], ''))->handle($coordinator);
        $deadline = microtime(true) + 5;
        do {
            clearstatcache(true, $pidPath);
            if (is_file($pidPath)) {
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        $this->assertFileExists($pidPath);
        $childPid = (int) file_get_contents($pidPath);
        $operation = $operation->fresh();
        $this->assertNotNull($operation->process_group_id);

        $coordinator->cancel($operation);
        $this->waitForTerminal($operation, 10);
        $this->assertSame('CANCELLED', $operation->fresh()->functional_state->value);
        $this->assertFalse($this->processIsExecuting($childPid), 'SIGTERM must stop descendants in the same process group.');
    }

    public function test_process_inspector_rejects_a_reused_pid_with_another_operation_marker(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill')) {
            $this->markTestSkipped('La regresión necesita /proc y señales POSIX.');
        }
        $setsid = (string) config('toolkit.runner.session_wrapper', '/usr/bin/setsid');
        if (! is_file($setsid) || ! is_executable($setsid)) {
            $this->markTestSkipped('La regresión requiere setsid.');
        }
        $execution = $this->execution($this->project());
        $expectedUuid = (string) Str::uuid();
        $commandHash = hash('sha256', 'pid-reuse-test');
        $pipes = [];
        $process = proc_open(
            [$setsid, PHP_BINARY, '-r', 'while (true) { usleep(100000); }'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes,
            base_path(),
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'MOODLE_OPERATION_ID' => 'different-operation', 'MOODLE_COMMAND_SHA256' => $commandHash],
            ['bypass_shell' => true],
        );
        $this->assertIsResource($process);

        try {
            $status = proc_get_status($process);
            $pid = (int) $status['pid'];
            $deadline = microtime(true) + 3;
            while ($pid < 2 && microtime(true) < $deadline) {
                usleep(25_000);
                $status = proc_get_status($process);
                $pid = (int) $status['pid'];
            }
            $stat = (string) file_get_contents('/proc/'.$pid.'/stat');
            $closingParen = strrpos($stat, ')');
            $fields = $closingParen === false ? [] : (preg_split('/\s+/', trim(substr($stat, $closingParen + 1))) ?: []);
            $this->assertGreaterThan(1, $pid);
            $this->assertMatchesRegularExpression('/^\d+$/D', (string) ($fields[19] ?? ''));
            $operation = $this->remoteOperation($execution, 'pid-reuse-operation')->forceFill([
                'operation_uuid' => $expectedUuid,
                'command_sha256' => $commandHash,
                'process_id' => (string) $pid,
                'process_group_id' => (string) $pid,
                'process_start_identity' => (string) ($fields[19] ?? ''),
            ]);

            $inspector = app(LocalProcessInspector::class);
            $this->assertFalse($inspector->isRunning($operation));
            $this->assertFalse($inspector->terminate($operation));
        } finally {
            if (isset($pid) && $pid > 1) {
                @posix_kill(-$pid, SIGKILL);
            }
            proc_close($process);
        }
    }

    private function approveCapacity(\App\Models\Execution $execution): void
    {
        app(ApproveExecutionCapacity::class)->approve($execution, 32 * 1024 * 1024, 10, $execution->creator);
    }

    private function waitForTerminal(RemoteOperation $operation, int $timeoutSeconds = 10): RemoteOperation
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $coordinator = app(RemoteOperationCoordinator::class);
        do {
            $operation->refresh();
            if ($operation->communication_state->value === 'TERMINATED') {
                return $operation;
            }
            $operation = $coordinator->reconcile($operation);
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail('La evidencia terminal durable no apareció antes del timeout de la prueba.');
    }

    private function processIsExecuting(int $pid): bool
    {
        if ($pid < 2 || ! is_file('/proc/'.$pid.'/stat')) {
            return false;
        }
        $stat = (string) file_get_contents('/proc/'.$pid.'/stat');
        $closingParen = strrpos($stat, ')');
        $fields = $closingParen === false ? [] : (preg_split('/\s+/', trim(substr($stat, $closingParen + 1))) ?: []);

        return ($fields[0] ?? null) !== 'Z';
    }

    private function remoteOperation(\App\Models\Execution $execution, string $key): RemoteOperation
    {
        return RemoteOperation::query()->create([
            'execution_id' => $execution->getKey(),
            'operation_uuid' => (string) Str::uuid(),
            'idempotency_key' => $key,
            'provider_key' => 'local-registered-process',
            'host_id' => gethostname() ?: 'local',
            'runtime_key' => 'workspace-process-v2',
            'command_key' => 'platform_health',
            'command_sha256' => hash('sha256', $key),
            'communication_state' => 'RECONCILING',
            'functional_state' => 'STARTING',
            'next_poll_at' => now()->utc(),
        ]);
    }
}
