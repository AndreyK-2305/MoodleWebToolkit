<?php

namespace App\Domain\Processes\DTOs;

final readonly class RegisteredProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public ?int $processId,
        public bool $timedOut,
        public bool $outputTruncated,
        public bool $resourceLimitExceeded,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut && ! $this->resourceLimitExceeded;
    }
}
