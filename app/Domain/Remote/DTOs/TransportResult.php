<?php

namespace App\Domain\Remote\DTOs;

final readonly class TransportResult
{
    /** The script ran and returned an exit code. */
    public const COMPLETED = 'COMPLETED';

    /** The server could not be reached or rejected the session. */
    public const UNREACHABLE = 'UNREACHABLE';

    /** The call exceeded its deadline; the remote state is unknown. */
    public const TIMED_OUT = 'TIMED_OUT';

    public function __construct(
        public string $status,
        public ?int $exitCode,
        public string $stdout,
        public string $stderr,
        public ?string $errorCode = null,
    ) {}

    public function completed(): bool
    {
        return $this->status === self::COMPLETED;
    }
}
