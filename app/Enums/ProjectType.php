<?php

namespace App\Enums;

enum ProjectType: string
{
    case COLLECT = 'COLLECT';
    case CONSOLIDATE = 'CONSOLIDATE';
    case INTEGRATE = 'INTEGRATE';

    public function label(): string
    {
        return match ($this) {
            self::COLLECT => 'Recolectar',
            self::CONSOLIDATE => 'Consolidar',
            self::INTEGRATE => 'Integrar',
        };
    }
}
