<?php

namespace App\Exceptions;

use RuntimeException;

class ExecutionCommandLeaseLost extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El worker perdió el arrendamiento del comando y no puede persistir más efectos.');
    }
}
