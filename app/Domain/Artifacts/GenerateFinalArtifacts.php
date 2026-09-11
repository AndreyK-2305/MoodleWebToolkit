<?php

namespace App\Domain\Artifacts;

use App\Domain\Academic\AcademicPreview;
use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\DTOs\StoredArtifact;
use App\Models\Execution;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Throwable;

class GenerateFinalArtifacts
{
    public const REQUIRED_TYPES = ['JSON_REPORT', 'VERIFICATION_REPORT', 'LOG_EXPORT', 'FINAL_SUMMARY'];

    public function __construct(
        private readonly ArtifactStorage $storage,
        private readonly AcademicPreview $preview,
        private readonly SensitiveValueRedactor $redactor,
    ) {}

    /**
     * @return list<array{type: string, filename: string, mime_type: string, stored: StoredArtifact, final_path: string, metadata: array<string, mixed>}>
     */
    public function stage(
        Execution $execution,
        User $actor,
        int $commandId,
        string $leaseOwner,
        CarbonInterface $requestedAt,
        CarbonInterface $completionAt,
        ?callable $heartbeat = null,
    ): array {
        $execution->loadMissing(['project', 'steps', 'verifications', 'academicProposals.proposer']);
        $baseName = Str::slug($execution->project->name) ?: 'proyecto';
        $privateOwner = Str::slug($leaseOwner);
        $stagingPrefix = "executions/{$execution->workspace_key}/.staging/{$commandId}/{$privateOwner}";
        $finalPrefix = "executions/{$execution->workspace_key}/final/{$commandId}/{$privateOwner}";
        $latestVerification = $execution->verifications()->latest('proposal_version')->first();
        $generatedAt = $completionAt->toImmutable();
        $specifications = [
            [
                'type' => 'JSON_REPORT',
                'filename' => "{$baseName}-informe.json",
                'mime_type' => 'application/json',
                'chunks' => fn (): iterable => $this->jsonChunks([
                    'contract_version' => 1,
                    'project' => ['uuid' => $execution->project->uuid, 'name' => $execution->project->name, 'type' => $execution->project->type->value],
                    'execution' => ['uuid' => $execution->uuid, 'attempt' => $execution->attempt, 'proposal_version' => $execution->proposal_version],
                    'academic_fingerprint' => $execution->review_fingerprint,
                    'academic_nodes' => $this->preview->state($execution),
                    'generated_at' => $generatedAt->toIso8601String(),
                ]),
            ],
            [
                'type' => 'VERIFICATION_REPORT',
                'filename' => "{$baseName}-verificacion.json",
                'mime_type' => 'application/json',
                'chunks' => fn (): iterable => $this->jsonChunks([
                    'contract_version' => 1,
                    'execution_uuid' => $execution->uuid,
                    'proposal_version' => $latestVerification?->proposal_version,
                    'fingerprint' => $latestVerification?->fingerprint,
                    'approved' => $latestVerification?->approved,
                    'status' => $latestVerification?->status->value,
                    'summary' => $latestVerification?->summary,
                    'details' => $latestVerification?->details,
                    'checked_at' => $latestVerification?->checked_at?->toIso8601String(),
                    'generated_at' => $generatedAt->toIso8601String(),
                ]),
            ],
            [
                'type' => 'LOG_EXPORT',
                'filename' => "{$baseName}-logs.json",
                'mime_type' => 'application/json',
                'chunks' => fn (): iterable => $this->logExportChunks($execution, $generatedAt, $heartbeat),
            ],
            [
                'type' => 'FINAL_SUMMARY',
                'filename' => "{$baseName}-resumen-final.json",
                'mime_type' => 'application/json',
                'chunks' => fn (): iterable => $this->jsonChunks([
                    'contract_version' => 1,
                    'project' => ['uuid' => $execution->project->uuid, 'name' => $execution->project->name, 'type' => $execution->project->type->value],
                    'execution' => [
                        'uuid' => $execution->uuid,
                        'attempt' => $execution->attempt,
                        'final_status' => 'COMPLETED',
                        'progress' => 100,
                        'started_at' => $execution->started_at?->toIso8601String(),
                        'completed_at' => $completionAt->toIso8601String(),
                    ],
                    'finalization_requested_at' => $requestedAt->toIso8601String(),
                    'generated_at' => $generatedAt->toIso8601String(),
                    'finalized_by' => ['id' => $actor->getKey(), 'name' => $actor->name],
                    'proposal_version' => $execution->proposal_version,
                    'proposal_count' => $execution->academicProposals->count(),
                    'validated_fingerprint' => $execution->validated_fingerprint,
                ]),
            ],
        ];
        $staged = [];

        try {
            foreach ($specifications as $specification) {
                if ($heartbeat !== null) {
                    $heartbeat();
                }
                $basename = strtolower(str_replace('_', '-', $specification['type'])).'.json';
                $stored = $this->storage->writeStream("{$stagingPrefix}/{$basename}", ($specification['chunks'])());
                $staged[] = [
                    'type' => $specification['type'],
                    'filename' => $specification['filename'],
                    'mime_type' => $specification['mime_type'],
                    'stored' => $stored,
                    'final_path' => "{$finalPrefix}/{$basename}",
                    'metadata' => [
                        'contract_version' => 1,
                        'proposal_version' => $execution->proposal_version,
                        'generated_at' => $generatedAt->toIso8601String(),
                    ],
                ];
            }
        } catch (Throwable $exception) {
            $this->cleanup($staged);
            throw $exception;
        }

        return $staged;
    }

    /** @param list<array{stored: StoredArtifact}> $artifacts */
    public function cleanup(array $artifacts): void
    {
        foreach ($artifacts as $artifact) {
            try {
                if ($this->storage->exists($artifact['stored']->path)) {
                    $this->storage->delete($artifact['stored']->path);
                }
            } catch (Throwable) {
                // Cleanup is best effort and restricted to this worker's private staging.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @return iterable<string>
     */
    private function jsonChunks(array $value): iterable
    {
        yield json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /** @return iterable<string> */
    private function logExportChunks(Execution $execution, CarbonInterface $generatedAt, ?callable $heartbeat): iterable
    {
        yield '{"contract_version":1,"execution_uuid":'.json_encode($execution->uuid, JSON_THROW_ON_ERROR);
        yield ',"generated_at":'.json_encode($generatedAt->toIso8601String(), JSON_THROW_ON_ERROR).',"logs":[';
        $first = true;

        $processed = 0;

        foreach ($execution->logs()->orderBy('id')->lazyById(200) as $log) {
            if (! $first) {
                yield ',';
            }

            $first = false;
            yield json_encode([
                'stream' => $log->stream->value,
                'level' => $log->level,
                'message' => $this->redactor->redactString($log->message),
                'context' => $this->redactor->redact($log->context),
                'logged_at' => $log->logged_at?->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (++$processed % 200 === 0) {
                if ($heartbeat !== null) {
                    $heartbeat();
                }
            }
        }

        yield '],"events":[';
        $first = true;

        $processed = 0;

        foreach ($execution->events()->orderBy('id')->lazyById(200) as $event) {
            if (! $first) {
                yield ',';
            }

            $first = false;
            yield json_encode([
                'sequence' => $event->sequence,
                'type' => $event->type,
                'severity' => $event->severity->value,
                'message' => $this->redactor->redactString((string) $event->message),
                'created_at' => $event->created_at->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (++$processed % 200 === 0) {
                if ($heartbeat !== null) {
                    $heartbeat();
                }
            }
        }

        yield "]}\n";
    }
}
