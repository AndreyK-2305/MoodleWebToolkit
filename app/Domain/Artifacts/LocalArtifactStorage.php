<?php

namespace App\Domain\Artifacts;

use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\DTOs\StoredArtifact;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class LocalArtifactStorage implements ArtifactStorage
{
    public function __construct(private readonly string $disk = 'local') {}

    public function put(string $path, string $contents): StoredArtifact
    {
        $path = $this->safePath($path);
        $storage = $this->storage();

        $this->rejectSymbolicLinks($path);
        $temporaryPath = $path.'.tmp-'.Str::uuid();

        try {
            if (! $storage->put($temporaryPath, $contents)) {
                throw new RuntimeException('No se pudo preparar el artefacto local.');
            }

            $this->rejectSymbolicLinks($temporaryPath);

            if ($storage->exists($path)) {
                $storage->delete($path);
            }

            if (! $storage->move($temporaryPath, $path)) {
                throw new RuntimeException('No se pudo promover atómicamente el artefacto local.');
            }
        } finally {
            if ($storage->exists($temporaryPath)) {
                $storage->delete($temporaryPath);
            }
        }

        return new StoredArtifact(
            $this->disk,
            $path,
            strlen($contents),
            hash('sha256', $contents),
        );
    }

    public function read(string $path): string
    {
        $path = $this->safePath($path);
        $this->rejectSymbolicLinks($path);
        $contents = $this->storage()->get($path);

        if (! is_string($contents)) {
            throw new RuntimeException('No se pudo leer el artefacto local.');
        }

        return $contents;
    }

    public function exists(string $path): bool
    {
        $path = $this->safePath($path);
        $this->rejectSymbolicLinks($path);

        return $this->storage()->exists($path);
    }

    public function delete(string $path): void
    {
        $path = $this->safePath($path);
        $this->rejectSymbolicLinks($path);
        $this->storage()->delete($path);
    }

    private function storage(): FilesystemAdapter
    {
        return Storage::disk($this->disk);
    }

    private function safePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === ''
            || str_contains($path, "\0")
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path) === 1
            || preg_match('#(^|/)\.\.(/|$)#', $path) === 1
        ) {
            throw new InvalidArgumentException('La ruta del artefacto debe ser relativa y no puede escapar del almacenamiento.');
        }

        return preg_replace('#/+#', '/', $path) ?? $path;
    }

    private function rejectSymbolicLinks(string $path): void
    {
        $absolute = $this->storage()->path($path);
        $root = rtrim($this->storage()->path(''), DIRECTORY_SEPARATOR);
        $relative = ltrim(substr($absolute, strlen($root)), DIRECTORY_SEPARATOR);
        $cursor = $root;

        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $relative)) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($cursor)) {
                throw new InvalidArgumentException('No se permiten enlaces simbólicos en rutas de artefactos.');
            }
        }
    }
}
