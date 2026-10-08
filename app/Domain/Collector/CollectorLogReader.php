<?php

namespace App\Domain\Collector;

use App\Domain\Tools\DTOs\NormalizedToolEvent;
use InvalidArgumentException;

/**
 * @phpstan-type CollectorCursor array{offset: int, wire_sequence: int, device: ?string, inode: ?string, prefix_sha256: string, discarding: bool}
 * @phpstan-type CollectorReadResult array{cursor: CollectorCursor, health: string, complete: bool, events: list<array{event: NormalizedToolEvent, wire_sequence: ?int}>}
 */
final class CollectorLogReader
{
    /**
     * @param  CollectorCursor  $cursor
     * @return CollectorReadResult
     */
    public function read(string $path, string $operationUuid, array $cursor, bool $final = false): array
    {
        if ($cursor['offset'] < 0 || $cursor['offset'] > 8388608 || $cursor['wire_sequence'] < 0
            || preg_match('/^[a-f0-9]{64}$/D', $cursor['prefix_sha256']) !== 1) {
            throw new InvalidArgumentException('El cursor de lectura no es válido.');
        }
        $blocked = fn (string $health): array => ['cursor' => $cursor, 'health' => $health, 'complete' => false, 'events' => []];
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return $blocked('MISSING');
        }
        if (is_link($path) || realpath($path) !== $path || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || $stat['size'] > 8388608) {
            return $blocked('UNSAFE');
        }
        if ($cursor['inode'] !== null && ($cursor['inode'] !== (string) $stat['ino'] || $cursor['device'] !== (string) $stat['dev'])) {
            return $blocked('ROTATED');
        }
        if ($stat['size'] < $cursor['offset']) {
            return $blocked('TRUNCATED');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return $blocked('UNREADABLE');
        }
        try {
            $opened = fstat($handle);
            if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino'] || $opened['nlink'] !== 1) {
                return $blocked('UNSAFE');
            }
            $prefix = $this->prefix($handle, $cursor['offset']);
            if ($prefix === null || ! hash_equals($cursor['prefix_sha256'], hash_final(hash_copy($prefix)))) {
                return $blocked('ALTERED');
            }
            $next = [...$cursor, 'device' => (string) $opened['dev'], 'inode' => (string) $opened['ino']];
            $parser = new CollectorEventParser($operationUuid, $cursor['wire_sequence']);
            $buffer = '';
            $readBytes = 0;
            $lines = 0;
            $events = [];
            while ($readBytes < 65536 && $lines < 16) {
                if ($buffer === '') {
                    $chunk = fread($handle, min(16384, 65536 - $readBytes));
                    if ($chunk === false) {
                        return $blocked('UNREADABLE');
                    }
                    $readBytes += strlen($chunk);
                    $buffer = $chunk;
                    if ($buffer === '') {
                        break;
                    }
                }
                $newline = strpos($buffer, "\n");
                if ($next['discarding']) {
                    $discarded = $newline === false ? $buffer : substr($buffer, 0, $newline + 1);
                    hash_update($prefix, $discarded);
                    $next['offset'] += strlen($discarded);
                    $buffer = $newline === false ? '' : substr($buffer, $newline + 1);
                    if ($newline !== false) {
                        $next['discarding'] = false;
                        $lines++;
                    }

                    continue;
                }
                if ($newline === false) {
                    if (strlen($buffer) > 8192) {
                        hash_update($prefix, $buffer);
                        $next['offset'] += strlen($buffer);
                        $buffer = '';
                        $next['discarding'] = true;
                        $events[] = ['event' => new NormalizedToolEvent('collector.log', 'collection',
                            message: 'Se descartó una línea que excede el límite de lectura.'), 'wire_sequence' => null];
                        $lines++;

                        continue;
                    }
                    if (feof($handle) || $readBytes >= 65536) {
                        if ($final && feof($handle)) {
                            foreach ($parser->feed($buffer, true) as $event) {
                                $events[] = ['event' => $event, 'wire_sequence' => null];
                            }
                            hash_update($prefix, $buffer);
                            $next['offset'] += strlen($buffer);
                            $buffer = '';
                        }
                        break;
                    }
                    $chunk = fread($handle, min(16384, 65536 - $readBytes));
                    if ($chunk === false) {
                        return $blocked('UNREADABLE');
                    }
                    $readBytes += strlen($chunk);
                    $buffer .= $chunk;

                    continue;
                }
                $line = substr($buffer, 0, $newline + 1);
                $buffer = substr($buffer, $newline + 1);
                $previousSequence = $parser->lastSequence();
                foreach ($parser->feed($line) as $event) {
                    $events[] = ['event' => $event, 'wire_sequence' => $parser->lastSequence() > $previousSequence ? $parser->lastSequence() : null];
                }
                hash_update($prefix, $line);
                $next['offset'] += strlen($line);
                $lines++;
            }
            $next['wire_sequence'] = $parser->lastSequence();
            $next['prefix_sha256'] = hash_final($prefix);
            $checkedPrefix = $this->prefix($handle, $next['offset']);
            $after = fstat($handle);
            clearstatcache(true, $path);
            $pathStat = @lstat($path);
            if ($checkedPrefix === null || ! hash_equals($next['prefix_sha256'], hash_final($checkedPrefix))
                || $after === false || $after['size'] < $next['offset'] || $after['nlink'] !== 1 || $pathStat === false
                || $pathStat['ino'] !== $opened['ino'] || $pathStat['dev'] !== $opened['dev']) {
                return $blocked('ALTERED');
            }

            return ['cursor' => $next, 'health' => 'OK', 'complete' => $next['offset'] === $after['size'], 'events' => $events];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function prefix($handle, int $bytes): ?\HashContext
    {
        if (fseek($handle, 0) !== 0) {
            return null;
        }
        $hash = hash_init('sha256');
        while ($bytes > 0) {
            $chunk = fread($handle, min(65536, $bytes));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            hash_update($hash, $chunk);
            $bytes -= strlen($chunk);
        }

        return $hash;
    }
}
