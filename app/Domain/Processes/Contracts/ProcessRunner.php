<?php

namespace App\Domain\Processes\Contracts;

use App\Domain\Processes\DTOs\ProcessRequest;
use App\Domain\Processes\DTOs\ProcessResult;

interface ProcessRunner
{
    public function run(ProcessRequest $request): ProcessResult;
}
