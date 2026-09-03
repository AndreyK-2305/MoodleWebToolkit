<?php

namespace App\Domain\Connections\DTOs;

final readonly class ConnectionResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public bool $successful,
        public string $message,
        public array $metadata = [],
    ) {}
}
