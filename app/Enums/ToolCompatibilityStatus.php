<?php

namespace App\Enums;

enum ToolCompatibilityStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case EXPERIMENTAL = 'EXPERIMENTAL';
    case LABORATORY = 'LABORATORY';
    case BLOCKED = 'BLOCKED';
    case INCOMPATIBLE = 'INCOMPATIBLE';
    case RETIRED = 'RETIRED';
}
