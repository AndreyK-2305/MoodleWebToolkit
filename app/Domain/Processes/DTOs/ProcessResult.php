<?php

namespace App\Domain\Processes\DTOs;

final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
