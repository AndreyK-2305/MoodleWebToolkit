<?php

namespace Tests\Feature\Tools;

use App\Domain\Executions\LocalToolExecutionProvider;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\Artifact;
use App\Models\Execution;
use App\Models\RemoteOperation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Domain\DomainTestCase;

class ArtifactDescriptorCollectionTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['toolkit.workspaces.root' => Storage::disk('local')->path('workspaces')]);
    }

    public function test_collection_preserves_declared_zip_category_and_producing_operation(): void
    {
        [$execution, $operation] = $this->fixture();
        $output = app(ExecutionWorkspaceManager::class)->resolve($execution, 'output', 'backup.zip');
        file_put_contents($output, 'synthetic archive bytes');

        $artifacts = app(LocalToolExecutionProvider::class)->collectArtifacts($operation);

        $this->assertCount(1, $artifacts);
        $this->assertSame('FULL_BACKUP', $artifacts[0]->category);
        $this->assertSame($operation->getKey(), $artifacts[0]->remote_operation_id);
        $this->assertSame(hash_file('sha256', $output), $artifacts[0]->sha256);
        $this->assertSame('CONFIDENTIAL', $artifacts[0]->metadata['sensitivity']);
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_declared_outputs_create_no_artifact(string $case): void
    {
        [$execution, $operation] = $this->fixture();
        $manager = app(ExecutionWorkspaceManager::class);
        $output = $manager->resolve($execution, 'output', 'backup.zip');
        if ($case !== 'missing') {
            file_put_contents($output, $case === 'changed' ? 'changed' : 'synthetic archive bytes');
        }
        if ($case === 'extra') {
            file_put_contents($manager->resolve($execution, 'output', 'undeclared.zip'), 'extra');
        }
        if ($case === 'symlink') {
            if (PHP_OS_FAMILY === 'Windows') {
                $this->markTestSkipped('Symlink privileges are supplied by the Linux quality stack.');
            }
            unlink($output);
            $source = $manager->resolve($execution, 'temporary', 'source.bin');
            file_put_contents($source, 'synthetic archive bytes');
            symlink($source, $output);
        }
        $evidence = $operation->evidence;
        if ($case === 'oversized') {
            $evidence['artifact_descriptors'][0]['max_size_bytes'] = 1;
        } elseif ($case === 'mime') {
            $evidence['artifact_descriptors'][0]['mime_types'] = ['application/zip'];
        } elseif ($case === 'traversal') {
            $evidence['artifact_descriptors'][0]['relative_path'] = '../backup.zip';
        } elseif ($case === 'unterminated') {
            $operation->forceFill(['communication_state' => 'CONNECTED', 'terminated_at' => null])->save();
        }
        $operation->forceFill(['evidence' => $evidence])->save();
        try {
            app(LocalToolExecutionProvider::class)->collectArtifacts($operation);
            $this->fail('Collection must fail closed for '.$case.'.');
        } catch (RuntimeException) {
            $this->assertSame(0, Artifact::query()->where('execution_id', $execution->getKey())->count());
            $this->assertSame([], Storage::disk('local')->allFiles('artifacts'));
        }
    }

    public static function invalidOutputs(): array
    {
        return array_map(fn (string $case): array => [$case], ['missing', 'extra', 'changed', 'oversized', 'mime', 'traversal', 'symlink', 'unterminated']);
    }

    /** @return array{Execution, RemoteOperation} */
    private function fixture(): array
    {
        $execution = $this->execution($this->project());
        app(ApproveExecutionCapacity::class)->approve($execution, 8192, 0, $execution->creator);
        $uuid = (string) Str::uuid();
        $hash = hash('sha256', 'synthetic-producer');
        $operation = RemoteOperation::query()->create([
            'execution_id' => $execution->getKey(),
            'operation_uuid' => $uuid,
            'idempotency_key' => 'declared-artifact',
            'provider_key' => 'local-registered-process',
            'host_id' => gethostname() ?: 'local',
            'runtime_key' => 'workspace-process-v2',
            'command_key' => 'synthetic-output',
            'command_sha256' => $hash,
            'communication_state' => 'TERMINATED',
            'functional_state' => 'SUCCEEDED',
            'terminated_at' => now()->utc(),
            'exit_code' => 0,
            'evidence' => [
                'exit_evidence' => ['operation_uuid' => $uuid, 'command_sha256' => $hash],
                'artifact_descriptors' => [[
                    'relative_path' => 'backup.zip', 'name' => 'backup.zip', 'category' => 'FULL_BACKUP',
                    'mime_types' => ['text/plain'], 'max_size_bytes' => 1024, 'required' => true,
                    'expected_sha256' => hash('sha256', 'synthetic archive bytes'), 'sensitivity' => 'CONFIDENTIAL',
                ]],
            ],
        ]);

        return [$execution, $operation];
    }
}
