<?php

namespace App\Domain\Executions;

use App\Domain\Artifacts\RegisterReferencedArtifact;
use App\Domain\Executions\Contracts\ExecutionRuntimeProvider;
use App\Domain\Tools\DeployToolDistribution;
use App\Domain\Tools\ToolDistributionVerifier;
use App\Domain\Tools\ToolOperationGate;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\ArtifactCategory;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Artifact;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\ExecutionLog;
use App\Models\RemoteOperation;
use App\Models\ToolDistribution;
use App\Domain\Tools\Contracts\ToolAdapter;
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

    public function deploy(Execution $execution, ToolDistribution $distribution): array
    {
        return $this->deployer->deploy($execution, $distribution);
    }

    public function start(Execution $execution, string $idempotencyKey, string $commandKey, array $parameters = []): RemoteOperation
    {
        $binding = $execution->toolBinding()->with(['toolVersion', 'distribution'])->first();
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

        $this->gate->assertRunnable($binding->toolVersion, $binding->workflow_key);
        $distributionSlug = \Illuminate\Support\Str::slug($binding->distribution->key);
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

    public function readEvents(RemoteOperation $operation): array
    {
        return $operation->execution->events()->get()->map(fn ($event): array => [
            'sequence' => $event->sequence,
            'type' => $event->type,
            'step_key' => $event->step_key,
            'severity' => $event->severity->value,
            'progress' => $event->progress,
            'message' => app(\App\Domain\Artifacts\SensitiveValueRedactor::class)->redactString((string) $event->message),
            'payload' => app(\App\Domain\Artifacts\SensitiveValueRedactor::class)->redact($event->payload),
            'created_at' => $event->created_at?->toIso8601String(),
        ])->all();
    }

    public function readLogs(RemoteOperation $operation): array
    {
        $redactor = app(\App\Domain\Artifacts\SensitiveValueRedactor::class);

        return ExecutionLog::query()
            ->where('execution_id', $operation->execution_id)
            ->orderBy('id')
            ->get()
            ->map(fn (ExecutionLog $log): array => [
                'stream' => $log->stream->value,
                'level' => $log->level,
                'message' => $redactor->redactString($log->message),
                'context' => $redactor->redact($log->context),
                'logged_at' => $log->logged_at?->toIso8601String(),
            ])->all();
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
        // The local provider owns only the registered process represented by this operation.
        return $this->cancel($operation);
    }

    /** @return list<Artifact> */
    public function collectArtifacts(RemoteOperation $operation): array
    {
        if (! $this->verifyTermination($operation)) {
            throw new RuntimeException('No se recogen artefactos hasta verificar la terminación del proceso registrado.');
        }

        $execution = $operation->execution;
        $root = $this->workspaces->resolve($execution, 'output');
        $artifacts = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isLink()) {
                throw new RuntimeException('La recolección de artefactos rechazó un enlace simbólico.');
            }
            if (! $item->isFile()) {
                continue;
            }

            $name = $item->getFilename();
            $extension = strtolower($item->getExtension());
            $category = match (true) {
                $extension === 'mbz' => ArtifactCategory::COURSE_PACKAGE,
                $extension === 'zip' => ArtifactCategory::SOURCE_PACKAGE,
                $extension === 'log' => ArtifactCategory::LOG,
                str_contains(strtolower($name), 'manifest') => ArtifactCategory::MANIFEST,
                in_array($extension, ['json', 'csv', 'html', 'pdf'], true) => ArtifactCategory::REPORT,
                default => ArtifactCategory::TECHNICAL_EVIDENCE,
            };
            $artifacts[] = $this->artifacts->register($execution, $item->getPathname(), $category, $name, [
                'remote_operation_uuid' => $operation->operation_uuid,
                'command_key' => $operation->command_key,
                'source_relative_path' => str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1)),
                'storage_strategy' => 'same-filesystem-hard-link',
            ]);
        }

        return $artifacts;
    }

    public function verifyTermination(RemoteOperation $operation): bool
    {
        $operation->refresh();

        return $operation->communication_state->value === 'TERMINATED'
            && $operation->terminated_at !== null
            && ! $this->inspector->isRunning($operation);
    }

    public function cleanup(Execution $execution): void
    {
        $this->workspaces->cleanup($execution);
    }
}
