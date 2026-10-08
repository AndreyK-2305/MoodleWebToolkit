<?php

namespace App\Domain\Collector;

use App\Domain\Collector\Contracts\SecretProvider;
use LogicException;

final class TestingSecretProvider implements SecretProvider
{
    /** @param array<string, array<string, string>> $values */
    public function __construct(private readonly array $values)
    {
        if (! app()->environment('testing')) {
            throw new LogicException('El proveedor de pruebas solo se utiliza en testing.');
        }
    }

    public function available(string $reference, string $version): bool
    {
        return isset($this->values[$reference][$version]);
    }

    public function consume(string $reference, string $version, callable $consumer): mixed
    {
        if (! $this->available($reference, $version)) {
            throw new LogicException('La referencia de pruebas no está disponible.');
        }

        return $consumer($this->values[$reference][$version]);
    }
}
