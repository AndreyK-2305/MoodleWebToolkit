<?php

namespace App\Enums;

enum LogStream: string
{
    case STDOUT = 'STDOUT';
    case STDERR = 'STDERR';
    case SYSTEM = 'SYSTEM';
}
