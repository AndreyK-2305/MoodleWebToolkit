<?php

namespace App\Domain\Artifacts;

final class SensitiveValueRedactor
{
    private const SENSITIVE_KEY = '/password|passwd|secret|token|cookie|authorization|app[_-]?key|private[_-]?key|resume[_-]?token/i';

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
        $value = preg_replace_callback(
            '/\b(Proxy-Authorization|Authorization|Set-Cookie|Cookie)\s*:\s*[^\r\n]*/iu',
            fn (array $match): string => $match[1].': [REDACTED]',
            $value,
        ) ?? $value;

        $value = preg_replace('#(?<=://)[^/@\s]+(?::[^/@\s]*)?@#u', '[REDACTED]@', $value) ?? $value;

        return preg_replace(
            '/\b(password|passwd|secret|token|app[_-]?key|private[_-]?key|resume[_-]?token)\b\s*[:=]\s*[^\s,;]+/iu',
            '$1=[REDACTED]',
            $value,
        ) ?? $value;
    }
}
