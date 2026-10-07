<?php

namespace Tests\Unit;

use App\Domain\Artifacts\LocalArtifactStorage;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class ArtifactReferenceStorageTest extends TestCase
{
    public function test_large_artifact_reference_uses_the_same_file_without_copying(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $sourcePath = 'workspaces/reference/source.mbz';
        $disk->put($sourcePath, str_repeat('course-data-', 1000));
        $source = $disk->path($sourcePath);

        $stored = (new LocalArtifactStorage)->referenceExisting($source, 'artifacts/execution/course.mbz');

        $this->assertSame(strlen(str_repeat('course-data-', 1000)), $stored->size);
        $this->assertSame(hash_file('sha256', $source), $stored->checksum);
        $this->assertSame(fileinode($source), fileinode($disk->path($stored->path)));
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0, fileperms($source) & 0222, 'chmod on the artifact hard link also makes the producer inode read-only.');
        }
    }

    public function test_artifact_reference_rejects_paths_outside_private_storage(): void
    {
        Storage::fake('local');

        $this->expectException(InvalidArgumentException::class);
        (new LocalArtifactStorage)->referenceExisting(__FILE__, 'artifacts/outside.php');
    }

    public function test_artifact_reference_rejects_an_incorrect_expected_hash_without_creating_a_target(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('workspaces/reference/source.bin', 'expected data');
        $source = $disk->path('workspaces/reference/source.bin');

        try {
            (new LocalArtifactStorage)->referenceExisting($source, 'artifacts/execution/wrong.bin', str_repeat('0', 64));
            $this->fail('A mismatched expected hash must block the reference.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('hash esperado', $exception->getMessage());
            $this->assertFalse($disk->exists('artifacts/execution/wrong.bin'));
        }
    }

    public function test_artifact_reference_never_overwrites_an_existing_target(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('workspaces/reference/source.bin', 'source');
        $disk->put('artifacts/execution/existing.bin', 'existing');

        $this->expectException(\RuntimeException::class);
        (new LocalArtifactStorage)->referenceExisting(
            $disk->path('workspaces/reference/source.bin'),
            'artifacts/execution/existing.bin',
        );
    }
}
