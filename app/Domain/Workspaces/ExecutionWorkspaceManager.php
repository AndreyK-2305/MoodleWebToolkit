<?php

namespace App\Domain\Workspaces;

use App\Enums\WorkspaceStatus;
use App\Models\Artifact;
use App\Models\Execution;
use App\Models\ExecutionWorkspace;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ExecutionWorkspaceManager
{
    /** @var list<string> */
    private const AREAS = ['tools', 'input', 'output', 'logs', 'state', 'temporary'];

    public function prepare(Execution $execution, ?int $quotaBytes = null): ExecutionWorkspace
    {
        $execution->loadMissing('project');
        $relative = $execution->project->uuid.'/'.$execution->uuid;
        $existingWorkspace = ExecutionWorkspace::query()->where('execution_id', $execution->getKey())->first();
        if ($existingWorkspace?->status === WorkspaceStatus::CLEANED) {
            throw new RuntimeException('El workspace de esta ejecución ya fue limpiado y no se puede reabrir.');
        }
        $root = rtrim((string) config('toolkit.workspaces.root'), DIRECTORY_SEPARATOR);
        $this->ensureDirectory($root);
        $workspacePath = $root.DIRECTORY_SEPARATOR.$execution->project->uuid.DIRECTORY_SEPARATOR.$execution->uuid;
        $this->ensureDirectory($root.DIRECTORY_SEPARATOR.$execution->project->uuid);
        $this->ensureDirectory($workspacePath);

        foreach (self::AREAS as $area) {
            $this->ensureDirectory($workspacePath.DIRECTORY_SEPARATOR.$area);
        }

        $workspace = ExecutionWorkspace::query()->firstOrCreate(
            ['execution_id' => $execution->getKey()],
            [
                'uuid' => (string) Str::uuid(),
                'relative_path' => 'workspaces/'.$relative,
                'quota_bytes' => max(1, $quotaBytes ?? (int) config('toolkit.workspaces.quota_bytes', 20 * 1024 * 1024 * 1024)),
                'usage_bytes' => 0,
                'status' => WorkspaceStatus::READY,
            ],
        );

        if ($workspace->relative_path !== 'workspaces/'.$relative || $workspace->uuid === '') {
            throw new RuntimeException('La identidad persistida del workspace no coincide con la ejecución.');
        }

        return $workspace;
    }

    public function resolve(Execution $execution, string $area, string $relativePath = ''): string
    {
        if (! in_array($area, self::AREAS, true)) {
            throw new InvalidArgumentException('El área del workspace no está permitida.');
        }

        $workspace = $this->prepare($execution);
        $root = rtrim((string) config('toolkit.workspaces.root'), DIRECTORY_SEPARATOR);
        $executionRoot = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, substr($workspace->relative_path, strlen('workspaces/')));
        $areaRoot = $executionRoot.DIRECTORY_SEPARATOR.$area;
        $this->ensureDirectory($areaRoot);

        if ($relativePath === '') {
            return $areaRoot;
        }

        $normalized = str_replace('\\', '/', trim($relativePath));

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) === 1
            || str_contains($normalized, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $normalized) === 1
        ) {
            throw new InvalidArgumentException('La ruta debe ser relativa al área y no puede contener traversal.');
        }

        $absolute = $areaRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $this->rejectSymlinkPath($areaRoot, $absolute);

        return $absolute;
    }

    public function writeState(Execution $execution, string $name, array $state): string
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,119}\.json$/D', $name) !== 1) {
            throw new InvalidArgumentException('El nombre del archivo de estado no es válido.');
        }

        $target = $this->resolve($execution, 'state', $name);
        $contents = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertWithinQuota($execution, strlen($contents));
        $temporary = $target.'.tmp-'.Str::uuid();

        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir el estado del workspace.');
        }

        @chmod($temporary, 0600);

        if (! rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo actualizar el estado del workspace de forma atómica.');
        }

        $this->measure($execution);

        return $target;
    }

    public function measure(Execution $execution): int
    {
        $workspace = $this->prepare($execution);
        $root = rtrim((string) config('toolkit.workspaces.root'), DIRECTORY_SEPARATOR);
        $executionRoot = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, substr($workspace->relative_path, strlen('workspaces/')));
        $usage = $this->directoryBytes($executionRoot);

        if ($usage > $workspace->quota_bytes) {
            $workspace->forceFill(['usage_bytes' => $usage, 'last_measured_at' => now()->utc(), 'status' => WorkspaceStatus::FAILED])->save();
            throw new RuntimeException('El workspace excedió su cuota configurada.');
        }

        $workspace->forceFill(['usage_bytes' => $usage, 'last_measured_at' => now()->utc()])->save();

        return $usage;
    }

    public function cleanup(Execution $execution): void
    {
        if ($execution->isActive()) {
            throw new RuntimeException('No se limpia un workspace mientras su ejecución permanezca activa.');
        }

        $workspace = $this->prepare($execution);
        $root = rtrim((string) config('toolkit.workspaces.root'), DIRECTORY_SEPARATOR);
        $executionRoot = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, substr($workspace->relative_path, strlen('workspaces/')));
        $workspace->forceFill(['status' => WorkspaceStatus::CLEANING])->save();
        $protected = $this->protectedPaths($execution);
        $this->removeContents($executionRoot, $protected, $executionRoot);
        $usage = is_dir($executionRoot) ? $this->directoryBytes($executionRoot) : 0;
        $workspace->forceFill([
            'usage_bytes' => $usage,
            'last_measured_at' => now()->utc(),
            'cleaned_at' => $usage === 0 ? now()->utc() : null,
            'status' => $usage === 0 ? WorkspaceStatus::CLEANED : WorkspaceStatus::READY,
        ])->save();
    }

    private function assertWithinQuota(Execution $execution, int $incomingBytes): void
    {
        $workspace = $this->prepare($execution);
        if ($this->measure($execution) + $incomingBytes > $workspace->quota_bytes) {
            throw new RuntimeException('La escritura excedería la cuota del workspace.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('No se permiten enlaces simbólicos en el árbol de workspaces.');
        }

        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('No se pudo crear un directorio privado de workspace.');
        }

        @chmod($path, 0700);
    }

    private function rejectSymlinkPath(string $root, string $target): void
    {
        $relative = ltrim(substr($target, strlen($root)), DIRECTORY_SEPARATOR);
        $cursor = rtrim($root, DIRECTORY_SEPARATOR);

        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $relative), 'strlen') as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($cursor)) {
                throw new RuntimeException('Se rechazó un enlace simbólico dentro del workspace.');
            }
        }
    }

    private function directoryBytes(string $root): int
    {
        if (! is_dir($root)) {
            return 0;
        }

        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('Se detectó un enlace simbólico dentro del workspace.');
            }
            if ($entry->isFile()) {
                $total += $entry->getSize();
            }
        }

        return $total;
    }

    /** @return array<string, true> */
    private function protectedPaths(Execution $execution): array
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $diskRoot = realpath($disk->path(''));
        $protected = [];

        if ($diskRoot === false) {
            return $protected;
        }

        foreach (Artifact::query()->where('execution_id', $execution->getKey())->get() as $artifact) {
            if ($artifact->disk !== 'local') {
                continue;
            }

            $absolute = $disk->path($artifact->path);
            $relative = ltrim(substr($absolute, strlen($diskRoot)), DIRECTORY_SEPARATOR);
            $candidate = realpath($absolute);

            if ($candidate !== false && str_starts_with($candidate, $diskRoot.DIRECTORY_SEPARATOR)) {
                $protected[$candidate] = true;
            } elseif ($relative !== '' && ! str_contains($relative, '..')) {
                $protected[$absolute] = true;
            }
        }

        return $protected;
    }

    /** @param array<string, true> $protected */
    private function removeContents(string $path, array $protected, string $root): void
    {
        if (is_link($path)) {
            throw new RuntimeException('La limpieza rechazó una ruta enlazada.');
        }

        if (is_file($path)) {
            $real = realpath($path) ?: $path;
            if ($path !== $root && ! isset($protected[$real])) {
                unlink($path);
            }
            return;
        }

        if (! is_dir($path)) {
            return;
        }

        @chmod($path, 0700);

        foreach (new \DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $this->removeContents($entry->getPathname(), $protected, $root);
        }

        if ($path !== $root && count(scandir($path) ?: []) === 2) {
            rmdir($path);
        }
    }
}
