<?php

namespace App\Domain\Artifacts;

use InvalidArgumentException;
use RuntimeException;

/** Holds unclassified bytes; only sanitized complete records leave this object. */
final class StreamingSensitiveValueRedactor
{
    public const MAX_PENDING_BYTES = 65_536;

    public const UNFRAMED_MARKER = '[REDACTED UNFRAMED OUTPUT]';

    private string $pending = '';

    private bool $discarding = false;

    private bool $privateKey = false;

    private bool $finished = false;

    private bool $ambiguousContinuation = false;

    private ?bool $json = null;

    private int $depth = 0;

    private bool $quoted = false;

    private bool $escaped = false;

    public function __construct(private readonly SensitiveValueRedactor $redactor, private readonly int $maximumPendingBytes = self::MAX_PENDING_BYTES)
    {
        if ($maximumPendingBytes < 64 || $maximumPendingBytes > self::MAX_PENDING_BYTES) {
            throw new InvalidArgumentException('El buffer pendiente debe estar entre 64 y 65536 bytes.');
        }
    }

    public function push(string $chunk): string
    {
        if ($this->finished) {
            throw new RuntimeException('El redactor de streaming ya está cerrado.');
        }
        $output = '';
        for ($index = 0, $length = strlen($chunk); $index < $length; $index++) {
            if ($this->ambiguousContinuation) {
                return $output;
            }
            $character = $chunk[$index];
            $this->trackJson($character);
            $boundary = $character === "\n" && (! $this->json || $this->depth <= 0);
            if ($this->discarding) {
                $this->trackDiscardedPem($character);
                $boundary = $character === "\n" && (! $this->json || $this->depth <= 0);
                if ($boundary) {
                    $this->discarding = false;
                    $this->resetRecord();
                }

                continue;
            }
            if (strlen($this->pending) === $this->maximumPendingBytes) {
                if (! $this->privateKey && preg_match('/-----BEGIN (?:[A-Z0-9]+ )?PRIVATE KEY-----/', $this->pending) === 1) {
                    $output .= '[REDACTED PRIVATE KEY]';
                    $this->privateKey = true;
                    $this->json = false;
                } elseif (! $this->privateKey) {
                    $output .= self::UNFRAMED_MARKER."\n";
                }
                // Reuse the pending buffer for delimiter recognition while
                // discarding. Its bounded tail is never released as output.
                $this->pending = substr($this->pending, -64);
                $this->discarding = true;
                $this->trackDiscardedPem($character);
                $boundary = $character === "\n" && (! $this->json || $this->depth <= 0);
                if ($boundary) {
                    $this->discarding = false;
                    $this->resetRecord();
                }

                continue;
            }
            $this->pending .= $character;
            if ($boundary) {
                $record = $this->pending;
                $this->resetRecord();
                $output .= $this->sanitizeRecord($record);
            }
        }

        return $output;
    }

    public function finish(): string
    {
        if ($this->finished) {
            return '';
        }
        $this->finished = true;
        $record = $this->pending;
        $this->pending = '';

        return $this->ambiguousContinuation || $this->discarding || $record === '' ? '' : $this->sanitizeRecord($record);
    }

    public function discard(): void
    {
        $this->pending = '';
        $this->finished = true;
    }

    public function bufferedBytes(): int
    {
        return strlen($this->pending);
    }

    private function trackJson(string $character): void
    {
        if ($this->json === null && ! ctype_space($character)) {
            $this->json = ! $this->privateKey && in_array($character, ['{', '['], true);
        }
        if (! $this->json) {
            return;
        }
        if ($this->quoted) {
            if ($this->escaped) {
                $this->escaped = false;
            } elseif ($character === '\\') {
                $this->escaped = true;
            } elseif ($character === '"') {
                $this->quoted = false;
            }
        } elseif ($character === '"') {
            $this->quoted = true;
        } elseif (in_array($character, ['{', '['], true)) {
            $this->depth++;
        } elseif (in_array($character, ['}', ']'], true)) {
            $this->depth--;
        }
    }

    private function resetRecord(): void
    {
        $this->pending = '';
        $this->json = null;
        $this->depth = 0;
        $this->quoted = false;
        $this->escaped = false;
    }

    private function trackDiscardedPem(string $character): void
    {
        $this->pending = substr($this->pending.$character, -64);
        if (! $this->privateKey && preg_match('/-----BEGIN (?:[A-Z0-9]+ )?PRIVATE KEY-----$/D', $this->pending) === 1) {
            $this->privateKey = true;
            $this->json = false;
        } elseif ($this->privateKey && preg_match('/-----END (?:[A-Z0-9]+ )?PRIVATE KEY-----$/D', $this->pending) === 1) {
            $this->privateKey = false;
        }
    }

    private function sanitizeRecord(string $record): string
    {
        if ($this->privateKey) {
            if (preg_match('/-----END (?:[A-Z0-9]+ )?PRIVATE KEY-----/', $record, $end, PREG_OFFSET_CAPTURE) !== 1) {
                return '';
            }
            $this->privateKey = false;

            return $this->sanitizeRecord(substr($record, $end[0][1] + strlen($end[0][0])));
        }
        if (preg_match('/-----BEGIN (?:[A-Z0-9]+ )?PRIVATE KEY(?:-----)?/', $record, $begin, PREG_OFFSET_CAPTURE) === 1) {
            $prefix = $this->sanitizePlain(substr($record, 0, $begin[0][1]));
            $this->privateKey = true;

            return $prefix.'[REDACTED PRIVATE KEY]'.$this->sanitizeRecord(substr($record, $begin[0][1] + strlen($begin[0][0])));
        }

        return $this->sanitizePlain($record);
    }

    private function sanitizePlain(string $record): string
    {
        if (preg_match('//u', $record) !== 1) {
            $this->ambiguousContinuation = true;

            return self::UNFRAMED_MARKER;
        }
        $trimmed = ltrim($record);
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            try {
                json_decode($record, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->ambiguousContinuation = true;

                return self::UNFRAMED_MARKER;
            }
        } else {
            preg_match_all('/(?:"(?<quoted>[A-Za-z][A-Za-z0-9_%_-]*)"|(?<key>[A-Za-z][A-Za-z0-9_%_-]*))\s*[:=]\s*/', $record, $assignments, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($assignments as $assignment) {
                $quoted = $assignment['quoted'][0] ?? '';
                $key = $quoted !== '' ? $quoted : ($assignment['key'][0] ?? '');
                if (! $this->redactor->isSensitiveKeyName(rawurldecode($key))) {
                    continue;
                }
                $rest = substr($record, $assignment[0][1] + strlen($assignment[0][0]));
                if ($rest === '') {
                    $this->ambiguousContinuation = true;

                    return self::UNFRAMED_MARKER."\n";
                }
                if (in_array($rest[0], ['{', '[', "'"], true)
                    || ($rest[0] === '"' && preg_match('/^"(?:\\\\.|[^"\\\\])*"/s', $rest) !== 1)
                ) {
                    $this->ambiguousContinuation = true;

                    return self::UNFRAMED_MARKER;
                }
            }
        }
        $ending = str_ends_with($record, "\r\n") ? "\r\n" : (str_ends_with($record, "\n") ? "\n" : '');
        $body = $ending === '' ? $record : substr($record, 0, -strlen($ending));

        return $this->redactor->redactString($body).$ending;
    }
}
