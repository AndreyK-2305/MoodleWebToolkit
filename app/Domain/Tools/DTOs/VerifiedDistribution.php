<?php

namespace App\Domain\Tools\DTOs;

/**
 * @phpstan-type FileHashMap array<string, string>
 */
final readonly class VerifiedDistribution
{
    /**
     * @param FileHashMap $manifestFiles
     * @param FileHashMap $mutableFiles
     */
    public function __construct(
        public string $sourceRoot,
        public string $treeSha256,
        public string $manifestSha256,
        public int $fileCount,
        public array $manifestFiles,
        public array $mutableFiles,
        public string $verifiedAt,
    ) {}

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return [
            'distribution_sha256' => $this->treeSha256,
            'manifest_sha256' => $this->manifestSha256,
            'manifest_file_count' => count($this->manifestFiles),
            'file_count' => $this->fileCount,
            'mutable_files' => $this->mutableFiles,
            'verified_at' => $this->verifiedAt,
        ];
    }
}
