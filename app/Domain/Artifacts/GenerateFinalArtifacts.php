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

    /** @return array<string, mixed> */
    public function prepareResumableLog(
        Execution $execution,
        int $commandId,
        string $owner,
        CarbonInterface $startedAt,
    ): array {
        $path = "executions/{$execution->workspace_key}/.staging/{$commandId}/".Str::slug($owner).'/log-export.json';
        $header = '{"contract_version":1,"execution_uuid":'.json_encode($execution->uuid, JSON_THROW_ON_ERROR)
            .',"generated_at":'.json_encode($startedAt->toIso8601String(), JSON_THROW_ON_ERROR).',"logs":[';
        $stored = $this->storage->writeStream($path, [$header]);
        $hash = ResumableSha256::start();
        $hash->update($header);

        return [
            'type' => 'LOG_EXPORT',
            'path' => $stored->path,
            'size' => $stored->size,
            'sha_state' => $hash->state(),
            'record_count' => 0,
            'checksum' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $work
     * @return array{work: array<string, mixed>, cursor: int, count: int, done: bool}
     */
    public function appendLogBatch(Execution $execution, array $work, int $cursor, int $limit): array
    {
        $rows = $execution->logs()->where('id', '>', $cursor)->orderBy('id')->limit($limit + 1)->get();
        $batch = $rows->take($limit);
        $chunks = [];
        $recordCount = (int) ($work['record_count'] ?? 0);

        foreach ($batch as $log) {
            $chunks[] = ($recordCount > 0 ? ',' : '').json_encode([
                'stream' => $log->stream->value,
                'level' => $log->level,
                'message' => $this->redactor->redactString($log->message),
                'context' => $this->redactor->redact($log->context),
                'logged_at' => $log->logged_at?->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $recordCount++;
            $cursor = (int) $log->getKey();
        }

        $done = $rows->count() <= $limit;

        if ($done) {
            $chunks[] = '],"events":[';
        }

        $work = $this->appendToWork($work, $chunks);
        $work['record_count'] = $recordCount;

        return ['work' => $work, 'cursor' => $cursor, 'count' => $batch->count(), 'done' => $done];
    }

    /**
     * @param  array<string, mixed>  $work
     * @return array{work: array<string, mixed>, cursor: int, count: int, done: bool}
     */
    public function appendEventBatch(Execution $execution, array $work, int $cursor, int $limit): array
    {
        $rows = $execution->events()->where('id', '>', $cursor)->orderBy('id')->limit($limit + 1)->get();
        $batch = $rows->take($limit);
        $chunks = [];
        $eventCount = (int) ($work['event_count'] ?? 0);

        foreach ($batch as $event) {
            $chunks[] = ($eventCount > 0 ? ',' : '').json_encode([
                'sequence' => $event->sequence,
                'type' => $event->type,
                'severity' => $event->severity->value,
                'message' => $this->redactor->redactString((string) $event->message),
                'created_at' => $event->created_at->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $eventCount++;
            $cursor = (int) $event->getKey();
        }

        $done = $rows->count() <= $limit;

        if ($done) {
            $chunks[] = "]}\n";
        }

        $work = $this->appendToWork($work, $chunks);
        $work['event_count'] = $eventCount;

        if ($done) {
            $work['checksum'] = ResumableSha256::resume($work['sha_state'])->finish();
        }

        return ['work' => $work, 'cursor' => $cursor, 'count' => $batch->count(), 'done' => $done];
    }

    /**
     * @param  array<string, mixed>  $logWork
     * @return list<array<string, mixed>>
     */
    public function stageRemainingReports(
        Execution $execution,
        User $actor,
        int $commandId,
        string $owner,
        CarbonInterface $generatedAt,
        array $logWork,
    ): array {
        $execution->loadMissing(['project', 'verifications']);
        $baseName = Str::slug($execution->project->name) ?: 'proyecto';
        $ownerPrefix = "executions/{$execution->workspace_key}/.staging/{$commandId}/".Str::slug($owner);
        $finalPrefix = "executions/{$execution->workspace_key}/final/{$commandId}";
        $latestVerification = $execution->verifications()->latest('proposal_version')->first();
        $specifications = [
            [
                'type' => 'JSON_REPORT',
                'filename' => "{$baseName}-informe.json",
                'value' => [
                    'contract_version' => 1,
                    'project' => ['uuid' => $execution->project->uuid, 'name' => $execution->project->name, 'type' => $execution->project->type->value],
                    'execution' => ['uuid' => $execution->uuid, 'attempt' => $execution->attempt, 'proposal_version' => $execution->proposal_version],
                    'academic_fingerprint' => $execution->review_fingerprint,
                    'academic_nodes' => $this->preview->state($execution),
                    'generated_at' => $generatedAt->toIso8601String(),
                ],
            ],
            [
                'type' => 'VERIFICATION_REPORT',
                'filename' => "{$baseName}-verificacion.json",
                'value' => [
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
                ],
            ],
        ];
        $artifacts = [];

        try {
            foreach ($specifications as $specification) {
                $basename = strtolower(str_replace('_', '-', $specification['type'])).'.json';
                $stored = $this->storage->writeStream("{$ownerPrefix}/{$basename}", $this->jsonChunks($specification['value']));
                $artifacts[] = $this->descriptor(
                    $specification['type'],
                    $specification['filename'],
                    $stored,
                    "{$finalPrefix}/{$basename}",
                    $execution,
                    $generatedAt,
                );
            }
        } catch (Throwable $exception) {
            $this->cleanup($artifacts);
            throw $exception;
        }

        $logStored = new StoredArtifact('local', (string) $logWork['path'], (int) $logWork['size'], (string) $logWork['checksum']);
        $artifacts[] = $this->descriptor(
            'LOG_EXPORT',
            "{$baseName}-logs.json",
            $logStored,
            "{$finalPrefix}/log-export.json",
            $execution,
            $generatedAt,
        );

        return $artifacts;
    }

    /** @return array<string, mixed> */
    public function stageFinalSummary(
        Execution $execution,
        User $actor,
        int $commandId,
        string $owner,
        CarbonInterface $requestedAt,
        CarbonInterface $completionAt,
    ): array {
        $execution->loadMissing(['project']);
        $baseName = Str::slug($execution->project->name) ?: 'proyecto';
        $basename = 'final-summary.json';
        $stored = $this->storage->writeStream(
            "executions/{$execution->workspace_key}/.staging/{$commandId}/".Str::slug($owner)."/{$basename}",
            $this->jsonChunks([
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
                'finalization_started_at' => $execution->finalization?->finalization_started_at?->toIso8601String(),
                'generated_at' => $completionAt->toIso8601String(),
                'finalized_by' => ['id' => $actor->getKey(), 'name' => $actor->name],
                'proposal_version' => $execution->proposal_version,
                'proposal_count' => $execution->academicProposals()->count(),
                'validated_fingerprint' => $execution->validated_fingerprint,
            ]),
        );

        return $this->descriptor(
            'FINAL_SUMMARY',
            "{$baseName}-resumen-final.json",
            $stored,
            "executions/{$execution->workspace_key}/final/{$commandId}/{$basename}",
            $execution,
            $completionAt,
        );
    }

    /** @param array<string, mixed> $descriptor */
    public function stored(array $descriptor, bool $final = false): StoredArtifact
    {
        /** @var array{disk: string, path: string, size: int, checksum: string} $stored */
        $stored = $descriptor[$final ? 'final' : 'stored'];

        return new StoredArtifact($stored['disk'], $stored['path'], $stored['size'], $stored['checksum']);
    }

    /** @param list<array<string, mixed>> $artifacts */
    public function cleanup(array $artifacts): void
    {
        foreach ($artifacts as $artifact) {
            try {
                $stored = $artifact['stored'];
                $path = $stored instanceof StoredArtifact ? $stored->path : (string) $stored['path'];

                if ($this->storage->exists($path)) {
                    $this->storage->delete($path);
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
        yield json_encode($this->redactor->redact($value), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * @param  array<string, mixed>  $work
     * @param  list<string>  $chunks
     * @return array<string, mixed>
     */
    private function appendToWork(array $work, array $chunks): array
    {
        $hash = ResumableSha256::resume($work['sha_state']);

        foreach ($chunks as $chunk) {
            $hash->update($chunk);
        }

        $work['size'] = $this->storage->appendStream((string) $work['path'], $chunks, (int) $work['size']);
        $work['sha_state'] = $hash->state();

        return $work;
    }

    /**
     * @return array<string, mixed>
     */
    private function descriptor(
        string $type,
        string $filename,
        StoredArtifact $stored,
        string $finalPath,
        Execution $execution,
        CarbonInterface $generatedAt,
    ): array {
        return [
            'type' => $type,
            'filename' => $filename,
            'mime_type' => 'application/json',
            'stored' => [
                'disk' => $stored->disk,
                'path' => $stored->path,
                'size' => $stored->size,
                'checksum' => $stored->checksum,
            ],
            'final_path' => $finalPath,
            'metadata' => [
                'contract_version' => 1,
                'proposal_version' => $execution->proposal_version,
                'generated_at' => $generatedAt->toIso8601String(),
            ],
        ];
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
