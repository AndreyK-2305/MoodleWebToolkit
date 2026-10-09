<?php

namespace App\Domain\Collector\Contracts;

interface SecretProvider
{
    public function available(string $reference, string $version): bool;

    /**
     * Values are available only to backend callbacks, never serialized as evidence.
     *
     * @template T
     *
     * @param  callable(string): T  $consumer
     * @return T
     */
    public function consume(string $reference, string $version, callable $consumer): mixed;
}
