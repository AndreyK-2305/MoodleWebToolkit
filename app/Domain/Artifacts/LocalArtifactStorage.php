<?php

namespace App\Domain\Artifacts;

use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\DTOs\StoredArtifact;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class LocalArtifactStorage implements ArtifactStorage
{
    public function __construct(private readonly string $disk = 'local') {}

    public function put(string $path, string $contents): StoredArtifact
    {
        $path = $this->safePath($path);
        $storage = $this->storage();

        if (! $storage->put($path, $contents)) {
            throw new RuntimeException('No se pudo guardar el artefacto local.');
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
        $contents = $this->storage()->get($this->safePath($path));

        if (! is_string($contents)) {
            throw new RuntimeException('No se pudo leer el artefacto local.');
        }

        return $contents;
    }

    public function exists(string $path): bool
    {
        return $this->storage()->exists($this->safePath($path));
    }

    public function delete(string $path): void
    {
        $this->storage()->delete($this->safePath($path));
    }

    private function storage(): FilesystemAdapter
    {
        return Storage::disk($this->disk);
    }

    private function safePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new InvalidArgumentException('La ruta del artefacto debe ser relativa y no puede escapar del almacenamiento.');
        }

        return $path;
    }
}
