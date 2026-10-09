<?php

namespace Tests\Unit;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Artifacts\StreamingSensitiveValueRedactor;
use App\Domain\Processes\SanitizedOutputCapture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SanitizedOutputCaptureTest extends TestCase
{
    #[DataProvider('lengths')]
    public function test_limit_marker_counts_and_hash_are_exact(int $length): void
    {
        $persisted = '';
        $stream = new SanitizedOutputCapture(new StreamingSensitiveValueRedactor(new SensitiveValueRedactor), 128, 256,
            function (string $safe) use (&$persisted): void {
                $persisted .= $safe;
            },
        );
        $raw = str_repeat('x', $length - 1)."\n";
        foreach (str_split($raw, 23) as $chunk) {
            $stream->observe($chunk);
            $this->assertLessThanOrEqual(128, strlen($persisted));
        }
        $stream->finish();
        $this->assertSame($length, $stream->observedBytes());
        $this->assertSame(strlen($persisted), $stream->persistedBytes());
        $this->assertSame(hash('sha256', $persisted), $stream->sha256());
        $this->assertSame($persisted, $stream->captured());
        $this->assertSame($length > 128, $stream->truncated());
        $this->assertSame($length > 128 ? 1 : 0, substr_count($persisted, '[OUTPUT TRUNCATED: durable limit reached]'));
    }

    public static function lengths(): array
    {
        return [[10], [128], [129], [8192]];
    }

    public function test_raw_secrets_are_never_given_to_the_persistence_callback(): void
    {
        $persisted = '';
        $stream = new SanitizedOutputCapture(new StreamingSensitiveValueRedactor(new SensitiveValueRedactor), 128, 32,
            function (string $safe) use (&$persisted): void {
                $this->assertStringNotContainsString('private-value', $safe);
                $persisted .= $safe;
            },
        );
        $stream->observe('password=');
        $this->assertSame('', $persisted);
        $stream->observe('private-value');
        $stream->finish();
        $this->assertSame('password=[REDACTED]', $persisted);
        $this->assertSame(strlen('password=private-value'), $stream->observedBytes());
        $this->assertSame(strlen($persisted), $stream->persistedBytes());
    }

    public function test_stream_keeps_draining_after_truncation_without_buffering_discarded_bytes(): void
    {
        $persisted = '';
        $stream = new SanitizedOutputCapture(new StreamingSensitiveValueRedactor(new SensitiveValueRedactor, 64), 64, 32,
            function (string $safe) use (&$persisted): void {
                $persisted .= $safe;
            },
        );
        $line = str_repeat('x', 31)."\n";
        for ($index = 0; $index < 1000; $index++) {
            $stream->observe($line);
        }
        $stream->finish();
        $this->assertSame(32_000, $stream->observedBytes());
        $this->assertSame(64, strlen($persisted));
        $this->assertLessThanOrEqual(32, strlen($stream->captured()));
        $this->assertSame(1, substr_count($persisted, '[OUTPUT TRUNCATED: durable limit reached]'));
    }

    public function test_persistence_failure_does_not_sign_unwritten_bytes(): void
    {
        $stream = new SanitizedOutputCapture(new StreamingSensitiveValueRedactor(new SensitiveValueRedactor), 128, 128,
            function (): void {
                throw new RuntimeException('Synthetic durable write failed.');
            },
        );
        try {
            $stream->observe("password=private-value\n");
            $this->fail('A write failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('private-value', $exception->getMessage());
            $this->assertSame(0, $stream->persistedBytes());
            $this->assertSame(hash('sha256', ''), $stream->sha256());
        }
    }

    public function test_capacity_stop_discards_pending_bytes_but_keeps_counting_and_hashing_only_persisted_output(): void
    {
        $persisted = '';
        $stream = new SanitizedOutputCapture(new StreamingSensitiveValueRedactor(new SensitiveValueRedactor), 128, 128,
            function (string $safe) use (&$persisted): void {
                $persisted .= $safe;
            });
        $stream->observe("safe\npassword=private");
        $before = $persisted;
        $stream->discardPending();
        $stream->observe("-value\nmore raw output\n");
        $stream->finish();
        $this->assertSame($before, $persisted);
        $this->assertSame(strlen("safe\npassword=private-value\nmore raw output\n"), $stream->observedBytes());
        $this->assertSame(strlen($persisted), $stream->persistedBytes());
        $this->assertSame(hash('sha256', $persisted), $stream->sha256());
        $this->assertTrue($stream->truncated());
        $this->assertStringNotContainsString('private', $persisted);
    }
}
