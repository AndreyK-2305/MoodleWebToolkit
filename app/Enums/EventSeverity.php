<?php

namespace App\Enums;

enum EventSeverity: string
{
    case INFO = 'INFO';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
}
