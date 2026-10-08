<?php

namespace App\Domain\Processes\DTOs;

final readonly class RegisteredProcessResult
{
    public int $stdoutPersistedBytes;

    public int $stderrPersistedBytes;

    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public ?int $processId,
        public bool $timedOut,
        public bool $outputTruncated,
        public bool $resourceLimitExceeded,
        public int $stdoutBytes,
        public int $stderrBytes,
        public string $stdoutSha256,
        public string $stderrSha256,
        public int $stdoutObservedBytes = 0,
        public int $stderrObservedBytes = 0,
        public bool $stdoutTruncated = false,
        public bool $stderrTruncated = false,
        public int $durableOutputLimitBytes = 0,
    ) {
        // Compatibility aliases: Bytes always counts sanitized persisted bytes.
        $this->stdoutPersistedBytes = $stdoutBytes;
        $this->stderrPersistedBytes = $stderrBytes;
    }

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut && ! $this->resourceLimitExceeded;
    }
}
