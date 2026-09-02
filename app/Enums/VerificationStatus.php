<?php

namespace App\Enums;

enum VerificationStatus: string
{
    case PENDING = 'PENDING';
    case PASSED = 'PASSED';
    case WARNING = 'WARNING';
    case FAILED = 'FAILED';
}
