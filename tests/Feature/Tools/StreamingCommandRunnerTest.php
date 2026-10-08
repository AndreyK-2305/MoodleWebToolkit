<?php

namespace Tests\Feature\Tools;

use App\Domain\Processes\RegisteredCommandRunner;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\Execution;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Domain\DomainFixtures;
use Tests\TestCase;

class StreamingCommandRunnerTest extends TestCase
{
    use DatabaseMigrations;
    use DomainFixtures;

    private string $workspaceRoot;

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    public static function fragmentedSecrets(): array
    {
        return array_map(fn (string $scenario): array => [$scenario], ['split-key', 'split-value', 'authorization', 'url', 'pem', 'independent']);
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

    private function approvedExecution(): Execution
    {
        $execution = $this->execution($this->project());
        app(ApproveExecutionCapacity::class)->approve($execution, 32 * 1024 * 1024, 10, $execution->creator);

        return $execution;
    }

    private function requireLinux(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Streaming and durable pipe regressions require the Linux runtime.');
        }
    }
}
