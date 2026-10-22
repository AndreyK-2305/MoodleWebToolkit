<?php

namespace App\Domain\Remote;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Executions\ExecutionLifecycle;
use App\Enums\ConnectionStatus;
use App\Enums\EventSeverity;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Enums\RemoteCommunicationState;
use App\Enums\RemoteFunctionalState;
use App\Exceptions\RemoteAgentUnavailable;
use App\Exceptions\ToolOperationBlocked;
use App\Models\AuditLog;
use App\Models\Conflict;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Project;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\ToolDistribution;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Drives an Execution through a remote consolidation: the Consolidator's
 * assistant runs in automatic mode on the destination and resumes from its
 * own checkpoints. Each run is a durable agent operation; when the assistant
 * stops for the administrator (exit 22) the Execution waits, and Continue
 * launches the next run on the same Execution. Each pass is bounded and
 * idempotent; the scheduler repeats it while a run is active.
 */
class RemoteConsolidationWorkflow
{
    public const COMMAND = 'consolidator.run';

    public const APPROVE_COMMAND = 'consolidator.plugins-approve';

    public const COMMANDS = [self::COMMAND, self::APPROVE_COMMAND, RemoteConsolidationBackup::COMMAND];

    /** The assistant's exit code when it waits for the administrator. */
    public const EXIT_WAITING = 22;

    private const LOCK = 80012;

    private const MANUAL_AFTER = 8;

    private const STATE_FIELDS = ['schema_version', 'assistant_version', 'updated_at_utc', 'stage', 'status', 'execution_mode', 'destination_write_recorded'];

    private const WORKER_FIELDS = ['phase', 'updated_at_utc', 'workers', 'workers_requested', 'workers_effective', 'completed',
        'completed_with_warnings', 'failed', 'pending', 'total', 'percent'];

    private const CLOSURE_FIELDS = ['closure_status', 'courses_verified', 'total_courses_verified', 'failed_courses'];

    public function __construct(
        private readonly RemoteAgentOperations $operations,
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
        private readonly RemoteDestinationPackages $packages,
        private readonly ExecutionLifecycle $lifecycle,
        private readonly ExecutionEventRecorder $events,
        private readonly SensitiveValueRedactor $redactor,
        private readonly RemoteConsolidationInterventions $interventions,
    ) {}

    /** Launches the first run once, after checking the destination and its copias/ live. */
    public function start(Execution $execution): ?RemoteOperation
    {
        $settings = $this->settings($execution);
        $existing = $this->runs($execution)->last();
        if ($existing !== null) {
            return $existing;
        }
        if ($execution->status === ExecutionStatus::QUEUED) {
            $execution = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::RUNNING);
        }
        if ($execution->status !== ExecutionStatus::RUNNING) {
            throw new ToolOperationBlocked('El estado vigente no admite iniciar la consolidación remota.');
        }
        try {
            $server = $this->server($execution, $settings);
            $distribution = $this->assertLaunchable($server, $settings, false);
            $operation = $this->launch($execution, $server, $settings, $distribution, 1, $execution->creator);
        } catch (ToolOperationBlocked $exception) {
            $this->close($execution, null, false, $exception->getMessage());

            return null;
        }
        $this->step($execution, '01-importar-paquetes', ExecutionStepStatus::RUNNING);
        $this->events->record($execution->fresh(), 'consolidator.run_registered', '01-importar-paquetes',
            message: 'El Consolidador quedó en marcha en '.$server->name.' y continuará aunque se cierre el navegador.',
            payload: ['operation_uuid' => $operation->operation_uuid, 'destination_profile' => $settings['destination_profile'],
                'packages' => count($settings['source_packages'])], operation: $operation);

        return $operation;
    }

    /** Continue after an administrator intervention: the next run resumes from the approved checkpoints. */
    public function resume(Execution $execution, User $actor): RemoteOperation
    {
        $settings = $this->settings($execution);
        $execution->refresh();
        if ($execution->status !== ExecutionStatus::WAITING_USER_ACTION
            || $execution->remoteOperations()->where('communication_state', '!=', RemoteCommunicationState::TERMINATED->value)->exists()) {
            throw new ToolOperationBlocked('Solo se continúa una consolidación que espera una intervención.');
        }
        // Checked before any state changes: a destination not ready keeps the Execution waiting.
        $server = $this->server($execution, $settings);
        $distribution = $this->assertLaunchable($server, $settings, true);
        $waiting = $this->waitingStep($execution);
        // The transition is the claim: a second Continue finds the Execution already RUNNING.
        $running = $this->lifecycle->transition($execution, ExecutionStatus::RUNNING, $actor);
        try {
            $operation = $this->launch($running, $server, $settings, $distribution, $this->runs($running)->count() + 1, $actor);
        } catch (ToolOperationBlocked $exception) {
            $this->close($running, null, false, $exception->getMessage());

            throw $exception;
        }
        if ($waiting !== null) {
            $this->step($running, $waiting, ExecutionStepStatus::RUNNING);
        }
        $this->events->record($running, 'consolidator.resumed', $waiting,
            message: 'Intervención confirmada; el Consolidador reanuda desde sus checkpoints aprobados.',
            payload: ['operation_uuid' => $operation->operation_uuid, 'stage' => $waiting], operation: $operation);
        AuditLog::query()->create(['actor_id' => $actor->getKey(), 'project_id' => $running->project_id, 'execution_id' => $running->id,
            'action' => 'CONSOLIDATOR_RESUMED', 'payload' => ['operation_uuid' => $operation->operation_uuid, 'stage' => $waiting]]);

        return $operation;
    }

    /**
     * The consolidation can be confirmed only while what was reviewed still holds: the last
     * run closed it, the packages and the sealed site package match the review fingerprint, and
     * the destination, read live, still reports the same closure and site package.
     *
     * @return array<string, mixed> the live report, or the reviewed one between the boundary passes
     */
    public function assertFinalizable(Execution $execution, bool $live = true): array
    {
        $settings = $this->settings($execution);
        $run = $this->runs($execution)->last();
        $report = is_array($run?->evidence['consolidator'] ?? null) ? $run->evidence['consolidator'] : [];
        if ($run === null || $run->functional_state !== RemoteFunctionalState::SUCCEEDED || $run->exit_code !== 0
            || ($report['state']['stage'] ?? null) !== 'completed' || ($report['state']['status'] ?? null) !== 'completed') {
            throw new ToolOperationBlocked('La confirmación requiere que el Consolidador haya cerrado la consolidación.');
        }
        if ($execution->remoteOperations()->where('command_key', RemoteConsolidationBackup::COMMAND)
            ->where('communication_state', '!=', RemoteCommunicationState::TERMINATED->value)->exists()) {
            throw new ToolOperationBlocked('Espere a que termine el respaldo de la copia integral antes de confirmar.');
        }
        $site = $report['site_package']['package_sha256'] ?? null;
        if (! is_string($execution->review_fingerprint) || ! hash_equals($this->fingerprint($execution, $settings, $site), $execution->review_fingerprint)) {
            throw new ToolOperationBlocked('Lo revisado cambió después de la revisión.');
        }
        if (! $live) {
            return $report;
        }
        $server = $this->server($execution, $settings);
        try {
            $response = $this->agent->call($this->connections->target($server), 'consolidator-report', [(string) $settings['destination_profile']], 30);
        } catch (RemoteAgentUnavailable $exception) {
            throw new ToolOperationBlocked('No se pudo leer el destino para confirmar la consolidación ('.$exception->errorCode.').');
        }
        $live = $response->ok ? $this->report($response->data) : null;
        if ($live === null || ($live['state']['stage'] ?? null) !== 'completed' || ($live['state']['status'] ?? null) !== 'completed'
            || ($live['closure']['closure_status'] ?? null) !== ($report['closure']['closure_status'] ?? false)
            || ($live['site_package']['package_sha256'] ?? null) !== $site) {
            throw new ToolOperationBlocked('El destino ya no reporta el mismo cierre y la misma copia integral que se revisaron.');
        }

        return $live;
    }

    /** Requests cancellation of the active run; a waiting Execution closes at once. */
    public function cancel(Execution $execution): void
    {
        foreach ($execution->remoteOperations()->where('communication_state', '!=', RemoteCommunicationState::TERMINATED->value)->get() as $operation) {
            if ($operation->process_id !== null && ! isset($operation->evidence['cancel_requested_at'])) {
                try {
                    $this->operations->cancel($operation, $execution->creator);
                } catch (ToolOperationBlocked) {
                    // A launch not yet confirmed is left to the reconciler.
                }
            }
        }
        $this->advanceExecution($execution);
    }

    /** One bounded pass for a run of a remote consolidation. */
    public function advance(RemoteOperation $operation): void
    {
        $operation->loadMissing('execution');
        $execution = $operation->execution;
        if (! RemoteConsolidationPreparation::selected($execution->toolBinding) || ! in_array($operation->command_key, self::COMMANDS, true)) {
            throw new ToolOperationBlocked('La operación no pertenece a una consolidación remota.');
        }
        // The backup of the site package runs during REVIEW and never moves the Execution itself.
        if ($operation->command_key === RemoteConsolidationBackup::COMMAND) {
            app(RemoteConsolidationBackup::class)->observe($operation);

            return;
        }
        if ($operation->communication_state !== RemoteCommunicationState::TERMINATED) {
            $operation = $this->operations->reconcile($operation);
        }
        // The assistant's own documents are read while it runs and once more after it ends.
        if ($operation->command_key === self::COMMAND && $operation->process_id !== null && ($operation->evidence['consolidator']['final'] ?? false) !== true) {
            $this->observeReport($operation);
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
        $run = $this->runs($execution)->last();
        $active = $execution->remoteOperations()->where('communication_state', '!=', RemoteCommunicationState::TERMINATED->value)->exists();
        if ($execution->status === ExecutionStatus::CANCELLING) {
            if (! $active) {
                $this->close($execution, $run, true);
            }

            return;
        }
        if ($run === null || $execution->status !== ExecutionStatus::RUNNING) {
            return;
        }
        // A plugin approval launched after the latest run is followed before anything else.
        $approval = $execution->remoteOperations()->where('command_key', self::APPROVE_COMMAND)->where('id', '>', $run->id)->orderByDesc('id')->first();
        if ($approval !== null) {
            if ($approval->communication_state === RemoteCommunicationState::TERMINATED) {
                $this->afterApproval($execution, $approval);
            }

            return;
        }
        $report = $run->evidence['consolidator'] ?? null;
        $report = is_array($report) ? $report : null;
        if ($report !== null) {
            $this->applyStages($execution, $report);
        }
        if ($run->communication_state !== RemoteCommunicationState::TERMINATED) {
            return;
        }
        if (($report['final'] ?? false) !== true) {
            $this->defer($run, 'CONSOLIDATOR_REPORT_PENDING', 'El Consolidador terminó; falta leer su estado final.');

            return;
        }
        $state = is_array($report['state'] ?? null) ? $report['state'] : [];
        if ($run->functional_state === RemoteFunctionalState::SUCCEEDED && $run->exit_code === 0
            && ($state['stage'] ?? null) === 'completed' && ($state['status'] ?? null) === 'completed') {
            $this->review($execution, $run, $report);

            return;
        }
        if ($run->exit_code === self::EXIT_WAITING && $run->functional_state !== RemoteFunctionalState::CANCELLED) {
            $this->wait($execution, $run, $report);

            return;
        }
        $this->close($execution, $run, $run->functional_state === RemoteFunctionalState::CANCELLED,
            'El Consolidador se detuvo en '.$this->stageName($state['stage'] ?? null).': '.($report['message'] ?? 'sin mensaje').' Su evidencia y checkpoints se conservan en el destino.');
    }

    /**
     * Marks the stages from the assistant's state: the stages before the current one are done,
     * because the assistant runs them in order and skips those already approved.
     *
     * @param  array<string, mixed>  $report
     */
    private function applyStages(Execution $execution, array $report): void
    {
        $state = is_array($report['state'] ?? null) ? $report['state'] : [];
        $stage = $state['stage'] ?? null;
        $keys = array_keys(RemoteConsolidationPreparation::STAGES);
        $index = $stage === 'completed' ? count($keys) : array_search($stage, $keys, true);
        if (! is_int($index)) {
            return;
        }
        $completed = $index + (($state['status'] ?? null) === 'completed' ? 1 : 0);
        foreach ($keys as $position => $key) {
            if ($position < $completed) {
                $this->step($execution, $key, ExecutionStepStatus::SUCCESS);
            } elseif ($position === $index) {
                $this->step($execution, $key, ExecutionStepStatus::RUNNING);
            }
        }
        // Real progress: approved stages over the total, plus the parallel restore within its stage.
        $partial = 0.0;
        $workers = is_array($report['workers'] ?? null) ? $report['workers'] : null;
        if ($stage === '13-aplicar-lote' && is_int($workers['total'] ?? null) && $workers['total'] > 0 && is_int($workers['completed'] ?? null)) {
            $partial = min(1.0, $workers['completed'] / $workers['total']);
        }
        $progress = max(0, min(99, (int) floor(($completed + ($completed === $index ? $partial : 0.0)) / count($keys) * 100)));
        if ($execution->progress === $progress) {
            return;
        }
        DB::transaction(function () use ($execution, $progress, $stage, $state, $workers): void {
            $locked = Execution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ExecutionStatus::RUNNING || $locked->progress === $progress) {
                return;
            }
            $locked->forceFill(['progress' => $progress])->save();
            $this->events->record($locked, 'consolidator.progress', is_string($stage) && isset(RemoteConsolidationPreparation::STAGES[$stage]) ? $stage : null,
                progress: $progress, message: $this->stageName($stage).(is_array($workers) && $stage === '13-aplicar-lote'
                    ? ' — cursos: '.($workers['completed'] ?? 0).' de '.($workers['total'] ?? '?').'.' : '.'),
                payload: ['stage' => $stage, 'status' => $state['status'] ?? null, 'workers' => $workers]);
        }, attempts: 3);
    }

    /**
     * The Execution waits and opens the typed intervention for the stage; its documents are
     * read from the destination after the transition, outside the lock.
     *
     * @param  array<string, mixed>  $report
     */
    private function wait(Execution $execution, RemoteOperation $run, array $report): void
    {
        $waited = DB::transaction(function () use ($execution, $run, $report): bool {
            $locked = Execution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ExecutionStatus::RUNNING) {
                return false;
            }
            $stage = $report['state']['stage'] ?? null;
            $stage = is_string($stage) && isset(RemoteConsolidationPreparation::STAGES[$stage]) ? $stage : null;
            if ($stage !== null) {
                $this->step($locked, $stage, ExecutionStepStatus::WAITING_USER);
            }
            $waiting = $this->lifecycle->transitionForWorker($locked, ExecutionStatus::WAITING_USER_ACTION);
            $this->events->record($waiting, 'consolidator.waiting_user_action', $stage, EventSeverity::WARNING,
                message: 'El Consolidador espera una intervención en '.$this->stageName($stage).': '.($report['message'] ?? 'revise el destino').'',
                payload: ['stage' => $stage, 'status' => $report['state']['status'] ?? null, 'review_paths' => $report['review_paths'] ?? []],
                operation: $run);

            return true;
        }, attempts: 3);
        if ($waited) {
            $this->interventions->open($execution->fresh(), $this->settings($execution), $report, $run->id);
        }
    }

    /**
     * Applies the administrator's decision on the open intervention: confirming continues with
     * the next run; accepting a plugin intervention first approves the sealed inventory.
     */
    public function resolve(Execution $execution, Conflict $conflict, string $decision, User $actor): void
    {
        $execution->refresh();
        if ($execution->status !== ExecutionStatus::WAITING_USER_ACTION) {
            return;
        }
        if ($decision === 'ACCEPT' && $conflict->type === RemoteConsolidationInterventions::PLUGINS) {
            $this->approvePlugins($execution, $actor);

            return;
        }
        $this->resume($execution, $actor);
    }

    private function approvePlugins(Execution $execution, User $actor): void
    {
        $settings = $this->settings($execution);
        $server = $this->server($execution, $settings);
        $distribution = $this->assertLaunchable($server, $settings, true);
        $running = $this->lifecycle->transition($execution, ExecutionStatus::RUNNING, $actor);
        $attempt = $running->remoteOperations()->where('command_key', self::APPROVE_COMMAND)->count() + 1;
        try {
            $operation = $this->operations->launch($running, $server, 'consolidator-plugins-approve-'.$attempt, self::APPROVE_COMMAND, [
                'destination' => (string) $settings['destination_profile'], 'distribution' => $distribution->distribution_sha256,
            ], $actor);
        } catch (ToolOperationBlocked $exception) {
            $this->close($running, null, false, $exception->getMessage());

            return;
        }
        $this->step($running, '03-plugins', ExecutionStepStatus::RUNNING);
        $this->events->record($running, 'consolidator.plugins_approval_requested', '03-plugins',
            message: 'Aprobación de plugins aceptada; el Consolidador sella el inventario en el destino.',
            payload: ['operation_uuid' => $operation->operation_uuid], operation: $operation);
        AuditLog::query()->create(['actor_id' => $actor->getKey(), 'project_id' => $running->project_id, 'execution_id' => $running->id,
            'action' => 'CONSOLIDATOR_PLUGINS_APPROVED', 'payload' => ['operation_uuid' => $operation->operation_uuid]]);
    }

    /** After the plugin approval ends: a sealed approval continues with the next run, a failed one waits again. */
    private function afterApproval(Execution $execution, RemoteOperation $approval): void
    {
        if ($approval->functional_state === RemoteFunctionalState::SUCCEEDED && $approval->exit_code === 0) {
            $settings = $this->settings($execution);
            try {
                $server = $this->server($execution, $settings);
                $distribution = $this->assertLaunchable($server, $settings, true);
                $operation = $this->launch($execution, $server, $settings, $distribution, $this->runs($execution)->count() + 1, $execution->creator);
            } catch (ToolOperationBlocked $exception) {
                $this->close($execution, $approval, false, $exception->getMessage());

                return;
            }
            $this->events->record($execution, 'consolidator.resumed', '03-plugins',
                message: 'Plugins aprobados; el Consolidador reanuda desde sus checkpoints aprobados.',
                payload: ['operation_uuid' => $operation->operation_uuid, 'stage' => '03-plugins'], operation: $operation);

            return;
        }
        $this->wait($execution, $approval, ['state' => ['stage' => '03-plugins', 'status' => 'PLUGIN_APPROVAL_FAILED'],
            'message' => 'La aprobación de plugins no se selló en el destino (código '.($approval->exit_code ?? '?').'); revise el inventario.']);
    }

    /** @param array<string, mixed> $report */
    private function review(Execution $execution, RemoteOperation $run, array $report): void
    {
        DB::transaction(function () use ($execution, $run, $report): void {
            Project::query()->whereKey($execution->project_id)->lockForUpdate()->firstOrFail();
            $locked = Execution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ExecutionStatus::RUNNING) {
                return;
            }
            foreach (array_keys(RemoteConsolidationPreparation::STAGES) as $key) {
                $this->step($locked, $key, ExecutionStepStatus::SUCCESS);
            }
            $verifying = $this->lifecycle->transitionForWorker($locked, ExecutionStatus::VERIFYING);
            $settings = $this->settings($verifying);
            $closure = is_array($report['closure'] ?? null) ? $report['closure'] : [];
            $site = is_array($report['site_package'] ?? null) ? $report['site_package'] : [];
            $fingerprint = $this->fingerprint($verifying, $settings, $site['package_sha256'] ?? null);
            Verification::query()->firstOrCreate(['execution_id' => $verifying->id, 'proposal_version' => 0],
                ['key' => 'consolidator-remote-closure', 'fingerprint' => $fingerprint, 'status' => 'PASSED', 'approved' => true,
                    'summary' => 'El Consolidador cerró la consolidación en el destino ('.($closure['closure_status'] ?? 'sin estado').').',
                    'details' => ['closure' => $closure, 'site_package' => $site, 'packages' => $settings['source_packages']],
                    'checked_at' => now()->utc()]);
            $verifying->forceFill(['progress' => null, 'review_fingerprint' => $fingerprint, 'validated_proposal_version' => 0,
                'validated_fingerprint' => $fingerprint])->save();
            $review = $this->lifecycle->transitionForWorker($verifying, ExecutionStatus::REVIEW);
            $this->events->record($review, 'consolidator.closed', 'finalization',
                message: 'Consolidación verificada y cerrada por el Consolidador: '.($closure['total_courses_verified'] ?? $closure['courses_verified'] ?? '?')
                    .' cursos verificados, '.($closure['failed_courses'] ?? '?').' diferencias.',
                payload: ['closure' => $closure, 'site_package_sha256' => $site['package_sha256'] ?? null], operation: $run);
            AuditLog::query()->create(['project_id' => $review->project_id, 'execution_id' => $review->id, 'action' => 'CONSOLIDATOR_CLOSED',
                'payload' => ['closure_status' => $closure['closure_status'] ?? null, 'site_package_sha256' => $site['package_sha256'] ?? null]]);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $settings */
    private function fingerprint(Execution $execution, array $settings, mixed $sitePackage): string
    {
        $packages = collect(is_array($settings['source_packages'] ?? null) ? $settings['source_packages'] : [])
            ->filter(fn ($package): bool => is_array($package))->sortBy('uuid')
            ->map(fn (array $package): string => ($package['uuid'] ?? '').'|'.($package['sha256'] ?? ''));

        return hash('sha256', $execution->uuid.'|'.$packages->implode('|').'|'.(is_string($sitePackage) ? $sitePackage : ''));
    }

    private function observeReport(RemoteOperation $operation): void
    {
        $settings = $this->settings($operation->execution);
        $server = Server::query()->where('uuid', (string) $operation->host_id)->firstOrFail();
        try {
            $response = $this->agent->call($this->connections->target($server), 'consolidator-report', [$settings['destination_profile']], 30);
        } catch (RemoteAgentUnavailable) {
            return;
        }
        if (! $response->ok) {
            return;
        }
        $report = $this->report($response->data);
        // A report read after the run ended is the final one for that run.
        $report['final'] = $operation->communication_state === RemoteCommunicationState::TERMINATED;
        DB::transaction(function () use ($operation, $report): void {
            $locked = RemoteOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill(['evidence' => [...($locked->evidence ?? []), 'consolidator' => $report]])->save();
        }, attempts: 3);
    }

    /**
     * Whitelisted fields of the Consolidator's documents; the message is redacted and bounded.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function report(array $data): array
    {
        $state = $this->document($data['assistant_state_base64'] ?? null);
        $workers = $this->document($data['workers_status_base64'] ?? null);
        $closure = $this->document($data['closure_summary_base64'] ?? null);
        $site = $this->document($data['site_package_summary_base64'] ?? null);
        $paths = [];
        foreach (is_array($state['review_paths'] ?? null) ? $state['review_paths'] : [] as $path) {
            if (is_string($path) && preg_match('#^[A-Za-z0-9_.\\\\/-]{1,200}$#D', $path) === 1 && ! str_contains($path, '..')) {
                $paths[] = str_replace('\\', '/', $path);
            }
        }
        $active = [];
        foreach (is_array($workers['active'] ?? null) ? array_slice($workers['active'], 0, 8) : [] as $item) {
            if (is_array($item)) {
                $active[] = ['worker' => is_int($item['worker'] ?? null) ? $item['worker'] : null,
                    'source' => is_string($item['source'] ?? null) ? mb_substr($item['source'], 0, 64) : null,
                    'course_key' => is_string($item['course_key'] ?? null) ? mb_substr($item['course_key'], 0, 64) : null,
                    'elapsed_seconds' => is_int($item['elapsed_seconds'] ?? null) ? $item['elapsed_seconds'] : null];
            }
        }
        $message = is_string($state['message'] ?? null) ? mb_substr($this->redactor->redactString($state['message']), 0, 500) : null;
        $sitePackage = is_string($site['package_sha256'] ?? null) && preg_match('/^[0-9a-f]{64}$/D', $site['package_sha256']) === 1 ? $site['package_sha256'] : null;

        return [
            'state' => $state === null ? null : array_intersect_key($state, array_flip(self::STATE_FIELDS)),
            'message' => $message,
            'review_paths' => $paths,
            'workers' => $workers === null ? null : [...array_intersect_key($workers, array_flip(self::WORKER_FIELDS)), 'active' => $active],
            'closure' => $closure === null ? null : [...array_intersect_key($closure, array_flip(self::CLOSURE_FIELDS)),
                'sources' => is_array($closure['sources'] ?? null) ? count($closure['sources']) : null],
            'site_package' => $sitePackage === null ? null : ['package_sha256' => $sitePackage],
            'write_locked' => ($data['write_locked'] ?? false) === true,
            'observed_at' => is_string($data['observed_at'] ?? null) ? $data['observed_at'] : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function document(mixed $encoded): ?array
    {
        if (! is_string($encoded) || $encoded === 'too_large') {
            return null;
        }
        $decoded = base64_decode($encoded, true);
        // PowerShell writes UTF-8, sometimes with a byte order mark.
        $json = is_string($decoded) ? json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $decoded) ?? '', true) : null;

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function assertLaunchable(Server $server, array $settings, bool $resuming): ToolDistribution
    {
        $this->connections->assertEnabled();
        if ($this->connections->primary($server)?->status !== ConnectionStatus::VALID) {
            throw new ToolOperationBlocked('El servidor de destino debe superar la prueba de conexión.');
        }
        $distribution = ToolDistribution::query()->where('key', CheckRemoteDestination::DISTRIBUTION_KEY)->where('verification_state', 'VERIFIED')->first();
        $deployed = $server->metadata['remote_tools'][CheckRemoteDestination::DISTRIBUTION_KEY]['tree_sha256'] ?? null;
        if ($distribution === null || ! is_string($deployed) || ! hash_equals($distribution->distribution_sha256, $deployed)) {
            throw new ToolOperationBlocked('El Consolidador verificado del catálogo no está desplegado en '.$server->name.'.');
        }
        $profile = (string) $settings['destination_profile'];
        if (($server->metadata['remote_destinations'][$profile]['ready'] ?? false) !== true) {
            throw new ToolOperationBlocked('El perfil de destino '.$profile.' no superó su comprobación.');
        }
        // copias/ is checked live: the Consolidator imports every ZIP it finds there. Once the
        // destination was written the packages are fixed and the assistant enforces them itself.
        $listing = $this->packages->list($server, $profile);
        if (! $listing['listed']) {
            throw new ToolOperationBlocked('No se pudo listar copias/ en el destino ('.$listing['error_code'].').');
        }
        if (! $listing['write_locked']) {
            [$matches, $detail] = RemoteConsolidationPreflight::copias($listing, $settings['source_packages']);
            if (! $matches) {
                throw new ToolOperationBlocked($detail);
            }
        } elseif (! $resuming) {
            throw new ToolOperationBlocked('El Consolidador ya escribió en este destino; una nueva consolidación requiere un destino limpio.');
        }

        return $distribution;
    }

    /** @param array<string, mixed> $settings */
    private function launch(Execution $execution, Server $server, array $settings, ToolDistribution $distribution, int $attempt, ?User $actor): RemoteOperation
    {
        return $this->operations->launch($execution, $server, 'consolidator-run-'.$attempt, self::COMMAND, [
            'destination' => (string) $settings['destination_profile'],
            'distribution' => $distribution->distribution_sha256,
            'workers' => (string) $settings['workers'],
        ], $actor);
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
            foreach ($locked->steps()->whereIn('status', ['RUNNING', 'PENDING', 'WAITING_USER'])->get() as $step) {
                $step->forceFill(['status' => $cancelled ? ExecutionStepStatus::CANCELLED : ExecutionStepStatus::FAILED, 'finished_at' => now()->utc()])->save();
            }
            // A waiting Execution has no active run; it is cancelled directly.
            if ($cancelled && $locked->status === ExecutionStatus::WAITING_USER_ACTION) {
                $locked = $this->lifecycle->transitionForWorker($locked, ExecutionStatus::CANCELLING);
            }
            $closed = $this->lifecycle->transitionForWorker($locked, $cancelled ? ExecutionStatus::CANCELLED : ExecutionStatus::FAILED);
            $this->events->record($closed, $cancelled ? 'consolidator.cancelled' : 'consolidator.failed', null, EventSeverity::WARNING,
                message: $cancelled ? 'El Consolidador se detuvo; cancelación confirmada. Sus checkpoints quedan en el destino.'
                    : ($reason ?? 'La consolidación remota terminó sin cerrarse.'), operation: $operation);
            AuditLog::query()->create(['project_id' => $closed->project_id, 'execution_id' => $closed->id,
                'action' => $cancelled ? 'CONSOLIDATOR_CANCELLATION_CONFIRMED' : 'CONSOLIDATOR_EXECUTION_FAILED',
                'payload' => ['operation_uuid' => $operation?->operation_uuid, 'exit_code' => $operation?->exit_code, 'mode' => 'REMOTE']]);
        }, attempts: 3);
    }

    /** Keeps a terminated run due with backoff until its final report is read; flags it after repeated attempts. */
    private function defer(RemoteOperation $operation, string $code, string $message): void
    {
        DB::transaction(function () use ($operation, $code, $message): void {
            $locked = RemoteOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $attempts = min(65535, $locked->reconcile_attempts + 1);
            $manual = $attempts >= self::MANUAL_AFTER;
            if ($locked->last_reconcile_error !== $code || $manual !== $locked->manual_intervention_required) {
                $this->events->record($locked->execution, 'consolidator.reconciliation_pending', severity: EventSeverity::WARNING,
                    message: $manual ? 'La consolidación remota requiere revisar su evidencia: '.$message : $message,
                    payload: ['manual_intervention_required' => $manual, 'code' => $code], operation: $locked);
            }
            $locked->forceFill(['reconcile_attempts' => $attempts, 'last_reconcile_error' => $code, 'manual_intervention_required' => $manual,
                'next_poll_at' => $manual ? null : now()->utc()->addSeconds(min(900, 15 * (2 ** min(6, $attempts))))])->save();
        }, attempts: 3);
    }

    private function waitingStep(Execution $execution): ?string
    {
        $key = ExecutionStep::query()->where('execution_id', $execution->id)->where('status', ExecutionStepStatus::WAITING_USER->value)->value('step_key');

        return is_string($key) ? $key : null;
    }

    private function stageName(mixed $stage): string
    {
        return is_string($stage) && isset(RemoteConsolidationPreparation::STAGES[$stage])
            ? '«'.RemoteConsolidationPreparation::STAGES[$stage].'»' : 'una etapa del asistente';
    }

    /** @return Collection<int, RemoteOperation> */
    private function runs(Execution $execution): Collection
    {
        return $execution->remoteOperations()->where('command_key', self::COMMAND)->orderBy('id')->get();
    }

    /** @param array<string, mixed> $settings */
    private function server(Execution $execution, array $settings): Server
    {
        $server = Server::query()->where('project_id', $execution->project_id)->where('uuid', (string) $settings['server_uuid'])->first();
        if ($server === null) {
            throw new ToolOperationBlocked('El servidor de destino ya no pertenece al proyecto.');
        }

        return $server;
    }

    /** @return array<string, mixed> */
    private function settings(Execution $execution): array
    {
        $binding = $execution->toolBinding;
        $settings = $binding?->configuration_snapshot;
        if (! RemoteConsolidationPreparation::selected($binding) || ! is_array($settings)
            || ($settings['schema_version'] ?? null) !== RemoteConsolidationConfiguration::SCHEMA || ! is_array($settings['source_packages'] ?? null)) {
            throw new ToolOperationBlocked('La ejecución no tiene un binding de consolidación remota.');
        }

        return $settings;
    }

    private function step(Execution $execution, string $key, ExecutionStepStatus $status): void
    {
        $step = ExecutionStep::query()->where('execution_id', $execution->id)->where('step_key', $key)->first();
        if ($step === null || $step->status === $status || ($step->status === ExecutionStepStatus::SUCCESS && $status !== ExecutionStepStatus::SUCCESS)) {
            return;
        }
        $step->forceFill(['status' => $status, 'progress' => $status === ExecutionStepStatus::SUCCESS ? 100 : $step->progress,
            'started_at' => $step->started_at ?? now()->utc(),
            'finished_at' => $status === ExecutionStepStatus::SUCCESS ? now()->utc() : null])->save();
    }
}
