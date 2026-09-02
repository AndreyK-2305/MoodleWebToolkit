<?php

namespace App\Enums;

enum ProjectType: string
{
    case COLLECT = 'COLLECT';
    case CONSOLIDATE = 'CONSOLIDATE';
    case INTEGRATE = 'INTEGRATE';
}
