<?php

namespace App\Domain\Collector;

use App\Domain\Collector\Contracts\SecretProvider;
use RuntimeException;

final class LabFileSecretProvider implements SecretProvider
{
    public function __construct(private readonly ?string $root = null) {}

    public function available(string $reference, string $version): bool
    {
        try {
            $this->path($reference, $version);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function consume(string $reference, string $version, callable $consumer): mixed
    {
        $path = $this->path($reference, $version);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('La referencia LAB no está disponible.');
        }
        try {
            $stat = fstat($handle);
            $expected = lstat($path);
            if ($stat === false || $expected === false || $stat['ino'] !== $expected['ino']
                || $stat['dev'] !== $expected['dev'] || $stat['nlink'] !== 1
                || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600
            ) {
                throw new RuntimeException('El archivo de referencia LAB no es privado o cambió durante la lectura.');
            }
            $value = stream_get_contents($handle, 8193);
            if (! is_string($value) || $value === '' || strlen($value) > 8192 || str_contains($value, "\0")) {
                throw new RuntimeException('El valor LAB no cumple el contrato de materialización.');
            }

            return $consumer($value);
        } finally {
            fclose($handle);
            unset($value);
        }
    }

    private function path(string $reference, string $version): string
    {
        if (preg_match('/^[a-z][a-z0-9-]{2,63}$/D', $reference) !== 1
            || preg_match('/^[1-9][0-9]{0,8}$/D', $version) !== 1
        ) {
            throw new RuntimeException('La referencia o versión LAB no es válida.');
        }
        $configured = $this->root ?? (string) config('collector.secret_root');
        $root = realpath($configured);
        if ($root === false || $configured !== $root || ! is_dir($root) || is_link($root)) {
            throw new RuntimeException('El ámbito de referencias LAB no está disponible.');
        }
        $path = $root.'/'.$reference.'.'.$version;
        $stat = @lstat($path);
        if ($stat === false || is_link($path) || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1 || ! is_readable($path)
        ) {
            throw new RuntimeException('La referencia LAB requiere un archivo regular privado 0600.');
        }

        return $path;
    }
}
