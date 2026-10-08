<?php

namespace App\Domain\Processes;

use App\Domain\Artifacts\StreamingSensitiveValueRedactor;
use Closure;
use HashContext;
use InvalidArgumentException;

/** Bounded sanitized file capture; observed raw bytes are counted, never hashed. */
final class SanitizedOutputCapture
{
    public const TRUNCATION_MARKER = "\n[OUTPUT TRUNCATED: durable limit reached]\n";

    private int $observed = 0;

    private int $persisted = 0;

    private int $sanitized = 0;

    private bool $truncated = false;

    private bool $memoryTruncated = false;

    private bool $finished = false;

    private string $tail = '';

    private string $captured = '';

    private readonly string $marker;

    private readonly HashContext $hash;

    /** @param Closure(string): void $persist */
    public function __construct(
        private readonly StreamingSensitiveValueRedactor $redactor,
        private readonly int $durableLimit,
        private readonly int $memoryLimit,
        private readonly Closure $persist,
    ) {
        if ($durableLimit < 1 || $memoryLimit < 1) {
            throw new InvalidArgumentException('Los límites de captura deben ser enteros positivos.');
        }
        $this->marker = $durableLimit >= strlen(self::TRUNCATION_MARKER) ? self::TRUNCATION_MARKER : '!';
        $this->hash = hash_init('sha256');
    }

    public function observe(string $chunk): void
    {
        $this->observed += strlen($chunk);
        if ($this->truncated || $this->finished) {
            return;
        }
        $this->append($this->redactor->push($chunk));
        if ($this->truncated) {
            $this->redactor->discard();
        }
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }
        if (! $this->truncated) {
            $this->append($this->redactor->finish());
            $this->write($this->tail);
            $this->tail = '';
        }
        $this->finished = true;
    }

    public function observedBytes(): int
    {
        return $this->observed;
    }

    public function persistedBytes(): int
    {
        return $this->persisted;
    }

    public function captured(): string
    {
        return $this->captured;
    }

    public function sha256(): string
    {
        return hash_final(hash_copy($this->hash));
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    public function memoryTruncated(): bool
    {
        return $this->memoryTruncated;
    }

    private function append(string $safe): void
    {
        if ($this->truncated || $safe === '') {
            return;
        }
        $this->sanitized += strlen($safe);
        $buffer = $this->tail.$safe;
        $prefix = substr($buffer, 0, max(0, $this->durableLimit - strlen($this->marker) - $this->persisted));
        $this->write($prefix);
        if ($this->sanitized > $this->durableLimit) {
            $this->tail = '';
            $this->write($this->marker);
            $this->truncated = true;
        } else {
            // Hold at most one marker's worth of sanitized tail until EOF. This
            // lets exact-limit output keep every byte without a truncation mark.
            $this->tail = substr($buffer, strlen($prefix));
        }
    }

    private function write(string $safe): void
    {
        if ($safe === '') {
            return;
        }
        ($this->persist)($safe);
        $this->persisted += strlen($safe);
        hash_update($this->hash, $safe);
        $remaining = max(0, $this->memoryLimit - strlen($this->captured));
        $this->memoryTruncated = $this->memoryTruncated || strlen($safe) > $remaining;
        $this->captured .= substr($safe, 0, $remaining);
    }
}
