<?php

namespace App\Domain\Tools;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Collector\CollectorRegisteredCommand;
use App\Enums\ArtifactCategory;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Artifact;
use App\Models\CollectorPackageAudit;
use App\Models\RemoteOperation;
use App\Models\SourcePackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SourcePackageRegistry
{
    private readonly SensitiveValueRedactor $redactor;

    public function __construct(SensitiveValueRedactor $redactor)
    {
        $this->redactor = $redactor;
    }

    /**
     * @param  array<array-key, mixed>  $compatibleWorkflows
     * @param  array<string, mixed>  $capabilities
     */
    public function register(
        Artifact $artifact,
        string $sourceId,
        string $producerToolVersion,
        string $schemaVersion,
        string $name,
        array $compatibleWorkflows,
        array $capabilities = [],
        string $sensitivity = 'INTERNAL',
    ): SourcePackage {
        $artifact->loadMissing('execution.project');
        $execution = $artifact->execution;
        if ($artifact->category !== ArtifactCategory::SOURCE_PACKAGE->value || $execution === null) {
            throw new ToolOperationBlocked('El artefacto debe pertenecer a la categoría SOURCE_PACKAGE.');
        }
        $producerOperation = $execution->remoteOperations()
            ->whereKey($artifact->remote_operation_id)
            ->where('communication_state', 'TERMINATED')
            ->first();
        $exitEvidence = $producerOperation?->evidence['exit_evidence'] ?? null;
        if ($producerOperation === null || $producerOperation->terminated_at === null || $producerOperation->exit_code !== 0
            || $producerOperation->functional_state->value !== 'SUCCEEDED' || is_array($exitEvidence) === false
            || ($exitEvidence['operation_uuid'] ?? null) !== $producerOperation->operation_uuid
            || ($exitEvidence['command_sha256'] ?? null) !== $producerOperation->command_sha256
        ) {
            throw new ToolOperationBlocked('El paquete fuente solo se registra después de confirmar la terminación de su operación productora.');
        }
        if (! in_array($producerToolVersion, ['7.4.1-linux', '7.4.2-linux'], true)
            || ! in_array($schemaVersion, ['1.0', 'recolector-source.v1'], true)) {
            throw new ToolOperationBlocked('La versión productora o schema del paquete fuente es desconocido.');
        }
        $collectorAudit = $artifact->metadata['collector_audit'] ?? null;
        if ($producerOperation->command_key === CollectorRegisteredCommand::KEY) {
            $this->assertDurableCollectorAudit($artifact);
        }
        if ($schemaVersion === '1.0' && (! is_array($collectorAudit)
            || ($collectorAudit['validation_schema'] ?? null) !== 'collector-web-audit.v1'
            || ($collectorAudit['result'] ?? null) !== 'VALID'
            || ($collectorAudit['execution_uuid'] ?? null) !== $execution->uuid
            || ($collectorAudit['project_uuid'] ?? null) !== $execution->project->uuid
            || ($collectorAudit['package_sha256'] ?? null) !== $artifact->sha256
            || ($collectorAudit['package_bytes'] ?? null) !== $artifact->size
            || ($collectorAudit['producer_version'] ?? null) !== $producerToolVersion
            || ($collectorAudit['source_id'] ?? null) !== $sourceId
            || ($collectorAudit['capabilities'] ?? null) !== $capabilities
            || ($collectorAudit['manifest_sha256'] ?? null) !== ($artifact->metadata['manifest_sha256'] ?? null))) {
            throw new ToolOperationBlocked('El paquete requiere una auditoría íntegra vinculada a su proyecto, ejecución y manifiesto.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $sourceId) !== 1 || $name === '') {
            throw new ToolOperationBlocked('La identidad y el esquema del paquete fuente son obligatorios.');
        }
        if (in_array($sensitivity, ['PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'], true) === false
            || array_is_list($compatibleWorkflows) === false
        ) {
            throw new ToolOperationBlocked('La sensibilidad o los flujos compatibles del paquete fuente no son válidos.');
        }
        $workflowKeys = [];
        foreach ($compatibleWorkflows as $workflow) {
            if (is_string($workflow) === false || preg_match('/^[a-z][a-z0-9._:-]{1,119}$/D', $workflow) !== 1) {
                throw new ToolOperationBlocked('La sensibilidad o los flujos compatibles del paquete fuente no son válidos.');
            }
            $workflowKeys[] = $workflow;
        }
        $identityStrings = [$sourceId, $producerToolVersion, $schemaVersion, $name, ...$workflowKeys];
        if (array_filter($identityStrings, fn (string $value): bool => $this->redactor->redactString($value) !== $value) !== []
            || $this->redactor->redact($capabilities) !== $capabilities
        ) {
            throw new ToolOperationBlocked('Los metadatos del paquete fuente contienen material sensible y no se persistirán.');
        }

        $compatibleWorkflows = array_values(array_unique($workflowKeys));
        sort($compatibleWorkflows, SORT_STRING);

        $attributes = [
            'uuid' => (string) Str::uuid(),
            'project_id' => $execution->project_id,
            'producer_execution_id' => $execution->getKey(),
            'artifact_id' => $artifact->getKey(),
            'producer_tool_version' => $producerToolVersion,
            'schema_version' => $schemaVersion,
            'source_id' => $sourceId,
            'name' => $name,
            'size_bytes' => $artifact->size,
            'sha256' => $artifact->sha256,
            'manifest_sha256' => $artifact->metadata['manifest_sha256'] ?? null,
            'validation_state' => 'REGISTERED',
            'capabilities' => $capabilities,
            'compatibility' => ['workflows' => $compatibleWorkflows],
            'sensitivity' => $sensitivity,
            'availability' => 'AVAILABLE',
            'evidence' => [
                'registered_at' => now()->utc()->toIso8601String(),
                'producer_operation_id' => $artifact->remote_operation_id,
                'producer_execution_uuid' => $execution->uuid,
                'collector_audit' => $schemaVersion === '1.0' ? $collectorAudit : null,
            ],
        ];

        return DB::transaction(function () use ($artifact, $attributes): SourcePackage {
            Artifact::query()->whereKey($artifact->id)->lockForUpdate()->firstOrFail();
            $existing = SourcePackage::query()->where('artifact_id', $artifact->id)->first();
            if ($existing !== null) {
                foreach (['project_id', 'producer_execution_id', 'artifact_id', 'producer_tool_version', 'schema_version',
                    'source_id', 'name', 'size_bytes', 'sha256', 'manifest_sha256', 'capabilities', 'compatibility', 'sensitivity'] as $key) {
                    if ($this->canonical($existing->getAttribute($key)) !== $this->canonical($attributes[$key])) {
                        throw new ToolOperationBlocked('El artefacto ya tiene un registro fuente con otra identidad o contrato.');
                    }
                }
                if ($existing->validation_state === 'REVOKED' || $existing->availability !== 'AVAILABLE') {
                    throw new ToolOperationBlocked('El registro fuente existente no está disponible.');
                }

                return $existing;
            }

            return SourcePackage::query()->create($attributes);
        });
    }

    public function validate(SourcePackage $package): SourcePackage
    {
        $package->loadMissing('artifact', 'producerExecution');
        if (RemoteOperation::query()->whereKey($package->artifact?->remote_operation_id)->where('command_key', CollectorRegisteredCommand::KEY)->exists()) {
            $this->assertDurableCollectorAudit($package->artifact);
        }
        $this->assertKnownContract($package);
        $artifact = $package->artifact;
        if ($package->validation_state === 'REVOKED' || $package->availability !== 'AVAILABLE'
            || $artifact === null || $artifact->category !== ArtifactCategory::SOURCE_PACKAGE->value
            || (int) $artifact->execution_id !== (int) $package->producer_execution_id
            || hash_equals($package->sha256, $artifact->sha256) === false
            || (int) $package->size_bytes !== (int) $artifact->size
        ) {
            throw new ToolOperationBlocked('El artefacto fuente dejó de coincidir con su registro inmutable.');
        }
        if ($package->schema_version === '1.0' && ($this->canonical($package->evidence['collector_audit'] ?? null) !== $this->canonical($artifact->metadata['collector_audit'] ?? null)
            || ($package->evidence['collector_audit']['result'] ?? null) !== 'VALID')) {
            throw new ToolOperationBlocked('La evidencia de auditoría del paquete fue sustituida.');
        }

        $path = Storage::disk($artifact->disk)->path($artifact->path);
        $hash = is_file($path) && is_link($path) === false ? hash_file('sha256', $path) : false;
        $size = is_file($path) && is_link($path) === false ? filesize($path) : false;
        if (is_string($hash) === false || hash_equals($package->sha256, $hash) === false || $size !== $package->size_bytes) {
            throw new ToolOperationBlocked('El contenido del paquete fuente cambió desde su registro.');
        }

        $package->forceFill([
            'validation_state' => 'VALID',
            'validated_at' => now()->utc(),
            'evidence' => [...($package->evidence ?? []), 'validated_sha256' => $hash, 'validated_size_bytes' => $size],
        ])->save();

        return $package->refresh();
    }

    public function revoke(SourcePackage $package, string $reason): SourcePackage
    {
        if (trim($reason) === '' || $this->redactor->redactString($reason) !== $reason) {
            throw new ToolOperationBlocked('La revocación requiere un motivo auditable.');
        }

        $package->forceFill([
            'validation_state' => 'REVOKED',
            'availability' => 'REVOKED',
            'revoked_at' => now()->utc(),
            'evidence' => [...($package->evidence ?? []), 'revocation_reason' => $reason],
        ])->save();

        return $package->refresh();
    }

    public function assertKnownContract(SourcePackage $package): void
    {
        if (! in_array($package->producer_tool_version, ['7.4.1-linux', '7.4.2-linux'], true)
            || ! in_array($package->schema_version, ['1.0', 'recolector-source.v1'], true)) {
            throw new ToolOperationBlocked('El productor o schema existente no tiene un contrato reconocido.');
        }
        if ($package->schema_version === '1.0') {
            $audit = $package->evidence['collector_audit'] ?? null;
            if (! is_array($audit) || ($audit['validation_schema'] ?? null) !== 'collector-web-audit.v1'
                || ($audit['result'] ?? null) !== 'VALID' || ($audit['producer_version'] ?? null) !== $package->producer_tool_version
                || ($audit['package_sha256'] ?? null) !== $package->sha256 || ($audit['source_id'] ?? null) !== $package->source_id
                || ($audit['manifest_sha256'] ?? null) !== $package->manifest_sha256
                || $this->canonical($audit['capabilities'] ?? null) !== $this->canonical($package->capabilities)
                || ($package->producer_tool_version === '7.4.2-linux' && ($package->capabilities['theme_inventory'] ?? null) !== '1.0')) {
                throw new ToolOperationBlocked('El contrato existente necesita auditoría verificable y capabilities compatibles.');
            }
        }
    }

    private function assertDurableCollectorAudit(Artifact $artifact): void
    {
        $id = $artifact->metadata['collector_audit_id'] ?? null;
        $audit = is_int($id) ? CollectorPackageAudit::query()->whereKey($id)->where('execution_id', $artifact->execution_id)
            ->where('remote_operation_id', $artifact->remote_operation_id)->first() : null;
        if ($audit === null || $audit->package_sha256 !== $artifact->sha256 || $audit->package_bytes !== $artifact->size
            || $this->canonical($audit->snapshot) !== $this->canonical($artifact->metadata['collector_audit'] ?? null)) {
            throw new ToolOperationBlocked('El paquete real carece de su auditoría independiente inmutable.');
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
