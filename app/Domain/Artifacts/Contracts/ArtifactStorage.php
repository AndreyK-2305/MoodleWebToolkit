<?php

namespace App\Domain\Artifacts\Contracts;

use App\Domain\Artifacts\DTOs\StoredArtifact;
use App\Domain\Artifacts\Streams\ArtifactReadStream;

interface ArtifactStorage
{
    /** @param iterable<string> $chunks */
    public function writeStream(string $path, iterable $chunks): StoredArtifact;

    public function readStream(string $path): ArtifactReadStream;

    public function promote(string $stagingPath, string $finalPath): StoredArtifact;

    public function exists(string $path): bool;

    public function delete(string $path): void;
}
