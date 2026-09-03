<?php

namespace App\Domain\Artifacts\Contracts;

use App\Domain\Artifacts\DTOs\StoredArtifact;

interface ArtifactStorage
{
    public function put(string $path, string $contents): StoredArtifact;

    public function read(string $path): string;

    public function exists(string $path): bool;

    public function delete(string $path): void;
}
