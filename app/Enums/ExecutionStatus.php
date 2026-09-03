<?php

namespace App\Enums;

enum ExecutionStatus: string
{
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case WAITING_USER_ACTION = 'WAITING_USER_ACTION';
    case CANCELLING = 'CANCELLING';
    case CANCELLED = 'CANCELLED';
    case FAILED = 'FAILED';
    case VERIFYING = 'VERIFYING';
    case REVIEW = 'REVIEW';
    case COMPLETED = 'COMPLETED';

    public function isActive(): bool
    {
        return in_array($this, [
            self::QUEUED,
            self::RUNNING,
            self::WAITING_USER_ACTION,
            self::CANCELLING,
            self::VERIFYING,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::CANCELLED, self::FAILED, self::COMPLETED], true);
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, match ($this) {
            self::QUEUED => [self::RUNNING, self::CANCELLING, self::CANCELLED, self::FAILED],
            self::RUNNING => [self::WAITING_USER_ACTION, self::CANCELLING, self::FAILED, self::VERIFYING],
            self::WAITING_USER_ACTION => [self::RUNNING, self::CANCELLING, self::FAILED],
            self::CANCELLING => [self::CANCELLED, self::FAILED],
            self::VERIFYING => [self::REVIEW, self::FAILED],
            self::REVIEW => [self::VERIFYING, self::COMPLETED],
            self::CANCELLED, self::FAILED, self::COMPLETED => [],
        }, true);
    }

    public function projectStatus(): ProjectStatus
    {
        return ProjectStatus::from($this->value);
    }
}
