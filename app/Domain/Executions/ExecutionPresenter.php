<?php

namespace App\Domain\Executions;

use App\Domain\Academic\AcademicPreview;
use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Collector\CollectorExecutionPreparation;
use App\Domain\Remote\OperatorText;
use App\Domain\Remote\RemoteCollectionConfiguration;
use App\Domain\Remote\RemoteCollectionPreparation;
use App\Domain\Remote\RemoteCollectorExport;
use App\Domain\Remote\RemoteConsolidationBackup;
use App\Domain\Remote\RemoteConsolidationInterventions;
use App\Domain\Remote\RemoteConsolidationPreparation;
use App\Domain\Remote\RemoteConsolidationWorkflow;
use App\Domain\Remote\RemoteSourceCleanup;
use App\Domain\Remote\RetryRemoteConsolidation;
use App\Models\AcademicProposal;
use App\Models\Artifact;
use App\Models\Checkpoint;
use App\Models\Conflict;
use App\Models\Execution;
use App\Models\ExecutionEvent;
use App\Models\ExecutionStep;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\SourcePackage;
use App\Models\Verification;

class ExecutionPresenter
{
    public function __construct(private readonly AcademicPreview $preview) {}

    /** @return array<string, mixed> */
    public function execution(Execution $execution): array
    {
        $execution->loadMissing(['steps', 'conflicts', 'checkpoints', 'resumedFromExecution']);
        $real = $execution->toolBinding?->adapter_key === CollectorExecutionPreparation::ADAPTER_KEY;
        $remote = RemoteCollectionPreparation::selected($execution->toolBinding);
        $operation = $real || $remote ? $execution->remoteOperations()->latest('id')->first() : null;

        return [
            'uuid' => $execution->uuid,
            'attempt' => $execution->attempt,
            'resumed_from_execution_uuid' => $execution->resumedFromExecution?->uuid,
            'retried_from_execution_uuid' => $execution->retried_from_execution_id === null ? null : Execution::query()->whereKey($execution->retried_from_execution_id)->value('uuid'),
            'collector' => $remote ? $this->remoteCollector($execution, $operation) : ($real ? ['mode' => 'LABORATORY', 'producer_version' => '7.4.2-linux', 'resume_supported' => false,
                'configuration_version' => $execution->project->configuration?->version,
                'can_fresh_export' => in_array($execution->status->value, ['FAILED', 'CANCELLED'], true)
                    && (int) $execution->project->executions()->max('attempt') === $execution->attempt
                    && ! $execution->remoteOperations()->where('communication_state', '!=', 'TERMINATED')->exists(),
                'operation' => $operation === null ? null : ['uuid' => $operation->operation_uuid,
                    'communication_state' => $operation->communication_state->value, 'functional_state' => $operation->functional_state->value,
                    // Monotonic launch evidence: CONNECTED only lasts while the process runs.
                    'started_at' => $operation->started_at?->toIso8601String(),
                    'manual_intervention_required' => $operation->manual_intervention_required]] : null),
            'consolidation' => RemoteConsolidationPreparation::selected($execution->toolBinding) ? $this->remoteConsolidation($execution) : null,
            'status' => $execution->status->value,
            'progress' => $execution->progress,
            'started_at' => $execution->started_at?->toIso8601String(),
            'finished_at' => $execution->finished_at?->toIso8601String(),
            'last_event_sequence' => $execution->last_event_sequence,
            'steps' => $execution->steps
                ->map(fn (ExecutionStep $step): array => [
                    'key' => $step->step_key,
                    'name' => $step->name,
                    'position' => $step->position,
                    'status' => $step->status->value,
                    'progress' => $step->progress,
                    'started_at' => $step->started_at?->toIso8601String(),
                    'finished_at' => $step->finished_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'conflicts' => $execution->conflicts
                ->map(fn (Conflict $conflict): array => [
                    'id' => $conflict->getKey(),
                    'key' => $conflict->key,
                    'type' => $conflict->type,
                    'status' => $conflict->status->value,
                    'version' => $conflict->version,
                    'message' => $conflict->details['message'] ?? null,
                    'allowed_decisions' => is_array($conflict->details['allowed_decisions'] ?? null)
                        ? array_values($conflict->details['allowed_decisions'])
                        : [],
                    'resolved_at' => $conflict->resolved_at?->toIso8601String(),
                    // The Consolidator's own guidance for a typed intervention, whitelisted.
                    'intervention' => in_array($conflict->type, RemoteConsolidationInterventions::TYPES, true)
                        ? RemoteConsolidationInterventions::present($conflict->details) : null,
                ])
                ->values()
                ->all(),
            'checkpoints' => $execution->checkpoints
                ->map(fn (Checkpoint $checkpoint): array => [
                    'id' => $checkpoint->getKey(),
                    'step_key' => $checkpoint->step_key,
                    'type' => $checkpoint->type,
                    'validated' => $checkpoint->validated,
                    'created_at' => $checkpoint->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The destination, packages and the Consolidator's own state for the latest run; closed fields only.
     *
     * @return array<string, mixed>
     */
    private function remoteConsolidation(Execution $execution): array
    {
        $settings = $execution->toolBinding->configuration_snapshot ?? [];
        $runs = $execution->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::COMMAND)->orderBy('id')->get();
        $run = $runs->last();
        $report = is_array($run?->evidence['consolidator'] ?? null) ? $run->evidence['consolidator'] : [];
        $server = is_string($settings['server_uuid'] ?? null)
            ? Server::query()->where('project_id', $execution->project_id)->where('uuid', $settings['server_uuid'])->first() : null;

        return [
            'mode' => 'REMOTE_CONSOLIDATION',
            'server' => $server?->name,
            'destination_profile' => $settings['destination_profile'] ?? null,
            'workers' => $settings['workers'] ?? null,
            'packages' => array_values(array_map(fn ($package): array => ['name' => $package['name'] ?? null, 'source_id' => $package['source_id'] ?? null,
                'sha256' => $package['sha256'] ?? null], array_filter(is_array($settings['source_packages'] ?? null) ? $settings['source_packages'] : [], 'is_array'))),
            'runs' => $runs->count(),
            'operation' => $run === null ? null : ['uuid' => $run->operation_uuid, 'communication_state' => $run->communication_state->value,
                'functional_state' => $run->functional_state->value, 'exit_code' => $run->exit_code,
                'manual_intervention_required' => $run->manual_intervention_required],
            'stage' => $report['state']['stage'] ?? null,
            'stage_status' => $report['state']['status'] ?? null,
            'message' => OperatorText::forWebUser(is_string($report['message'] ?? null) ? $report['message'] : null),
            'server_message' => OperatorText::technical(is_string($report['message'] ?? null) ? $report['message'] : null),
            'review_paths' => $report['review_paths'] ?? [],
            'workers_status' => $report['workers'] ?? null,
            'closure' => $report['closure'] ?? null,
            'site_package_sha256' => $report['site_package']['package_sha256'] ?? null,
            'write_locked' => ($report['write_locked'] ?? false) === true,
            'observed_at' => $report['observed_at'] ?? null,
            'backup' => app(RemoteConsolidationBackup::class)->state($execution),
            'can_backup' => $execution->status->value === 'REVIEW' && is_string($report['site_package']['package_sha256'] ?? null)
                && ! in_array(app(RemoteConsolidationBackup::class)->state($execution)['state'], ['RUNNING', 'SUCCEEDED'], true),
            'configuration_version' => $execution->project->configuration?->version,
            'can_retry' => RetryRemoteConsolidation::retryable($execution, $execution->project),
        ];
    }

    /** @return array<string, mixed> */
    public function review(Execution $execution): array
    {
        $real = $execution->toolBinding?->adapter_key === CollectorExecutionPreparation::ADAPTER_KEY
            || RemoteCollectionPreparation::selected($execution->toolBinding)
            || RemoteConsolidationPreparation::selected($execution->toolBinding);
        $execution->loadMissing([
            'academicSnapshot', 'academicProposals.proposer', 'verifications.requester',
            'artifacts', 'finalizer', 'resumedFromExecution',
        ]);

        return [
            'proposal_version' => $execution->proposal_version,
            'fingerprint' => $execution->review_fingerprint,
            'validated_proposal_version' => $execution->validated_proposal_version,
            'validated_fingerprint' => $execution->validated_fingerprint,
            'validation_current' => $execution->review_fingerprint !== null
                && $execution->validated_fingerprint !== null
                && $execution->validated_proposal_version === $execution->proposal_version
                && hash_equals($execution->review_fingerprint, $execution->validated_fingerprint),
            'tree' => $real || $execution->academicSnapshot === null ? [] : $this->preview->hierarchicalState($execution),
            'source_packages' => $real ? SourcePackage::query()->where('producer_execution_id', $execution->id)->get()->map(fn (SourcePackage $package): array => [
                'uuid' => $package->uuid, 'name' => $package->name, 'producer_version' => $package->producer_tool_version,
                'schema_version' => $package->schema_version, 'sha256' => $package->sha256, 'manifest_sha256' => $package->manifest_sha256,
                'size_bytes' => $package->size_bytes, 'validation_state' => $package->validation_state,
                'capabilities' => $package->capabilities, 'courses' => $package->evidence['collector_audit']['courses'] ?? null,
                'metadata_state' => $package->evidence['collector_audit']['metadata_state'] ?? null,
            ])->all() : [],
            'proposals' => $execution->academicProposals->map(fn (AcademicProposal $proposal): array => [
                'id' => $proposal->getKey(),
                'version' => $proposal->version,
                'operation' => $proposal->operation,
                'node_id' => $proposal->node_id,
                'node_type' => $proposal->node_type,
                'old_value' => $proposal->old_value,
                'new_value' => $proposal->new_value,
                'status' => $proposal->status,
                'proposed_by' => $proposal->proposer?->name,
                'created_at' => $proposal->created_at?->toIso8601String(),
            ])->values()->all(),
            'verifications' => $execution->verifications->sortByDesc('proposal_version')->map(fn (Verification $verification): array => [
                'id' => $verification->getKey(),
                'key' => $verification->key,
                'proposal_version' => $verification->proposal_version,
                'fingerprint' => $verification->fingerprint,
                'status' => $verification->status->value,
                'approved' => $verification->approved,
                'summary' => $verification->summary,
                'details' => $verification->details,
                'checked_at' => $verification->checked_at?->toIso8601String(),
            ])->values()->all(),
            'artifacts' => $execution->artifacts->map(fn (Artifact $artifact): array => [
                'id' => $artifact->getKey(),
                'type' => $artifact->type,
                'filename' => $artifact->filename,
                'mime_type' => $artifact->mime_type,
                'size' => $artifact->size,
                'sha256' => $artifact->sha256,
                // A package published by reference stays in its bucket at the administrator's disposal.
                'location' => $artifact->disk === 's3' ? 's3://'.$artifact->path : null,
                'created_at' => $artifact->created_at?->toIso8601String(),
            ])->values()->all(),
            'completion_summary' => $execution->completion_summary,
            'finalized_by' => $execution->finalizer?->name,
            'read_only' => $execution->status->isTerminal(),
        ];
    }

    /** @return array<string, mixed> */
    public function event(ExecutionEvent $event): array
    {
        return [
            'sequence' => $event->sequence,
            'type' => $event->type,
            'step_key' => $event->step_key,
            'severity' => $event->severity->value,
            'progress' => $event->progress,
            'message' => app(SensitiveValueRedactor::class)->redact($event->message),
            'payload' => app(SensitiveValueRedactor::class)->redact($event->payload),
            'created_at' => $event->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function remoteCollector(Execution $execution, ?RemoteOperation $operation): array
    {
        $settings = $execution->toolBinding->configuration_snapshot ?? [];
        $configured = RemoteCollectionConfiguration::sources($settings);
        $names = Server::query()->where('project_id', $execution->project_id)->whereIn('uuid', array_column($configured, 'server_uuid'))->pluck('name', 'uuid');
        $exports = $execution->remoteOperations()->where('command_key', RemoteCollectorExport::COMMAND)->orderBy('id')->get()
            ->keyBy(fn (RemoteOperation $export): string => (string) ($export->evidence['parameters']['package'] ?? ''));
        $sources = [];
        foreach ($configured as $source) {
            $export = $exports->get($source['package_name']);
            $report = $export?->evidence['collector'] ?? null;
            $courses = is_array($report['progress'] ?? null) ? $report['progress'] : [];
            $sources[] = ['server' => $names[$source['server_uuid']] ?? null, 'source_profile' => $source['source_profile'],
                'package_name' => $source['package_name'],
                'report' => is_array($report) ? ['stage' => $courses['stage'] ?? $report['status']['stage'] ?? null,
                    'completed_courses' => $courses['completed_courses'] ?? null, 'total_courses' => $courses['total_courses'] ?? null,
                    'failed_courses' => $courses['failed_courses'] ?? null, 'package_ready' => ($report['package_ready'] ?? false) === true] : null,
                'operation' => $export === null ? null : ['uuid' => $export->operation_uuid, 'communication_state' => $export->communication_state->value,
                    'functional_state' => $export->functional_state->value, 'manual_intervention_required' => $export->manual_intervention_required]];
        }
        $reports = array_values(array_filter(array_column($sources, 'report')));
        $sum = fn (string $field): ?int => count($reports) === count($sources) && collect($reports)->every(fn (array $report): bool => is_int($report[$field]))
            ? (int) collect($reports)->sum($field) : null;

        return ['mode' => 'REMOTE', 'producer_version' => '7.4.2-linux', 'resume_supported' => false,
            'configuration_version' => $execution->project->configuration?->version,
            'can_fresh_export' => in_array($execution->status->value, ['FAILED', 'CANCELLED'], true)
                && (int) $execution->project->executions()->max('attempt') === $execution->attempt
                && ! $execution->remoteOperations()->where('communication_state', '!=', 'TERMINATED')->exists(),
            // Summary across sources; the detail per source is in "sources".
            'server' => implode(', ', array_filter(array_column($sources, 'server'))),
            'source_profile' => implode(', ', array_column($sources, 'source_profile')),
            'sources' => $sources,
            'source_cleanup' => RemoteSourceCleanup::state($execution),
            'report' => $reports === [] ? null : ['stage' => count($sources) === 1 ? ($reports[0]['stage'] ?? null) : null,
                'completed_courses' => $sum('completed_courses'), 'total_courses' => $sum('total_courses'), 'failed_courses' => $sum('failed_courses'),
                'package_ready' => count($reports) === count($sources) && collect($reports)->every(fn (array $report): bool => $report['package_ready'])],
            'operation' => $operation === null ? null : ['uuid' => $operation->operation_uuid, 'command_key' => $operation->command_key,
                'communication_state' => $operation->communication_state->value, 'functional_state' => $operation->functional_state->value,
                'manual_intervention_required' => $operation->manual_intervention_required]];
    }
}
