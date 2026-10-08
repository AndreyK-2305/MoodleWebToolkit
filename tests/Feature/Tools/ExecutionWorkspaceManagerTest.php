<?php

namespace Tests\Feature\Tools;

use App\Domain\Artifacts\LocalArtifactStorage;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\ExecutionStatus;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Execution;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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
        $first = $this->execution($project, ExecutionStatus::FAILED, attempt: 1);
        $second = $this->execution($project, attempt: 2);
        $manager = app(ExecutionWorkspaceManager::class);
        $this->approveCapacity($first, 1024);
        $this->approveCapacity($second, 1024);
        $manager->prepare($first);
        $manager->prepare($second);

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
        $this->approveCapacity($execution, 1024);
        $manager->prepare($execution);
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
        $this->approveCapacity($execution, 64);
        $manager->prepare($execution);

        $this->expectException(RuntimeException::class);
        $manager->writeState($execution, 'too-large.json', ['payload' => str_repeat('x', 256)]);
    }

    public function test_quota_checks_include_files_written_by_earlier_calls(): void
    {
        $execution = $this->execution($this->project());
        $manager = app(ExecutionWorkspaceManager::class);
        $this->approveCapacity($execution, 128);
        $manager->prepare($execution);
        $manager->writeAtomic($execution, 'output', 'first.bin', str_repeat('a', 80));

        $this->expectException(RuntimeException::class);
        $manager->writeAtomic($execution, 'output', 'second.bin', str_repeat('b', 80));
    }

    public function test_workspace_requires_capacity_approval_and_records_the_exact_quota(): void
    {
        $execution = $this->execution($this->project());
        $manager = app(ExecutionWorkspaceManager::class);
        try {
            $manager->prepare($execution);
            $this->fail('A workspace without an approved estimate must remain blocked.');
        } catch (RuntimeException) {
            $this->assertSame(0, $execution->workspace()->count());
        }
        $approval = app(ApproveExecutionCapacity::class)->approve($execution, 1000, 25, $execution->creator);
        $workspace = $manager->prepare($execution);
        $this->assertSame(1250, $workspace->quota_bytes);
        $this->assertGreaterThanOrEqual(1250, $approval->available_bytes_observed);
        $this->assertSame(hash('sha256', json_encode($approval->evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $approval->fingerprint);
    }

    public function test_capacity_rejects_insufficient_available_space(): void
    {
        $execution = $this->execution($this->project());
        $this->expectException(ToolOperationBlocked::class);
        $this->expectExceptionMessage('capacidad disponible');
        app(ApproveExecutionCapacity::class)->approve($execution, PHP_INT_MAX, 0, $execution->creator);
    }

    public function test_workspace_cleanup_preserves_an_artifact_hardlink_and_counts_each_workspace_path(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $this->workspaceRoot = $disk->path('workspaces');
        config(['toolkit.workspaces.root' => $this->workspaceRoot]);
        $execution = $this->execution($this->project());
        $this->approveCapacity($execution, 4096);
        $manager = app(ExecutionWorkspaceManager::class);
        $source = $manager->resolve($execution, 'output', 'result.bin');
        file_put_contents($source, str_repeat('x', 128));
        $alias = $manager->resolve($execution, 'output', 'alias.bin');
        $this->assertTrue(link($source, $alias));
        $this->assertSame(256, $manager->measure($execution), 'Quota accounting counts paths, even when they share an inode.');
        $stored = (new LocalArtifactStorage)->referenceExisting($source, 'artifacts/retained/result.bin');

        $execution->transitionTo(ExecutionStatus::FAILED);
        $manager->cleanup($execution->refresh());

        $this->assertFileDoesNotExist($source);
        $this->assertFileDoesNotExist($alias);
        $this->assertSame(str_repeat('x', 128), $disk->get($stored->path));
        $this->assertSame($stored->checksum, hash_file('sha256', $disk->path($stored->path)));
    }

    private function approveCapacity(Execution $execution, int $bytes): void
    {
        app(ApproveExecutionCapacity::class)->approve($execution, $bytes, 0, $execution->creator);
    }
}
