<?php

namespace App\Enums;

enum RemoteCommunicationState: string
{
    case CONNECTED = 'CONNECTED';
    case DEGRADED = 'DEGRADED';
    case UNREACHABLE = 'UNREACHABLE';
    case RECONCILING = 'RECONCILING';
    case TERMINATED = 'TERMINATED';
}
