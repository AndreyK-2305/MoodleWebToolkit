<?php

namespace App\Enums;

enum WorkspaceStatus: string
{
    case READY = 'READY';
    case ACTIVE = 'ACTIVE';
    case CLEANING = 'CLEANING';
    case CLEANED = 'CLEANED';
    case FAILED = 'FAILED';
}
