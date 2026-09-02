<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case UNTESTED = 'UNTESTED';
    case VALID = 'VALID';
    case INVALID = 'INVALID';
}
