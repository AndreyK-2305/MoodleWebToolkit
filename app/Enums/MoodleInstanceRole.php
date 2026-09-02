<?php

namespace App\Enums;

enum MoodleInstanceRole: string
{
    case SOURCE = 'SOURCE';
    case DESTINATION = 'DESTINATION';
}
