<?php

namespace App\Enums;

enum PreflightResult: string
{
    case SUCCESS = 'SUCCESS';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
}
