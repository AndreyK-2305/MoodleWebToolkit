<?php

namespace App\Exceptions;

use App\Enums\ProjectStatus;
use DomainException;

class NewExecutionBlocked extends DomainException
{
    public function __construct(ProjectStatus $status)
    {
        parent::__construct("No se puede crear una ejecución con el proyecto en {$status->value}.");
    }
}
