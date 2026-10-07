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
    }

    public function test_artifact_reference_rejects_paths_outside_private_storage(): void
    {
        Storage::fake('local');

        $this->expectException(InvalidArgumentException::class);
        (new LocalArtifactStorage)->referenceExisting(__FILE__, 'artifacts/outside.php');
    }
}
