<?php

namespace App\Enums;

enum SmtpEncryption: string
{
    case TLS = 'TLS';
    case STARTTLS = 'STARTTLS';
    case NONE = 'NONE';

    public function label(): string
    {
        return match ($this) {
            self::TLS => 'TLS (conexión cifrada desde el inicio)',
            self::STARTTLS => 'STARTTLS (cifrado obligatorio tras conectar)',
            self::NONE => 'Ninguno',
        };
    }
}
