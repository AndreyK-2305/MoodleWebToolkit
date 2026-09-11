<?php

namespace App\Domain\Artifacts;

use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\DTOs\StoredArtifact;
use App\Domain\Artifacts\Streams\ArtifactReadStream;
use App\Domain\Idempotency\IdempotencyRegistry;
use App\Exceptions\ArtifactIntegrityException;
use App\Exceptions\IdempotencyKeyConflict;
use App\Models\Artifact;
use App\Models\ArtifactDownload;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DownloadArtifact
{
    public function __construct(
        private readonly ArtifactStorage $storage,
        private readonly ArtifactStreamVerifier $verifier,
        private readonly IdempotencyRegistry $idempotency,
    ) {}

    public function prepare(Artifact $artifact, User $actor, string $idempotencyKey): ArtifactReadStream
    {
        $artifact->loadMissing('execution.project');
        $execution = $artifact->execution;
        $expectedPrefix = "executions/{$execution->workspace_key}/";

        if ($artifact->disk !== 'local' || ! str_starts_with($artifact->path, $expectedPrefix)) {
            throw new ArtifactIntegrityException('La ruta lógica del artefacto no pertenece al workspace de la ejecución.');
        }

        $stored = new StoredArtifact($artifact->disk, $artifact->path, $artifact->size, $artifact->sha256);
        try {
            $stream = $this->storage->readStream($artifact->path);
        } catch (\Throwable $exception) {
            throw new ArtifactIntegrityException('El archivo solicitado ya no existe en el almacenamiento.', previous: $exception);
        }

        try {
            $this->verifier->verifyStream($stream, $stored, rewindAfter: true);
            $payload = ['artifact_id' => $artifact->getKey(), 'execution_uuid' => $execution->uuid, 'sha256' => $artifact->sha256];
            $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $this->registerDownload($artifact, $actor, $idempotencyKey, $payloadHash, $payload);
        } catch (\Throwable $exception) {
            $stream->close();
            throw $exception;
        }

        return $stream;
    }

    /** @param array<string, mixed> $payload */
    private function registerDownload(
        Artifact $artifact,
        User $actor,
        string $idempotencyKey,
        string $payloadHash,
        array $payload,
    ): void {
        $execution = $artifact->execution;

        DB::transaction(function () use ($artifact, $execution, $actor, $idempotencyKey, $payloadHash, $payload): void {
            Execution::query()->lockForUpdate()->findOrFail((int) $execution->getKey());
            $actorId = (int) $actor->getKey();
            $scope = "execution:{$execution->getKey()}:artifact:{$artifact->getKey()}:download";
            $receipt = $this->idempotency->find(
                (int) $execution->getKey(),
                $actorId,
                'DOWNLOAD',
                $idempotencyKey,
                $payloadHash,
            );

            if ($receipt !== null) {
                return;
            }

            $existing = ArtifactDownload::query()
                ->where('execution_id', $execution->getKey())
                ->where('user_id', $actor->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->artifact_id !== $artifact->getKey() || ! hash_equals($existing->payload_hash, $payloadHash)) {
                    throw new IdempotencyKeyConflict;
                }

                $this->idempotency->record(
                    (int) $execution->getKey(), $actorId, 'DOWNLOAD', 'artifact', (int) $artifact->getKey(),
                    $scope, $idempotencyKey, $payloadHash, 'artifact_download', (int) $existing->getKey(), 200,
                );

                return;
            }

            $download = ArtifactDownload::query()->create([
                'artifact_id' => $artifact->getKey(),
                'execution_id' => $execution->getKey(),
                'user_id' => $actor->getKey(),
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
            ]);
            $this->idempotency->record(
                (int) $execution->getKey(), $actorId, 'DOWNLOAD', 'artifact', (int) $artifact->getKey(),
                $scope, $idempotencyKey, $payloadHash, 'artifact_download', (int) $download->getKey(), 200,
            );
            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'project_id' => $execution->project_id,
                'execution_id' => $execution->getKey(),
                'action' => 'ARTIFACT_DOWNLOADED',
                'auditable_type' => $download->getMorphClass(),
                'auditable_id' => $download->getKey(),
                'payload' => $payload,
            ]);
        }, attempts: 3);
    }
}
