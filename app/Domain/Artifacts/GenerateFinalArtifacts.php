<?php

namespace App\Domain\Artifacts;

use App\Domain\Academic\AcademicPreview;
use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\DTOs\StoredArtifact;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

class GenerateFinalArtifacts
{
    public const REQUIRED_TYPES = ['JSON_REPORT', 'VERIFICATION_REPORT', 'LOG_EXPORT', 'FINAL_SUMMARY'];

    public function __construct(
        private readonly ArtifactStorage $storage,
        private readonly AcademicPreview $preview,
    ) {}

    /** @return list<array{type: string, filename: string, mime_type: string, stored: StoredArtifact, metadata: array<string, mixed>}> */
    public function generate(Execution $execution, User $actor): array
    {
        $execution->loadMissing(['project', 'steps', 'verifications', 'academicProposals.proposer', 'logs', 'events']);
        $generatedAt = $execution->commands()->where('command_type', 'FINALIZE')->firstOrFail()->created_at ?? now();
        $baseName = Str::slug($execution->project->name) ?: 'proyecto';
        $prefix = "executions/{$execution->workspace_key}";
        $latestVerification = $execution->verifications()->latest('proposal_version')->first();
        $specifications = [
            [
                'type' => 'JSON_REPORT',
                'filename' => "{$baseName}-informe.json",
                'mime_type' => 'application/json',
                'contents' => $this->json([
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
                'contents' => $this->json([
                    'contract_version' => 1,
                    'execution_uuid' => $execution->uuid,
                    'proposal_version' => $latestVerification?->proposal_version,
                    'fingerprint' => $latestVerification?->fingerprint,
                    'approved' => $latestVerification?->approved,
                    'status' => $latestVerification?->status->value,
                    'summary' => $latestVerification?->summary,
                    'details' => $latestVerification?->details,
                    'checked_at' => $latestVerification?->checked_at?->toIso8601String(),
                ]),
            ],
            [
                'type' => 'LOG_EXPORT',
                'filename' => "{$baseName}-logs.json",
                'mime_type' => 'application/json',
                'contents' => $this->json([
                    'contract_version' => 1,
                    'execution_uuid' => $execution->uuid,
                    'logs' => $execution->logs->map(fn ($log): array => [
                        'stream' => $log->stream->value,
                        'level' => $log->level,
                        'message' => $this->redactString($log->message),
                        'context' => $this->redact($log->context),
                        'logged_at' => $log->logged_at?->toIso8601String(),
                    ])->values()->all(),
                    'events' => $execution->events->map(fn ($event): array => [
                        'sequence' => $event->sequence,
                        'type' => $event->type,
                        'severity' => $event->severity->value,
                        'message' => $this->redactString((string) $event->message),
                        'created_at' => $event->created_at->toIso8601String(),
                    ])->values()->all(),
                ]),
            ],
            [
                'type' => 'FINAL_SUMMARY',
                'filename' => "{$baseName}-resumen-final.json",
                'mime_type' => 'application/json',
                'contents' => $this->json([
                    'contract_version' => 1,
                    'project' => ['uuid' => $execution->project->uuid, 'name' => $execution->project->name, 'type' => $execution->project->type->value],
                    'execution' => [
                        'uuid' => $execution->uuid,
                        'attempt' => $execution->attempt,
                        'final_status' => 'COMPLETED',
                        'progress' => 100,
                        'started_at' => $execution->started_at?->toIso8601String(),
                        'completed_at' => $generatedAt->toIso8601String(),
                    ],
                    'finalized_by' => ['id' => $actor->getKey(), 'name' => $actor->name],
                    'proposal_version' => $execution->proposal_version,
                    'proposal_count' => $execution->academicProposals->count(),
                    'validated_fingerprint' => $execution->validated_fingerprint,
                ]),
            ],
        ];
        $stored = [];

        try {
            foreach ($specifications as $specification) {
                $extension = str_ends_with($specification['filename'], '.json') ? 'json' : 'txt';
                $path = "{$prefix}/".strtolower(str_replace('_', '-', $specification['type'])).".{$extension}";
                $result = $this->storage->put($path, $specification['contents']);
                $stored[] = [
                    'type' => $specification['type'],
                    'filename' => $specification['filename'],
                    'mime_type' => $specification['mime_type'],
                    'stored' => $result,
                    'metadata' => ['contract_version' => 1, 'proposal_version' => $execution->proposal_version],
                ];
            }
        } catch (Throwable $exception) {
            $this->cleanup($stored);
            throw $exception;
        }

        return $stored;
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
                // A failed cleanup must not hide the original generation error.
            }
        }
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/password|passwd|secret|token|cookie|authorization|app[_-]?key|private[_-]?key|resume[_-]?token/i', $key) === 1) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = $this->redact($childValue, (string) $childKey);
            }

            return $redacted;
        }

        return is_string($value) ? $this->redactString($value) : $value;
    }

    private function redactString(string $value): string
    {
        return preg_replace(
            '/\b(password|passwd|secret|token|cookie|authorization|app[_-]?key|private[_-]?key|resume[_-]?token)\b\s*[:=]\s*[^\s,;]+/iu',
            '$1=[REDACTED]',
            $value,
        ) ?? $value;
    }
}
