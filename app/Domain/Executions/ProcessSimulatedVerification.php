<?php

namespace App\Domain\Executions;

use App\Domain\Academic\AcademicPreview;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\EventSeverity;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Enums\VerificationStatus;
use App\Exceptions\ExecutionCommandLeaseLost;
use App\Models\AuditLog;
use App\Models\ExecutionStep;
use Illuminate\Support\Facades\DB;

class ProcessSimulatedVerification
{
    public function __construct(
        private readonly ExecutionCommandLease $leases,
        private readonly ExecutionLifecycle $lifecycle,
        private readonly ExecutionEventRecorder $events,
        private readonly AcademicPreview $preview,
    ) {}

    public function process(int $commandId, string $owner): void
    {
        DB::transaction(function () use ($commandId, $owner): void {
            $command = $this->leases->lockCommand($commandId);

            if ($command === null
                || ! $this->leases->isOwnedAndActive($command, $owner)
                || $command->command_type !== ExecutionCommandType::VALIDATE
            ) {
                throw new ExecutionCommandLeaseLost;
            }

            $execution = $command->execution;
            /** @var array<string, mixed> $payload */
            $payload = $command->payload ?? [];
            $version = is_int($payload['proposal_version'] ?? null) ? $payload['proposal_version'] : -1;
            $fingerprint = is_string($payload['fingerprint'] ?? null) ? $payload['fingerprint'] : '';

            if ($execution->status !== ExecutionStatus::VERIFYING
                || $version !== $execution->proposal_version
                || $execution->review_fingerprint === null
                || ! hash_equals($execution->review_fingerprint, $fingerprint)
            ) {
                throw new ExecutionCommandLeaseLost;
            }

            $nodes = $this->preview->state($execution);

            if (! hash_equals($fingerprint, $this->preview->fingerprint($nodes, $version))) {
                throw new ExecutionCommandLeaseLost;
            }

            $step = ExecutionStep::query()
                ->where('execution_id', $execution->getKey())
                ->where('step_key', 'verification')
                ->lockForUpdate()
                ->firstOrFail();
            $step->status = ExecutionStepStatus::RUNNING;
            $step->progress = null;
            $step->started_at = now();
            $step->finished_at = null;
            $step->save();
            $this->events->recordNormalized($execution, new NormalizedToolEvent(
                'verification.started',
                stepKey: 'verification',
                progress: null,
                message: 'La verificación simulada comenzó sobre una huella exacta y persistida.',
                payload: ['proposal_version' => $version, 'fingerprint' => $fingerprint],
            ));

            $rejectedNodes = collect($nodes)
                ->filter(fn (array $node): bool => preg_match('/(?:rechazar|reject|\[invalid\])/iu', (string) $node['name']) === 1)
                ->pluck('id')
                ->values()
                ->all();
            $approved = $rejectedNodes === [];
            $checks = [
                [
                    'key' => 'stable-identifiers',
                    'severity' => 'INFO',
                    'approved' => true,
                    'message' => 'Todos los nodos conservan identificadores estables y únicos.',
                    'observed' => ['node_count' => count($nodes), 'unique_ids' => count(array_unique(array_column($nodes, 'id')))],
                ],
                [
                    'key' => 'academic-structure',
                    'severity' => $approved ? 'INFO' : 'ERROR',
                    'approved' => $approved,
                    'message' => $approved
                        ? 'La estructura y las propuestas cumplen las reglas simuladas.'
                        : 'La simulación detectó nombres marcados para rechazo.',
                    'observed' => ['rejected_node_ids' => $rejectedNodes],
                ],
                [
                    'key' => 'proposal-fingerprint',
                    'severity' => 'INFO',
                    'approved' => true,
                    'message' => 'La versión y la huella coinciden con el estado revisado.',
                    'observed' => ['proposal_version' => $version, 'fingerprint' => $fingerprint],
                ],
            ];
            $verification = $execution->verifications()->create([
                'key' => "academic-review-v{$version}",
                'proposal_version' => $version,
                'fingerprint' => $fingerprint,
                'status' => $approved ? VerificationStatus::PASSED : VerificationStatus::FAILED,
                'approved' => $approved,
                'requested_by' => $command->created_by,
                'summary' => $approved
                    ? 'La validación simulada aprobó el estado académico.'
                    : 'La validación simulada rechazó el estado académico.',
                'details' => [
                    'contract_version' => 1,
                    'overall_status' => $approved ? 'APPROVED' : 'REJECTED',
                    'checks' => $checks,
                    'observed' => ['project_type' => $execution->project->type->value, 'node_count' => count($nodes)],
                ],
                'checked_at' => now(),
            ]);

            $execution->validated_proposal_version = $approved ? $version : null;
            $execution->validated_fingerprint = $approved ? $fingerprint : null;
            $execution->progress = 75;
            $execution->save();
            $metadata = $step->metadata ?? [];
            $metadata['last_validation'] = ['verification_id' => $verification->getKey(), 'proposal_version' => $version, 'approved' => $approved];
            $step->metadata = $metadata;
            $step->status = $approved ? ExecutionStepStatus::SUCCESS : ExecutionStepStatus::FAILED;
            $step->progress = 75;
            $step->finished_at = now();
            $step->save();
            $this->leases->finish($command);
            $review = $this->lifecycle->transitionForWorker($execution, ExecutionStatus::REVIEW);

            AuditLog::query()->create([
                'actor_id' => $command->created_by,
                'project_id' => $review->project_id,
                'execution_id' => $review->getKey(),
                'action' => $approved ? 'EXECUTION_VALIDATION_APPROVED' : 'EXECUTION_VALIDATION_REJECTED',
                'auditable_type' => $verification->getMorphClass(),
                'auditable_id' => $verification->getKey(),
                'payload' => ['proposal_version' => $version, 'fingerprint' => $fingerprint],
            ]);
            $this->events->recordNormalized($review, new NormalizedToolEvent(
                'verification.completed',
                stepKey: 'verification',
                severity: $approved ? EventSeverity::INFO : EventSeverity::ERROR,
                progress: 75,
                message: $verification->summary,
                payload: ['approved' => $approved, 'proposal_version' => $version, 'fingerprint' => $fingerprint, 'verification_id' => $verification->getKey()],
            ));
        }, attempts: 3);
    }
}
