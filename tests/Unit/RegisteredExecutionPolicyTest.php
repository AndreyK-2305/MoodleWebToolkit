<?php

namespace Tests\Unit;

use App\Domain\Processes\RegisteredExecutionPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegisteredExecutionPolicyTest extends TestCase
{
    public function test_unlimited_wall_and_cpu_policy_survives_more_than_24_hours(): void
    {
        $policy = app(RegisteredExecutionPolicy::class);
        $resolved = $policy->resolve(['wall_timeout_seconds' => null]);
        $this->assertNull($resolved['wall_timeout_seconds']);
        $this->assertNull($resolved['resource_limits']['cpu_seconds']);
        $this->assertFalse($policy->expired($resolved['wall_timeout_seconds'], 100, 100 + 7 * 86400));
        $this->assertTrue($policy->expired(3600, 100, 3701));
        $this->assertSame(172800, $policy->resolve(['wall_timeout_seconds' => 172800])['wall_timeout_seconds']);
        $this->assertSame(172800, $policy->resolve(['timeout' => 172800])['wall_timeout_seconds']);
        $this->assertSame(172800, $policy->resolve(['resource_limits' => ['cpu_seconds' => 172800]])['resource_limits']['cpu_seconds']);
    }

    #[DataProvider('invalidTimeouts')]
    public function test_invalid_policy_is_rejected(string $field, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(RegisteredExecutionPolicy::class)->resolve([$field => $value]);
    }

    public static function invalidTimeouts(): array
    {
        $cases = [];
        foreach (['wall_timeout_seconds', 'stall_timeout_seconds', 'heartbeat_interval_seconds', 'startup_timeout_seconds', 'cancellation_grace_seconds'] as $field) {
            foreach ([0, -1, false, 1.5, '0', 'unlimited', '99999999999999999999'] as $value) {
                $cases[] = [$field, $value];
            }
        }
        foreach (['heartbeat_interval_seconds', 'startup_timeout_seconds', 'cancellation_grace_seconds'] as $field) {
            $cases[] = [$field, null];
            $cases[] = [$field, 121];
        }

        return $cases;
    }
}
