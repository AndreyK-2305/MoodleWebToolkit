<?php

namespace App\Domain\Artifacts;

final class SensitiveValueRedactor
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'authorization',
        'proxyauthorization',
        'setcookie',
        'cookie',
        'password',
        'passwd',
        'secret',
        'token',
        'authtoken',
        'accesstoken',
        'refreshtoken',
        'resumetoken',
        'oauthtoken',
        'clientsecret',
        'apikey',
        'awssecretaccesskey',
        'secretaccesskey',
        'databasepassword',
        'dbpassword',
        'connectionpassword',
        'appkey',
        'privatekey',
    ];

    public function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = $this->redact($childValue, (string) $childKey);
            }

            return $redacted;
        }

        return is_string($value) ? $this->redactString($value) : $value;
    }

    public function redactString(string $value): string
    {
        $value = preg_replace(
            '/-----BEGIN (?:[A-Z0-9]+ )?PRIVATE KEY-----.*?-----END (?:[A-Z0-9]+ )?PRIVATE KEY-----/s',
            '[REDACTED PRIVATE KEY]',
            $value,
        ) ?? $value;

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($decoded)) {
                return json_encode(
                    $this->redact($decoded),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
            }
        } catch (\JsonException) {
            // Continue with conservative text and JSON-like fragment patterns.
        }

        $value = preg_replace_callback(
            '/\b(Proxy-Authorization|Authorization|Set-Cookie|Cookie)\s*:\s*[^\r\n]*/iu',
            fn (array $match): string => $match[1].': [REDACTED]',
            $value,
        ) ?? $value;

        $value = preg_replace('#(?<=://)[^/@\s]+(?::[^/@\s]*)?@#u', '[REDACTED]@', $value) ?? $value;

        $value = preg_replace_callback(
            '/(?<quoted>"(?<key>[A-Za-z][A-Za-z0-9_-]*)")\s*:\s*(?<value>"(?:\\\\.|[^"\\\\])*"|(?![\[{])[^\s,;}]+)/u',
            fn (array $match): string => $this->isSensitiveKey($match['key'])
                ? $match['quoted'].':"[REDACTED]"'
                : $match[0],
            $value,
        ) ?? $value;

        $value = preg_replace_callback(
            '/(?<![A-Za-z0-9_%-])(?<key>[A-Za-z][A-Za-z0-9_%-]*)\s*=\s*(?<value>"(?:\\\\.|[^"\\\\])*"|[^\s&,;}]+)/u',
            fn (array $match): string => $this->isSensitiveKey(rawurldecode($match['key']))
                ? $match['key'].'=[REDACTED]'
                : $match[0],
            $value,
        ) ?? $value;

        return preg_replace_callback(
            '/(?<![A-Za-z0-9_-])(?<key>[A-Za-z][A-Za-z0-9_-]*)\s*:\s*(?!\/\/)(?<value>"(?:\\\\.|[^"\\\\])*"|(?![\[{])[^\s&,;}]+)/u',
            fn (array $match): string => $this->isSensitiveKey($match['key'])
                ? $match['key'].':[REDACTED]'
                : $match[0],
            $value,
        ) ?? $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $key) ?? $key);

        return in_array($normalized, self::SENSITIVE_KEYS, true)
            || preg_match('/(?:password|passwd|passphrase|token|secret|privatekey|apikey)$/D', $normalized) === 1;
    }
}
