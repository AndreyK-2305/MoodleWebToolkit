<?php

namespace App\Domain\Secrets\Contracts;

interface SecretStore
{
    public function put(string $reference, string $secret): void;

    public function get(string $reference): string;

    public function forget(string $reference): void;
}
