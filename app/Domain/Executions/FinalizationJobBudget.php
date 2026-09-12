<?php

namespace App\Domain\Executions;

class FinalizationJobBudget
{
    private int $deadlineNanoseconds = PHP_INT_MAX;

    public function start(int $seconds): void
    {
        $this->deadlineNanoseconds = hrtime(true) + ($seconds * 1_000_000_000);
    }

    public function exhausted(): bool
    {
        return hrtime(true) >= $this->deadlineNanoseconds;
    }
}
