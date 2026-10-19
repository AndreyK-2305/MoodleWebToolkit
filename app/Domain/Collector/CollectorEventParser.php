<?php

namespace App\Domain\Collector;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\EventSeverity;
use InvalidArgumentException;
use JsonException;

final class CollectorEventParser
{
    private string $pending = '';

    private bool $discarding = false;

    public function __construct(
        private readonly string $operationUuid,
        private int $lastSequence = 0,
        private readonly int $maximumLineBytes = 8192,
    ) {
        if ($lastSequence < 0 || $maximumLineBytes < 128 || $maximumLineBytes > 65536) {
            throw new InvalidArgumentException('El cursor o límite del parser no es válido.');
        }
    }

    /** @return list<NormalizedToolEvent> */
    public function feed(string $chunk, bool $final = false): array
    {
        $events = [];
        // Process bounded segments; never persist a partial line that could contain a secret.
        while ($chunk !== '') {
            $newline = strpos($chunk, "\n");
            $segment = $newline === false ? $chunk : substr($chunk, 0, $newline);
            $chunk = $newline === false ? '' : substr($chunk, $newline + 1);
            if (! $this->discarding) {
                if (strlen($this->pending) + strlen($segment) > $this->maximumLineBytes) {
                    $this->pending = '';
                    $this->discarding = true;
                    $events[] = $this->log('Se descartó una línea que excede el límite del parser.');
                } else {
                    $this->pending .= $segment;
                }
            }
            if ($newline !== false) {
                if (! $this->discarding && trim($this->pending) !== '') {
                    $event = $this->line($this->pending);
                    if ($event !== null) {
                        $events[] = $event;
                    }
                }
                $this->pending = '';
                $this->discarding = false;
            }
        }
        if ($final && ($this->pending !== '' || $this->discarding)) {
            $events[] = $this->log('La lectura terminó con una línea incompleta; no se interpreta como estado terminal.');
            $this->pending = '';
            $this->discarding = false;
        }

        return $events;
    }

    public function lastSequence(): int
    {
        return $this->lastSequence;
    }

    private function line(string $line): ?NormalizedToolEvent
    {
        $safe = app(SensitiveValueRedactor::class)->redactString(mb_convert_encoding($line, 'UTF-8', 'UTF-8'));
        try {
            $document = json_decode($safe, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->log('Se recibió una línea no estructurada; su contenido se descartó.');
        }
        if (! is_array($document) || ($document['schema_version'] ?? null) !== 'collector-event.v1') {
            return $this->log('Se recibió un documento desconocido; su contenido se descartó.');
        }
        if (($document['operation_uuid'] ?? null) !== $this->operationUuid || ! is_int($document['sequence'] ?? null)
            || $document['sequence'] < 1) {
            return $this->log('Se rechazó una señal con identidad o secuencia inválida.');
        }
        if ($document['sequence'] <= $this->lastSequence) {
            return null;
        }
        $this->lastSequence = $document['sequence'];
        $type = $document['type'] ?? null;
        if ($type === 'snapshot') {
            $stage = $document['stage'] ?? null;
            if (! in_array($stage, ['identities', 'source-inventory', 'plugins', 'existing-backups-index', 'course-backups', 'sealing', 'sealed', 'validation'], true)) {
                return $this->log('El Recolector informó una etapa desconocida.');
            }
            $total = $document['total_courses'] ?? null;
            $completed = $document['completed_courses'] ?? null;
            $failed = $document['failed_courses'] ?? null;
            $known = is_int($total) && $total > 0 && $total <= 1000000 && is_int($completed)
                && $completed >= 0 && $completed <= $total && is_int($failed) && $failed >= 0 && $completed + $failed <= $total;
            $progress = $known && $stage === 'course-backups' ? (int) floor($completed * 100 / $total) : null;

            return new NormalizedToolEvent('collector.progress', 'collection', progress: $progress,
                message: $known ? "Cursos procesados: {$completed} de {$total}." : 'El Recolector continúa en una etapa sin unidades conocidas.',
                payload: ['stage' => $stage, 'units' => $known ? ['kind' => 'courses', 'completed' => $completed, 'total' => $total, 'failed' => $failed] : null]);
        }
        if (in_array($type, ['started', 'package_validated', 'error', 'cancelled'], true)) {
            $error = $type === 'error';

            return new NormalizedToolEvent('collector.'.$type, $type === 'package_validated' ? 'verification' : 'collection',
                severity: $error ? EventSeverity::ERROR : EventSeverity::INFO,
                message: match ($type) {
                    'started' => 'El proceso real del Recolector comenzó.',
                    'package_validated' => 'El auditor del Recolector validó el paquete; resta confirmar la evidencia durable.',
                    'cancelled' => 'El bridge recibió una señal de cancelación; resta confirmar la terminación.',
                    default => 'El proceso real informó un error; se preservó su evidencia privada.',
                });
        }

        return $this->log('Se recibió un tipo de señal desconocido del bridge.');
    }

    private function log(string $message): NormalizedToolEvent
    {
        return new NormalizedToolEvent('collector.log', 'collection', message: $message);
    }
}
