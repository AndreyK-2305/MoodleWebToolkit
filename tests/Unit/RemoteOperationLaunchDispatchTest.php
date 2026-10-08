<?php

namespace Tests\Unit;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Executions\LocalProcessInspector;
use App\Domain\Executions\RemoteOperationCoordinator;
use App\Domain\Processes\RegisteredCommandRegistry;
use App\Domain\Processes\RegisteredCommandRunner;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Jobs\RunRegisteredRemoteOperation;
use App\Models\Execution;
use App\Models\RemoteOperation;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class RemoteOperationLaunchDispatchTest extends TestCase
{
    public function test_launch_queue_retries_after_the_short_job_timeout_within_a_bounded_window(): void
    {
        $timeout = (new RunRegisteredRemoteOperation(1, 'synthetic', [], ''))->timeout;
        $retryAfter = (int) config('queue.connections.redis-tool-runs.retry_after');
        $this->assertLessThanOrEqual(120, $timeout);
        $this->assertGreaterThan($timeout, $retryAfter);
        $this->assertLessThanOrEqual(180, $retryAfter);
    }

    public function test_execute_passes_the_persisted_identity_command_and_parameters_to_the_launcher(): void
    {
        config(['toolkit.features.local_runner.enabled' => true]);
        $operation = new RemoteOperation;
        $operation->id = 41;
        $parameters = ['source_id' => 'fixture'];
        $registry = Mockery::mock(RegisteredCommandRegistry::class);
        $registry->shouldReceive('resolve')->once()->with('platform_fixture', $parameters)->andReturn([
            'argv' => [PHP_BINARY, '-r', 'exit(0);'],
            'environment' => [],
            'timeout' => 5,
            'max_output_bytes' => 1024,
            'artifact_descriptors' => [],
            'cancellable' => false,
        ]);
        DB::shouldReceive('transaction')->once()->andReturn([$operation, true]);
        $coordinator = Mockery::mock(RemoteOperationCoordinator::class, [
            Mockery::mock(RegisteredCommandRunner::class),
            $registry,
            Mockery::mock(LocalProcessInspector::class),
            app(SensitiveValueRedactor::class),
            Mockery::mock(ExecutionWorkspaceManager::class),
        ])->makePartial();
        $coordinator->shouldReceive('runScheduled')->once()
            ->with(41, 'platform_fixture', $parameters, 'fixture-workspace')
            ->andReturn($operation);

        $this->assertSame($operation, $coordinator->execute(
            new Execution, 'launch-fixture', 'platform_fixture', $parameters, 'fixture-workspace',
        ));
    }
}
