<?php

namespace App\Domain\Executions;

use App\Domain\Artifacts\ArtifactStreamVerifier;
use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\DTOs\StoredArtifact;
use App\Domain\Artifacts\GenerateFinalArtifacts;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Exceptions\ExecutionCommandLeaseLost;
use App\Models\AuditLog;
use App\Models\ExecutionStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProcessExecutionFinalization
{
    public function __construct(
        private readonly ExecutionCommandLease $leases,
        private readonly ExecutionLifecycle $lifecycle,
        private readonly ExecutionEventRecorder $events,
        private readonly GenerateFinalArtifacts $generator,
        private readonly ArtifactStorage $storage,
        private readonly ArtifactStreamVerifier $verifier,
    ) {}

    public function process(int $commandId, string $owner): void
    {
        $command = DB::transaction(function () use ($commandId, $owner) {
            $locked = $this->leases->lockCommand($commandId);

            if ($locked === null
                || ! $this->leases->isOwnedAndActive($locked, $owner)
                || $locked->command_type !== ExecutionCommandType::FINALIZE
                || $locked->execution->status !== ExecutionStatus::REVIEW
            ) {
                throw new ExecutionCommandLeaseLost;
            }

            return $locked;
        }, attempts: 3);
        $actor = User::query()->findOrFail($command->created_by);
        $completionAt = now()->utc()->toImmutable();
        $requestedAt = ($command->created_at ?? $completionAt)->utc();
        $heartbeat = function () use ($commandId, $owner): void {
            if (! $this->leases->renew($commandId, $owner)) {
                throw new ExecutionCommandLeaseLost;
            }
        };
        $generated = $this->generator->stage(
            $command->execution,
            $actor,
            $commandId,
            $owner,
            $requestedAt,
            $completionAt,
            $heartbeat,
        );
        $promoted = [];
        $committed = false;

        try {
            foreach ($generated as $item) {
                $this->verifier->verify($item['stored']);
            }

            DB::transaction(function () use ($commandId, $owner, $generated, $actor, $completionAt, &$promoted): void {
                $command = $this->leases->lockCommand($commandId);

                if ($command === null
                    || ! $this->leases->isOwnedAndActive($command, $owner)
                    || $command->command_type !== ExecutionCommandType::FINALIZE
                ) {
                    throw new ExecutionCommandLeaseLost;
                }

                $execution = $command->execution;
                /** @var array<string, mixed> $payload */
                $payload = $command->payload ?? [];

                if ($execution->status !== ExecutionStatus::REVIEW
                    || $execution->review_fingerprint === null
                    || $execution->validated_fingerprint === null
                    || $execution->validated_proposal_version !== $execution->proposal_version
                    || ($payload['proposal_version'] ?? null) !== $execution->proposal_version
                    || ! is_string($payload['fingerprint'] ?? null)
                    || ! hash_equals($execution->review_fingerprint, $payload['fingerprint'])
                    || ! hash_equals($execution->review_fingerprint, $execution->validated_fingerprint)
                ) {
                    throw new ExecutionCommandLeaseLost;
                }

                if ($execution->commands()->whereNull('processed_at')->whereKeyNot($command->getKey())->lockForUpdate()->exists()) {
                    throw new RuntimeException('Existe otra operación pendiente durante la finalización.');
                }

                if ($execution->artifacts()->exists()) {
                    throw new RuntimeException('La ejecución ya contiene artefactos finales.');
                }

                // Renew while holding the command lock. The four following atomic
                // promotions are bounded and each rechecks ownership immediately
                // before its irreversible create-if-absent operation.
                $command->lease_expires_at = now()->utc()->addSeconds($this->leases->durationSeconds());
                $command->save();

                foreach ($generated as $item) {
                    if (! $this->leases->isOwnedAndActive($command, $owner)) {
                        throw new ExecutionCommandLeaseLost;
                    }

                    $final = $this->storage->promote($item['stored']->path, $item['final_path']);

                    if ($final->size !== $item['stored']->size || ! hash_equals($final->checksum, $item['stored']->checksum)) {
                        throw new RuntimeException('La promoción modificó el contenido del artefacto.');
                    }

                    $this->verifier->verify($final);
                    $promoted[] = $final;
                }

                foreach ($generated as $index => $item) {
                    $final = $promoted[$index];
                    $execution->artifacts()->create([
                        'type' => $item['type'],
                        'disk' => $final->disk,
                        'path' => $final->path,
                        'filename' => $item['filename'],
                        'mime_type' => $item['mime_type'],
                        'size' => $final->size,
                        'sha256' => $final->checksum,
                        'metadata' => $item['metadata'],
                    ]);
                }

                if ($execution->artifacts()->whereIn('type', GenerateFinalArtifacts::REQUIRED_TYPES)->count() !== count(GenerateFinalArtifacts::REQUIRED_TYPES)) {
                    throw new RuntimeException('No se generaron todos los artefactos obligatorios.');
                }

                $step = ExecutionStep::query()
                    ->where('execution_id', $execution->getKey())
                    ->where('step_key', 'finalization')
                    ->lockForUpdate()
                    ->firstOrFail();
                $step->status = ExecutionStepStatus::SUCCESS;
                $step->progress = 100;
                $step->started_at ??= $completionAt;
                $step->finished_at = $completionAt;
                $step->metadata = ['artifact_types' => GenerateFinalArtifacts::REQUIRED_TYPES];
                $step->save();
                $execution->progress = 100;
                $execution->finalized_by = $actor->getKey();
                $execution->completion_summary = [
                    'result' => 'COMPLETED',
                    'proposal_version' => $execution->proposal_version,
                    'fingerprint' => $execution->review_fingerprint,
                    'artifact_count' => count(GenerateFinalArtifacts::REQUIRED_TYPES),
                    'completed_at' => $completionAt->toIso8601String(),
                ];
                $execution->save();
                $this->leases->finish($command);

                $completionPayload = [
                    'proposal_version' => $execution->proposal_version,
                    'fingerprint' => $execution->review_fingerprint,
                    'artifact_count' => 4,
                    'completed_at' => $completionAt->toIso8601String(),
                ];
                AuditLog::query()->create([
                    'actor_id' => $actor->getKey(),
                    'project_id' => $execution->project_id,
                    'execution_id' => $execution->getKey(),
                    'action' => 'EXECUTION_COMPLETED',
                    'auditable_type' => $execution->getMorphClass(),
                    'auditable_id' => $execution->getKey(),
                    'payload' => $completionPayload,
                ]);
                $this->events->recordNormalized($execution, new NormalizedToolEvent(
                    'execution.completed',
                    stepKey: 'finalization',
                    progress: 100,
                    message: 'La ejecución fue cerrada con sus cuatro artefactos verificados y quedó en modo de sólo lectura.',
                    payload: $completionPayload,
                ), $completionAt);
                $this->lifecycle->transitionForWorker($execution, ExecutionStatus::COMPLETED, $completionAt);
            });
            $committed = true;
        } finally {
            $this->generator->cleanup($generated);

            if (! $committed) {
                $this->cleanupPromoted($promoted);
            }
        }
    }

    /** @param list<StoredArtifact> $artifacts */
    private function cleanupPromoted(array $artifacts): void
    {
        foreach ($artifacts as $artifact) {
            try {
                if ($this->storage->exists($artifact->path)) {
                    $this->storage->delete($artifact->path);
                }
            } catch (Throwable) {
                // These paths are private to this command and lease owner.
            }
        }
    }
}
