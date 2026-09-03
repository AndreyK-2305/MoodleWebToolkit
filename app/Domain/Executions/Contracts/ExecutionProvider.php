<?php

namespace App\Domain\Executions\Contracts;

use App\Domain\Tools\Contracts\ToolAdapter;
use App\Models\ExecutionCommand;

interface ExecutionProvider
{
    public function execute(ExecutionCommand $command, ToolAdapter $adapter): void;
}
