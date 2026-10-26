<?php

namespace App\Enums;

/** The event groups a user chooses to receive by email. */
enum NotificationGroup: string
{
    case INTERVENTIONS = 'INTERVENTIONS';
    case OUTCOMES = 'OUTCOMES';
    case BACKUPS = 'BACKUPS';
    case PROGRESS = 'PROGRESS';

    /** The preference column that opts a user in. */
    public function preference(): string
    {
        return match ($this) {
            self::INTERVENTIONS => 'interventions',
            self::OUTCOMES => 'outcomes',
            self::BACKUPS => 'backups',
            self::PROGRESS => 'progress',
        };
    }
}
