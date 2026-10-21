<?php

namespace App\Domain\Remote;

use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Executions\ExecutionLifecycle;
use App\Domain\Tools\SourcePackageRegistry;
use App\Enums\EventSeverity;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Enums\RemoteCommunicationState;
use App\Enums\RemoteFunctionalState;
use App\Exceptions\ToolOperationBlocked;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Project;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\SourcePackage;
use App\Models\Verification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Drives an Execution through a remote collection: export on the source
 * server, publication to S3 and registration of the SourcePackage, ending
 * in REVIEW. Each pass is bounded and idempotent; the scheduler repeats it
 * while the Execution is active, so losing a worker never loses the run.
 */
class RemoteCollectionWorkflow
{
    private const LOCK = 74204;

    private const MANUAL_AFTER = 8;

    public function __construct(
        private readonly RemoteCollectorExport $exports,
        private readonly RemoteSourcePackagePublisher $publisher,
        private readonly RemoteAgentOperations $operations,
        private readonly ExecutionLifecycle $lifecycle,
        private readonly ExecutionEventRecorder $events,
        private readonly SourcePackageRegistry $packages,
        private readonly RemoteSourceCleanup $cleanup,
    ) {}

    /**
     * Launches one export per source, once. Every source is checked before
     * any is launched, so a source that is not ready never leaves the others
     * exporting alone; a launch the platform rejects closes the Execution.
     *
     * @return list<RemoteOperation>
     */
    public function start(Execution $execution): array
    {
        $settings = $this->settings($execution);
        $existing = $this->exports($execution);
        if ($existing->isNotEmpty()) {
            return array_values($existing->all());
        }
        if ($execution->status === ExecutionStatus::QUEUED) {
            $execution = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::RUNNING);
        }
        if ($execution->status !== ExecutionStatus::RUNNING) {
            throw new ToolOperationBlocked('El estado vigente no admite iniciar la recolección remota.');
        }
        $targets = [];
        try {
            foreach (RemoteCollectionConfiguration::sources($settings) as $source) {
                $server = Server::query()->where('project_id', $execution->project_id)->where('uuid', $source['server_uuid'])->firstOrFail();
                $this->exports->assertLaunchable($server, $source['source_profile']);
                $targets[] = [$server, $source];
            }
        } catch (ToolOperationBlocked $exception) {
            $this->close($execution, null, false, $exception->getMessage());

            return [];
        }
        $this->step($execution, 'collection', ExecutionStepStatus::RUNNING);
        $operations = [];
        foreach ($targets as [$server, $source]) {
            try {
                $operation = $this->exports->start($execution, $server, $source['source_profile'], $source['package_name'],
                    (string) ($settings['workers'] ?? 1), $execution->creator);
            } catch (ToolOperationBlocked $exception) {
                // Already launched sources are cancelled; the Execution fails once all of them end.
                $this->failSources($execution, $exception->getMessage());

                return $operations;
            }
            $operations[] = $operation;
            $this->events->record($execution->fresh(), 'collector.operation_registered', 'collection',
                message: 'La exportación de '.$server->name.' quedó registrada en el servidor y continuará aunque se cierre el navegador.',
                payload: ['operation_uuid' => $operation->operation_uuid, 'server_uuid' => $server->uuid, 'source_profile' => $source['source_profile'],
                    'package_name' => $source['package_name']], operation: $operation);
        }

        return $operations;
    }

    /**
     * The packages can be confirmed only while every export and publication
     * ended well and each object in S3 still matches what was reviewed.
     *
     * @return list<SourcePackage>
     */
    public function assertFinalizable(Execution $execution): array
    {
        $settings = $this->settings($execution);
        $exports = $this->exports($execution);
        if ($exports->count() !== count(RemoteCollectionConfiguration::sources($settings))) {
            throw new ToolOperationBlocked('La confirmación requiere una exportación por origen configurado.');
        }
        foreach ($exports as $export) {
            foreach ([$export, $this->upload($execution, $export)] as $operation) {
                if ($operation === null || $operation->communication_state !== RemoteCommunicationState::TERMINATED
                    || $operation->functional_state !== RemoteFunctionalState::SUCCEEDED || $operation->exit_code !== 0) {
                    throw new ToolOperationBlocked('La confirmación requiere todas las exportaciones y publicaciones terminadas con éxito.');
                }
            }
        }
        $packages = array_values(SourcePackage::query()->where('producer_execution_id', $execution->id)->orderBy('uuid')->get()
            ->map(fn (SourcePackage $package): SourcePackage => $this->packages->validate($package))->all());
        if (count($packages) !== $exports->count() || collect($packages)->contains(fn (SourcePackage $package): bool => $package->validation_state !== 'VALID')
            || ! is_string($execution->review_fingerprint) || ! hash_equals($this->fingerprint($execution, $packages), $execution->review_fingerprint)) {
            throw new ToolOperationBlocked('La identidad de los paquetes cambió después de la revisión.');
        }

        return $packages;
    }

    /** Requests cancellation of every active operation; the Execution closes once all of them terminate. */
    public function cancel(Execution $execution): void
    {
        foreach ($execution->remoteOperations()->where('communication_state', '!=', RemoteCommunicationState::TERMINATED->value)->get() as $operation) {
            if ($operation->process_id !== null) {
                $this->operations->cancel($operation, $execution->creator);
            }
        }
        $this->advanceExecution($execution);
    }

    /** One bounded pass for an operation of a remote collection. */
    public function advance(RemoteOperation $operation): void
    {
        $operation->loadMissing('execution');
        $execution = $operation->execution;
        if (! RemoteCollectionPreparation::selected($execution->toolBinding)) {
            throw new ToolOperationBlocked('La operación no pertenece a una recolección remota.');
        }
        if ($operation->command_key === RemoteCollectorExport::COMMAND) {
            // A terminated export with a complete report needs no further call to the server.
            if ($operation->communication_state !== RemoteCommunicationState::TERMINATED || ! $this->reportFinal($operation)) {
                $this->exports->observe($operation);
            }
        } elseif ($operation->command_key === RemoteSourcePackagePublisher::COMMAND) {
            // Packages are registered only once every upload ended well (advanceLocked); a
            // cancelled or closed Execution never registers one and its objects stay in S3.
            $this->operations->reconcile($operation);
        }
        $this->advanceExecution($execution);
    }

    private function advanceExecution(Execution $execution): void
    {
        $pdo = DB::connection()->getPdo();
        $lock = $pdo->prepare('SELECT pg_try_advisory_lock('.self::LOCK.', ?)');
        $lock->execute([$execution->id]);
        if (! $lock->fetchColumn()) {
            return;
        }
        try {
            $this->advanceLocked($execution->fresh());
            // Once the package is verified in S3 or the Execution closed, the source keeps only evidence.
            $this->cleanup->clean($execution->fresh());
        } finally {
            $pdo->prepare('SELECT pg_advisory_unlock('.self::LOCK.', ?)')->execute([$execution->id]);
        }
    }

    private function advanceLocked(Execution $execution): void
    {
        if (! $execution->status->isActive()) {
            return;
        }
        $exports = $this->exports($execution);
        $active = $execution->remoteOperations()->where('communication_state', '!=', RemoteCommunicationState::TERMINATED->value)->exists();
        if ($execution->status === ExecutionStatus::CANCELLING) {
            if (! $active) {
                $this->close($execution, $exports->first(), true);
            }

            return;
        }
        if ($exports->isEmpty()) {
            return;
        }
        // A source that failed fails the Execution: the others are cancelled and it closes when all end.
        if (($reason = $this->failureReason($execution)) !== null) {
            if ($active) {
                $this->cancelActive($execution);
            } else {
                $this->close($execution, $exports->first(), false, $reason);
            }

            return;
        }
        $this->progress($execution, $exports);
        $ready = true;
        foreach ($exports as $export) {
            if ($export->communication_state !== RemoteCommunicationState::TERMINATED) {
                $ready = false;

                continue;
            }
            $report = $export->evidence['collector'] ?? null;
            if (is_array($report) && ($report['package_ready'] ?? false) === true) {
                continue;
            }
            $ready = false;
            if ($export->functional_state !== RemoteFunctionalState::SUCCEEDED || $export->exit_code !== 0 || $this->reportFinal($export)) {
                $this->failSources($execution, 'La exportación de '.$this->serverName($export).' terminó sin un paquete válido; su evidencia se conserva para un nuevo intento.', $export);

                return;
            }
            // The process ended well but its final report was not read yet.
            $this->defer($export, 'REMOTE_REPORT_PENDING', 'La exportación terminó; falta leer su reporte final.');
        }
        if (! $ready) {
            return;
        }
        if ($execution->status === ExecutionStatus::RUNNING) {
            $this->step($execution, 'collection', ExecutionStepStatus::SUCCESS);
            $execution = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::VERIFYING);
            $this->step($execution, 'verification', ExecutionStepStatus::RUNNING);
            foreach ($exports as $export) {
                $this->events->record($execution, 'collector.export_completed', 'collection',
                    message: 'El Recolector terminó en '.$this->serverName($export).' y produjo el paquete.',
                    payload: ['package_sha256' => $export->evidence['collector']['package_sha256'] ?? null,
                        'package_size' => $export->evidence['collector']['package_size'] ?? null], operation: $export);
            }
        }
        if ($execution->status !== ExecutionStatus::VERIFYING) {
            return;
        }
        $uploads = [];
        foreach ($exports as $export) {
            $upload = $this->upload($execution, $export);
            if ($upload === null) {
                $upload = $this->publisher->publish($export, $execution->creator);
                $this->events->record($execution, 'collector.publication_requested', 'verification',
                    message: $this->serverName($export).' está validando y publicando su paquete en el almacenamiento de la plataforma.',
                    payload: ['operation_uuid' => $upload->operation_uuid], operation: $upload);
            }
            $uploads[] = $upload;
        }
        foreach ($uploads as $upload) {
            if ($upload->communication_state === RemoteCommunicationState::TERMINATED && $upload->functional_state !== RemoteFunctionalState::SUCCEEDED) {
                $this->failSources($execution, 'La publicación de un paquete falló; su evidencia se conserva para un nuevo intento.', $upload);

                return;
            }
        }
        if (collect($uploads)->contains(fn (RemoteOperation $upload): bool => $upload->communication_state !== RemoteCommunicationState::TERMINATED)) {
            return;
        }
        // Every upload ended well: now each package is verified from S3 and registered.
        $packages = [];
        foreach ($uploads as $upload) {
            try {
                $package = $this->publisher->observe($upload);
            } catch (ToolOperationBlocked $exception) {
                $this->defer($upload, 'REMOTE_PUBLICATION_PENDING', $exception->getMessage());

                return;
            }
            if ($package === null || $package->validation_state !== 'VALID') {
                return;
            }
            $packages[] = $package;
        }
        $this->review($execution, $uploads, $packages);
    }

    /** Records once why the collection fails and cancels the sources still running. */
    private function failSources(Execution $execution, string $reason, ?RemoteOperation $operation = null): void
    {
        if ($this->failureReason($execution) === null) {
            $this->events->record($execution, 'collector.source_failed', 'collection', EventSeverity::WARNING, message: $reason,
                payload: ['operation_uuid' => $operation?->operation_uuid], operation: $operation);
        }
        $this->cancelActive($execution);
        if (! $execution->remoteOperations()->where('communication_state', '!=', RemoteCommunicationState::TERMINATED->value)->exists()) {
            $this->close($execution, $operation, false, $reason);
        }
    }

    private function cancelActive(Execution $execution): void
    {
        foreach ($execution->remoteOperations()->where('communication_state', '!=', RemoteCommunicationState::TERMINATED->value)->get() as $active) {
            if ($active->process_id !== null && ! isset($active->evidence['cancel_requested_at'])) {
                try {
                    $this->operations->cancel($active, $execution->creator);
                } catch (ToolOperationBlocked) {
                    // A non-cancellable or not yet confirmed operation is left to end on its own.
                }
            }
        }
    }

    private function failureReason(Execution $execution): ?string
    {
        $reason = $execution->events()->where('type', 'collector.source_failed')->orderByDesc('sequence')->value('message');

        return is_string($reason) ? $reason : null;
    }

    private function serverName(RemoteOperation $operation): string
    {
        return (string) (Server::query()->where('uuid', (string) $operation->host_id)->value('name') ?? 'el servidor de origen');
    }

    /**
     * Real progress only: completed over known total across every source, and
     * unknown until each source reports its total.
     *
     * @param  Collection<int, RemoteOperation>  $exports
     */
    private function progress(Execution $execution, Collection $exports): void
    {
        $total = 0;
        $completed = 0;
        $failed = 0;
        foreach ($exports as $export) {
            $courses = $export->evidence['collector']['progress'] ?? null;
            if (! is_array($courses) || ! is_int($courses['total_courses'] ?? null) || $courses['total_courses'] < 1
                || ! is_int($courses['completed_courses'] ?? null)) {
                return;
            }
            $total += $courses['total_courses'];
            $completed += $courses['completed_courses'];
            $failed += is_int($courses['failed_courses'] ?? null) ? $courses['failed_courses'] : 0;
        }
        $progress = max(0, min(100, (int) floor($completed / $total * 100)));
        if ($execution->status !== ExecutionStatus::RUNNING || $execution->progress === $progress) {
            return;
        }
        DB::transaction(function () use ($execution, $exports, $progress, $total, $completed, $failed): void {
            $locked = Execution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ExecutionStatus::RUNNING || $locked->progress === $progress) {
                return;
            }
            $locked->forceFill(['progress' => $progress])->save();
            ExecutionStep::query()->where('execution_id', $locked->id)->where('step_key', 'collection')->update(['progress' => $progress]);
            $this->events->record($locked, 'collector.progress', 'collection', progress: $progress,
                message: 'Cursos exportados: '.$completed.' de '.$total.($exports->count() > 1 ? ' en '.$exports->count().' orígenes' : '').'.',
                payload: ['total_courses' => $total, 'completed_courses' => $completed, 'failed_courses' => $failed, 'sources' => $exports->count()],
                operation: $exports->first());
        }, attempts: 3);
    }

    /**
     * @param  list<RemoteOperation>  $uploads
     * @param  list<SourcePackage>  $packages
     */
    private function review(Execution $execution, array $uploads, array $packages): void
    {
        DB::transaction(function () use ($execution, $uploads, $packages): void {
            Project::query()->whereKey($execution->project_id)->lockForUpdate()->firstOrFail();
            $locked = Execution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ExecutionStatus::VERIFYING) {
                return;
            }
            $fingerprint = $this->fingerprint($locked, $packages);
            Verification::query()->firstOrCreate(['execution_id' => $locked->id, 'proposal_version' => 0],
                ['key' => 'collector-remote-package-audit', 'fingerprint' => $fingerprint, 'status' => 'PASSED', 'approved' => true,
                    'summary' => count($packages) === 1 ? 'Paquete fuente validado en origen y verificado desde el almacenamiento por la plataforma.'
                        : count($packages).' paquetes fuente validados en sus orígenes y verificados desde el almacenamiento por la plataforma.',
                    'details' => ['packages' => array_map(fn (SourcePackage $package): array => $package->evidence['collector_audit'] ?? [], $packages)],
                    'checked_at' => now()->utc()]);
            $locked->forceFill(['progress' => null, 'review_fingerprint' => $fingerprint,
                'validated_proposal_version' => 0, 'validated_fingerprint' => $fingerprint])->save();
            $this->step($locked, 'verification', ExecutionStepStatus::SUCCESS);
            $review = $this->lifecycle->transitionForWorker($locked, ExecutionStatus::REVIEW);
            foreach ($packages as $index => $package) {
                $this->events->record($review, 'collector.package_registered', 'verification',
                    message: 'El paquete fuente '.$package->name.' verificado está disponible para revisión.',
                    payload: ['source_package_uuid' => $package->uuid, 'producer_version' => $package->producer_tool_version,
                        'sha256' => $package->sha256, 'manifest_sha256' => $package->manifest_sha256], operation: $uploads[$index] ?? null);
                AuditLog::query()->create(['project_id' => $locked->project_id, 'execution_id' => $locked->id,
                    'action' => 'COLLECTOR_PACKAGE_REGISTERED', 'payload' => ['source_package_uuid' => $package->uuid, 'sha256' => $package->sha256, 'mode' => 'REMOTE']]);
            }
        }, attempts: 3);
    }

    /**
     * Identity of what was reviewed; for one package it equals the single-source form.
     *
     * @param  list<SourcePackage>  $packages
     */
    private function fingerprint(Execution $execution, array $packages): string
    {
        $parts = collect($packages)->sortBy('uuid')->map(fn (SourcePackage $package): string => $package->uuid.'|'.$package->sha256.'|'.$package->manifest_sha256);

        return hash('sha256', $execution->uuid.'|'.$parts->implode('|'));
    }

    private function close(Execution $execution, ?RemoteOperation $operation, bool $cancelled, ?string $reason = null): void
    {
        DB::transaction(function () use ($execution, $operation, $cancelled, $reason): void {
            Project::query()->whereKey($execution->project_id)->lockForUpdate()->firstOrFail();
            $locked = Execution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->isActive()) {
                return;
            }
            $cancelled = $cancelled || $locked->status === ExecutionStatus::CANCELLING;
            foreach ($locked->steps()->whereIn('status', ['RUNNING', 'PENDING'])->get() as $step) {
                $step->forceFill(['status' => $cancelled ? ExecutionStepStatus::CANCELLED : ExecutionStepStatus::FAILED, 'finished_at' => now()->utc()])->save();
            }
            $closed = $this->lifecycle->transitionForWorker($locked, $cancelled ? ExecutionStatus::CANCELLED : ExecutionStatus::FAILED);
            $this->events->record($closed, $cancelled ? 'collector.cancelled' : 'collector.failed', 'collection', EventSeverity::WARNING,
                message: $cancelled ? 'Las operaciones remotas terminaron; cancelación confirmada.'
                    : ($reason ?? 'La operación remota terminó sin un paquete válido; su evidencia se conserva para un nuevo intento.'), operation: $operation);
            AuditLog::query()->create(['project_id' => $closed->project_id, 'execution_id' => $closed->id,
                'action' => $cancelled ? 'COLLECTOR_CANCELLATION_CONFIRMED' : 'COLLECTOR_EXECUTION_FAILED',
                'payload' => ['operation_uuid' => $operation?->operation_uuid, 'exit_code' => $operation?->exit_code, 'mode' => 'REMOTE']]);
        }, attempts: 3);
    }

    /** Keeps a terminated operation due with backoff; flags it for review after repeated attempts. */
    private function defer(RemoteOperation $operation, string $code, string $message): void
    {
        DB::transaction(function () use ($operation, $code, $message): void {
            $locked = RemoteOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $attempts = min(65535, $locked->reconcile_attempts + 1);
            $manual = $attempts >= self::MANUAL_AFTER;
            if ($locked->last_reconcile_error !== $code || $manual !== $locked->manual_intervention_required) {
                $this->events->record($locked->execution, 'collector.reconciliation_pending', severity: EventSeverity::WARNING,
                    message: $manual ? 'La recolección remota requiere revisar su evidencia: '.$message : $message,
                    payload: ['manual_intervention_required' => $manual, 'code' => $code], operation: $locked);
            }
            $locked->forceFill(['reconcile_attempts' => $attempts, 'last_reconcile_error' => $code, 'manual_intervention_required' => $manual,
                'next_poll_at' => $manual ? null : now()->utc()->addSeconds(min(900, 15 * (2 ** min(6, $attempts))))])->save();
        }, attempts: 3);
    }

    private function reportFinal(RemoteOperation $operation): bool
    {
        $state = $operation->evidence['collector']['status']['state'] ?? null;

        return in_array($state, ['success', 'error', 'failed', 'cancelled'], true);
    }

    /** @return Collection<int, RemoteOperation> */
    private function exports(Execution $execution): Collection
    {
        return $execution->remoteOperations()->where('command_key', RemoteCollectorExport::COMMAND)->orderBy('id')->get();
    }

    private function upload(Execution $execution, RemoteOperation $export): ?RemoteOperation
    {
        return $execution->remoteOperations()->where('command_key', RemoteSourcePackagePublisher::COMMAND)
            ->where('evidence->parameters->collector', $export->operation_uuid)->orderByDesc('id')->first();
    }

    /** @return array<string, mixed> */
    private function settings(Execution $execution): array
    {
        $binding = $execution->toolBinding;
        $settings = $binding?->configuration_snapshot;
        if (! RemoteCollectionPreparation::selected($binding) || ! is_array($settings)
            || ! in_array($settings['schema_version'] ?? null, [RemoteCollectionConfiguration::SCHEMA, RemoteCollectionConfiguration::SCHEMA_V1], true)
            || RemoteCollectionConfiguration::sources($settings) === []) {
            throw new ToolOperationBlocked('La ejecución no tiene un binding de recolección remota.');
        }

        return $settings;
    }

    private function step(Execution $execution, string $key, ExecutionStepStatus $status): void
    {
        $step = ExecutionStep::query()->where('execution_id', $execution->id)->where('step_key', $key)->firstOrFail();
        $step->forceFill(['status' => $status, 'progress' => $status === ExecutionStepStatus::SUCCESS ? 100 : $step->progress,
            'started_at' => $step->started_at ?? now()->utc(),
            'finished_at' => $status === ExecutionStepStatus::SUCCESS ? now()->utc() : null])->save();
    }
}
