<?php

namespace App\Domain\Executions;

use App\Domain\Artifacts\RegisterReferencedArtifact;
use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Collector\CollectorExecutionPreparation;
use App\Domain\Collector\CollectorRegisteredCommand;
use App\Domain\Collector\CollectorRuntimeConfiguration;
use App\Domain\Executions\Contracts\ExecutionRuntimeProvider;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Domain\Tools\DeployToolDistribution;
use App\Domain\Tools\ToolDistributionVerifier;
use App\Domain\Tools\ToolOperationGate;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\ArtifactCategory;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Artifact;
use App\Models\Execution;
use App\Models\ExecutionCapacityApproval;
use App\Models\ExecutionCommand;
use App\Models\ExecutionEvent;
use App\Models\ExecutionLog;
use App\Models\ExecutionRuntimeConfiguration;
use App\Models\ExecutionToolBinding;
use App\Models\RemoteOperation;
use App\Models\ToolDistribution;
use FilesystemIterator;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class LocalToolExecutionProvider implements ExecutionRuntimeProvider
{
    public function __construct(
        private readonly ExecutionWorkspaceManager $workspaces,
        private readonly ToolDistributionVerifier $verifier,
        private readonly DeployToolDistribution $deployer,
        private readonly RemoteOperationCoordinator $operations,
        private readonly ToolOperationGate $gate,
        private readonly RegisterReferencedArtifact $artifacts,
        private readonly LocalProcessInspector $inspector,
    ) {}

    public function execute(ExecutionCommand $command, ToolAdapter $adapter): void
    {
        throw new ToolOperationBlocked('El provider real requiere un binding de catálogo y una operación explícita; la cola Fake se conserva como provider predeterminado.');
    }

    /** @return array<string, mixed> */
    public function prepare(Execution $execution, ToolDistribution $distribution): array
    {
        $workspace = $this->workspaces->prepare($execution);
        $verified = $this->verifier->verify($distribution);
        $evidence = [
            'execution_uuid' => $execution->uuid,
            'workspace_uuid' => $workspace->uuid,
            'distribution_key' => $distribution->key,
            'distribution_sha256' => $verified->treeSha256,
            'manifest_sha256' => $verified->manifestSha256,
            'file_count' => $verified->fileCount,
            'verified_at' => $verified->verifiedAt,
        ];
        $this->workspaces->writeState($execution, 'prepare-'.$workspace->uuid.'.json', $evidence);

        return $evidence;
    }

    /** @return array<string, mixed> */
    public function deploy(Execution $execution, ToolDistribution $distribution): array
    {
        return $this->deployer->deploy($execution, $distribution);
    }

    public function start(Execution $execution, string $idempotencyKey, string $commandKey, array $parameters = []): RemoteOperation
    {
        $binding = $execution->toolBinding()->with(['toolVersion.tool', 'distribution'])->first();
        if ($binding === null) {
            throw new ToolOperationBlocked('La ejecución no tiene un binding inmutable de versión y distribución.');
        }
        if ($binding->provider_key !== 'local-registered-process') {
            throw new ToolOperationBlocked('El binding pertenece a un proveedor distinto del runner local registrado.');
        }
        if ($binding->distribution->verification_state !== 'VERIFIED'
            || ! hash_equals($binding->distribution_sha256, $binding->distribution->distribution_sha256)
        ) {
            throw new ToolOperationBlocked('La distribución ya no coincide con el hash fijado al crear la ejecución.');
        }

        $capacity = ExecutionCapacityApproval::query()->where('execution_id', $execution->getKey())->find($binding->capacity_approval_id);
        if ($capacity === null || (int) $capacity->approved_quota_bytes !== (int) $binding->approved_quota_bytes) {
            throw new ToolOperationBlocked('La cuota de la ejecución no coincide con una aprobación de capacidad vigente.');
        }
        $available = @disk_free_space((string) config('toolkit.workspaces.root'));
        if (is_float($available) === false || $available < $capacity->approved_quota_bytes) {
            throw new ToolOperationBlocked('El espacio disponible ya no cubre la cuota aprobada para esta ejecución.');
        }

        if ($binding->toolVersion->tool->key === 'moodle-consolidador') {
            $this->assertApprovedV8RuntimeConfiguration($execution, $binding);
        }
        if ($binding->adapter_key === CollectorExecutionPreparation::ADAPTER_KEY) {
            $runtime = ExecutionRuntimeConfiguration::query()->whereKey($binding->runtime_configuration_id)
                ->where('execution_id', $execution->id)->firstOrFail();
            app(CollectorRuntimeConfiguration::class)->verify($execution, $runtime);
            if ($commandKey !== CollectorRegisteredCommand::KEY || $parameters !== ['project_uuid' => $execution->project->uuid,
                'execution_uuid' => $execution->uuid, 'runtime_sha256' => $runtime->content_sha256]) {
                throw new ToolOperationBlocked('El comando real solo acepta identidades y el hash de su runtime aprobado.');
            }
        }

        $this->gate->assertRunnable($binding->toolVersion, $binding->workflow_key);
        $distributionSlug = Str::slug($binding->distribution->key);
        $toolDirectory = $this->workspaces->resolve($execution, 'tools', $distributionSlug);
        $deploymentEvidencePath = $this->workspaces->resolve($execution, 'state', 'distribution-'.$distributionSlug.'.json');
        $deploymentEvidence = is_file($deploymentEvidencePath)
            ? json_decode((string) file_get_contents($deploymentEvidencePath), true)
            : null;
        if (! is_dir($toolDirectory) || ! is_array($deploymentEvidence)
            || ! isset($deploymentEvidence['source_tree_sha256'])
            || ! hash_equals($binding->distribution_sha256, (string) $deploymentEvidence['source_tree_sha256'])
        ) {
            throw new ToolOperationBlocked('La distribución fijada todavía no está desplegada y verificada en el workspace.');
        }

        return $this->operations->schedule(
            $execution,
            $idempotencyKey,
            $commandKey,
            $parameters,
            $distributionSlug,
        );
    }

    /** @return array<string, mixed> */
    public function inspect(RemoteOperation $operation): array
    {
        $operation->refresh();

        return [
            'operation_uuid' => $operation->operation_uuid,
            'provider_key' => $operation->provider_key,
            'host_id' => $operation->host_id,
            'runtime_key' => $operation->runtime_key,
            'process_id' => $operation->process_id,
            'communication_state' => $operation->communication_state->value,
            'functional_state' => $operation->functional_state->value,
            'last_heartbeat_at' => $operation->last_heartbeat_at?->toIso8601String(),
            'last_observed_at' => $operation->last_observed_at?->toIso8601String(),
            'next_poll_at' => $operation->next_poll_at?->toIso8601String(),
            'terminated_at' => $operation->terminated_at?->toIso8601String(),
            'exit_code' => $operation->exit_code,
            'evidence' => $operation->evidence,
        ];
    }

    public function poll(RemoteOperation $operation): RemoteOperation
    {
        return $this->reconcile($operation);
    }

    /** @return list<array<string, mixed>> */
    public function readEvents(RemoteOperation $operation, int $afterSequence = 0): array
    {
        $events = $operation->execution->events()->where('remote_operation_id', $operation->getKey())->where('sequence', '>', max(0, $afterSequence))->get()->map(fn (ExecutionEvent $event): array => [
            'sequence' => $event->sequence,
            'type' => $event->type,
            'step_key' => $event->step_key,
            'severity' => $event->severity->value,
            'progress' => $event->progress,
            'message' => app(SensitiveValueRedactor::class)->redactString((string) $event->message),
            'payload' => app(SensitiveValueRedactor::class)->redact($event->payload),
            'created_at' => $event->created_at->toIso8601String(),
        ])->all();

        return array_values($events);
    }

    /** @return list<array<string, mixed>> */
    public function readLogs(RemoteOperation $operation, int $afterId = 0): array
    {
        $redactor = app(SensitiveValueRedactor::class);

        $logs = ExecutionLog::query()
            ->where('execution_id', $operation->execution_id)
            ->where('remote_operation_id', $operation->getKey())
            ->where('id', '>', max(0, $afterId))
            ->orderBy('id')
            ->get()
            ->map(fn (ExecutionLog $log): array => [
                'stream' => $log->stream->value,
                'level' => $log->level,
                'message' => $redactor->redactString($log->message),
                'context' => $redactor->redact($log->context),
                'logged_at' => $log->logged_at?->toIso8601String(),
            ])->all();

        return array_values($logs);
    }

    public function heartbeat(RemoteOperation $operation): RemoteOperation
    {
        return $this->operations->heartbeat($operation);
    }

    public function reconcile(RemoteOperation $operation): RemoteOperation
    {
        return $this->operations->reconcile($operation);
    }

    public function cancel(RemoteOperation $operation): RemoteOperation
    {
        return $this->operations->cancel($operation);
    }

    public function stopRuntime(RemoteOperation $operation): RemoteOperation
    {
        throw new ToolOperationBlocked('La parada de un runtime completo no está implementada. Use la cancelación granular de esta operación.');
    }

    /**
     * @param  array<string, array<string, mixed>>  $metadataByPath
     * @return list<Artifact>
     */
    public function collectArtifacts(RemoteOperation $operation, array $metadataByPath = []): array
    {
        if (! $this->verifyTermination($operation)) {
            throw new RuntimeException('No se recogen artefactos hasta verificar la terminación del proceso registrado.');
        }

        $execution = $operation->execution;
        $root = $this->workspaces->resolve($execution, 'output');
        $descriptors = $operation->evidence['artifact_descriptors'] ?? null;
        if (! is_array($descriptors) || ! array_is_list($descriptors)) {
            throw new RuntimeException('La operación no tiene descriptores declarativos de artefactos.');
        }
        $declared = [];
        foreach ($descriptors as $descriptor) {
            if (! is_array($descriptor) || ! is_string($descriptor['relative_path'] ?? null)
                || ! is_string($descriptor['name'] ?? null) || ! is_string($descriptor['category'] ?? null)
                || ! is_array($descriptor['mime_types'] ?? null) || ! is_int($descriptor['max_size_bytes'] ?? null)
            ) {
                throw new RuntimeException('El contrato de descriptor de artefacto guardado no es válido.');
            }
            $relative = str_replace('\\', '/', $descriptor['relative_path']);
            if ($relative === '' || str_starts_with($relative, '/') || preg_match('#(^|/)\.\.?(/|$)#', $relative) === 1 || isset($declared[$relative])) {
                throw new RuntimeException('Un descriptor de artefacto contiene una ruta duplicada o insegura.');
            }
            $declared[$relative] = $descriptor;
        }
        $actual = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isLink()) {
                throw new RuntimeException('La recolección de artefactos rechazó un enlace simbólico.');
            }
            if (! $item->isFile()) {
                if ($item->isDir()) {
                    continue;
                }
                throw new RuntimeException('La recolección de artefactos rechazó un archivo especial.');
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            $actual[$relative] = $item->getPathname();
        }
        ksort($actual, SORT_STRING);
        $requiredPaths = array_keys(array_filter($declared, fn (array $descriptor): bool => ($descriptor['required'] ?? true) === true));
        sort($requiredPaths, SORT_STRING);
        if (array_diff(array_keys($actual), array_keys($declared)) !== []
            || array_diff($requiredPaths, array_keys($actual)) !== []
        ) {
            throw new RuntimeException('La salida contiene archivos no declarados o falta un artefacto requerido.');
        }

        $artifacts = [];
        foreach ($actual as $relative => $path) {
            $descriptor = $declared[$relative];
            $sizeBefore = filesize($path);
            $statBefore = @stat($path);
            $hash = hash_file('sha256', $path);
            $mime = function_exists('mime_content_type') ? (@mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
            clearstatcache(true, $path);
            $sizeAfter = filesize($path);
            $statAfter = @stat($path);
            if (! is_int($sizeBefore) || $sizeBefore > $descriptor['max_size_bytes']
                || $sizeAfter !== $sizeBefore || ! is_array($statBefore) || ! is_array($statAfter)
                || $statBefore['dev'] !== $statAfter['dev'] || $statBefore['ino'] !== $statAfter['ino']
                || $statBefore['size'] !== $statAfter['size'] || $statBefore['mtime'] !== $statAfter['mtime']
                || ! is_string($hash) || ! in_array($mime, $descriptor['mime_types'], true)
                || (isset($descriptor['expected_sha256']) && ! hash_equals($descriptor['expected_sha256'], $hash))
            ) {
                throw new RuntimeException("El artefacto declarado [{$relative}] no pasó validación de tamaño, hash estable o MIME.");
            }
            $category = ArtifactCategory::tryFrom($descriptor['category']);
            if ($category === null) {
                throw new RuntimeException('La categoría del descriptor no está permitida.');
            }
            $artifacts[] = $this->artifacts->register($execution, $path, $category, $descriptor['name'], [
                ...($metadataByPath[$relative] ?? []),
                'remote_operation_uuid' => $operation->operation_uuid,
                'command_key' => $operation->command_key,
                'source_relative_path' => $relative,
                'sensitivity' => $descriptor['sensitivity'] ?? 'INTERNAL',
                'declared_mime_type' => $mime,
                'storage_strategy' => 'same-filesystem-hard-link',
            ], $hash, $sizeBefore, (int) $operation->getKey());
        }

        return $artifacts;
    }

    public function verifyTermination(RemoteOperation $operation): bool
    {
        $operation->refresh();

        if ($operation->command_key === CollectorRegisteredCommand::KEY) {
            return $this->operations->verifyTerminalEvidence($operation);
        }

        return $operation->communication_state->value === 'TERMINATED'
            && $operation->terminated_at !== null
            && ! $this->inspector->isRunning($operation);
    }

    public function cleanup(Execution $execution): void
    {
        $this->workspaces->cleanup($execution);
    }

    private function assertApprovedV8RuntimeConfiguration(Execution $execution, ExecutionToolBinding $binding): void
    {
        if ($binding->runtime_configuration_id === null) {
            throw new ToolOperationBlocked('V8 no puede iniciar sin configuración runtime generada y aprobada.');
        }
        $configuration = ExecutionRuntimeConfiguration::query()
            ->whereKey($binding->runtime_configuration_id)
            ->where('execution_id', $execution->getKey())
            ->where('tool_version_id', $binding->tool_version_id)
            ->where('approval_state', 'APPROVED')
            ->first();
        if ($configuration === null) {
            throw new ToolOperationBlocked('La aprobación de configuración runtime de V8 no existe o fue revocada.');
        }
        $path = $this->workspaces->resolve($execution, 'state', $configuration->relative_path);
        if (is_link($path) || ! is_file($path)) {
            throw new ToolOperationBlocked('No existe el archivo de configuración activa aprobado de V8.');
        }
        $contents = file_get_contents($path);
        $hash = hash_file('sha256', $path);
        if (! is_string($contents) || ! is_string($hash) || ! hash_equals($configuration->content_sha256, $hash)) {
            throw new ToolOperationBlocked('La configuración activa cambió después de su aprobación.');
        }
        foreach (['pregrado-2026-03-04-directo', 'posgrados-2025-05-02-directo', 'benchmark-operator'] as $forbidden) {
            if (str_contains($contents, $forbidden)) {
                throw new ToolOperationBlocked('La configuración activa de V8 contiene una decisión del benchmark.');
            }
        }
    }
}
