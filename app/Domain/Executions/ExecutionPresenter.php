<?php

namespace App\Domain\Executions;

use App\Domain\Academic\AcademicPreview;
use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Collector\CollectorExecutionPreparation;
use App\Models\AcademicProposal;
use App\Models\Artifact;
use App\Models\Checkpoint;
use App\Models\Conflict;
use App\Models\Execution;
use App\Models\ExecutionEvent;
use App\Models\ExecutionStep;
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
        $operation = $real ? $execution->remoteOperations()->latest('id')->first() : null;

        return [
            'uuid' => $execution->uuid,
            'attempt' => $execution->attempt,
            'resumed_from_execution_uuid' => $execution->resumedFromExecution?->uuid,
            'retried_from_execution_uuid' => $execution->retried_from_execution_id === null ? null : Execution::query()->whereKey($execution->retried_from_execution_id)->value('uuid'),
            'collector' => $real ? ['mode' => 'LABORATORY', 'producer_version' => '7.4.2-linux', 'resume_supported' => false,
                'configuration_version' => $execution->project->configuration?->version,
                'can_fresh_export' => in_array($execution->status->value, ['FAILED', 'CANCELLED'], true)
                    && (int) $execution->project->executions()->max('attempt') === $execution->attempt
                    && ! $execution->remoteOperations()->where('communication_state', '!=', 'TERMINATED')->exists(),
                'operation' => $operation === null ? null : ['uuid' => $operation->operation_uuid,
                    'communication_state' => $operation->communication_state->value, 'functional_state' => $operation->functional_state->value,
                    'manual_intervention_required' => $operation->manual_intervention_required]] : null,
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

    /** @return array<string, mixed> */
    public function review(Execution $execution): array
    {
        $real = $execution->toolBinding?->adapter_key === CollectorExecutionPreparation::ADAPTER_KEY;
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
}
