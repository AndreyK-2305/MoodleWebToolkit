<?php

namespace App\Domain\Remote;

use App\Domain\Executions\ExecutionEventRecorder;
use App\Enums\ArtifactCategory;
use App\Enums\EventSeverity;
use App\Enums\ExecutionStatus;
use App\Enums\RemoteCommunicationState;
use App\Enums\RemoteFunctionalState;
use App\Exceptions\RemoteAgentUnavailable;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Artifact;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The backup of a consolidation's sealed site package, taken while the
 * Execution is in REVIEW: once COMPLETED the project is read-only. The
 * destination uploads the ZIP with its own role; the platform verifies the
 * object in S3 with its identity and registers it as a FULL_BACKUP reference
 * that Finalize includes. Afterwards the source ZIPs in copias/, already in
 * S3, are released on the destination; the site package stays there for the
 * publication.
 */
class RemoteConsolidationBackup
{
    public const COMMAND = 'consolidator.site-upload';

    private const MANUAL_AFTER = 8;

    public function __construct(
        private readonly RemoteAgentOperations $operations,
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
        private readonly RemotePackageObjects $objects,
        private readonly ExecutionEventRecorder $events,
    ) {}

    public function start(Execution $execution, User $actor): RemoteOperation
    {
        $execution->refresh();
        if (! RemoteConsolidationPreparation::selected($execution->toolBinding) || $execution->status !== ExecutionStatus::REVIEW) {
            throw new ToolOperationBlocked('El respaldo de la copia integral se toma durante la revisión de la consolidación.');
        }
        if ($this->artifact($execution) !== null) {
            throw new ToolOperationBlocked('La copia integral ya está respaldada.');
        }
        $previous = $execution->remoteOperations()->where('command_key', self::COMMAND)->orderByDesc('id')->get();
        if ($previous->contains(fn (RemoteOperation $operation): bool => $operation->communication_state !== RemoteCommunicationState::TERMINATED)) {
            throw new ToolOperationBlocked('Ya hay un respaldo en curso.');
        }
        $settings = $execution->toolBinding->configuration_snapshot ?? [];
        $run = $execution->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::COMMAND)->orderByDesc('id')->first();
        $sha = $run?->evidence['consolidator']['site_package']['package_sha256'] ?? null;
        $bucket = config('toolkit.remote.package_bucket');
        if (! is_string($sha) || ! is_string($bucket) || $bucket === '') {
            throw new ToolOperationBlocked('No hay una copia integral sellada o un bucket configurado para respaldarla.');
        }
        $server = Server::query()->where('project_id', $execution->project_id)->where('uuid', (string) ($settings['server_uuid'] ?? ''))->firstOrFail();
        $operation = $this->operations->launch($execution, $server, 'consolidator-site-upload-'.($previous->count() + 1), self::COMMAND, [
            'destination' => (string) ($settings['destination_profile'] ?? ''), 'project' => $execution->project->uuid, 'execution' => $execution->uuid,
            'sha' => $sha, 'bucket' => $bucket, 'region' => (string) config('toolkit.remote.package_region', 'us-east-2'),
        ], $actor);
        $this->events->record($execution, 'consolidator.backup_requested', 'finalization',
            message: 'El destino está respaldando la copia integral sellada en el almacenamiento de la plataforma.',
            payload: ['operation_uuid' => $operation->operation_uuid, 'sha256' => $sha], operation: $operation);

        return $operation;
    }

    /** One bounded pass: reconcile the upload and, once it ended well, verify and register it. */
    public function observe(RemoteOperation $operation): void
    {
        if ($operation->communication_state !== RemoteCommunicationState::TERMINATED) {
            $operation = $this->operations->reconcile($operation);
        }
        if ($operation->communication_state !== RemoteCommunicationState::TERMINATED || isset($operation->evidence['backup'])) {
            return;
        }
        $execution = $operation->execution;
        if ($operation->functional_state !== RemoteFunctionalState::SUCCEEDED || $operation->exit_code !== 0) {
            $this->settle($operation, ['state' => 'FAILED', 'exit_code' => $operation->exit_code]);
            $this->events->record($execution, 'consolidator.backup_failed', 'finalization', EventSeverity::WARNING,
                message: 'El respaldo de la copia integral no terminó (código '.($operation->exit_code ?? '?').'); puede intentarse de nuevo.',
                operation: $operation);

            return;
        }
        try {
            $artifact = $this->register($operation);
        } catch (ToolOperationBlocked|RemoteAgentUnavailable $exception) {
            $this->defer($operation, $exception->getMessage());

            return;
        }
        $release = $this->release($operation);
        $this->settle($operation, ['state' => 'SUCCEEDED', 'artifact_id' => $artifact->getKey(), 'copias_release' => $release]);
        $this->events->record($execution, 'consolidator.backup_registered', 'finalization',
            message: 'Copia integral respaldada y verificada en el almacenamiento'.($release === null ? '.'
                : '; se liberaron '.$release['released'].' paquete(s) de origen del destino.'),
            payload: ['sha256' => $artifact->sha256, 'size' => $artifact->size, 'copias_release' => $release], operation: $operation);
    }

    public function artifact(Execution $execution): ?Artifact
    {
        return $execution->artifacts()->where('category', ArtifactCategory::FULL_BACKUP->value)->where('storage_mode', 'REFERENCE')->first();
    }

    /** @return array{state: string|null, sha256: string|null, size: int|null, location: array<string, string>|null, released: int|null, freed_bytes: int|null} */
    public function state(Execution $execution): array
    {
        $artifact = $this->artifact($execution);
        $operation = $execution->remoteOperations()->where('command_key', self::COMMAND)->orderByDesc('id')->first();
        $backup = is_array($operation?->evidence['backup'] ?? null) ? $operation->evidence['backup'] : null;
        $state = $artifact !== null ? 'SUCCEEDED' : ($operation === null ? null
            : ($operation->communication_state !== RemoteCommunicationState::TERMINATED || $backup === null ? 'RUNNING' : (string) $backup['state']));
        $release = is_array($backup['copias_release'] ?? null) ? $backup['copias_release'] : null;

        return ['state' => $state, 'sha256' => $artifact?->sha256, 'size' => $artifact?->size,
            'location' => $artifact === null ? null : $this->objects->location($artifact),
            'released' => is_int($release['released'] ?? null) ? $release['released'] : null,
            'freed_bytes' => is_int($release['freed_bytes'] ?? null) ? $release['freed_bytes'] : null];
    }

    private function register(RemoteOperation $operation): Artifact
    {
        $execution = $operation->execution;
        $parameters = $operation->evidence['parameters'] ?? [];
        $server = Server::query()->where('uuid', (string) $operation->host_id)->firstOrFail();
        $response = $this->agent->call($this->connections->target($server), 'site-upload-report', [$operation->operation_uuid], 30);
        $result = $response->ok && is_string($response->data['result_base64'] ?? null)
            ? json_decode((string) base64_decode($response->data['result_base64'], true), true) : null;
        $key = 'mwt/'.$execution->project->uuid.'/consolidated/'.$execution->uuid.'/'.($parameters['sha'] ?? '').'.zip';
        if (! is_array($result) || ($result['sha256'] ?? null) !== ($parameters['sha'] ?? null) || ($result['key'] ?? null) !== $key
            || ($result['bucket'] ?? null) !== ($parameters['bucket'] ?? null) || ($result['region'] ?? null) !== ($parameters['region'] ?? null)
            || ! is_int($result['size'] ?? null) || $result['size'] < 1) {
            throw new ToolOperationBlocked('El destino no reportó el respaldo esperado.');
        }
        [$bucket, $region, $sha, $size] = [(string) $result['bucket'], (string) $result['region'], (string) $result['sha256'], (int) $result['size']];
        // The destination never vouches for itself: the platform checks the object with its own identity.
        $storage = $this->objects->verifyPackage($region, $bucket, $key, $sha, $size);

        return DB::transaction(function () use ($execution, $operation, $bucket, $region, $key, $sha, $size, $storage): Artifact {
            $locked = Execution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ExecutionStatus::REVIEW) {
                throw new ToolOperationBlocked('La consolidación dejó de estar en revisión antes de registrar su respaldo.');
            }
            $artifact = $this->artifact($locked) ?? Artifact::query()->create([
                'execution_id' => $locked->id, 'remote_operation_id' => $operation->id, 'type' => 'full_backup',
                'category' => ArtifactCategory::FULL_BACKUP->value, 'storage_mode' => 'REFERENCE', 'disk' => 's3', 'path' => $bucket.'/'.$key,
                'filename' => (Str::slug($locked->project->name) ?: 'consolidacion').'-copia-integral.zip', 'mime_type' => 'application/zip',
                'size' => $size, 'sha256' => $sha,
                'metadata' => ['s3' => ['region' => $region, 'bucket' => $bucket, 'key' => $key], 'checksum_verification' => $storage['checksum_verification'],
                    'upload_operation_uuid' => $operation->operation_uuid, 'retention' => 'ADMINISTRATOR'],
            ]);
            AuditLog::query()->create(['project_id' => $locked->project_id, 'execution_id' => $locked->id, 'action' => 'CONSOLIDATOR_BACKUP_REGISTERED',
                'payload' => ['sha256' => $sha, 'size' => $size, 'bucket' => $bucket, 'key' => $key, 'checksum_verification' => $storage['checksum_verification']]]);

            return $artifact;
        }, attempts: 3);
    }

    /** @return array{released: int, freed_bytes: int}|null */
    private function release(RemoteOperation $operation): ?array
    {
        $server = Server::query()->where('uuid', (string) $operation->host_id)->first();
        if ($server === null) {
            return null;
        }
        try {
            $response = $this->agent->call($this->connections->target($server), 'copias-release',
                [(string) ($operation->evidence['parameters']['destination'] ?? '')], 60);
        } catch (RemoteAgentUnavailable) {
            return null;
        }

        return $response->ok ? ['released' => max(0, (int) ($response->data['released'] ?? 0)), 'freed_bytes' => max(0, (int) ($response->data['freed_bytes'] ?? 0))] : null;
    }

    /** @param array<string, mixed> $backup */
    private function settle(RemoteOperation $operation, array $backup): void
    {
        DB::transaction(function () use ($operation, $backup): void {
            $locked = RemoteOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['evidence' => [...($locked->evidence ?? []), 'backup' => $backup], 'next_poll_at' => null])->save();
        }, attempts: 3);
    }

    /** Keeps a finished upload due with backoff while its registration cannot be verified yet. */
    private function defer(RemoteOperation $operation, string $message): void
    {
        DB::transaction(function () use ($operation, $message): void {
            $locked = RemoteOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $attempts = min(65535, $locked->reconcile_attempts + 1);
            $manual = $attempts >= self::MANUAL_AFTER;
            if ($locked->last_reconcile_error !== 'CONSOLIDATOR_BACKUP_PENDING' || $manual !== $locked->manual_intervention_required) {
                $this->events->record($locked->execution, 'consolidator.backup_pending', 'finalization', EventSeverity::WARNING,
                    message: $manual ? 'El respaldo requiere revisar su evidencia: '.$message : $message, operation: $locked);
            }
            $locked->forceFill(['reconcile_attempts' => $attempts, 'last_reconcile_error' => 'CONSOLIDATOR_BACKUP_PENDING',
                'manual_intervention_required' => $manual, 'next_poll_at' => $manual ? null : now()->utc()->addSeconds(min(900, 15 * (2 ** min(6, $attempts))))])->save();
        }, attempts: 3);
    }
}
