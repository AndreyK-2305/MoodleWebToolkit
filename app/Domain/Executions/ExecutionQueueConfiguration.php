<?php

namespace App\Domain\Executions;

use LogicException;

final class ExecutionQueueConfiguration
{
    public const JOB_TIMEOUT_SECONDS = 120;

    public const DEFAULT_REDIS_RETRY_AFTER_SECONDS = 180;

    public static function assertSafe(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        if ($retryAfter > self::JOB_TIMEOUT_SECONDS) {
            return;
        }

        throw new LogicException(sprintf(
            'Configuración insegura: REDIS_QUEUE_RETRY_AFTER (%d s) debe ser mayor que el timeout de la unidad asíncrona (%d s). Defina REDIS_QUEUE_RETRY_AFTER=%d o un valor superior.',
            $retryAfter,
            self::JOB_TIMEOUT_SECONDS,
            self::DEFAULT_REDIS_RETRY_AFTER_SECONDS,
        ));
    }
}
