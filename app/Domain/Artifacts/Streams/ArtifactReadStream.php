<?php

namespace App\Domain\Artifacts\Streams;

use App\Domain\Artifacts\Contracts\ArtifactStorage;
use Closure;
use InvalidArgumentException;
use RuntimeException;

final class ArtifactReadStream
{
    /** @param resource $handle */
    public function __construct(private $handle, private readonly ?Closure $observer = null) {}

    public function read(int $length = 65536): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('El tamaño del bloque debe ser positivo.');
        }

        if (! is_resource($this->handle)) {
            throw new RuntimeException('El stream del artefacto ya está cerrado.');
        }

        $chunk = fread($this->handle, min($length, ArtifactStorage::MAX_CHUNK_BYTES));

        if ($chunk === false) {
            throw new RuntimeException('No se pudo leer el siguiente bloque del artefacto.');
        }

        ($this->observer)?->__invoke(strlen($chunk));

        return $chunk;
    }

    public function eof(): bool
    {
        return ! is_resource($this->handle) || feof($this->handle);
    }

    public function seek(int $offset): void
    {
        if ($offset < 0 || ! is_resource($this->handle) || fseek($this->handle, $offset) !== 0) {
            throw new RuntimeException('No se pudo posicionar la lectura del artefacto.');
        }
    }

    public function rewind(): void
    {
        if (! is_resource($this->handle) || ! rewind($this->handle)) {
            throw new RuntimeException('No se pudo reiniciar la lectura del artefacto.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function isClosed(): bool
    {
        return ! is_resource($this->handle);
    }

    public function __destruct()
    {
        $this->close();
    }
}
