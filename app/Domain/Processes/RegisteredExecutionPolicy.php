<?php

namespace App\Domain\Processes;

use InvalidArgumentException;

/** Time and resource policy is part of the registered command's durable identity. */
class RegisteredExecutionPolicy
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array{startup_timeout_seconds: int, heartbeat_interval_seconds: int, stall_timeout_seconds: ?int, wall_timeout_seconds: ?int, cancellation_grace_seconds: int, resource_limits: array{cpu_seconds: ?int, memory_bytes: int, processes: int, file_bytes: int}}
     */
    public function resolve(array $definition): array
    {
        $limits = $definition['resource_limits'] ?? config('toolkit.runner.limits', []);
        if (! is_array($limits) || array_diff(array_keys($limits), ['cpu_seconds', 'memory_bytes', 'processes', 'file_bytes']) !== []) {
            throw new InvalidArgumentException('La política de recursos del comando no es válida.');
        }

        return [
            'startup_timeout_seconds' => $this->bounded($this->value($definition, 'startup_timeout_seconds', config('toolkit.runner.supervisor_start_timeout_seconds', 3)), 30),
            'heartbeat_interval_seconds' => $this->bounded($this->value($definition, 'heartbeat_interval_seconds', config('toolkit.runner.heartbeat_interval_seconds', 10)), 60),
            'stall_timeout_seconds' => $this->nullable(array_key_exists('stall_timeout_seconds', $definition) ? $definition['stall_timeout_seconds'] : config('toolkit.runner.stall_timeout_seconds')),
            'wall_timeout_seconds' => $this->nullable(array_key_exists('wall_timeout_seconds', $definition) ? $definition['wall_timeout_seconds'] : ($definition['timeout'] ?? config('toolkit.runner.timeout_seconds', 3600))),
            'cancellation_grace_seconds' => $this->bounded($this->value($definition, 'cancellation_grace_seconds', config('toolkit.runner.cancel_grace_seconds', 3)), 30),
            'resource_limits' => [
                'cpu_seconds' => $this->nullable($limits['cpu_seconds'] ?? null),
                'memory_bytes' => $this->positive($limits['memory_bytes'] ?? 8_589_934_592),
                'processes' => $this->positive($limits['processes'] ?? 128),
                'file_bytes' => $this->positive($limits['file_bytes'] ?? 1_099_511_627_776),
            ],
        ];
    }

    public function expired(?int $seconds, float $startedAt, float $observedAt): bool
    {
        return $seconds !== null && $observedAt - $startedAt > $seconds;
    }

    private function nullable(mixed $value): ?int
    {
        return $value === null ? null : $this->positive($value);
    }

    /** @param array<string, mixed> $definition */
    private function value(array $definition, string $field, mixed $default): mixed
    {
        return array_key_exists($field, $definition) ? $definition[$field] : $default;
    }

    private function bounded(mixed $value, int $maximum): int
    {
        $seconds = $this->positive($value);
        if ($seconds > $maximum) {
            throw new InvalidArgumentException('La política excede el presupuesto de una unidad corta del runner.');
        }

        return $seconds;
    }

    private function positive(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('Los límites deben ser enteros positivos; solo los límites opcionales aceptan null.');
        }

        return $value;
    }
}
