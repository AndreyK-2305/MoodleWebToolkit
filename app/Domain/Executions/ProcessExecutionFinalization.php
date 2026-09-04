<?php

namespace App\Domain\Executions;

use App\Domain\Artifacts\Contracts\ArtifactStorage;
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

class ProcessExecutionFinalization
{
    public function __construct(
        private readonly ExecutionCommandLease $leases,
        private readonly ExecutionLifecycle $lifecycle,
        private readonly ExecutionEventRecorder $events,
        private readonly GenerateFinalArtifacts $generator,
        private readonly ArtifactStorage $storage,
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
        $generated = $this->generator->generate($command->execution, $actor);
        $committed = false;

        try {
            DB::transaction(function () use ($commandId, $owner, $generated, $actor): void {
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

                foreach ($generated as $item) {
                    $contents = $this->storage->read($item['stored']->path);

                    if (strlen($contents) !== $item['stored']->size || ! hash_equals(hash('sha256', $contents), $item['stored']->checksum)) {
                        throw new RuntimeException('Falló la verificación del artefacto antes del cierre.');
                    }

                    $execution->artifacts()->create([
                        'type' => $item['type'],
                        'disk' => $item['stored']->disk,
                        'path' => $item['stored']->path,
                        'filename' => $item['filename'],
                        'mime_type' => $item['mime_type'],
                        'size' => $item['stored']->size,
                        'sha256' => $item['stored']->checksum,
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
                $step->started_at ??= now();
                $step->finished_at = now();
                $step->metadata = ['artifact_types' => GenerateFinalArtifacts::REQUIRED_TYPES];
                $step->save();
                $execution->progress = 100;
                $execution->finalized_by = $actor->getKey();
                $execution->completion_summary = [
                    'result' => 'COMPLETED',
                    'proposal_version' => $execution->proposal_version,
                    'fingerprint' => $execution->review_fingerprint,
                    'artifact_count' => count(GenerateFinalArtifacts::REQUIRED_TYPES),
                ];
                $execution->save();
                $this->leases->finish($command);

                AuditLog::query()->create([
                    'actor_id' => $actor->getKey(),
                    'project_id' => $execution->project_id,
                    'execution_id' => $execution->getKey(),
                    'action' => 'EXECUTION_COMPLETED',
                    'auditable_type' => $execution->getMorphClass(),
                    'auditable_id' => $execution->getKey(),
                    'payload' => ['proposal_version' => $execution->proposal_version, 'fingerprint' => $execution->review_fingerprint, 'artifact_count' => 4],
                ]);
                $this->events->recordNormalized($execution, new NormalizedToolEvent(
                    'execution.completed',
                    stepKey: 'finalization',
                    progress: 100,
                    message: 'La ejecución fue cerrada con sus cuatro artefactos verificados y quedó en modo de sólo lectura.',
                    payload: ['proposal_version' => $execution->proposal_version, 'fingerprint' => $execution->review_fingerprint, 'artifact_count' => 4],
                ));
                $this->lifecycle->transitionForWorker($execution, ExecutionStatus::COMPLETED);
            }, attempts: 3);
            $committed = true;
        } finally {
            if (! $committed) {
                $this->generator->cleanup($generated);
            }
        }
    }
}
