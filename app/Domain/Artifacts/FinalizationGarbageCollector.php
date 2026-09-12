<?php

namespace App\Domain\Artifacts;

use App\Models\Artifact;
use App\Models\ExecutionFinalization;

final readonly class FinalizationGarbageCollector
{
    public const DEFAULT_MINIMUM_AGE_SECONDS = 86_400;

    public function __construct(private LocalArtifactStorage $storage) {}

    /** @return array{deleted_files: int} */
    public function collect(?int $minimumAgeSeconds = null): array
    {
        $minimumAgeSeconds ??= (int) config(
            'services.finalization.cleanup_minimum_age_seconds',
            self::DEFAULT_MINIMUM_AGE_SECONDS,
        );
        $minimumAgeSeconds = max(0, $minimumAgeSeconds);
        $cutoff = now()->utc()->getTimestamp() - $minimumAgeSeconds;
        $protectedPaths = array_fill_keys(Artifact::query()->pluck('path')->all(), true);
        $protectedPrefixes = [];

        ExecutionFinalization::query()
            ->with('command:id,processed_at')
            ->whereNull('completed_at')
            ->each(function (ExecutionFinalization $state) use (&$protectedPaths, &$protectedPrefixes): void {
                if ($state->command === null || $state->command->processed_at !== null) {
                    return;
                }

                foreach ($state->temporary_files as $file) {
                    if (is_string($file['path'] ?? null)) {
                        $protectedPaths[$file['path']] = true;
                    }
                }

                foreach ($state->artifacts as $artifact) {
                    foreach (['stored', 'final'] as $key) {
                        if (is_array($artifact[$key] ?? null) && is_string($artifact[$key]['path'] ?? null)) {
                            $protectedPaths[$artifact[$key]['path']] = true;
                        }
                    }
                }

                $protectedPrefixes[] = rtrim($state->final_prefix, '/').'/';

                if ($state->lease_owner !== null
                    && $state->lease_expires_at !== null
                    && $state->lease_expires_at->isFuture()
                ) {
                    $protectedPrefixes[] = rtrim($state->staging_prefix, '/').'/'.$state->lease_owner.'/';
                }
            });

        $deleted = 0;

        foreach ($this->storage->files() as $path) {
            $candidate = str_contains($path, '/.staging/') || str_contains($path, '/final/');

            if (! $candidate || isset($protectedPaths[$path]) || $this->hasProtectedPrefix($path, $protectedPrefixes)) {
                continue;
            }

            if ($this->storage->lastModified($path) > $cutoff) {
                continue;
            }

            $this->storage->delete($path);
            $deleted++;
        }

        $this->storage->pruneEmptyDirectories();

        return ['deleted_files' => $deleted];
    }

    /** @param list<string> $prefixes */
    private function hasProtectedPrefix(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
