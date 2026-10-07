<?php

namespace Tests\Feature\Tools;

use App\Domain\Processes\RegisteredCommandRunner;
use App\Domain\Executions\RemoteOperationCoordinator;
use App\Jobs\RunRegisteredRemoteOperation;
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
                    'fixed_arguments' => ['-r', 'fwrite(STDOUT, "password=private-value");'],
                    'parameters' => [],
                    'timeout' => 5,
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
        $result = app(RegisteredCommandRunner::class)->run($execution, 'platform_health');

        $this->assertTrue($result->successful());
        $this->assertGreaterThan(0, $result->processId);
        $this->assertStringContainsString('password=[REDACTED]', $result->stdout);
        $this->assertStringNotContainsString('private-value', $result->stdout);
    }

    public function test_registered_argument_schema_rejects_shell_syntax(): void
    {
        $execution = $this->execution($this->project());

        $this->expectException(InvalidArgumentException::class);
        app(RegisteredCommandRunner::class)->run($execution, 'platform_export', ['source_id' => 'valid; touch injected']);
    }

    public function test_operation_identity_prevents_a_second_process_after_a_successful_command(): void
    {
        config(['queue.default' => 'sync']);
        $execution = $this->execution($this->project());
        $coordinator = app(RemoteOperationCoordinator::class);
        $first = $coordinator->schedule($execution, 'start-health-check', 'platform_health');
        $second = $coordinator->schedule($execution, 'start-health-check', 'platform_health');

        $this->assertSame($first->operation_uuid, $second->operation_uuid);
        $this->assertSame(1, RemoteOperation::query()->where('execution_id', $execution->getKey())->count());
        $this->assertSame('TERMINATED', $second->communication_state->value);
        $this->assertSame('SUCCEEDED', $second->functional_state->value);
        $this->assertSame(0, $second->exit_code);
    }

    public function test_cancelling_a_queued_operation_prevents_the_job_from_starting_it(): void
    {
        Queue::fake();
        $execution = $this->execution($this->project());
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
        $result = app(RegisteredCommandRunner::class)->run($execution, 'platform_health');

        $this->assertTrue($result->successful());
    }
}
