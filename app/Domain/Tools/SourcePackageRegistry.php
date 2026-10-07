<?php

namespace App\Domain\Tools;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Enums\ArtifactCategory;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Artifact;
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
     * @param  list<string>  $compatibleWorkflows
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
        if ($producerOperation === null || $producerOperation->terminated_at === null || is_array($exitEvidence) === false
            || ($exitEvidence['operation_uuid'] ?? null) !== $producerOperation->operation_uuid
            || ($exitEvidence['command_sha256'] ?? null) !== $producerOperation->command_sha256
        ) {
            throw new ToolOperationBlocked('El paquete fuente solo se registra después de confirmar la terminación de su operación productora.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $sourceId) !== 1 || $producerToolVersion === '' || $schemaVersion === '' || $name === '') {
            throw new ToolOperationBlocked('La identidad y el esquema del paquete fuente son obligatorios.');
        }
        if (in_array($sensitivity, ['PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'], true) === false
            || array_is_list($compatibleWorkflows) === false
            || array_filter($compatibleWorkflows, fn (mixed $workflow): bool => is_string($workflow) === false
                || preg_match('/^[a-z][a-z0-9._:-]{1,119}$/D', $workflow) !== 1) !== []
        ) {
            throw new ToolOperationBlocked('La sensibilidad o los flujos compatibles del paquete fuente no son válidos.');
        }
        $identityStrings = [$sourceId, $producerToolVersion, $schemaVersion, $name, ...$compatibleWorkflows];
        if (array_filter($identityStrings, fn (string $value): bool => $this->redactor->redactString($value) !== $value) !== []
            || $this->redactor->redact($capabilities) !== $capabilities
        ) {
            throw new ToolOperationBlocked('Los metadatos del paquete fuente contienen material sensible y no se persistirán.');
        }

        $compatibleWorkflows = array_values(array_unique($compatibleWorkflows));
        sort($compatibleWorkflows, SORT_STRING);

        return DB::transaction(fn (): SourcePackage => SourcePackage::query()->create([
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
            ],
        ]));
    }

    public function validate(SourcePackage $package): SourcePackage
    {
        $package->loadMissing('artifact', 'producerExecution');
        $artifact = $package->artifact;
        if ($package->validation_state === 'REVOKED' || $package->availability !== 'AVAILABLE'
            || $artifact === null || $artifact->category !== ArtifactCategory::SOURCE_PACKAGE->value
            || (int) $artifact->execution_id !== (int) $package->producer_execution_id
            || hash_equals($package->sha256, $artifact->sha256) === false
            || (int) $package->size_bytes !== (int) $artifact->size
        ) {
            throw new ToolOperationBlocked('El artefacto fuente dejó de coincidir con su registro inmutable.');
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
}
