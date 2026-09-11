<?php

namespace App\Domain\Artifacts;

use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\DTOs\StoredArtifact;
use App\Domain\Artifacts\Streams\ArtifactReadStream;
use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class LocalArtifactStorage implements ArtifactStorage
{
    public function __construct(
        private readonly string $disk = 'local',
        private readonly ?Closure $writeObserver = null,
        private readonly ?Closure $readObserver = null,
    ) {}

    /** @param iterable<string> $chunks */
    public function writeStream(string $path, iterable $chunks): StoredArtifact
    {
        $path = $this->safePath($path);
        $this->rejectSymbolicLinks($path);
        $temporaryPath = $path.'.tmp-'.Str::uuid();
        $temporaryAbsolute = $this->absolutePath($temporaryPath);
        $targetAbsolute = $this->absolutePath($path);
        $directory = dirname($temporaryAbsolute);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio del artefacto local.');
        }

        $handle = fopen($temporaryAbsolute, 'xb');

        if ($handle === false) {
            throw new RuntimeException('No se pudo preparar el artefacto local.');
        }

        $hash = hash_init('sha256');
        $size = 0;

        try {
            foreach ($chunks as $chunk) {
                $offset = 0;
                $length = strlen($chunk);

                while ($offset < $length) {
                    $written = fwrite($handle, substr($chunk, $offset));

                    if ($written === false || $written === 0) {
                        throw new RuntimeException('No se pudo escribir un bloque del artefacto local.');
                    }

                    $offset += $written;
                }

                hash_update($hash, $chunk);
                $size += $length;
                ($this->writeObserver)?->__invoke($length);
            }

            if (! fflush($handle)) {
                throw new RuntimeException('No se pudo sincronizar el artefacto local.');
            }

            fclose($handle);
            $handle = null;

            if (file_exists($targetAbsolute)) {
                throw new RuntimeException('La ruta privada de staging ya existe.');
            }

            if (! rename($temporaryAbsolute, $targetAbsolute)) {
                throw new RuntimeException('No se pudo cerrar atómicamente el artefacto en staging.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (file_exists($temporaryAbsolute)) {
                unlink($temporaryAbsolute);
            }
        }

        return new StoredArtifact($this->disk, $path, $size, hash_final($hash));
    }

    public function readStream(string $path): ArtifactReadStream
    {
        $path = $this->safePath($path);
        $this->rejectSymbolicLinks($path);
        $handle = fopen($this->absolutePath($path), 'rb');

        if ($handle === false) {
            throw new RuntimeException('No se pudo leer el artefacto local.');
        }

        return new ArtifactReadStream($handle, $this->readObserver);
    }

    public function promote(string $stagingPath, string $finalPath): StoredArtifact
    {
        $stagingPath = $this->safePath($stagingPath);
        $finalPath = $this->safePath($finalPath);
        $this->rejectSymbolicLinks($stagingPath);
        $this->rejectSymbolicLinks($finalPath);
        $source = $this->absolutePath($stagingPath);
        $target = $this->absolutePath($finalPath);
        $directory = dirname($target);

        if (! is_file($source)) {
            throw new RuntimeException('El artefacto de staging ya no existe.');
        }

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio final del artefacto.');
        }

        // link() is an atomic create-if-absent operation on the local filesystem.
        // It never removes or overwrites a winner's existing destination.
        if (! link($source, $target)) {
            throw new RuntimeException('No se pudo promover el artefacto sin sobrescribir el destino.');
        }

        $size = filesize($target);
        $checksum = hash_file('sha256', $target);

        if ($size === false || $checksum === false) {
            @unlink($target);
            throw new RuntimeException('No se pudo inspeccionar el artefacto promovido.');
        }

        return new StoredArtifact($this->disk, $finalPath, $size, $checksum);
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

    public function put(string $path, string $contents): StoredArtifact
    {
        return $this->writeStream($path, [$contents]);
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);
        $contents = '';

        try {
            while (! $stream->eof()) {
                $contents .= $stream->read();
            }
        } finally {
            $stream->close();
        }

        return $contents;
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

    private function absolutePath(string $path): string
    {
        return $this->storage()->path($path);
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
