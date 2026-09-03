<?php

namespace App\Console\Commands;

use App\Domain\Executions\ExecutionQueueConfiguration;
use Illuminate\Console\Command;
use LogicException;

class AssertExecutionQueueConfiguration extends Command
{
    protected $signature = 'executions:assert-queue-safe';

    protected $description = 'Comprueba que Redis no reentregue jobs antes de su timeout';

    public function handle(): int
    {
        try {
            ExecutionQueueConfiguration::assertSafe();
        } catch (LogicException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Redis retry_after=%d s supera el timeout del job 1D=%d s.',
            (int) config('queue.connections.redis.retry_after'),
            ExecutionQueueConfiguration::JOB_TIMEOUT_SECONDS,
        ));

        return self::SUCCESS;
    }
}
