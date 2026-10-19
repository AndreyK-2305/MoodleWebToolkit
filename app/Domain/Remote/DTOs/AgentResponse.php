<?php

namespace App\Domain\Remote\DTOs;

final readonly class AgentResponse
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public bool $ok,
        public string $command,
        public ?string $agentVersion,
        public array $data,
        public ?string $errorCode,
        public TransportResult $transport,
    ) {}
}
