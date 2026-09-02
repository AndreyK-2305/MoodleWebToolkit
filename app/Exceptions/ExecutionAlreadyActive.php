<?php

namespace App\Exceptions;

use DomainException;

class ExecutionAlreadyActive extends DomainException
{
    public function __construct()
    {
        parent::__construct('El proyecto ya tiene una ejecución activa.');
    }
}
