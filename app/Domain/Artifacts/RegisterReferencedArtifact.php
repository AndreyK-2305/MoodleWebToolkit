<?php

namespace App\Domain\Artifacts;

use App\Domain\Artifacts\Contracts\ArtifactReferenceStorage;
use App\Enums\ArtifactCategory;
use App\Models\Artifact;
use App\Models\Execution;
use App\Models\RemoteOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RegisterReferencedArtifact
{
    public function __construct(
        private readonly ArtifactReferenceStorage $storage,
        private readonly SensitiveValueRedactor $redactor,
    ) {}

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
    ): Artifact {
        if ($remoteOperationId === null) {
            throw new InvalidArgumentException('El artefacto requiere una operación productora terminada.');
        }

        return DB::transaction(function () use ($execution, $sourceAbsolutePath, $category, $filename, $metadata,
            $expectedSha256, $expectedSize, $remoteOperationId): Artifact {
            RemoteOperation::query()->whereKey($remoteOperationId)->lockForUpdate()->firstOrFail();

            return $this->registerLocked($execution, $sourceAbsolutePath, $category, $filename, $metadata,
                $expectedSha256, $expectedSize, $remoteOperationId);
        });
    }

    /** @param array<string, mixed> $metadata */
    private function registerLocked(Execution $execution, string $sourceAbsolutePath, ArtifactCategory $category,
        string $filename, array $metadata, ?string $expectedSha256, ?int $expectedSize, ?int $remoteOperationId): Artifact
    {
        $operation = $remoteOperationId === null
            ? null
            : RemoteOperation::query()
                ->whereKey($remoteOperationId)
                ->where('execution_id', $execution->getKey())
                ->where('communication_state', 'TERMINATED')
                ->first();
        $exitEvidence = $operation?->evidence['exit_evidence'] ?? null;
        if ($operation === null || $operation->terminated_at === null || is_array($exitEvidence) === false
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

        $relativeSource = $metadata['source_relative_path'] ?? null;
        $existing = is_string($relativeSource) ? Artifact::query()->where('remote_operation_id', $remoteOperationId)
            ->where('metadata->source_relative_path', $relativeSource)->first() : null;
        if ($existing !== null) {
            $target = Storage::disk($existing->disk)->path($existing->path);
            clearstatcache(true, $sourceAbsolutePath);
            clearstatcache(true, $target);
            $sourceStat = @lstat($sourceAbsolutePath);
            $targetStat = @lstat($target);
            $hash = is_file($sourceAbsolutePath) && ! is_link($sourceAbsolutePath) ? hash_file('sha256', $sourceAbsolutePath) : false;
            if ($sourceStat === false || $targetStat === false || ($sourceStat['mode'] & 0170000) !== 0100000
                || ($targetStat['mode'] & 0170000) !== 0100000 || $sourceStat['nlink'] !== 2 || $targetStat['nlink'] !== 2
                || $sourceStat['dev'] !== $targetStat['dev'] || $sourceStat['ino'] !== $targetStat['ino']
                || $sourceStat['size'] !== $existing->size || ! is_string($hash) || ! hash_equals($existing->sha256, $hash)
                || ($expectedSha256 !== null && ! hash_equals($expectedSha256, $hash))
                || ($expectedSize !== null && $expectedSize !== $existing->size) || $existing->category !== $category->value
                || $existing->filename !== $filename || $this->canonical($existing->metadata) !== $this->canonical($this->redactor->redact($metadata))) {
                throw new InvalidArgumentException('La referencia existente perdió su identidad, contenido o metadata declarada.');
            }

            return $existing;
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

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonical(...), $value);
    }
}
