<?php

namespace Tests\Feature\Executions;

use App\Domain\Executions\ExecutionQueueConfiguration;
use App\Jobs\RunExecutionUnit;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ExecutionQueueConfigurationTest extends TestCase
{
    public function test_legacy_environment_without_retry_after_uses_safe_default(): void
    {
        $repository = Env::getRepository();
        $previous = $repository->get('REDIS_QUEUE_RETRY_AFTER');
        $repository->clear('REDIS_QUEUE_RETRY_AFTER');

        try {
            /** @var array{connections: array{redis: array{retry_after: int}}} $queueConfig */
            $queueConfig = require config_path('queue.php');
            $retryAfter = $queueConfig['connections']['redis']['retry_after'];
            $jobTimeout = (new RunExecutionUnit(1))->timeout;

            $this->assertSame(180, $retryAfter);
            $this->assertSame(120, $jobTimeout);
            $this->assertGreaterThan($jobTimeout, $retryAfter);
            $this->artisan('executions:assert-queue-safe')->assertSuccessful();
        } finally {
            if ($previous === null) {
                $repository->clear('REDIS_QUEUE_RETRY_AFTER');
            } else {
                $repository->set('REDIS_QUEUE_RETRY_AFTER', $previous);
            }
        }
    }

    public function test_explicit_retry_after_must_exceed_job_timeout(): void
    {
        $previous = config('queue.connections.redis.retry_after');
        config()->set('queue.connections.redis.retry_after', ExecutionQueueConfiguration::JOB_TIMEOUT_SECONDS);

        try {
            $this->assertSame(1, Artisan::call('executions:assert-queue-safe'));
            $this->assertStringContainsString('REDIS_QUEUE_RETRY_AFTER', Artisan::output());
        } finally {
            config()->set('queue.connections.redis.retry_after', $previous);
        }
    }
}
