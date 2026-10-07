<?php

namespace App\Domain\Artifacts;

use App\Domain\Artifacts\Contracts\ArtifactReferenceStorage;
use App\Enums\ArtifactCategory;
use App\Models\Artifact;
use App\Models\Execution;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RegisterReferencedArtifact
{
    public function __construct(private readonly ArtifactReferenceStorage $storage) {}

    /** @param array<string, mixed> $metadata */
    public function register(
        Execution $execution,
        string $sourceAbsolutePath,
        ArtifactCategory $category,
        string $filename,
        array $metadata = [],
    ): Artifact {
        $filename = basename(str_replace('\\', '/', $filename));
        if ($filename === '' || $filename === '.' || $filename === '..' || str_contains($filename, "\0")
            || mb_strlen($filename) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $filename) === 1
        ) {
            throw new InvalidArgumentException('El nombre del artefacto no es válido.');
        }

        $relativePath = 'artifacts/'.$execution->uuid.'/'.Str::uuid().'-'.$filename;
        $stored = $this->storage->referenceExisting($sourceAbsolutePath, $relativePath);

        try {
            return Artifact::query()->create([
                'execution_id' => $execution->getKey(),
                'type' => strtolower($category->value),
                'category' => $category->value,
                'storage_mode' => 'REFERENCE',
                'disk' => $stored->disk,
                'path' => $stored->path,
                'filename' => $filename,
                'mime_type' => function_exists('mime_content_type') ? (@mime_content_type($sourceAbsolutePath) ?: null) : null,
                'size' => $stored->size,
                'sha256' => $stored->checksum,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $exception) {
            $this->storage->delete($stored->path);
            throw $exception;
        }
    }
}
