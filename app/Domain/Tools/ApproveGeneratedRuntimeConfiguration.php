<?php

namespace App\Domain\Tools;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Exceptions\ToolOperationBlocked;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\ExecutionRuntimeConfiguration;
use App\Models\ToolVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApproveGeneratedRuntimeConfiguration
{
    private const FORBIDDEN_BENCHMARK_VALUES = [
        'pregrado-2026-03-04-directo',
        'posgrados-2025-05-02-directo',
        'benchmark-operator',
    ];

    private readonly ExecutionWorkspaceManager $workspaces;

    private readonly SensitiveValueRedactor $redactor;

    public function __construct(
        ExecutionWorkspaceManager $workspaces,
        SensitiveValueRedactor $redactor,
    ) {
        $this->workspaces = $workspaces;
        $this->redactor = $redactor;
    }

    /** @param array<string, mixed> $configuration */
    public function approve(Execution $execution, ToolVersion $version, array $configuration, string $schemaVersion, string $source, User $actor): ExecutionRuntimeConfiguration
    {
        if ($version->tool->key !== 'moodle-consolidador') {
            throw new ToolOperationBlocked('Esta aprobación de configuración solo aplica al Consolidador V8.');
        }
        if ($schemaVersion === '' || trim($source) === '' || $configuration === []
            || $this->redactor->redactString($schemaVersion) !== $schemaVersion
            || $this->redactor->redactString($source) !== $source
        ) {
            throw new ToolOperationBlocked('La configuración V8 generada requiere contenido, esquema y origen.');
        }
        $encoded = json_encode($this->canonicalize($configuration), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach (self::FORBIDDEN_BENCHMARK_VALUES as $value) {
            if (str_contains($encoded, $value)) {
                throw new ToolOperationBlocked('La configuración contiene una decisión identificable del benchmark y no puede activarse.');
            }
        }
        $this->assertNoInlineSecrets($configuration);

        $distribution = $version->distributions()->where('verification_state', 'VERIFIED')->first();
        if ($distribution === null) {
            throw new ToolOperationBlocked('No existe una distribución V8 verificada para vincular la configuración generada.');
        }
        $relativePath = 'runtime-config/'.Str::slug($distribution->key).'/config.json';
        $contentHash = hash('sha256', $encoded);
        $fingerprint = hash('sha256', json_encode([
            'execution_uuid' => $execution->uuid,
            'tool_version_id' => $version->getKey(),
            'schema_version' => $schemaVersion,
            'source' => $source,
            'approved_by' => $actor->getKey(),
            'content_sha256' => $contentHash,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($execution, $version, $schemaVersion, $source, $actor, $relativePath, $encoded, $contentHash, $fingerprint): ExecutionRuntimeConfiguration {
            $existing = ExecutionRuntimeConfiguration::query()
                ->where('execution_id', $execution->getKey())
                ->where('tool_version_id', $version->getKey())
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (hash_equals($existing->fingerprint, $fingerprint)) {

                    return $existing;
                }
                throw new ToolOperationBlocked('La configuración aprobada para esta ejecución y versión es inmutable.');
            }

            $this->workspaces->writeAtomic($execution, 'state', $relativePath, $encoded);

            $approved = ExecutionRuntimeConfiguration::query()->create([
                'execution_id' => $execution->getKey(),
                'tool_version_id' => $version->getKey(),
                'schema_version' => $schemaVersion,
                'source' => $source,
                'approved_by' => $actor->getKey(),
                'relative_path' => $relativePath,
                'content_sha256' => $contentHash,
                'fingerprint' => $fingerprint,
                'approval_state' => 'APPROVED',
                'approved_at' => now()->utc(),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'project_id' => $execution->project_id,
                'execution_id' => $execution->getKey(),
                'action' => 'execution.runtime_configuration.approved',
                'payload' => ['runtime_configuration_id' => $approved->getKey(), 'schema_version' => $schemaVersion, 'fingerprint' => $fingerprint],
            ]);

            return $approved;
        });
    }

    /** @param array<string, mixed> $configuration */
    private function assertNoInlineSecrets(array $configuration): void
    {
        foreach ($configuration as $key => $value) {
            if ($this->redactor->isSensitiveKeyName((string) $key)) {
                if (is_array($value) === false || array_diff(array_keys($value), ['secret_ref', 'version']) !== []
                    || is_string($value['secret_ref'] ?? null) === false || is_string($value['version'] ?? null) === false
                    || $value['secret_ref'] === '' || $value['version'] === ''
                    || $this->redactor->redactString($value['secret_ref']) !== $value['secret_ref']
                    || $this->redactor->redactString($value['version']) !== $value['version']
                ) {
                    throw new ToolOperationBlocked('No existe un almacén de secretos aprobado; no se guarda ningún secreto en claro.');
                }

                continue;
            }
            if (is_string($value) && $this->redactor->redactString($value) !== $value) {
                throw new ToolOperationBlocked('La configuración runtime contiene material sensible dentro de un valor de texto.');
            }
            if (is_array($value)) {
                $this->assertNoInlineSecrets($value);
            }
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value) === false) {

            return $value;
        }
        if (array_is_list($value)) {

            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
