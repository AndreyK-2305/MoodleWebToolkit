<?php

namespace App\Enums;

enum ExecutionStepStatus: string
{
    case PENDING = 'PENDING';
    case RUNNING = 'RUNNING';
    case WAITING_USER = 'WAITING_USER';
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
    case REUSED = 'REUSED';
}
