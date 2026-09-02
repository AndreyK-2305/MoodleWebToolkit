<?php

namespace App\Enums;

enum ConflictStatus: string
{
    case OPEN = 'OPEN';
    case RESOLVED = 'RESOLVED';
    case IGNORED = 'IGNORED';
}
