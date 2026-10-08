<?php

namespace Tests\Unit;

use App\Domain\Processes\RegisteredCommandRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegisteredOutputPolicyTest extends TestCase
{
    #[DataProvider('invalidLimits')]
    public function test_invalid_platform_or_command_limit_is_rejected(mixed $limit, bool $command): void
    {
        config(['toolkit.runner.commands' => require base_path('tests/Support/registered-command-fixtures.php')]);
        config([$command ? 'toolkit.runner.commands.platform_health.durable_log_max_bytes' : 'toolkit.runner.durable_log_max_bytes' => $limit]);
        $this->expectException(InvalidArgumentException::class);
        app(RegisteredCommandRegistry::class)->resolve('platform_health');
    }

    public static function invalidLimits(): array
    {
        $cases = [];
        foreach ([0, -1, false, 1.5, 'no-limit', '0', '999999999999999999999', 20 * 1024 * 1024 * 1024] as $value) {
            $cases[] = [$value, false];
            $cases[] = [$value, true];
        }

        return $cases;
    }

    public function test_limits_are_explicit_and_environment_integers_are_supported(): void
    {
        config([
            'toolkit.runner.commands' => require base_path('tests/Support/registered-command-fixtures.php'),
            'toolkit.runner.durable_log_max_bytes' => '1024',
            'toolkit.runner.commands.platform_health.durable_log_max_bytes' => 512,
        ]);
        $definition = app(RegisteredCommandRegistry::class)->resolve('platform_health');
        $this->assertSame(512, $definition['durable_log_max_bytes']);
        $this->assertSame(1024, $definition['platform_durable_log_max_bytes']);
        $this->assertSame(65536, $definition['stream_pending_max_bytes']);
    }
}
