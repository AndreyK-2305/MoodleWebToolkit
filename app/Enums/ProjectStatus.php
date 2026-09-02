<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case DRAFT = 'DRAFT';
    case CONFIGURING = 'CONFIGURING';
    case READY = 'READY';
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case WAITING_USER_ACTION = 'WAITING_USER_ACTION';
    case CANCELLING = 'CANCELLING';
    case CANCELLED = 'CANCELLED';
    case FAILED = 'FAILED';
    case VERIFYING = 'VERIFYING';
    case REVIEW = 'REVIEW';
    case COMPLETED = 'COMPLETED';

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED;
    }

    public function blocksNewExecution(): bool
    {
        return $this === self::REVIEW || $this === self::COMPLETED;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, match ($this) {
            self::DRAFT => [self::CONFIGURING],
            self::CONFIGURING => [self::DRAFT, self::READY],
            self::READY => [self::CONFIGURING, self::QUEUED],
            self::QUEUED => [self::RUNNING, self::CANCELLED, self::FAILED],
            self::RUNNING => [self::WAITING_USER_ACTION, self::CANCELLING, self::FAILED, self::VERIFYING],
            self::WAITING_USER_ACTION => [self::RUNNING, self::CANCELLING, self::FAILED],
            self::CANCELLING => [self::CANCELLED, self::FAILED],
            self::CANCELLED, self::FAILED => [self::QUEUED],
            self::VERIFYING => [self::REVIEW, self::FAILED],
            self::REVIEW => [self::VERIFYING, self::COMPLETED],
            self::COMPLETED => [],
        }, true);
    }
}
