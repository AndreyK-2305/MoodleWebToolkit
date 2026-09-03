<?php

namespace App\Domain\Executions\DTOs;

use App\Models\Execution;

final readonly class StartExecutionResult
{
    public function __construct(
        public Execution $execution,
        public bool $created,
    ) {}
}
