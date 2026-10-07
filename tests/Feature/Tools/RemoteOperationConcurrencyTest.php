<?php

namespace Tests\Feature\Tools;

use App\Domain\Executions\LocalProcessInspector;
use App\Domain\Executions\RemoteOperationCoordinator;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Models\Execution;
use App\Models\Project;
use App\Models\RemoteOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class RemoteOperationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private string $workspaceRoot;

    private Execution $execution;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->workspaceRoot = storage_path('framework/runner-concurrency-tests/'.Str::uuid());
        File::ensureDirectoryExists($this->workspaceRoot, 0700, true);
        config([
            'toolkit.features.local_runner.enabled' => true,
            'toolkit.workspaces.root' => $this->workspaceRoot,
            'toolkit.runner.enforce_os_limits' => false,
            'toolkit.runner.cancel_grace_seconds' => 2,
            'toolkit.runner.commands' => [
                'platform_concurrent' => [
                    'executable' => PHP_BINARY,
                    'fixed_arguments' => ['-r', 'usleep(15000000);'],
                    'parameters' => [],
                    'timeout' => 20,
                    'cancellable' => true,
                ],
            ],
        ]);

        $creator = User::factory()->create(['role' => UserRole::ADMIN, 'is_active' => true]);
        $project = Project::query()->create([
            'name' => 'Concurrency test',
            'type' => ProjectType::CONSOLIDATE,
            'status' => ProjectStatus::DRAFT,
            'created_by' => $creator->getKey(),
        ]);
        $this->execution = Execution::query()->create([
            'project_id' => $project->getKey(),
            'attempt' => 1,
            'status' => ExecutionStatus::QUEUED,
            'created_by' => $creator->getKey(),
        ]);
        app(ApproveExecutionCapacity::class)->approve($this->execution, 32 * 1024 * 1024, 10, $creator);
    }

    protected function tearDown(): void
    {
        if (isset($this->execution)) {
            foreach ($this->execution->remoteOperations()->get() as $operation) {
                app(LocalProcessInspector::class)->terminate($operation);
            }
        }
        if (isset($this->workspaceRoot) && is_dir($this->workspaceRoot)) {
            File::deleteDirectory($this->workspaceRoot);
        }
        parent::tearDown();
    }

    public function test_concurrent_launch_claims_create_only_one_registered_process(): void
    {
        $this->requireLinuxProcessSupport();
        $operation = $this->newOperation('parallel-launch');
        $jobs = [
            fn () => app(RemoteOperationCoordinator::class)->runScheduled((int) $operation->getKey(), 'platform_concurrent', [], ''),
            fn () => app(RemoteOperationCoordinator::class)->runScheduled((int) $operation->getKey(), 'platform_concurrent', [], ''),
        ];

        $this->runConcurrently($jobs, 'parallel-launch');
        $operation = $this->waitForProcess($operation);

        $this->assertNotNull($operation->process_id);
        $this->assertSame(1, count($this->processesMarkedFor($operation)));
        $this->stopAndConfirm($operation);
    }

    public function test_two_reconcilers_serialize_updates_for_one_operation(): void
    {
        $this->requireLinuxProcessSupport();
        $operation = $this->newOperation('parallel-reconcile');
        app(RemoteOperationCoordinator::class)->runScheduled((int) $operation->getKey(), 'platform_concurrent', [], '');
        $operation = $this->waitForProcess($operation);
        $jobs = [
            fn () => app(RemoteOperationCoordinator::class)->reconcile(RemoteOperation::query()->findOrFail($operation->getKey())),
            fn () => app(RemoteOperationCoordinator::class)->reconcile(RemoteOperation::query()->findOrFail($operation->getKey())),
        ];

        $this->runConcurrently($jobs, 'parallel-reconcile');
        $operation->refresh();

        $this->assertSame('CONNECTED', $operation->communication_state->value);
        $this->assertSame('RUNNING', $operation->functional_state->value);
        $this->assertSame(1, count($this->processesMarkedFor($operation)));
        $this->stopAndConfirm($operation);
    }

    private function newOperation(string $key): RemoteOperation
    {
        return app(RemoteOperationCoordinator::class)->schedule($this->execution, $key, 'platform_concurrent');
    }

    /** @param list<\Closure():mixed> $jobs */
    private function runConcurrently(array $jobs, string $label): void
    {
        if (function_exists('pcntl_fork') === false || function_exists('stream_socket_pair') === false) {
            $this->markTestSkipped('Las regresiones concurrentes requieren pcntl y sockets UNIX.');
        }
        DB::purge();
        $children = [];
        $barriers = [];
        foreach ($jobs as $index => $job) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                $this->fail('No se pudo crear la barrera de inicio de los procesos de prueba.');
            }
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('No se pudo iniciar un proceso de prueba concurrente.');
            }
            if ($pid === 0) {
                fclose($pair[0]);
                fread($pair[1], 1);
                fclose($pair[1]);
                DB::purge();
                try {
                    DB::reconnect();
                    $job();
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($this->workspaceRoot.DIRECTORY_SEPARATOR.$label.'-'.$index.'.error', $exception->getMessage());
                    exit(1);
                }
            }
            fclose($pair[1]);
            $children[] = ['pid' => $pid, 'index' => $index];
            $barriers[] = $pair[0];
        }
        foreach ($barriers as $barrier) {
            fwrite($barrier, '1');
            fclose($barrier);
        }
        foreach ($children as $child) {
            $pid = $child['pid'];
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status), "Proceso concurrente terminó de forma inesperada: {$label}.");
            $errorPath = $this->workspaceRoot.DIRECTORY_SEPARATOR.$label.'-'.$child['index'].'.error';
            $this->assertSame(0, pcntl_wexitstatus($status), (string) @file_get_contents($errorPath));
        }
        DB::reconnect();
    }

    private function waitForProcess(RemoteOperation $operation): RemoteOperation
    {
        $deadline = microtime(true) + 8;
        do {
            $operation->refresh();
            if ($operation->process_id !== null || $operation->communication_state->value === 'TERMINATED') {
                return $operation;
            }
            app(RemoteOperationCoordinator::class)->reconcile($operation);
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail('El proceso registrado no recibió identidad durante la ventana de concurrencia.');
    }

    /** @return list<int> */
    private function processesMarkedFor(RemoteOperation $operation): array
    {
        $matches = [];
        foreach (glob('/proc/[0-9]*/environ') ?: [] as $path) {
            $environment = @file_get_contents($path);
            if (is_string($environment)
                && str_contains($environment, 'MOODLE_OPERATION_ID='.$operation->operation_uuid."\0")
                && str_contains($environment, 'MOODLE_COMMAND_SHA256='.$operation->command_sha256."\0")
            ) {
                $matches[] = (int) basename(dirname($path));
            }
        }

        return $matches;
    }

    private function stopAndConfirm(RemoteOperation $operation): void
    {
        $coordinator = app(RemoteOperationCoordinator::class);
        $coordinator->cancel($operation->fresh());
        $deadline = microtime(true) + 8;
        do {
            $operation->refresh();
            if ($operation->communication_state->value === 'TERMINATED') {
                return;
            }
            $coordinator->reconcile($operation);
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail('La cancelación concurrente no produjo evidencia terminal durable.');
    }

    private function requireLinuxProcessSupport(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || function_exists('pcntl_fork') === false || function_exists('posix_kill') === false) {
            $this->markTestSkipped('La prueba multiproceso requiere Linux, pcntl y señales POSIX.');
        }
        $setsid = (string) config('toolkit.runner.session_wrapper', '/usr/bin/setsid');
        if (is_file($setsid) === false || is_executable($setsid) === false) {
            $this->markTestSkipped('La prueba multiproceso requiere setsid.');
        }
    }
}
