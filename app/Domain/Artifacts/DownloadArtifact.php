<?php

namespace App\Domain\Artifacts;

use App\Domain\Artifacts\Contracts\ArtifactStorage;
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
    public function __construct(private readonly ArtifactStorage $storage) {}

    public function contents(Artifact $artifact, User $actor, string $idempotencyKey): string
    {
        $artifact->loadMissing('execution.project');
        $execution = $artifact->execution;
        $expectedPrefix = "executions/{$execution->workspace_key}/";

        if ($artifact->disk !== 'local' || ! str_starts_with($artifact->path, $expectedPrefix)) {
            throw new ArtifactIntegrityException('La ruta lógica del artefacto no pertenece al workspace de la ejecución.');
        }

        if (! $this->storage->exists($artifact->path)) {
            throw new ArtifactIntegrityException('El archivo solicitado ya no existe en el almacenamiento.');
        }

        $contents = $this->storage->read($artifact->path);

        if (strlen($contents) !== $artifact->size || ! hash_equals($artifact->sha256, hash('sha256', $contents))) {
            throw new ArtifactIntegrityException('El archivo fue alterado y su descarga fue bloqueada.');
        }

        $payload = ['artifact_id' => $artifact->getKey(), 'execution_uuid' => $execution->uuid, 'sha256' => $artifact->sha256];
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($artifact, $execution, $actor, $idempotencyKey, $payloadHash, $payload): void {
            Execution::query()->lockForUpdate()->findOrFail((int) $execution->getKey());
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

                return;
            }

            $download = ArtifactDownload::query()->create([
                'artifact_id' => $artifact->getKey(),
                'execution_id' => $execution->getKey(),
                'user_id' => $actor->getKey(),
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
            ]);
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

        return $contents;
    }
}
