<?php

namespace App\Domain\Tools\DTOs;

use InvalidArgumentException;

final readonly class ExecutionStepDefinition
{
    public function __construct(
        public string $key,
        public string $name,
        public int $position,
    ) {
        if ($this->key === '' || $this->name === '' || $this->position < 1) {
            throw new InvalidArgumentException('La definición de etapa es inválida.');
        }
    }
}
