<?php

namespace App\Exceptions;

use RuntimeException;

class ExecutionDispatchFailed extends RuntimeException
{
    public function __construct(public readonly string $executionUuid)
    {
        parent::__construct('La ejecución quedó persistida, pero el despacho falló. Puede recuperarse con la misma Idempotency-Key.');
    }
}
