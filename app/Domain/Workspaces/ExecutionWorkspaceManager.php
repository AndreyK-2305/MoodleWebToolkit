<?php

namespace App\Domain\Workspaces;

use App\Enums\WorkspaceStatus;
use App\Models\Artifact;
use App\Models\Execution;
use App\Models\ExecutionCapacityApproval;
use App\Models\ExecutionWorkspace;
use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
        $capacity = ExecutionCapacityApproval::query()->where('execution_id', $execution->getKey())->first();
        $bindingQuota = $execution->toolBinding()->value('approved_quota_bytes');
        $approvedQuota = $bindingQuota !== null ? (int) $bindingQuota : $capacity?->approved_quota_bytes;
        if ($quotaBytes !== null && app()->environment('testing') === false) {
            throw new RuntimeException('El runtime no acepta cuotas arbitrarias; requiere una aprobación de capacidad persistida.');
        }
        if ($approvedQuota === null && (app()->environment('testing') && $existingWorkspace !== null) === false) {
            throw new RuntimeException('No se prepara un workspace sin estimación y aprobación explícita de capacidad.');
        }
        if ($capacity !== null && $bindingQuota !== null && (int) $capacity->approved_quota_bytes !== (int) $bindingQuota) {
            throw new RuntimeException('La cuota del binding no coincide con la aprobación inmutable de capacidad.');
        }
        $effectiveQuota = $quotaBytes ?? $approvedQuota ?? $existingWorkspace?->quota_bytes;
        if ($existingWorkspace !== null && $approvedQuota !== null && (int) $existingWorkspace->quota_bytes !== (int) $approvedQuota) {
            throw new RuntimeException('La cuota persistida del workspace ya no coincide con la aprobación fijada.');
        }
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
                'quota_bytes' => max(1, (int) $effectiveQuota),
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
        if (in_array($area, self::AREAS, true) === false) {
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

    /** @param array<string, mixed> $state */
    public function writeState(Execution $execution, string $name, array $state): string
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,119}\.json$/D', $name) !== 1) {
            throw new InvalidArgumentException('El nombre del archivo de estado no es válido.');
        }

        $contents = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->writeAtomic($execution, 'state', $name, $contents);
    }

    /** @param array<string, mixed> $state */
    public function writeOperationEvidence(Execution $execution, string $operationUuid, string $name, array $state): string
    {
        if (preg_match('/^[a-f0-9-]{36}$/Di', $operationUuid) !== 1
            || in_array($name, ['launch.json', 'heartbeat.json', 'exit.json', 'cancel.json'], true) === false
        ) {
            throw new InvalidArgumentException('La identidad del archivo de evidencia de operación no es válida.');
        }
        $contents = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $path = $this->operationEvidencePath($execution, $operationUuid, $name);
        if (in_array($name, ['launch.json', 'exit.json', 'cancel.json'], true) && is_file($path)) {
            $existing = json_decode((string) file_get_contents($path), true);
            if (is_array($existing) && $existing === $state) {
                return $path;
            }
            throw new RuntimeException("La evidencia inmutable [{$name}] ya existe y no se reemplazará.");
        }

        return $this->writeAtomic($execution, 'state', 'remote-operations/'.$operationUuid.'/'.$name, $contents);
    }

    public function operationEvidencePath(Execution $execution, string $operationUuid, string $name): string
    {
        if (preg_match('/^[a-f0-9-]{36}$/Di', $operationUuid) !== 1
            || in_array($name, ['launch.json', 'heartbeat.json', 'exit.json', 'cancel.json', 'request.json'], true) === false
        ) {
            throw new InvalidArgumentException('La ruta de evidencia de operación no es válida.');
        }
        $path = $this->resolve($execution, 'state', 'remote-operations/'.$operationUuid.'/'.$name);
        $this->ensureDirectory(dirname($path));

        return $path;
    }

    public function operationLogPath(Execution $execution, string $operationUuid, string $stream): string
    {
        if (preg_match('/^[a-f0-9-]{36}$/Di', $operationUuid) !== 1 || in_array($stream, ['stdout', 'stderr'], true) === false) {
            throw new InvalidArgumentException('La ruta del log de operación no es válida.');
        }
        $path = $this->resolve($execution, 'logs', 'remote-operations/'.$operationUuid.'/'.$stream.'.log');
        $this->ensureDirectory(dirname($path));

        return $path;
    }

    public function writeAtomic(Execution $execution, string $area, string $relativePath, string $contents): string
    {
        $lock = $this->acquireCapacityLock($execution);
        $temporary = null;
        $handle = null;
        try {
            $target = $this->resolve($execution, $area, $relativePath);
            $this->ensureDirectory(dirname($target));
            $this->assertWithinQuota($execution, strlen($contents));
            $temporary = $target.'.tmp-'.Str::uuid();
            $handle = fopen($temporary, 'xb');
            if ($handle === false) {
                throw new RuntimeException('No se pudo abrir un archivo temporal privado de estado.');
            }
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('No se pudo escribir el estado del workspace.');
                }
                $offset += $written;
            }
            if (fflush($handle) === false || (function_exists('fsync') && fsync($handle) === false)) {
                throw new RuntimeException('No se pudo sincronizar el estado del workspace.');
            }
            fclose($handle);
            $handle = null;

            @chmod($temporary, 0600);

            if (rename($temporary, $target) === false) {
                throw new RuntimeException('No se pudo actualizar el estado del workspace de forma atómica.');
            }
            $temporary = null;

            $directoryHandle = @fopen(dirname($target), 'r');
            if (is_resource($directoryHandle)) {
                if (function_exists('fsync')) {
                    @fsync($directoryHandle);
                }
                fclose($directoryHandle);
            }

            $this->measure($execution);

            return $target;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_string($temporary) && file_exists($temporary)) {
                @unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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

        if (is_dir($path) === false && mkdir($path, 0700, true) === false && is_dir($path) === false) {
            throw new RuntimeException('No se pudo crear un directorio privado de workspace.');
        }

        @chmod($path, 0700);
    }

    /** @return resource */
    private function acquireCapacityLock(Execution $execution)
    {
        $root = rtrim((string) config('toolkit.workspaces.root'), DIRECTORY_SEPARATOR);
        $lockDirectory = $root.DIRECTORY_SEPARATOR.'.locks';
        $this->ensureDirectory($root);
        $this->ensureDirectory($lockDirectory);
        $lockPath = $lockDirectory.DIRECTORY_SEPARATOR.$execution->uuid.'.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('El lock de cuota del workspace no puede ser un enlace simbólico.');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('No se pudo abrir el lock de capacidad del workspace.');
        }
        @chmod($lockPath, 0600);
        if (flock($lock, LOCK_EX) === false) {
            fclose($lock);
            throw new RuntimeException('No se pudo serializar la escritura contra la cuota del workspace.');
        }

        return $lock;
    }

    private function rejectSymlinkPath(string $root, string $target): void
    {
        $relative = ltrim(substr($target, strlen($root)), DIRECTORY_SEPARATOR);
        $cursor = rtrim($root, DIRECTORY_SEPARATOR);

        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $relative), fn (string $segment): bool => $segment !== '') as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($cursor)) {
                throw new RuntimeException('Se rechazó un enlace simbólico dentro del workspace.');
            }
        }
    }

    private function directoryBytes(string $root): int
    {
        if (is_dir($root) === false) {
            return 0;
        }

        $total = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
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
        $disk = Storage::disk('local');
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
            } elseif ($relative !== '' && str_contains($relative, '..') === false) {
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
            if ($path !== $root && isset($protected[$real]) === false) {
                unlink($path);
            }

            return;
        }

        if (is_dir($path) === false) {
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
