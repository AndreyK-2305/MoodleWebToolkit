<?php

namespace Tests\Unit;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Artifacts\StreamingSensitiveValueRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StreamingSensitiveValueRedactorTest extends TestCase
{
    #[DataProvider('records')]
    public function test_every_byte_boundary_is_safe_including_finish_without_newline(string $record): void
    {
        for ($cut = 1; $cut < strlen($record); $cut++) {
            $stream = new StreamingSensitiveValueRedactor(new SensitiveValueRedactor);
            $output = $stream->push(substr($record, 0, $cut));
            $output .= $stream->push(substr($record, $cut)).$stream->finish();
            $this->assertStringNotContainsString('private-value', $output);
        }
    }

    public static function records(): array
    {
        $records = [
            'password=private-value', 'passwd=private-value', 'passphrase=private-value',
            'refresh_token=private-value', 'client_secret=private-value', 'secret=private-value',
            'Authorization: Bearer private-value', 'Proxy-Authorization: Bearer private-value',
            'Cookie: session=private-value', 'Set-Cookie: session=private-value; HttpOnly',
            'https://user:private-value@example.test/path',
            '{"credentials":{"token":"private-value"},"safe":true}',
            "{\n  \"password\":\n    \"private-value\"\n}\n",
            "-----BEGIN PRIVATE KEY-----\nprivate-value\n-----END PRIVATE KEY-----",
            "-----BEGIN RSA PRIVATE KEY-----\nprivate-value",
            "password=\nprivate-value\n",
        ];

        return array_map(fn (string $record): array => [$record], $records);
    }

    public function test_streams_have_independent_context_and_do_not_release_pending_values(): void
    {
        $stdout = new StreamingSensitiveValueRedactor(new SensitiveValueRedactor);
        $stderr = new StreamingSensitiveValueRedactor(new SensitiveValueRedactor);
        $this->assertSame('', $stdout->push('pass'));
        $this->assertSame('', $stderr->push('Authori'));
        $this->assertSame('', $stdout->push('word=private-value'));
        $this->assertSame('', $stderr->push('zation: Bearer private-value'));
        $this->assertSame('password=[REDACTED]', $stdout->finish());
        $this->assertSame('Authorization: [REDACTED]', $stderr->finish());
    }

    public function test_oversized_unframed_record_is_discarded_until_its_boundary(): void
    {
        $stream = new StreamingSensitiveValueRedactor(new SensitiveValueRedactor, 64);
        $output = '';
        foreach (str_split('password='.str_repeat('private-value', 100), 7) as $chunk) {
            $output .= $stream->push($chunk);
            $this->assertLessThanOrEqual(64, $stream->bufferedBytes());
        }
        $output .= $stream->push("\nvisible\n").$stream->finish();
        $this->assertStringNotContainsString('private-value', $output);
        $this->assertSame(1, substr_count($output, '[REDACTED UNFRAMED OUTPUT]'));
        $this->assertStringContainsString("visible\n", $output);
    }

    public function test_private_key_state_survives_many_lines_and_overflow(): void
    {
        $stream = new StreamingSensitiveValueRedactor(new SensitiveValueRedactor, 64);
        $output = $stream->push("-----BEGIN PRIVATE KEY-----\n");
        $output .= $stream->push(str_repeat('private-value', 100)."\n");
        $output .= $stream->push("private-value\n-----END PRIVATE KEY-----\nvisible\n").$stream->finish();
        $this->assertStringNotContainsString('private-value', $output);
        $this->assertSame(1, substr_count($output, '[REDACTED PRIVATE KEY]'));
        $this->assertStringContainsString('visible', $output);
    }

    public function test_invalid_utf8_and_unfinished_json_fail_closed(): void
    {
        foreach (["password=private-value\xff\n", '{"password":"private-value'] as $record) {
            $stream = new StreamingSensitiveValueRedactor(new SensitiveValueRedactor);
            $output = $stream->push($record).$stream->finish();
            $this->assertStringNotContainsString('private-value', $output);
            $this->assertStringContainsString('[REDACTED UNFRAMED OUTPUT]', $output);
        }
    }
}
