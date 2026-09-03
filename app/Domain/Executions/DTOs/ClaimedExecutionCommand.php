<?php

namespace App\Domain\Executions\DTOs;

use App\Models\ExecutionCommand;

final readonly class ClaimedExecutionCommand
{
    public function __construct(
        public ExecutionCommand $command,
        public string $owner,
    ) {}
}
