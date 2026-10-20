<?php

namespace App\Domain\Remote;

use App\Domain\Collector\CollectorPackageMetadata;
use App\Domain\Tools\SourcePackageRegistry;
use App\Enums\ArtifactCategory;
use App\Enums\RemoteCommunicationState;
use App\Enums\RemoteFunctionalState;
use App\Exceptions\RemoteAgentUnavailable;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Artifact;
use App\Models\AuditLog;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\SourcePackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a ready remote export as a SourcePackage held in S3.
 *
 * The source server audits the ZIP with the deployed validator and uploads it
 * with its own write-only role. The platform then checks every object from S3
 * with its own identity before registering the package by reference.
 */
class RemoteSourcePackagePublisher
{
    public const COMMAND = 'package.upload';

    public const AUDIT_SCHEMA = 'collector-remote-audit.v1';

    public function __construct(
        private readonly RemoteAgentOperations $operations,
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
        private readonly RemotePackageObjects $objects,
        private readonly CollectorPackageMetadata $metadata,
        private readonly SourcePackageRegistry $registry,
    ) {}

    public function publish(RemoteOperation $export, ?User $actor): RemoteOperation
    {
        $collector = $export->evidence['collector'] ?? null;
        if ($export->command_key !== RemoteCollectorExport::COMMAND || ! is_array($collector) || ($collector['package_ready'] ?? null) !== true
            || ! is_string($collector['package_sha256'] ?? null)) {
            throw new ToolOperationBlocked('Solo se publica una exportación remota con el paquete listo.');
        }
        $bucket = config('toolkit.remote.package_bucket');
        if (! is_string($bucket) || $bucket === '') {
            throw new ToolOperationBlocked('No hay un bucket configurado para los paquetes fuente.');
        }
        $export->loadMissing('execution.project');
        $server = Server::query()->where('uuid', (string) $export->host_id)->firstOrFail();
        // An active or successful upload is reused; a failed one allows a new attempt with its own identity.
        $uploads = RemoteOperation::query()->where('execution_id', $export->execution_id)->where('command_key', self::COMMAND)
            ->where('evidence->parameters->collector', $export->operation_uuid)->orderBy('id')->get();
        $reusable = $uploads->first(fn (RemoteOperation $upload): bool => $upload->communication_state !== RemoteCommunicationState::TERMINATED
            || $upload->functional_state === RemoteFunctionalState::SUCCEEDED);
        if ($reusable !== null) {
            return $reusable;
        }

        return $this->operations->launch($export->execution, $server, 'upload-'.$collector['package_sha256'].'-'.($uploads->count() + 1), self::COMMAND, [
            'collector' => $export->operation_uuid,
            'project' => $export->execution->project->uuid,
            'bucket' => $bucket,
            'region' => (string) config('toolkit.remote.package_region', 'us-east-2'),
        ], $actor);
    }

    /**
     * Reconciles the upload; once it succeeded, verifies the objects and
     * registers the package. Returns the package when it is registered.
     */
    public function observe(RemoteOperation $upload, ?User $actor = null): ?SourcePackage
    {
        if ($upload->command_key !== self::COMMAND) {
            throw new ToolOperationBlocked('La operación no es una publicación de paquete.');
        }
        $upload = $this->operations->reconcile($upload);
        if ($upload->communication_state !== RemoteCommunicationState::TERMINATED || $upload->functional_state !== RemoteFunctionalState::SUCCEEDED) {
            return null;
        }
        $existing = $upload->evidence['source_package_uuid'] ?? null;
        if (is_string($existing)) {
            return SourcePackage::query()->where('uuid', $existing)->first();
        }

        return $this->register($upload, $actor);
    }

    private function register(RemoteOperation $upload, ?User $actor): SourcePackage
    {
        $parameters = $upload->evidence['parameters'] ?? [];
        $export = RemoteOperation::query()->with('execution.project')->where('execution_id', $upload->execution_id)
            ->where('operation_uuid', (string) ($parameters['collector'] ?? ''))->where('command_key', RemoteCollectorExport::COMMAND)->firstOrFail();
        $collector = $export->evidence['collector'] ?? [];
        $server = Server::query()->where('uuid', (string) $upload->host_id)->firstOrFail();
        try {
            $response = $this->agent->call($this->connections->target($server), 'upload-report', [$upload->operation_uuid], 30);
        } catch (RemoteAgentUnavailable $exception) {
            throw new ToolOperationBlocked('No se pudo leer el resultado de la publicación ('.$exception->errorCode.').');
        }
        $result = $response->ok && is_string($response->data['result_base64'] ?? null)
            ? json_decode((string) base64_decode($response->data['result_base64'], true), true) : null;
        $sha = (string) ($collector['package_sha256'] ?? '');
        $size = $collector['package_size'] ?? null;
        $source = (string) ($collector['status']['source_id'] ?? '');
        $project = $export->execution->project->uuid;
        // Computed from what the platform launched, never from the server's answer.
        $profile = (string) ($export->evidence['parameters']['source'] ?? '');
        $prefix = "mwt/{$project}/{$profile}/{$sha}";
        if (! is_array($result) || ($result['sha256'] ?? null) !== $sha || ($result['size'] ?? null) !== $size
            || ($result['bucket'] ?? null) !== ($parameters['bucket'] ?? null) || ($result['region'] ?? null) !== ($parameters['region'] ?? null)
            || ($result['prefix'] ?? null) !== $prefix || ($result['source'] ?? null) !== $profile
            || ($result['package'] ?? null) !== ($collector['package'] ?? null)
            || ! is_string($result['manifest_sha256'] ?? null) || ! is_string($result['validation_sha256'] ?? null)) {
            throw new ToolOperationBlocked('El resultado de la publicación no corresponde a la exportación lista.');
        }
        [$region, $bucket] = [(string) $result['region'], (string) $result['bucket']];

        $storage = $this->objects->verifyPackage($region, $bucket, $prefix.'.zip', $sha, (int) $size);
        $manifestBytes = $this->objects->document($region, $bucket, $prefix.'.manifest.json', $result['manifest_sha256']);
        $validation = json_decode($this->objects->document($region, $bucket, $prefix.'.validation.json', $result['validation_sha256']), true);
        $manifest = json_decode($manifestBytes, true, 64);
        if (! is_array($manifest) || ! is_array($validation)) {
            throw new ToolOperationBlocked('El manifiesto o el reporte de validación no son JSON válidos.');
        }
        $metadata = $this->metadata->recognize($manifest);
        if ($metadata['source_id'] !== $source || ($validation['result'] ?? null) !== 'ok' || ($validation['failures'] ?? null) !== []
            || ($validation['outer_zip']['sha256'] ?? null) !== $sha || ($validation['outer_zip']['bytes'] ?? null) !== $size) {
            throw new ToolOperationBlocked('La validación del paquete no acredita el contenido publicado.');
        }

        $audit = [...$metadata, 'validation_schema' => self::AUDIT_SCHEMA, 'result' => 'VALID',
            'execution_uuid' => $export->execution->uuid, 'project_uuid' => $project,
            'package_sha256' => $sha, 'package_bytes' => $size, 'manifest_sha256' => $result['manifest_sha256'],
            'validator_version' => '7.4.2-linux', 'validator_distribution_sha256' => $export->evidence['parameters']['distribution'] ?? null,
            'counts' => array_filter(is_array($validation['counts'] ?? null) ? $validation['counts'] : [], 'is_int'),
            'warnings_count' => count(is_array($validation['warnings'] ?? null) ? $validation['warnings'] : []),
            'checksum_verification' => $storage['checksum_verification'], 'upload_operation_uuid' => $upload->operation_uuid];

        $package = DB::transaction(function () use ($export, $upload, $bucket, $region, $prefix, $sha, $size, $audit, $collector): SourcePackage {
            $path = $bucket.'/'.$prefix.'.zip';
            $artifact = Artifact::query()->where('disk', 's3')->where('path', $path)->first()
                ?? Artifact::query()->create([
                    'execution_id' => $export->execution_id,
                    'remote_operation_id' => $export->getKey(),
                    'type' => strtolower(ArtifactCategory::SOURCE_PACKAGE->value),
                    'category' => ArtifactCategory::SOURCE_PACKAGE->value,
                    'storage_mode' => 'REFERENCE',
                    'disk' => 's3',
                    'path' => $path,
                    'filename' => ($collector['package'] ?? 'package').'.zip',
                    'mime_type' => 'application/zip',
                    'size' => $size,
                    'sha256' => $sha,
                    'metadata' => ['collector_audit' => $audit, 'manifest_sha256' => $audit['manifest_sha256'],
                        's3' => ['region' => $region, 'bucket' => $bucket, 'key' => $prefix.'.zip'],
                        'upload_operation_uuid' => $upload->operation_uuid],
                ]);
            $package = $this->registry->register($artifact, $audit['source_id'], $audit['producer_version'], $audit['schema_version'],
                $audit['name'], ['moodle.source.export'], $audit['capabilities'], 'CONFIDENTIAL');
            $package = $this->registry->validate($package);

            $locked = RemoteOperation::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill(['evidence' => [...($locked->evidence ?? []), 'source_package_uuid' => $package->uuid]])->save();

            return $package;
        }, attempts: 3);

        AuditLog::query()->create([
            'actor_id' => $actor?->getKey(), 'project_id' => $export->execution->project_id, 'execution_id' => $export->execution_id,
            'action' => 'REMOTE_SOURCE_PACKAGE_REGISTERED',
            'auditable_type' => $package->getMorphClass(), 'auditable_id' => $package->getKey(),
            'payload' => ['source_package_uuid' => $package->uuid, 'sha256' => $sha, 'size' => $size, 'bucket' => $bucket,
                'key' => $prefix.'.zip', 'checksum_verification' => $storage['checksum_verification']],
        ]);

        return $package;
    }
}
