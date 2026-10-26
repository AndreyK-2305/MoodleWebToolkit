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

    /**
     * Operations a new project may choose. INTEGRATE keeps working for the
     * projects that already have it, but the Integrador is blocked as
     * incompatible, so it is no longer offered.
     *
     * @return list<self>
     */
    public static function creatable(): array
    {
        return [self::COLLECT, self::CONSOLIDATE];
    }

    /** @return list<string> */
    public static function creatableValues(): array
    {
        return array_map(fn (self $type): string => $type->value, self::creatable());
    }
}
