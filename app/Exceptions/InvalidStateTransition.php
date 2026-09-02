<?php

namespace App\Exceptions;

use DomainException;

class InvalidStateTransition extends DomainException
{
    public static function between(string $aggregate, string $from, string $to): self
    {
        return new self("Transición inválida para {$aggregate}: {$from} → {$to}.");
    }
}
