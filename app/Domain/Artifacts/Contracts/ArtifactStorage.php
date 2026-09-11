<?php

namespace App\Domain\Artifacts\Contracts;

use App\Domain\Artifacts\DTOs\StoredArtifact;
use App\Domain\Artifacts\Streams\ArtifactReadStream;

interface ArtifactStorage
{
    public const MAX_CHUNK_BYTES = 65_536;

    /** @param iterable<string> $chunks */
    public function writeStream(string $path, iterable $chunks): StoredArtifact;

    /** @param iterable<string> $chunks */
    public function appendStream(string $path, iterable $chunks, int $expectedSize): int;

    public function readStream(string $path): ArtifactReadStream;

    public function promote(string $stagingPath, string $finalPath, ?StoredArtifact $expected = null): StoredArtifact;

    public function exists(string $path): bool;

    public function delete(string $path): void;
}
