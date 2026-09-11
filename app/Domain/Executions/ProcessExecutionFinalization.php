<?php

namespace App\Domain\Executions;

use App\Domain\Artifacts\ArtifactStreamVerifier;
use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\GenerateFinalArtifacts;
use App\Domain\Artifacts\ResumableSha256;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Exceptions\ArtifactIntegrityException;
use App\Exceptions\ExecutionCommandLeaseLost;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\ExecutionFinalization;
use App\Models\ExecutionStep;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProcessExecutionFinalization
{
    public const DEFAULT_RECORDS_PER_JOB = 200;

    public const DEFAULT_VERIFICATION_BYTES_PER_JOB = 1_048_576;

    public function __construct(
        private readonly ExecutionCommandLease $leases,
        private readonly ExecutionLifecycle $lifecycle,
        private readonly ExecutionEventRecorder $events,
        private readonly ExecutionCommandDispatcher $dispatcher,
        private readonly GenerateFinalArtifacts $generator,
        private readonly ArtifactStorage $storage,
        private readonly ArtifactStreamVerifier $verifier,
    ) {}

    public function process(int $commandId, string $owner): void
    {
        $continue = DB::transaction(function () use ($commandId, $owner): bool {
            $command = $this->leases->lockCommand($commandId);

            if ($command === null
                || ! $this->leases->isOwnedAndActive($command, $owner)
                || $command->command_type !== ExecutionCommandType::FINALIZE
                || $command->execution->status !== ExecutionStatus::REVIEW
            ) {
                throw new ExecutionCommandLeaseLost;
            }

            $execution = $command->execution;
            $state = ExecutionFinalization::query()
                ->where('execution_command_id', $commandId)
                ->lockForUpdate()
                ->first();

            if ($state === null) {
                $state = ExecutionFinalization::query()->create([
                    'execution_id' => $execution->getKey(),
                    'execution_command_id' => $commandId,
                    'stage' => 'PREPARE',
                    'staging_prefix' => "executions/{$execution->workspace_key}/.staging/{$commandId}",
                    'final_prefix' => "executions/{$execution->workspace_key}/final/{$commandId}",
                ]);
            }

            $this->assertStillFinalizable($command->payload ?? [], $execution, $commandId);
            $state->lease_owner = $owner;
            $state->lease_expires_at = $command->lease_expires_at;
            $state->save();
            $actor = User::query()->findOrFail($command->created_by);

            match ($state->stage) {
                'PREPARE' => $this->prepare($state, $execution, $commandId, $owner),
                'EXPORT_LOGS' => $this->exportLogs($state, $execution),
                'EXPORT_EVENTS' => $this->exportEvents($state, $execution),
                'GENERATE_REPORTS' => $this->generateReports($state, $execution, $actor, $commandId, $owner),
                'VERIFY_STAGING' => $this->verifyStaging($state),
                'PROMOTE_ARTIFACTS' => $this->promoteNext($state),
                'FINAL_SUMMARY' => $this->generateFinalSummary($state, $execution, $actor, $commandId, $owner, $command->created_at ?? now()),
                'PROMOTE_SUMMARY' => $this->promoteNext($state),
                'COMMIT' => $this->commit($state, $command, $actor),
                'COMPLETED' => null,
                default => throw new RuntimeException('La etapa persistida de finalización no es reconocida.'),
            };

            if ($state->stage === 'COMPLETED') {
                return false;
            }

            if (! $this->leases->isOwnedAndActive($command, $owner)) {
                throw new ExecutionCommandLeaseLost;
            }

            $this->leases->releaseForContinuation($command);
            $state->lease_owner = null;
            $state->lease_expires_at = null;
            $state->save();

            return true;
        }, attempts: 3);

        if ($continue) {
            $command = ExecutionCommand::query()->find($commandId);

            if ($command !== null && $command->processed_at === null && $command->dispatched_at === null) {
                $this->dispatcher->dispatch($command);
            }
        }
    }

    private function prepare(ExecutionFinalization $state, Execution $execution, int $commandId, string $owner): void
    {
        $startedAt = now()->utc()->toImmutable();
        $work = $this->generator->prepareResumableLog($execution, $commandId, $owner, $startedAt);
        $state->finalization_started_at = $startedAt;
        $state->temporary_files = [$work];
        $state->stage = 'EXPORT_LOGS';
        $state->save();
    }

    private function exportLogs(ExecutionFinalization $state, Execution $execution): void
    {
        $result = $this->generator->appendLogBatch(
            $execution,
            $this->logWork($state),
            $state->log_cursor,
            $this->recordsPerJob(),
        );
        $state->temporary_files = [$result['work']];
        $state->log_cursor = $result['cursor'];

        if ($result['done']) {
            $state->stage = 'EXPORT_EVENTS';
        }

        $state->save();
    }

    private function exportEvents(ExecutionFinalization $state, Execution $execution): void
    {
        $result = $this->generator->appendEventBatch(
            $execution,
            $this->logWork($state),
            $state->event_cursor,
            $this->recordsPerJob(),
        );
        $state->temporary_files = [$result['work']];
        $state->event_cursor = $result['cursor'];

        if ($result['done']) {
            $state->stage = 'GENERATE_REPORTS';
        }

        $state->save();
    }

    private function generateReports(
        ExecutionFinalization $state,
        Execution $execution,
        User $actor,
        int $commandId,
        string $owner,
    ): void {
        $artifacts = $this->generator->stageRemainingReports(
            $execution,
            $actor,
            $commandId,
            $owner,
            $state->finalization_started_at ?? now()->utc(),
            $this->logWork($state),
        );
        $state->artifacts = $artifacts;
        $state->temporary_files = array_map(
            fn (array $artifact): array => $artifact['stored'],
            $artifacts,
        );
        $state->stage = 'VERIFY_STAGING';
        $state->save();
    }

    private function verifyStaging(ExecutionFinalization $state): void
    {
        $artifacts = $state->artifacts;
        $verified = $state->verified_types;
        $descriptor = collect($artifacts)->first(fn (array $artifact): bool => ! in_array($artifact['type'], $verified, true));

        if ($descriptor === null) {
            $state->stage = 'PROMOTE_ARTIFACTS';
            $state->save();

            return;
        }

        $stored = $this->generator->stored($descriptor);

        if ($state->verification_type !== $descriptor['type']) {
            $state->verification_type = $descriptor['type'];
            $state->verification_offset = 0;
            $state->verification_hash_state = ResumableSha256::start()->state();
        }

        $hash = ResumableSha256::resume($state->verification_hash_state ?? ResumableSha256::start()->state());
        $stream = $this->storage->readStream($stored->path);
        $remaining = $this->verificationBytesPerJob();

        try {
            $stream->seek($state->verification_offset);

            while ($remaining > 0 && $state->verification_offset < $stored->size) {
                $chunk = $stream->read($remaining);

                if ($chunk === '') {
                    throw new ArtifactIntegrityException('El archivo terminó antes del tamaño persistido.');
                }

                $hash->update($chunk);
                $bytes = strlen($chunk);
                $state->verification_offset += $bytes;
                $remaining -= $bytes;
            }
        } finally {
            $stream->close();
        }

        $state->verification_hash_state = $hash->state();

        if ($state->verification_offset === $stored->size) {
            if (! hash_equals($stored->checksum, $hash->finish())) {
                throw new ArtifactIntegrityException('El archivo fue alterado y su uso fue bloqueado.');
            }

            $verified[] = $descriptor['type'];
            $state->verified_types = array_values(array_unique($verified));
            $state->verification_type = null;
            $state->verification_offset = 0;
            $state->verification_hash_state = null;

            if (count($state->verified_types) === count($artifacts)) {
                $state->stage = 'PROMOTE_ARTIFACTS';
            }
        }

        $state->save();
    }

    private function promoteNext(ExecutionFinalization $state): void
    {
        $artifacts = $state->artifacts;
        $promoted = $state->promoted_types;
        $descriptorIndex = null;

        foreach ($artifacts as $index => $artifact) {
            if (! in_array($artifact['type'], $promoted, true)) {
                $descriptorIndex = $index;
                break;
            }
        }

        if ($descriptorIndex === null) {
            $state->stage = count($artifacts) === count(GenerateFinalArtifacts::REQUIRED_TYPES) ? 'COMMIT' : 'FINAL_SUMMARY';
            $state->save();

            return;
        }

        $descriptor = $artifacts[$descriptorIndex];
        $expected = $this->generator->stored($descriptor);
        $final = $this->storage->promote($expected->path, $descriptor['final_path'], $expected);

        if ($final->size !== $expected->size || ! hash_equals($final->checksum, $expected->checksum)) {
            throw new RuntimeException('La promoción modificó el contenido del artefacto.');
        }

        $descriptor['final'] = [
            'disk' => $final->disk,
            'path' => $final->path,
            'size' => $final->size,
            'checksum' => $final->checksum,
        ];
        $artifacts[$descriptorIndex] = $descriptor;
        $promoted[] = $descriptor['type'];
        $state->artifacts = $artifacts;
        $state->promoted_types = array_values(array_unique($promoted));

        if (count($state->promoted_types) === count($artifacts)) {
            $state->stage = count($artifacts) === count(GenerateFinalArtifacts::REQUIRED_TYPES) ? 'COMMIT' : 'FINAL_SUMMARY';
        }

        $state->save();
    }

    private function generateFinalSummary(
        ExecutionFinalization $state,
        Execution $execution,
        User $actor,
        int $commandId,
        string $owner,
        CarbonInterface $requestedAt,
    ): void {
        $completionAt = now()->utc()->toImmutable();
        $execution->setRelation('finalization', $state);
        $summary = $this->generator->stageFinalSummary(
            $execution,
            $actor,
            $commandId,
            $owner,
            $requestedAt->utc(),
            $completionAt,
        );
        $this->verifier->verify($this->generator->stored($summary));
        $artifacts = $state->artifacts;
        $artifacts[] = $summary;
        $verified = $state->verified_types;
        $verified[] = 'FINAL_SUMMARY';
        $temporary = $state->temporary_files;
        $temporary[] = $summary['stored'];
        $state->artifacts = $artifacts;
        $state->verified_types = array_values(array_unique($verified));
        $state->temporary_files = $temporary;
        $state->closure_ready_at = $completionAt;
        $state->stage = 'PROMOTE_SUMMARY';
        $state->save();
    }

    private function commit(ExecutionFinalization $state, ExecutionCommand $command, User $actor): void
    {
        $execution = $command->execution;
        $completionAt = $state->closure_ready_at;
        $artifacts = $state->artifacts;

        if ($completionAt === null
            || count($artifacts) !== count(GenerateFinalArtifacts::REQUIRED_TYPES)
            || count($state->verified_types) !== count(GenerateFinalArtifacts::REQUIRED_TYPES)
            || count($state->promoted_types) !== count(GenerateFinalArtifacts::REQUIRED_TYPES)
            || $execution->artifacts()->exists()
        ) {
            throw new RuntimeException('La finalización no alcanzó una salida íntegra y única.');
        }

        foreach ($artifacts as $descriptor) {
            $final = $this->generator->stored($descriptor, final: true);
            $execution->artifacts()->create([
                'type' => $descriptor['type'],
                'disk' => $final->disk,
                'path' => $final->path,
                'filename' => $descriptor['filename'],
                'mime_type' => $descriptor['mime_type'],
                'size' => $final->size,
                'sha256' => $final->checksum,
                'metadata' => $descriptor['metadata'],
            ]);
        }

        $step = ExecutionStep::query()
            ->where('execution_id', $execution->getKey())
            ->where('step_key', 'finalization')
            ->lockForUpdate()
            ->firstOrFail();
        $step->status = ExecutionStepStatus::SUCCESS;
        $step->progress = 100;
        $step->started_at = $state->finalization_started_at;
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
            'finalization_started_at' => $state->finalization_started_at?->toIso8601String(),
            'completed_at' => $completionAt->toIso8601String(),
        ];
        $execution->save();
        $completionPayload = [
            'proposal_version' => $execution->proposal_version,
            'fingerprint' => $execution->review_fingerprint,
            'artifact_count' => count(GenerateFinalArtifacts::REQUIRED_TYPES),
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
        $this->leases->finish($command, $completionAt);
        $state->stage = 'COMPLETED';
        $state->completed_at = $completionAt;
        $state->lease_owner = null;
        $state->lease_expires_at = null;
        $state->save();
        $this->lifecycle->transitionForWorker($execution, ExecutionStatus::COMPLETED, $completionAt);
    }

    /** @param array<string, mixed> $payload */
    private function assertStillFinalizable(array $payload, Execution $execution, int $commandId): void
    {
        if ($execution->review_fingerprint === null
            || $execution->validated_fingerprint === null
            || $execution->validated_proposal_version !== $execution->proposal_version
            || ($payload['proposal_version'] ?? null) !== $execution->proposal_version
            || ! is_string($payload['fingerprint'] ?? null)
            || ! hash_equals($execution->review_fingerprint, $payload['fingerprint'])
            || ! hash_equals($execution->review_fingerprint, $execution->validated_fingerprint)
        ) {
            throw new ExecutionCommandLeaseLost;
        }

        if ($execution->commands()->whereNull('processed_at')->whereKeyNot($commandId)->lockForUpdate()->exists()) {
            throw new RuntimeException('Existe otra operación pendiente durante la finalización.');
        }
    }

    /** @return array<string, mixed> */
    private function logWork(ExecutionFinalization $state): array
    {
        $work = $state->temporary_files[0] ?? null;

        if (! is_array($work) || ($work['type'] ?? null) !== 'LOG_EXPORT') {
            throw new RuntimeException('No existe un cursor físico para el export de logs.');
        }

        return $work;
    }

    private function recordsPerJob(): int
    {
        return max(1, min(1_000, (int) config('services.finalization.records_per_job', self::DEFAULT_RECORDS_PER_JOB)));
    }

    private function verificationBytesPerJob(): int
    {
        return max(
            ArtifactStorage::MAX_CHUNK_BYTES,
            min(16_777_216, (int) config('services.finalization.verification_bytes_per_job', self::DEFAULT_VERIFICATION_BYTES_PER_JOB)),
        );
    }
}
