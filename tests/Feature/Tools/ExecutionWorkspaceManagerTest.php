<?php

namespace Tests\Feature\Tools;

use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\ExecutionStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\Feature\Domain\DomainTestCase;

class ExecutionWorkspaceManagerTest extends DomainTestCase
{
    private string $workspaceRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaceRoot = storage_path('framework/workspace-tests/'.Str::uuid());
        config(['toolkit.workspaces.root' => $this->workspaceRoot]);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspaceRoot) && is_dir($this->workspaceRoot)) {
            File::deleteDirectory($this->workspaceRoot);
        }
        parent::tearDown();
    }

    public function test_workspace_roots_are_execution_scoped_and_reject_traversal(): void
    {
        $project = $this->project();
        $first = $this->execution($project, attempt: 1);
        $second = $this->execution($project, attempt: 2);
        $manager = app(ExecutionWorkspaceManager::class);
        $manager->prepare($first, quotaBytes: 1024);
        $manager->prepare($second, quotaBytes: 1024);

        $firstRoot = $manager->resolve($first, 'output');
        $secondRoot = $manager->resolve($second, 'output');

        $this->assertNotSame($firstRoot, $secondRoot);
        $this->assertDirectoryExists($firstRoot);
        $this->assertDirectoryExists($secondRoot);

        try {
            $manager->resolve($first, 'output', '../outside.txt');
            $this->fail('Workspace traversal was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertFileDoesNotExist(dirname($firstRoot).DIRECTORY_SEPARATOR.'outside.txt');
        }
    }

    public function test_workspace_quota_and_cleanup_are_enforced(): void
    {
        $project = $this->project();
        $execution = $this->execution($project);
        $manager = app(ExecutionWorkspaceManager::class);
        $manager->prepare($execution, quotaBytes: 1024);
        $manager->writeState($execution, 'state.json', ['state' => 'ready']);
        $this->assertGreaterThan(0, $manager->measure($execution));

        $execution->transitionTo(ExecutionStatus::FAILED);
        $manager->cleanup($execution->refresh());
        $this->assertSame(0, $execution->workspace()->value('usage_bytes'));
    }

    public function test_workspace_state_cannot_exceed_its_recorded_quota(): void
    {
        $execution = $this->execution($this->project());
        $manager = app(ExecutionWorkspaceManager::class);
        $manager->prepare($execution, quotaBytes: 64);

        $this->expectException(RuntimeException::class);
        $manager->writeState($execution, 'too-large.json', ['payload' => str_repeat('x', 256)]);
    }

    public function test_quota_checks_include_files_written_by_earlier_calls(): void
    {
        $execution = $this->execution($this->project());
        $manager = app(ExecutionWorkspaceManager::class);
        $manager->prepare($execution, quotaBytes: 128);
        $manager->writeAtomic($execution, 'output', 'first.bin', str_repeat('a', 80));

        $this->expectException(RuntimeException::class);
        $manager->writeAtomic($execution, 'output', 'second.bin', str_repeat('b', 80));
    }
}
