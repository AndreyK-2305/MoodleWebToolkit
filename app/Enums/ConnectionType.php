<?php

namespace App\Enums;

enum ConnectionType: string
{
    case AWS_SSM = 'aws-ssm';
    case SSH = 'ssh';

    public function label(): string
    {
        return match ($this) {
            self::AWS_SSM => 'AWS Systems Manager',
            self::SSH => 'SSH',
        };
    }
}
