<?php

namespace Tests\Unit;

use App\Domain\Artifacts\SensitiveValueRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SensitiveValueRedactorRegressionTest extends TestCase
{
    #[DataProvider('sensitiveVariants')]
    public function test_nested_sensitive_variants_are_redacted(string $key): void
    {
        $redactor = new SensitiveValueRedactor;
        $value = ['safe' => 'visible', 'nested' => [['headers' => [$key => 'synthetic-secret-1g']]]];
        $result = $redactor->redact($value);
        $this->assertSame('visible', $result['safe']);
        $this->assertSame('[REDACTED]', $result['nested'][0]['headers'][$key]);
        $this->assertStringNotContainsString('synthetic-secret-1g', $redactor->redactString(json_encode($value, JSON_THROW_ON_ERROR)));
    }

    public static function sensitiveVariants(): array
    {
        return array_map(fn (string $key): array => [$key], [
            'smtp_password', 'ssh_passphrase', 'X-API-Key', 'AWS_SESSION_TOKEN',
            'oauth_client_secret', 'database_password', 'private_key', 'Set-Cookie',
        ]);
    }

    public function test_encoded_query_keys_and_multiline_private_keys_are_redacted(): void
    {
        $redactor = new SensitiveValueRedactor;
        $input = "https://user:synthetic-secret-1g@example.test/?smtp%5Fpassword=synthetic-secret-1g&safe=visible\n"
            ."-----BEGIN PRIVATE KEY-----\nsynthetic-secret-1g\n-----END PRIVATE KEY-----";
        $result = $redactor->redactString($input);
        $this->assertStringNotContainsString('synthetic-secret-1g', $result);
        $this->assertStringContainsString('safe=visible', $result);
    }

    public function test_non_secret_configuration_labels_remain_useful(): void
    {
        $value = ['token_count' => 3, 'password_policy' => 'strong', 'secret_present' => true];
        $this->assertSame($value, (new SensitiveValueRedactor)->redact($value));
    }
}
