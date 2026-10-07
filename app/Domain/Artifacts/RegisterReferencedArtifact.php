<?php

namespace App\Domain\Artifacts;

use App\Domain\Artifacts\Contracts\ArtifactReferenceStorage;
use App\Enums\ArtifactCategory;
use App\Models\Artifact;
use App\Models\Execution;
use App\Models\RemoteOperation;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RegisterReferencedArtifact
{
    public function __construct(
        private readonly ArtifactReferenceStorage $storage,
        private readonly SensitiveValueRedactor $redactor,
    )
    {}

    /** @param array<string, mixed> $metadata */
    public function register(
        Execution $execution,
        string $sourceAbsolutePath,
        ArtifactCategory $category,
        string $filename,
        array $metadata = [],
        ?string $expectedSha256 = null,
        ?int $expectedSize = null,
        ?int $remoteOperationId = null,
    ): Artifact
    {
        $operation = $remoteOperationId === null
            ? null
            : RemoteOperation::query()
                ->whereKey($remoteOperationId)
                ->where('execution_id', $execution->getKey())
                ->where('communication_state', 'TERMINATED')
                ->first();
        $exitEvidence = $operation?->evidence['exit_evidence'] ?? null;
        if ($operation === null || $operation->terminated_at === null || ! is_array($exitEvidence)
            || ($exitEvidence['operation_uuid'] ?? null) !== $operation->operation_uuid
            || ($exitEvidence['command_sha256'] ?? null) !== $operation->command_sha256
        ) {
            throw new InvalidArgumentException('El artefacto solo puede registrarse tras confirmar la terminación de su operación productora.');
        }

        $filename = basename(str_replace('\\', '/', $filename));
        if ($filename === '' || $filename === '.' || $filename === '..' || str_contains($filename, "\0")
            || mb_strlen($filename) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $filename) === 1
            || $this->redactor->redactString($filename) !== $filename
        ) {
            throw new InvalidArgumentException('El nombre del artefacto no es válido.');
        }

        $relativePath = 'artifacts/'.$execution->uuid.'/'.Str::uuid().'-'.$filename;
        $stored = $this->storage->referenceExisting($sourceAbsolutePath, $relativePath, $expectedSha256, $expectedSize);

        try {
            return Artifact::query()->create([
                'execution_id' => $execution->getKey(),
                'remote_operation_id' => $remoteOperationId,
                'type' => strtolower($category->value),
                'category' => $category->value,
                'storage_mode' => 'REFERENCE',
                'disk' => $stored->disk,
                'path' => $stored->path,
                'filename' => $filename,
                'mime_type' => function_exists('mime_content_type') ? (@mime_content_type($sourceAbsolutePath) ?: null) : null,
                'size' => $stored->size,
                'sha256' => $stored->checksum,
                'metadata' => $this->redactor->redact($metadata),
            ]);
        } catch (\Throwable $exception) {
            $this->storage->delete($stored->path);
            throw $exception;
        }
    }
}
