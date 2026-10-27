<?php

namespace App\Enums;

/** How the platform obtains the AWS identity of one account record. */
enum AwsCredentialMode: string
{
    case ENVIRONMENT = 'ENVIRONMENT';
    case ACCESS_KEYS = 'ACCESS_KEYS';
    case ASSUME_ROLE = 'ASSUME_ROLE';

    public function label(): string
    {
        return match ($this) {
            self::ENVIRONMENT => 'Rol del entorno',
            self::ACCESS_KEYS => 'Claves de acceso',
            self::ASSUME_ROLE => 'Asumir un rol',
        };
    }
}
