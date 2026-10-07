<?php

namespace App\Domain\Artifacts\Contracts;

use App\Domain\Artifacts\DTOs\StoredArtifact;

interface ArtifactReferenceStorage extends ArtifactStorage
{
    /** Register a same-filesystem immutable file by hard link, without copying its contents. */
    public function referenceExisting(string $sourceAbsolutePath, string $targetPath, ?string $expectedSha256 = null): StoredArtifact;
}
