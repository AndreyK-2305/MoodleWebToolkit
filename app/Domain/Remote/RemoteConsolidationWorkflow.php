<?php

namespace App\Domain\Remote;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Executions\ExecutionLifecycle;
use App\Domain\Remote\DTOs\RemoteTarget;
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
 * stops for the administrator (exit 22) or is blocked on a decision of its
 * configuration (exit 20) the Execution waits, and Continue launches the next
 * run on the same Execution. A new attempt after a failure resumes from the
 * same checkpoints. Each pass is bounded and idempotent; the scheduler repeats
 * it while a run is active.
 */
class RemoteConsolidationWorkflow
{
    public const COMMAND = 'consolidator.run';

    public const APPROVE_COMMAND = 'consolidator.plugins-approve';

    /** Seals a plugin lock that asks for no plugin, working around Consolidator 8.0.0 (rc12 and rc13). */
    public const NONE_COMMAND = 'consolidator.plugins-none';

    /** Applies the administrator's fuzzy identity review with the Consolidator's own validator. */
    public const IDENTITY_COMMAND = 'consolidator.identity-apply';

    /** Inspects a plugin outside the catalog on the destination while the Execution waits. */
    public const INSPECT_COMMAND = 'consolidator.plugin-inspect';

    /** Adds the confirmed inspections to the Consolidator's catalog with its own promotion rules. */
    public const PROMOTE_COMMAND = 'consolidator.plugin-promote';

    /** Steps run on the destination between two runs, each followed by the next run, and their stage. */
    private const STEP_COMMANDS = [self::APPROVE_COMMAND => '03-plugins', self::NONE_COMMAND => '03-plugins', self::PROMOTE_COMMAND => '03-plugins',
        self::IDENTITY_COMMAND => '05b-readiness'];

    public const COMMANDS = [self::COMMAND, self::APPROVE_COMMAND, self::NONE_COMMAND, self::IDENTITY_COMMAND, self::INSPECT_COMMAND,
        self::PROMOTE_COMMAND, RemoteConsolidationBackup::COMMAND];

    /** What an inspection's exit code means, as the agent's inspector reports it. */
    private const INSPECTION_ERRORS = [71 => 'el tipo de plugin no está soportado', 72 => 'no se pudo clonar el repositorio o el commit no existe',
        73 => 'el plugin usa submódulos Git, que no se inspeccionan desde la plataforma', 74 => 'version.php declara otro componente',
        75 => 'version.php no declara version y requires'];

    /** The assistant's exit code when it waits for the administrator. */
    public const EXIT_WAITING = 22;

    /** The assistant's exit code when a stage is blocked until its configuration or evidence is corrected. */
    public const EXIT_BLOCKED = 20;

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
            // A new attempt continues the consolidation the failed one left on the destination.
            $distribution = $this->assertLaunchable($server, $settings, $execution->retried_from_execution_id !== null);
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
        // An inspection runs while the Execution waits: it only fills the open plugin intervention.
        if ($operation->command_key === self::INSPECT_COMMAND) {
            $this->observeInspection($operation->communication_state === RemoteCommunicationState::TERMINATED ? $operation : $this->operations->reconcile($operation));

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
        $approval = $execution->remoteOperations()->whereIn('command_key', array_keys(self::STEP_COMMANDS))->where('id', '>', $run->id)->orderByDesc('id')->first();
        if ($approval !== null) {
            if ($approval->communication_state === RemoteCommunicationState::TERMINATED) {
                $this->afterStep($execution, $approval);
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
        // A blocked stage is a decision for the administrator too: the assistant keeps its
        // checkpoints, and after the correction Continue resumes it; Cancel stays available.
        if (in_array($run->exit_code, [self::EXIT_WAITING, self::EXIT_BLOCKED], true) && $run->functional_state !== RemoteFunctionalState::CANCELLED) {
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
     * the next run; accepting a plugin intervention first approves the sealed inventory; the
     * answers given in the platform (identity review, themes) are written on the destination
     * before continuing.
     */
    public function resolve(Execution $execution, Conflict $conflict, string $decision, User $actor): void
    {
        $execution->refresh();
        if ($execution->status !== ExecutionStatus::WAITING_USER_ACTION) {
            return;
        }
        if ($conflict->type === RemoteConsolidationInterventions::PLUGINS && in_array($decision, ['ACCEPT', 'CONTINUE_WITHOUT_PLUGINS'], true)) {
            $this->launchStep($execution, $actor, $decision === 'ACCEPT' ? self::APPROVE_COMMAND : self::NONE_COMMAND);

            return;
        }
        if ($decision === 'APPLY_ANSWERS') {
            $answers = is_array($conflict->details['answers'] ?? null) ? $conflict->details['answers'] : null;
            if ($answers === null) {
                throw new ToolOperationBlocked('La intervención no tiene respuestas registradas.');
            }
            $settings = $this->settings($execution);
            $target = $this->connections->target($this->server($execution, $settings));
            $profile = (string) $settings['destination_profile'];
            if ($conflict->type === RemoteConsolidationInterventions::IDENTITY) {
                $payload = rtrim(strtr(base64_encode((string) json_encode([
                    'operator' => $actor->name.' <'.$actor->email.'>', 'decision_timestamp_utc' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'evidence_reference' => 'MoodleWebToolkit: ejecución '.$execution->uuid.', intervención '.$conflict->id,
                    'decisions' => $answers['decisions'] ?? [],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
                $this->agentStep($target, 'identity-review-submit', [$profile, ...str_split($payload, 150)]);
                $this->launchStep($execution, $actor, self::IDENTITY_COMMAND);

                return;
            }
            if ($conflict->type === RemoteConsolidationInterventions::PLUGINS) {
                $approver = mb_substr($actor->name.' <'.$actor->email.'>', 0, 100);
                $this->launchStep($execution, $actor, self::PROMOTE_COMMAND, ['components' => implode(',', $answers['promote'] ?? []),
                    'approver' => rtrim(strtr(base64_encode($approver), '+/', '-_'), '=')]);

                return;
            }
            if ($conflict->type === RemoteConsolidationInterventions::THEMES) {
                $this->agentStep($target, 'theme-policy-set', [$profile, (string) ($answers['theme'] ?? ''), ($answers['accept_legacy'] ?? false) === true ? '1' : '0']);
                $this->resume($execution, $actor);

                return;
            }
            throw new ToolOperationBlocked('Esta intervención no admite respuestas desde la plataforma.');
        }
        $this->resume($execution, $actor);
    }

    /**
     * A short agent call that writes the administrator's answers on the destination.
     *
     * @param  list<string>  $arguments
     */
    private function agentStep(RemoteTarget $target, string $subcommand, array $arguments): void
    {
        try {
            $response = $this->agent->call($target, $subcommand, $arguments, 60);
        } catch (RemoteAgentUnavailable $exception) {
            throw new ToolOperationBlocked('No se pudo escribir la respuesta en el destino ('.$exception->errorCode.').');
        }
        if (! $response->ok) {
            throw new ToolOperationBlocked('El destino rechazó la respuesta ('.$response->errorCode.').');
        }
    }

    /**
     * Inspects, on the destination, a plugin the sources need and the catalog lacks: the
     * administrator names its Git repository and commit; the result fills the intervention.
     */
    public function inspectPlugin(Execution $execution, Conflict $conflict, User $actor, string $component, string $repository, string $commit): RemoteOperation
    {
        $execution->refresh();
        if ($execution->status !== ExecutionStatus::WAITING_USER_ACTION || $conflict->type !== RemoteConsolidationInterventions::PLUGINS
            || $conflict->status->value !== 'OPEN' || ! in_array($component, $conflict->details['unknown'] ?? [], true)) {
            throw new ToolOperationBlocked('Solo se inspecciona un plugin pedido por la intervención de plugins abierta.');
        }
        $settings = $this->settings($execution);
        $server = $this->server($execution, $settings);
        $distribution = $this->assertLaunchable($server, $settings, true);
        $attempt = $execution->remoteOperations()->where('command_key', self::INSPECT_COMMAND)->count() + 1;
        $operation = $this->operations->launch($execution, $server, 'consolidator-plugin-inspect-'.$attempt, self::INSPECT_COMMAND, [
            'destination' => (string) $settings['destination_profile'], 'distribution' => $distribution->distribution_sha256, 'component' => $component,
            'repository' => rtrim(strtr(base64_encode($repository), '+/', '-_'), '='), 'commit' => $commit,
        ], $actor);
        DB::transaction(function () use ($conflict, $component, $repository, $commit, $operation): void {
            $locked = Conflict::query()->whereKey($conflict->getKey())->lockForUpdate()->firstOrFail();
            $details = $locked->details ?? [];
            $details['inspections'][$component] = ['operation_uuid' => $operation->operation_uuid, 'state' => 'RUNNING',
                'repository' => $repository, 'commit' => $commit, 'message' => null];
            unset($details['proposals'][$component]);
            $locked->details = $details;
            $locked->save();
        });
        $this->events->record($execution, 'consolidator.plugin_inspection_requested', '03-plugins',
            message: 'Inspección de '.$component.' en el destino: '.$repository.' @ '.substr($commit, 0, 12).'.',
            payload: ['operation_uuid' => $operation->operation_uuid, 'component' => $component], operation: $operation);

        return $operation;
    }

    /** Settles a finished inspection into the open plugin intervention, once. */
    private function observeInspection(RemoteOperation $operation): void
    {
        if ($operation->communication_state !== RemoteCommunicationState::TERMINATED || isset($operation->evidence['inspection'])) {
            return;
        }
        $component = (string) ($operation->evidence['parameters']['component'] ?? '');
        $execution = $operation->execution;
        $proposal = null;
        $message = null;
        if ($operation->functional_state === RemoteFunctionalState::SUCCEEDED && $operation->exit_code === 0) {
            $settings = $this->settings($execution);
            try {
                $response = $this->agent->call($this->connections->target($this->server($execution, $settings)), 'plugin-proposal',
                    [(string) $settings['destination_profile'], $component], 30);
            } catch (RemoteAgentUnavailable) {
                return;
            }
            $decoded = $response->ok ? json_decode((string) base64_decode((string) ($response->data['proposal_base64'] ?? ''), true), true) : null;
            $entry = is_array($decoded['entry'] ?? null) ? $decoded['entry'] : [];
            if (($decoded['component'] ?? null) === $component && is_int($entry['version'] ?? null) && is_string($entry['tree_sha256'] ?? null)) {
                $proposal = ['version' => $entry['version'], 'requires' => is_int($entry['requires'] ?? null) ? $entry['requires'] : null,
                    'release' => is_string($entry['release'] ?? null) ? mb_substr($entry['release'], 0, 120) : null,
                    'path' => is_string($entry['path'] ?? null) ? mb_substr($entry['path'], 0, 120) : null,
                    'tree_sha256' => preg_match('/^[0-9a-f]{64}$/D', $entry['tree_sha256']) === 1 ? $entry['tree_sha256'] : null,
                    'dependencies' => array_slice(array_keys(is_array($entry['dependencies'] ?? null) ? $entry['dependencies'] : []), 0, 20)];
            } else {
                $message = 'La inspección terminó sin una propuesta válida.';
            }
        } else {
            $message = 'La inspección falló: '.(self::INSPECTION_ERRORS[$operation->exit_code ?? 0] ?? 'código '.($operation->exit_code ?? '?')).'.';
        }
        DB::transaction(function () use ($operation, $execution, $component, $proposal, $message): void {
            $locked = RemoteOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            if (isset($locked->evidence['inspection'])) {
                return;
            }
            $locked->forceFill(['evidence' => [...($locked->evidence ?? []), 'inspection' => ['state' => $proposal === null ? 'FAILED' : 'SUCCEEDED']]])->save();
            $conflict = Conflict::query()->where('execution_id', $execution->id)->where('type', RemoteConsolidationInterventions::PLUGINS)
                ->where('status', 'OPEN')->lockForUpdate()->first();
            if ($conflict === null || ($conflict->details['inspections'][$component]['operation_uuid'] ?? null) !== $operation->operation_uuid) {
                return;
            }
            $details = $conflict->details;
            $details['inspections'][$component]['state'] = $proposal === null ? 'FAILED' : 'SUCCEEDED';
            $details['inspections'][$component]['message'] = $message;
            if ($proposal !== null) {
                $details['proposals'][$component] = $proposal;
            }
            $conflict->details = $details;
            $conflict->save();
        }, attempts: 3);
    }

    /**
     * Launches one of the steps that run on the destination between two runs.
     *
     * @param  array<string, string>  $extra
     */
    private function launchStep(Execution $execution, User $actor, string $command, array $extra = []): void
    {
        $settings = $this->settings($execution);
        $server = $this->server($execution, $settings);
        $distribution = $this->assertLaunchable($server, $settings, true);
        $stage = self::STEP_COMMANDS[$command];
        $running = $this->lifecycle->transition($execution, ExecutionStatus::RUNNING, $actor);
        $attempt = $running->remoteOperations()->where('command_key', $command)->count() + 1;
        try {
            $operation = $this->operations->launch($running, $server, str_replace('.', '-', $command).'-'.$attempt, $command, [
                'destination' => (string) $settings['destination_profile'], 'distribution' => $distribution->distribution_sha256, ...$extra,
            ], $actor);
        } catch (ToolOperationBlocked $exception) {
            $this->close($running, null, false, $exception->getMessage());

            return;
        }
        $this->step($running, $stage, ExecutionStepStatus::RUNNING);
        [$type, $message, $action] = match ($command) {
            self::NONE_COMMAND => ['consolidator.plugins_approval_requested',
                'Continuar sin plugins aceptado; el destino reconstruye Moodle si hace falta y sella el inventario vacío.', 'CONSOLIDATOR_PLUGINS_NONE_ACCEPTED'],
            self::PROMOTE_COMMAND => ['consolidator.plugins_promoted',
                'Plugins confirmados para el catálogo; el Consolidador los agrega y revalida la etapa de plugins.', 'CONSOLIDATOR_PLUGINS_PROMOTED'],
            self::IDENTITY_COMMAND => ['consolidator.identity_review_submitted',
                'Revisión de identidades registrada; el Consolidador la valida y la aplica en el destino.', 'CONSOLIDATOR_IDENTITY_REVIEW_SUBMITTED'],
            default => ['consolidator.plugins_approval_requested',
                'Aprobación de plugins aceptada; el Consolidador sella el inventario en el destino.', 'CONSOLIDATOR_PLUGINS_APPROVED'],
        };
        $this->events->record($running, $type, $stage, message: $message,
            payload: ['operation_uuid' => $operation->operation_uuid, 'command' => $command], operation: $operation);
        AuditLog::query()->create(['actor_id' => $actor->getKey(), 'project_id' => $running->project_id, 'execution_id' => $running->id,
            'action' => $action, 'payload' => ['operation_uuid' => $operation->operation_uuid]]);
    }

    /** After a step on the destination ends: a successful one continues with the next run, a failed one waits again. */
    private function afterStep(Execution $execution, RemoteOperation $approval): void
    {
        $stage = self::STEP_COMMANDS[$approval->command_key] ?? '03-plugins';
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
            $this->events->record($execution, 'consolidator.resumed', $stage,
                message: ($approval->command_key === self::IDENTITY_COMMAND ? 'Revisión de identidades aplicada' : 'Plugins aprobados')
                    .'; el Consolidador reanuda desde sus checkpoints aprobados.',
                payload: ['operation_uuid' => $operation->operation_uuid, 'stage' => $stage], operation: $operation);

            return;
        }
        $code = $approval->exit_code ?? '?';
        $this->wait($execution, $approval, ['state' => ['stage' => $stage, 'status' => 'STEP_FAILED'], 'message' => match ($approval->command_key) {
            // The same prefix as the assistant's own stop, so the review opens again with its candidates.
            self::IDENTITY_COMMAND => 'V8_IDENTITY_REVIEW_REQUIRED: el Consolidador no aceptó la revisión (código '.$code.'); revise las decisiones.',
            self::NONE_COMMAND => 'El sellado del inventario sin plugins no se completó en el destino (código '.$code.'); revise el inventario.',
            self::PROMOTE_COMMAND => 'El catálogo no aceptó los plugins inspeccionados (código '.$code.'); revise las propuestas.',
            default => 'La aprobación de plugins no se completó en el destino (código '.$code.'); revise el inventario.',
        }]);
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
