<?php

namespace App\Domain\Tools\DTOs;

/**
 * @phpstan-type FileHashMap array<string, string>
 */
final readonly class VerifiedDistribution
{
    public string $sourceRoot;

    public string $treeSha256;

    public string $manifestSha256;

    public int $fileCount;

    /** @var FileHashMap */
    public array $manifestFiles;

    /** @var FileHashMap */
    public array $mutableFiles;

    public string $verifiedAt;

    /**
     * @param  FileHashMap  $manifestFiles
     * @param  FileHashMap  $mutableFiles
     */
    public function __construct(
        string $sourceRoot,
        string $treeSha256,
        string $manifestSha256,
        int $fileCount,
        array $manifestFiles,
        array $mutableFiles,
        string $verifiedAt,
    ) {
        $this->sourceRoot = $sourceRoot;
        $this->treeSha256 = $treeSha256;
        $this->manifestSha256 = $manifestSha256;
        $this->fileCount = $fileCount;
        $this->manifestFiles = $manifestFiles;
        $this->mutableFiles = $mutableFiles;
        $this->verifiedAt = $verifiedAt;
    }

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
