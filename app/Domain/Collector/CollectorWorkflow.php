<?php

namespace App\Domain\Collector;

use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Executions\ExecutionLifecycle;
use App\Domain\Executions\LocalToolExecutionProvider;
use App\Domain\Executions\RemoteOperationCoordinator;
use App\Domain\Tools\SourcePackageRegistry;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\EventSeverity;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Exceptions\ToolOperationBlocked;
use App\Jobs\RunRegisteredRemoteOperation;
use App\Models\Artifact;
use App\Models\AuditLog;
use App\Models\CollectorPackageAudit;
use App\Models\Execution;
use App\Models\ExecutionRuntimeConfiguration;
use App\Models\ExecutionStep;
use App\Models\ExecutionToolBinding;
use App\Models\Project;
use App\Models\RemoteOperation;
use App\Models\SourcePackage;
use App\Models\Verification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/** Independent of the browser/session and of a long-lived Laravel job. */
final class CollectorWorkflow
{
    public function __construct(private readonly LocalToolExecutionProvider $provider,
        private readonly ExecutionWorkspaceManager $workspaces, private readonly CollectorRuntimeConfiguration $runtime,
        private readonly CollectorEventObserver $observer, private readonly RemoteOperationCoordinator $operations,
        private readonly CollectorPackageInspector $inspector, private readonly SourcePackageRegistry $packages,
        private readonly ExecutionLifecycle $lifecycle, private readonly ExecutionEventRecorder $events) {}

    public function start(Execution $execution): RemoteOperation
    {
        $binding = $this->binding($execution);
        $existing = $execution->remoteOperations()->where('command_key', CollectorRegisteredCommand::KEY)->first();
        if ($existing !== null) {
            return $existing;
        }
        $runtime = ExecutionRuntimeConfiguration::query()->whereKey($binding->runtime_configuration_id)->firstOrFail();
        $this->runtime->verify($execution, $runtime);
        if ($execution->status === ExecutionStatus::QUEUED) {
            $execution = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::RUNNING);
        }
        if ($execution->status !== ExecutionStatus::RUNNING) {
            throw new ToolOperationBlocked('El estado vigente no admite iniciar COLLECT LAB.');
        }
        $this->provider->prepare($execution, $binding->distribution);
        $this->provider->deploy($execution, $binding->distribution);
        $this->step($execution, 'preparation', ExecutionStepStatus::SUCCESS);
        $this->step($execution, 'collection', ExecutionStepStatus::RUNNING);

        return DB::transaction(function () use ($execution, $runtime): RemoteOperation {
            Project::query()->whereKey($execution->project_id)->lockForUpdate()->firstOrFail();
            $locked = Execution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ExecutionStatus::RUNNING) {
                throw new ToolOperationBlocked('El estado cambió antes de registrar el lanzamiento.');
            }

            return $this->provider->start($locked, 'collector-export-'.$locked->uuid, CollectorRegisteredCommand::KEY,
                ['project_uuid' => $locked->project->uuid, 'execution_uuid' => $locked->uuid, 'runtime_sha256' => $runtime->content_sha256]);
        }, attempts: 3);
    }

    public function observe(RemoteOperation $operation): void
    {
        if ($operation->host_id !== (gethostname() ?: 'local') || $operation->command_key !== CollectorRegisteredCommand::KEY) {
            throw new ToolOperationBlocked('La observación real requiere el namespace del runner registrado.');
        }
        $binding = $this->binding($operation->execution);
        $pdo = DB::connection()->getPdo();
        $lock = $pdo->prepare('SELECT pg_try_advisory_lock(74203, ?)');
        $lock->execute([$operation->id]);
        if (! $lock->fetchColumn()) {
            return;
        }
        try {
            if ($operation->launch_claimed_at === null && $operation->process_id === null
                && $operation->communication_state->value !== 'TERMINATED' && ! isset($operation->evidence['cancel_requested_at'])) {
                if (! config('toolkit.features.recolector_742.enabled') || ! config('toolkit.features.local_runner.enabled')) {
                    $operation->forceFill(['next_poll_at' => now()->utc()->addSeconds(30)])->save();

                    return;
                }
                $runtime = ExecutionRuntimeConfiguration::query()->whereKey($binding->runtime_configuration_id)->firstOrFail();
                $this->runtime->verify($operation->execution, $runtime);
                dispatch((new RunRegisteredRemoteOperation($operation->id, CollectorRegisteredCommand::KEY,
                    ['project_uuid' => $operation->execution->project->uuid, 'execution_uuid' => $operation->execution->uuid,
                        'runtime_sha256' => $runtime->content_sha256], 'moodle-recolector-742-linux-tree'))->onConnection('redis-tool-runs')->onQueue('tool-runs'));
                $operation->forceFill(['next_poll_at' => now()->utc()->addSeconds(15)])->save();

                return;
            }
            $operation = $this->operations->reconcile($operation);
            $execution = $operation->execution->fresh();
            if ($execution->status->isTerminal() || $execution->status === ExecutionStatus::REVIEW) {
                return;
            }
            $beforeLaunch = ($operation->evidence['cancelled_before_launch'] ?? false) === true
                || ($operation->evidence['cancelled_before_process_start'] ?? false) === true;
            $cursor = $beforeLaunch ? null : $this->observer->observe($operation);
            if ($operation->communication_state->value !== 'TERMINATED') {
                return;
            }
            if (! $this->provider->verifyTermination($operation)) {
                $this->deferObservation($operation);

                return;
            }
            if ($cursor !== null && ($cursor->reader_health !== 'OK' || ! $cursor->read_complete)) {
                if ($cursor->reader_health !== 'OK') {
                    $this->deferObservation($operation);
                }

                return;
            }
            $this->removePrivateConfiguration($execution);
            if ($execution->status === ExecutionStatus::CANCELLING || $operation->functional_state->value !== 'SUCCEEDED' || $operation->exit_code !== 0) {
                $this->closeFailureOrCancellation($execution, $operation);

                return;
            }
            if ($execution->status === ExecutionStatus::RUNNING) {
                $execution = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::VERIFYING);
                $this->step($execution, 'collection', ExecutionStepStatus::SUCCESS);
                $this->step($execution, 'verification', ExecutionStepStatus::RUNNING);
            }
            try {
                $path = $this->outputPath($execution, 'source-package.zip', 21474836480);
                $audit = CollectorPackageAudit::query()->where('remote_operation_id', $operation->id)->first();
                if ($audit === null) {
                    $hash = is_file($path) && ! is_link($path) ? hash_file('sha256', $path) : false;
                    $bytes = is_file($path) && ! is_link($path) ? filesize($path) : false;
                    if (! is_string($hash) || ! is_int($bytes)) {
                        throw new ToolOperationBlocked('Falta un paquete fuente verificable.');
                    }
                    // The bridge measured code before export and after its package audit.
                    // Group/supervisor termination is reconciled separately above; no
                    // unmeasured data or database immutability is inferred from either.
                    $sourceAccess = $this->sourceAccess($execution);
                    $snapshot = [...$this->inspector->inspect($execution, $binding->distribution, $path, $hash, $bytes),
                        'source_access' => $sourceAccess,
                        'terminal_reconciliation' => 'VERIFIED_PROCESS_GROUP_AND_SUPERVISOR_TERMINATED'];
                    if (($snapshot['producer_version'] ?? null) !== '7.4.2-linux') {
                        throw new ToolOperationBlocked('Las nuevas recolecciones requieren productor 7.4.2.');
                    }
                    $audit = CollectorPackageAudit::query()->create(['remote_operation_id' => $operation->id,
                        'execution_id' => $execution->id, 'package_sha256' => $hash, 'package_bytes' => $bytes, 'snapshot' => $snapshot]);
                }
                if (! hash_equals($audit->package_sha256, (string) hash_file('sha256', $path)) || filesize($path) !== $audit->package_bytes) {
                    throw new ToolOperationBlocked('El paquete cambió después de la auditoría independiente.');
                }
                $metadata = $this->artifactMetadata($execution, $operation, $audit);
                $artifacts = $this->provider->collectArtifacts($operation, $metadata);
                $source = collect($artifacts)->first(fn ($artifact): bool => $artifact->category === 'SOURCE_PACKAGE');
                if ($source === null) {
                    throw new ToolOperationBlocked('No se capturó el paquete fuente declarado.');
                }
                $snapshot = $audit->snapshot;
                $package = $this->packages->register($source, $snapshot['source_id'], $snapshot['producer_version'], $snapshot['schema_version'],
                    $snapshot['name'], ['moodle.source.export'], $snapshot['capabilities'], 'CONFIDENTIAL');
                $package = $this->packages->validate($package);
                $this->review($execution, $operation, $package, $snapshot);
            } catch (ToolOperationBlocked|\JsonException|\InvalidArgumentException) {
                $this->closeFailureOrCancellation($execution, $operation);
            }
        } finally {
            $unlock = $pdo->prepare('SELECT pg_advisory_unlock(74203, ?)');
            $unlock->execute([$operation->id]);
        }
    }

    public function deferObservation(RemoteOperation $operation): void
    {
        DB::transaction(function () use ($operation): void {
            Project::query()->whereKey($operation->execution->project_id)->lockForUpdate()->firstOrFail();
            $execution = Execution::query()->whereKey($operation->execution_id)->lockForUpdate()->firstOrFail();
            $locked = RemoteOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if (! $execution->status->isActive()) {
                return;
            }
            $attempts = min(65535, $locked->reconcile_attempts + 1);
            $manual = $attempts >= 8;
            $code = 'COLLECTOR_RECONCILIATION_PENDING';
            if ($locked->last_reconcile_error !== $code || $manual !== $locked->manual_intervention_required) {
                $this->events->record($execution, 'collector.reconciliation_pending', severity: EventSeverity::WARNING,
                    message: $manual ? 'La operación requiere revisar su evidencia o limpieza privada en el runner.' : 'La observación quedó pendiente; se conserva la operación y su evidencia.',
                    payload: ['manual_intervention_required' => $manual], operation: $locked);
            }
            $locked->forceFill(['reconcile_attempts' => $attempts, 'last_reconcile_error' => $code,
                'manual_intervention_required' => $manual, 'next_poll_at' => $manual ? null : now()->utc()->addSeconds(min(300, 15 * (2 ** min(4, $attempts))))])->save();
        }, attempts: 3);
    }

    public function assertFinalizable(Execution $execution): SourcePackage
    {
        $this->binding($execution);
        $operation = $execution->remoteOperations()->where('command_key', CollectorRegisteredCommand::KEY)->sole();
        if (! $this->provider->verifyTermination($operation) || $operation->exit_code !== 0
            || $operation->functional_state->value !== 'SUCCEEDED') {
            throw new ToolOperationBlocked('La finalización requiere terminación íntegra en el runner.');
        }
        $this->removePrivateConfiguration($execution);
        $audit = CollectorPackageAudit::query()->where('remote_operation_id', $operation->id)->sole();
        $artifacts = $this->provider->collectArtifacts($operation, $this->artifactMetadata($execution, $operation, $audit));
        if (count($artifacts) !== 6 || $execution->artifacts()->where('remote_operation_id', $operation->id)->count() !== 6) {
            throw new ToolOperationBlocked('La captura real no coincide con los seis artefactos declarados.');
        }
        $package = SourcePackage::query()->where('producer_execution_id', $execution->id)->sole();
        $package = $this->packages->validate($package);
        $fingerprint = hash('sha256', $execution->uuid.'|'.$package->uuid.'|'.$package->sha256.'|'.$package->manifest_sha256);
        if (! is_string($execution->review_fingerprint) || ! hash_equals($fingerprint, $execution->review_fingerprint)) {
            throw new ToolOperationBlocked('La identidad del paquete cambió después de la revisión.');
        }

        return $package;
    }

    private function binding(Execution $execution): ExecutionToolBinding
    {
        $binding = $execution->toolBinding()->with('distribution')->first();
        if ($binding === null || $binding->adapter_key !== CollectorExecutionPreparation::ADAPTER_KEY
            || $binding->provider_key !== 'local-registered-process' || $binding->runtime_configuration_id === null) {
            throw new ToolOperationBlocked('La ejecución carece de binding real y runtime aprobado.');
        }

        return $binding;
    }

    private function removePrivateConfiguration(Execution $execution): void
    {
        $path = $this->workspaces->resolve($execution, 'input', 'moodle-runtime.php');
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return;
        }
        if (is_link($path) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || ($stat['mode'] & 0777) !== 0600 || ! @unlink($path)) {
            throw new ToolOperationBlocked('La retirada de configuración privada requiere reconciliación antes de cerrar.');
        }
        clearstatcache(true, $path);
        if (file_exists($path) || is_link($path)) {
            throw new ToolOperationBlocked('No se pudo acreditar la retirada de configuración privada.');
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function artifactMetadata(Execution $execution, RemoteOperation $operation, CollectorPackageAudit $audit): array
    {
        $zip = new ZipArchive;
        $path = $this->outputPath($execution, 'source-package.zip', 21474836480);
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new ToolOperationBlocked('El paquete auditado dejó de ser un ZIP íntegro.');
        }
        try {
            foreach (['manifest.json' => 'manifest.json', 'inventario-origen.json' => 'inventory.json'] as $entry => $file) {
                $content = $zip->getFromName($entry, 1048577);
                $output = $this->outputPath($execution, $file, 1048576);
                if (! is_string($content) || strlen($content) > 1048576 || ! is_file($output)
                    || ! hash_equals(hash('sha256', $content), (string) hash_file('sha256', $output))) {
                    throw new ToolOperationBlocked('Un manifiesto o inventario no coincide con el paquete auditado.');
                }
            }
            $inventory = json_decode((string) $zip->getFromName('inventario-origen.json', 1048576), true, 64, JSON_THROW_ON_ERROR);
            $visual = json_decode($this->smallOutput($execution, 'visual-inventory.json', 1048576), true, 64, JSON_THROW_ON_ERROR);
            $validation = json_decode($this->smallOutput($execution, 'validation.json', 65536), true, 32, JSON_THROW_ON_ERROR);
            $sourceAccess = $this->sourceAccess($execution);
            $sidecar = $this->smallOutput($execution, 'source-package.zip.sha256', 256);
            if (! is_array($visual) || ! is_array($inventory) || ($visual['schema_version'] ?? null) !== 'collector-visual-inventory.v1'
                || ($visual['themes'] ?? null) !== ($inventory['themes'] ?? null) || ($visual['theme_assignments'] ?? null) !== ($inventory['theme_assignments'] ?? null)
                || ! is_array($validation) || ($validation['package_sha256'] ?? null) !== $audit->package_sha256
                || ($validation['schema_version'] ?? null) !== 'collector-package-audit.v1'
                || ($validation['validator_version'] ?? null) !== '7.4.2-linux'
                || ($validation['manifest_sha256'] ?? null) !== $audit->snapshot['manifest_sha256']
                || ($validation['package_bytes'] ?? null) !== $audit->package_bytes || ($validation['result'] ?? null) !== 'VALID'
                || ($validation['operation_uuid'] ?? null) !== $operation->operation_uuid
                // PostgreSQL JSON objects may reorder keys; values are strictly
                // typed by the closed source-access contract before comparison.
                || $sourceAccess != ($audit->snapshot['source_access'] ?? null)
                || ($audit->snapshot['terminal_reconciliation'] ?? null) !== 'VERIFIED_PROCESS_GROUP_AND_SUPERVISOR_TERMINATED'
                || preg_match('/^'.preg_quote($audit->package_sha256, '/').'\s+(?:\*| )?source-package\.zip\s*$/D', trim($sidecar)) !== 1) {
                throw new ToolOperationBlocked('La evidencia visual, de validación o sidecar no corresponde al paquete.');
            }
        } finally {
            $zip->close();
        }

        return ['source-package.zip' => ['collector_audit' => $audit->snapshot,
            'manifest_sha256' => $audit->snapshot['manifest_sha256'], 'collector_audit_id' => $audit->id]];
    }

    /** @return array<string, mixed> */
    private function sourceAccess(Execution $execution): array
    {
        $validation = json_decode($this->smallOutput($execution, 'validation.json', 65536), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($validation) || array_diff(array_keys($validation), ['schema_version', 'result', 'package_sha256', 'package_bytes',
            'manifest_sha256', 'validator_version', 'operation_uuid', 'runtime_sha256', 'counts', 'warnings_count', 'source_access', 'producer_write_declarations']) !== []
            || ! is_array($validation['source_access'] ?? null)
            || ($validation['producer_write_declarations'] ?? null) !== ['source_write_performed' => 'DECLARED_NOT_VERIFIED',
                'destination_write_performed' => 'DECLARED_NOT_VERIFIED']) {
            throw new ToolOperationBlocked('Falta evidencia medida y explícita de acceso al origen.');
        }
        try {
            $binding = $this->binding($execution);
            $runtime = ExecutionRuntimeConfiguration::query()->whereKey($binding->runtime_configuration_id)->firstOrFail();
            $this->runtime->verify($execution, $runtime);
            if (($validation['runtime_sha256'] ?? null) !== $runtime->content_sha256) {
                throw new \RuntimeException('Source evidence belongs to a different approved runtime.');
            }
            (new CollectorSourceEvidence)->validate($validation['source_access']);
        } catch (\RuntimeException) {
            throw new ToolOperationBlocked('La evidencia de acceso al origen no cumple el contrato protegido.');
        }

        return $validation['source_access'];
    }

    private function outputPath(Execution $execution, string $name, int $maximumBytes): string
    {
        $path = $this->workspaces->resolve($execution, 'output', $name);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || is_link($path) || realpath($path) !== $path || ($stat['mode'] & 0170000) !== 0100000
            || $stat['size'] < 1 || $stat['size'] > $maximumBytes || ! in_array($stat['nlink'], [1, 2], true)) {
            throw new ToolOperationBlocked('La salida no es un archivo regular dentro del contrato declarado.');
        }
        if ($stat['nlink'] === 2) {
            $artifact = Artifact::query()->where('execution_id', $execution->id)->where('metadata->source_relative_path', $name)->first();
            $target = $artifact === null ? false : @lstat(Storage::disk($artifact->disk)->path($artifact->path));
            if ($artifact === null || $target === false || ($target['mode'] & 0170000) !== 0100000 || $target['nlink'] !== 2
                || $target['dev'] !== $stat['dev'] || $target['ino'] !== $stat['ino']
                || $artifact->size !== $stat['size'] || ! hash_equals($artifact->sha256, (string) hash_file('sha256', $path))) {
                throw new ToolOperationBlocked('La salida tiene un enlace que no pertenece a su captura acreditada.');
            }
        }

        return $path;
    }

    private function smallOutput(Execution $execution, string $name, int $maximumBytes): string
    {
        $path = $this->outputPath($execution, $name, $maximumBytes);
        $bytes = file_get_contents($path, length: max(1, $maximumBytes + 1));
        if (! is_string($bytes) || strlen($bytes) > $maximumBytes) {
            throw new ToolOperationBlocked('La evidencia supera su límite de lectura.');
        }

        return $bytes;
    }

    /** @param array<string, mixed> $snapshot */
    private function review(Execution $execution, RemoteOperation $operation, SourcePackage $package, array $snapshot): void
    {
        DB::transaction(function () use ($execution, $operation, $package, $snapshot): void {
            Project::query()->whereKey($execution->project_id)->lockForUpdate()->firstOrFail();
            $locked = Execution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ExecutionStatus::VERIFYING) {
                return;
            }
            $fingerprint = hash('sha256', $locked->uuid.'|'.$package->uuid.'|'.$package->sha256.'|'.$package->manifest_sha256);
            Verification::query()->firstOrCreate(['execution_id' => $locked->id, 'proposal_version' => 0],
                ['key' => 'collector-package-audit', 'fingerprint' => $fingerprint, 'status' => 'PASSED', 'approved' => true,
                    'summary' => 'Paquete fuente auditado contra distribución 7.4.2; conserva backups y hashes.',
                    'details' => $snapshot, 'checked_at' => now()->utc()]);
            $locked->forceFill(['progress' => null, 'review_fingerprint' => $fingerprint,
                'validated_proposal_version' => 0, 'validated_fingerprint' => $fingerprint])->save();
            $this->step($locked, 'verification', ExecutionStepStatus::SUCCESS);
            $review = $this->lifecycle->transitionForWorker($locked, ExecutionStatus::REVIEW);
            $this->events->record($review, 'collector.package_registered', 'verification', message: 'El paquete auditado está disponible para revisión y confirmación.',
                payload: ['source_package_uuid' => $package->uuid, 'producer_version' => $package->producer_tool_version,
                    'sha256' => $package->sha256, 'manifest_sha256' => $package->manifest_sha256], operation: $operation);
            AuditLog::query()->create(['project_id' => $locked->project_id, 'execution_id' => $locked->id,
                'action' => 'COLLECTOR_PACKAGE_REGISTERED', 'payload' => ['source_package_uuid' => $package->uuid, 'sha256' => $package->sha256]]);
        }, attempts: 3);
    }

    private function closeFailureOrCancellation(Execution $execution, RemoteOperation $operation): void
    {
        DB::transaction(function () use ($execution, $operation): void {
            Project::query()->whereKey($execution->project_id)->lockForUpdate()->firstOrFail();
            $locked = Execution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->isActive()) {
                return;
            }
            $cancelled = $locked->status === ExecutionStatus::CANCELLING;
            $target = $cancelled ? ExecutionStatus::CANCELLED : ExecutionStatus::FAILED;
            foreach ($locked->steps()->whereIn('status', ['RUNNING', 'PENDING'])->get() as $step) {
                $step->forceFill(['status' => $cancelled ? ExecutionStepStatus::CANCELLED : ExecutionStepStatus::FAILED, 'finished_at' => now()->utc()])->save();
            }
            $closed = $this->lifecycle->transitionForWorker($locked, $target);
            $this->events->record($closed, $cancelled ? 'collector.cancelled' : 'collector.failed', 'collection', EventSeverity::WARNING,
                message: $cancelled ? 'La operación terminó y retiró su configuración privada; cancelación confirmada.'
                    : 'La operación real terminó sin un paquete válido; se conserva su evidencia para un nuevo intento.', operation: $operation);
            AuditLog::query()->create(['project_id' => $closed->project_id, 'execution_id' => $closed->id,
                'action' => $cancelled ? 'COLLECTOR_CANCELLATION_CONFIRMED' : 'COLLECTOR_EXECUTION_FAILED',
                'payload' => ['operation_uuid' => $operation->operation_uuid, 'exit_code' => $operation->exit_code]]);
        }, attempts: 3);
    }

    private function step(Execution $execution, string $key, ExecutionStepStatus $status): void
    {
        $step = ExecutionStep::query()->where('execution_id', $execution->id)->where('step_key', $key)->firstOrFail();
        $step->forceFill(['status' => $status, 'progress' => null, 'started_at' => $step->started_at ?? now()->utc(),
            'finished_at' => $status === ExecutionStepStatus::SUCCESS ? now()->utc() : null])->save();
    }
}
