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
    ) {}

    /** Launches the export once; a launch the platform rejects closes the Execution as failed. */
    public function start(Execution $execution): ?RemoteOperation
    {
        $settings = $this->settings($execution);
        $existing = $this->export($execution);
        if ($existing !== null) {
            return $existing;
        }
        if ($execution->status === ExecutionStatus::QUEUED) {
            $execution = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::RUNNING);
        }
        if ($execution->status !== ExecutionStatus::RUNNING) {
            throw new ToolOperationBlocked('El estado vigente no admite iniciar la recolección remota.');
        }
        $server = Server::query()->where('project_id', $execution->project_id)->where('uuid', (string) $settings['server_uuid'])->firstOrFail();
        $this->step($execution, 'collection', ExecutionStepStatus::RUNNING);
        try {
            $operation = $this->exports->start($execution, $server, (string) $settings['source_profile'], (string) $settings['package_name'],
                (string) $settings['workers'], $execution->creator);
        } catch (ToolOperationBlocked $exception) {
            $this->close($execution, null, false, $exception->getMessage());

            return null;
        }
        $this->events->record($execution->fresh(), 'collector.operation_registered', 'collection',
            message: 'La exportación quedó registrada en el servidor de origen y continuará aunque se cierre el navegador.',
            payload: ['operation_uuid' => $operation->operation_uuid, 'server_uuid' => $server->uuid, 'source_profile' => (string) $settings['source_profile']],
            operation: $operation);

        return $operation;
    }

    /**
     * The package can be confirmed only while both operations ended well and
     * the object in S3 still matches what was reviewed.
     */
    public function assertFinalizable(Execution $execution): SourcePackage
    {
        $this->settings($execution);
        $export = $this->export($execution);
        $upload = $export === null ? null : $this->upload($execution, $export);
        foreach ([$export, $upload] as $operation) {
            if ($operation === null || $operation->communication_state !== RemoteCommunicationState::TERMINATED
                || $operation->functional_state !== RemoteFunctionalState::SUCCEEDED || $operation->exit_code !== 0) {
                throw new ToolOperationBlocked('La confirmación requiere la exportación y la publicación terminadas con éxito.');
            }
        }
        $package = $this->packages->validate(SourcePackage::query()->where('producer_execution_id', $execution->id)->sole());
        $fingerprint = hash('sha256', $execution->uuid.'|'.$package->uuid.'|'.$package->sha256.'|'.$package->manifest_sha256);
        if ($package->validation_state !== 'VALID' || ! is_string($execution->review_fingerprint) || ! hash_equals($fingerprint, $execution->review_fingerprint)) {
            throw new ToolOperationBlocked('La identidad del paquete cambió después de la revisión.');
        }

        return $package;
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
        } elseif ($operation->command_key === RemoteSourcePackagePublisher::COMMAND && $execution->status !== ExecutionStatus::VERIFYING) {
            // A cancelled or closed Execution never registers a package; its object stays in S3 for the administrator.
            $this->operations->reconcile($operation);
        } elseif ($operation->command_key === RemoteSourcePackagePublisher::COMMAND) {
            try {
                $this->publisher->observe($operation);
            } catch (ToolOperationBlocked $exception) {
                $this->defer($operation, 'REMOTE_PUBLICATION_PENDING', $exception->getMessage());

                return;
            }
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
        } finally {
            $pdo->prepare('SELECT pg_advisory_unlock('.self::LOCK.', ?)')->execute([$execution->id]);
        }
    }

    private function advanceLocked(Execution $execution): void
    {
        if (! $execution->status->isActive()) {
            return;
        }
        $export = $this->export($execution);
        if ($execution->status === ExecutionStatus::CANCELLING) {
            if (! $execution->remoteOperations()->where('communication_state', '!=', RemoteCommunicationState::TERMINATED->value)->exists()) {
                $this->close($execution, $export, true);
            }

            return;
        }
        if ($export === null) {
            return;
        }
        $report = $export->evidence['collector'] ?? null;
        $this->progress($execution, $export, is_array($report) ? $report : []);
        if ($export->communication_state !== RemoteCommunicationState::TERMINATED) {
            return;
        }
        if (! is_array($report) || ($report['package_ready'] ?? false) !== true) {
            if ($export->functional_state !== RemoteFunctionalState::SUCCEEDED || $export->exit_code !== 0 || $this->reportFinal($export)) {
                $this->close($execution, $export, false);
            } else {
                // The process ended well but its final report was not read yet.
                $this->defer($export, 'REMOTE_REPORT_PENDING', 'La exportación terminó; falta leer su reporte final.');
            }

            return;
        }
        if ($execution->status === ExecutionStatus::RUNNING) {
            $this->step($execution, 'collection', ExecutionStepStatus::SUCCESS);
            $execution = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::VERIFYING);
            $this->step($execution, 'verification', ExecutionStepStatus::RUNNING);
            $this->events->record($execution, 'collector.export_completed', 'collection',
                message: 'El Recolector terminó en el servidor de origen y produjo el paquete.',
                payload: ['package_sha256' => $report['package_sha256'], 'package_size' => $report['package_size']], operation: $export);
        }
        if ($execution->status !== ExecutionStatus::VERIFYING) {
            return;
        }
        $upload = $this->upload($execution, $export);
        if ($upload === null) {
            $upload = $this->publisher->publish($export, $execution->creator);
            $this->events->record($execution, 'collector.publication_requested', 'verification',
                message: 'El servidor de origen está validando y publicando el paquete en el almacenamiento de la plataforma.',
                payload: ['operation_uuid' => $upload->operation_uuid], operation: $upload);
        }
        if ($upload->communication_state !== RemoteCommunicationState::TERMINATED) {
            return;
        }
        if ($upload->functional_state !== RemoteFunctionalState::SUCCEEDED) {
            $this->close($execution, $upload, false);

            return;
        }
        $packageUuid = $upload->evidence['source_package_uuid'] ?? null;
        $package = is_string($packageUuid) ? SourcePackage::query()->where('uuid', $packageUuid)->first() : null;
        if ($package !== null && $package->validation_state === 'VALID') {
            $this->review($execution, $upload, $package);
        }
    }

    /** @param array<string, mixed> $report */
    private function progress(Execution $execution, RemoteOperation $export, array $report): void
    {
        $percent = $report['percent'] ?? null;
        if ($execution->status !== ExecutionStatus::RUNNING || ! is_numeric($percent)) {
            return;
        }
        $progress = max(0, min(100, (int) floor((float) $percent)));
        if ($execution->progress === $progress) {
            return;
        }
        DB::transaction(function () use ($execution, $export, $report, $progress): void {
            $locked = Execution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ExecutionStatus::RUNNING || $locked->progress === $progress) {
                return;
            }
            $locked->forceFill(['progress' => $progress])->save();
            ExecutionStep::query()->where('execution_id', $locked->id)->where('step_key', 'collection')->update(['progress' => $progress]);
            $courses = is_array($report['progress'] ?? null) ? $report['progress'] : [];
            $this->events->record($locked, 'collector.progress', 'collection', progress: $progress,
                message: 'Cursos exportados: '.($courses['completed_courses'] ?? '?').' de '.($courses['total_courses'] ?? '?').'.',
                payload: array_intersect_key($courses, array_flip(['stage', 'total_courses', 'completed_courses', 'failed_courses'])),
                operation: $export);
        }, attempts: 3);
    }

    private function review(Execution $execution, RemoteOperation $upload, SourcePackage $package): void
    {
        DB::transaction(function () use ($execution, $upload, $package): void {
            Project::query()->whereKey($execution->project_id)->lockForUpdate()->firstOrFail();
            $locked = Execution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ExecutionStatus::VERIFYING) {
                return;
            }
            $fingerprint = hash('sha256', $locked->uuid.'|'.$package->uuid.'|'.$package->sha256.'|'.$package->manifest_sha256);
            Verification::query()->firstOrCreate(['execution_id' => $locked->id, 'proposal_version' => 0],
                ['key' => 'collector-remote-package-audit', 'fingerprint' => $fingerprint, 'status' => 'PASSED', 'approved' => true,
                    'summary' => 'Paquete fuente validado en origen y verificado desde el almacenamiento por la plataforma.',
                    'details' => $package->evidence['collector_audit'] ?? [], 'checked_at' => now()->utc()]);
            $locked->forceFill(['progress' => null, 'review_fingerprint' => $fingerprint,
                'validated_proposal_version' => 0, 'validated_fingerprint' => $fingerprint])->save();
            $this->step($locked, 'verification', ExecutionStepStatus::SUCCESS);
            $review = $this->lifecycle->transitionForWorker($locked, ExecutionStatus::REVIEW);
            $this->events->record($review, 'collector.package_registered', 'verification',
                message: 'El paquete fuente verificado está disponible para revisión.',
                payload: ['source_package_uuid' => $package->uuid, 'producer_version' => $package->producer_tool_version,
                    'sha256' => $package->sha256, 'manifest_sha256' => $package->manifest_sha256], operation: $upload);
            AuditLog::query()->create(['project_id' => $locked->project_id, 'execution_id' => $locked->id,
                'action' => 'COLLECTOR_PACKAGE_REGISTERED', 'payload' => ['source_package_uuid' => $package->uuid, 'sha256' => $package->sha256, 'mode' => 'REMOTE']]);
        }, attempts: 3);
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

    private function export(Execution $execution): ?RemoteOperation
    {
        return $execution->remoteOperations()->where('command_key', RemoteCollectorExport::COMMAND)->orderBy('id')->first();
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
            || ($settings['schema_version'] ?? null) !== RemoteCollectionConfiguration::SCHEMA) {
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
