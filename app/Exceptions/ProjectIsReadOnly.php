<?php

namespace App\Exceptions;

use DomainException;

class ProjectIsReadOnly extends DomainException
{
    public function __construct()
    {
        parent::__construct('El proyecto está COMPLETED y es de solo lectura.');
    }
}
