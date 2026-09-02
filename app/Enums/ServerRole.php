<?php

namespace App\Enums;

enum ServerRole: string
{
    case SOURCE = 'SOURCE';
    case DESTINATION = 'DESTINATION';
    case AUXILIARY = 'AUXILIARY';
}
