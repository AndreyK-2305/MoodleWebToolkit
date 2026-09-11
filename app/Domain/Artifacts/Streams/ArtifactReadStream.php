<?php

namespace App\Domain\Artifacts\Streams;

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

        $chunk = fread($this->handle, $length);

        if ($chunk === false) {
            throw new RuntimeException('No se pudo leer el siguiente bloque del artefacto.');
        }

        ($this->observer)?->__invoke(strlen($chunk));

        return $chunk;
    }

    public function eof(): bool
    {
        return feof($this->handle);
    }

    public function rewind(): void
    {
        if (! rewind($this->handle)) {
            throw new RuntimeException('No se pudo reiniciar la lectura del artefacto.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
