<?php

namespace App\Domain\Tools\DTOs;

use App\Enums\EventSeverity;
use InvalidArgumentException;

final readonly class NormalizedToolEvent
{
    /** @param array<string, mixed>|null $payload */
    public function __construct(
        public string $type,
        public ?string $stepKey = null,
        public EventSeverity $severity = EventSeverity::INFO,
        public ?int $progress = null,
        public ?string $message = null,
        public ?array $payload = null,
    ) {
        if ($this->type === '') {
            throw new InvalidArgumentException('El tipo del evento normalizado es obligatorio.');
        }

        if ($this->progress !== null && ($this->progress < 0 || $this->progress > 100)) {
            throw new InvalidArgumentException('El progreso debe ser null o estar entre 0 y 100.');
        }
    }
}
