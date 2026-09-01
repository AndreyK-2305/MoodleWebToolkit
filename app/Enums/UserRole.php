<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'ADMIN';
    case OPERATOR = 'OPERATOR';
    case AUDITOR = 'AUDITOR';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrador',
            self::OPERATOR => 'Operador',
            self::AUDITOR => 'Auditor',
        };
    }
}
