<?php

namespace App\Enums;

enum RemoteFunctionalState: string
{
    case PREPARING = 'PREPARING';
    case STARTING = 'STARTING';
    case RUNNING = 'RUNNING';
    case WAITING = 'WAITING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
    case UNKNOWN = 'UNKNOWN';
}
