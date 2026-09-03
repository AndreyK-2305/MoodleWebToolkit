<?php

namespace App\Domain\Processes\DTOs;

final readonly class ProcessRequest
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function __construct(
        public array $command,
        public ?string $workingDirectory = null,
        public int $timeout = 60,
        public array $environment = [],
    ) {}
}
