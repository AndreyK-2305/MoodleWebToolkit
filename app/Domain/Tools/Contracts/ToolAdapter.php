<?php

namespace App\Domain\Tools\Contracts;

use App\Domain\Tools\DTOs\ExecutionStepDefinition;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Project;

interface ToolAdapter
{
    public function key(): string;

    /** @return array{resume: bool, retry: bool, cancel: bool, pause: bool} */
    public function capabilities(): array;

    /** @return list<ExecutionStepDefinition> */
    public function plan(Project $project): array;

    /** @return iterable<NormalizedToolEvent> */
    public function executeUnit(Execution $execution, ExecutionStep $step): iterable;
}
