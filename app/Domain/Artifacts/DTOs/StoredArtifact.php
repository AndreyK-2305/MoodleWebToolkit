<?php

namespace App\Domain\Artifacts\DTOs;

final readonly class StoredArtifact
{
    public function __construct(
        public string $disk,
        public string $path,
        public int $size,
        public string $checksum,
    ) {}
}
