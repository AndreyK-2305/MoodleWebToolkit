<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The agent call did not produce a trustworthy answer. The code is a closed
 * identifier safe to persist and display; it never carries remote output.
 */
class RemoteAgentUnavailable extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
