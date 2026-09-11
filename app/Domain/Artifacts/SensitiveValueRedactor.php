<?php

namespace App\Domain\Artifacts;

final class SensitiveValueRedactor
{
    private const SENSITIVE_KEY = '/^(?:proxy[-_]?authorization|authorization|set[-_]?cookie|cookie|password|passwd|secret|(?:access|refresh|resume|api|auth)?[_-]?token|app[_-]?key|private[_-]?key)$/i';

    public function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SENSITIVE_KEY, $key) === 1) {
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
            '/(?<key>"(?:Proxy-Authorization|Authorization|Set-Cookie|Cookie|password|passwd|secret|(?:access|refresh|resume|api|auth)?[_-]?token|app[_-]?key|private[_-]?key)")\s*:\s*(?<value>"(?:\\\\.|[^"\\\\])*"|[^\s,;}]+)/iu',
            fn (array $match): string => $match['key'].':"[REDACTED]"',
            $value,
        ) ?? $value;

        return preg_replace(
            '/\b(password|passwd|secret|(?:access|refresh|resume|api|auth)?[_-]?token|app[_-]?key|private[_-]?key)\b\s*[:=]\s*[^\s,;]+/iu',
            '$1=[REDACTED]',
            $value,
        ) ?? $value;
    }
}
