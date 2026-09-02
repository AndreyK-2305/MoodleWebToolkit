<?php

namespace App\Exceptions;

use DomainException;

class InvalidProjectAssignment extends DomainException
{
    public function __construct()
    {
        parent::__construct('Los usuarios ADMIN tienen acceso global y no pueden recibir asignaciones de proyecto.');
    }
}
