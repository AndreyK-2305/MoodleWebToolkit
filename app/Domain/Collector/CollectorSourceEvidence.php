<?php

namespace App\Domain\Collector;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/** Standalone, bounded LAB evidence. Only aggregate fingerprints leave this class. */
final class CollectorSourceEvidence
{
    /** @return array{sha256: string, entries: int, write_access_denied: bool, read_only_mount: bool} */
    public function fingerprint(string $code): array
    {
        clearstatcache();
        if (realpath($code) !== $code || ! is_dir($code) || is_link($code) || ! is_readable($code)) {
            throw new RuntimeException('Protected source cannot be measured.');
        }
        $rows = [];
        $denied = ! is_writable($code);
        $mounts = $this->mounts();
        $readOnly = $this->readOnlyMount($code, $mounts);
        $bytes = 0;
        $paths = [$code];
        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($code, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                $paths[] = $entry->getPathname();
                if (count($paths) > 1000000) {
                    throw new RuntimeException('Protected source exceeds LAB measurement policy.');
                }
            }
        } catch (Throwable) {
            throw new RuntimeException('Protected source cannot be measured.');
        }
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false || is_link($path) || ! is_readable($path)) {
                throw new RuntimeException('Protected source cannot be measured.');
            }
            $kind = $stat['mode'] & 0170000;
            $denied = $denied && ! is_writable($path);
            $readOnly = $readOnly && $this->readOnlyMount($path, $mounts);
            $relative = $path === $code ? '.' : substr($path, strlen($code) + 1);
            if ($kind === 0040000) {
                $rows[$relative] = ['directory', $stat['mode'] & 0777];
            } elseif ($kind === 0100000 && $stat['nlink'] === 1) {
                $bytes += $stat['size'];
                if ($bytes > 21474836480) {
                    throw new RuntimeException('Protected source exceeds LAB measurement policy.');
                }
                $handle = @fopen($path, 'rb');
                if ($handle === false) {
                    throw new RuntimeException('Protected source cannot be measured.');
                }
                try {
                    $opened = fstat($handle);
                    $digest = hash_init('sha256');
                    $read = @hash_update_stream($digest, $handle);
                    $closed = fstat($handle);
                    clearstatcache(true, $path);
                    if ($opened === false || $closed === false || $read !== $stat['size']
                        || $this->identity($stat) !== $this->identity($opened)
                        || $this->identity($stat) !== $this->identity($closed)
                        || $this->identity($stat) !== $this->identity(@lstat($path))) {
                        throw new RuntimeException('Protected source changed during measurement.');
                    }
                    $rows[$relative] = ['file', $stat['mode'] & 0777, $stat['size'], hash_final($digest)];
                } finally {
                    fclose($handle);
                }
            } else {
                throw new RuntimeException('Protected source contains unsupported entries.');
            }
        }
        ksort($rows, SORT_STRING);

        return ['sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'entries' => count($rows), 'write_access_denied' => $denied, 'read_only_mount' => $readOnly];
    }

    /** @param array{sha256: string, entries: int, write_access_denied: bool, read_only_mount: bool} $measurement */
    public function assertProtected(array $measurement): void
    {
        if (! $measurement['write_access_denied']) {
            throw new RuntimeException('Protected source is writable by the collector process.');
        }
        if (! $measurement['read_only_mount']) {
            throw new RuntimeException('A read-only source mount could not be verified.');
        }
    }

    /**
     * @param  array{sha256: string, entries: int, write_access_denied: bool, read_only_mount: bool}  $before
     * @param  array{sha256: string, entries: int, write_access_denied: bool, read_only_mount: bool}  $after
     * @return array<string, mixed>
     */
    public function compare(array $before, array $after): array
    {
        if (! hash_equals($before['sha256'], $after['sha256']) || $before['entries'] !== $after['entries']) {
            throw new RuntimeException('Protected source was mutated.');
        }
        $this->assertProtected($before);
        $this->assertProtected($after);
        $evidence = ['schema_version' => 'collector-source-access.v1',
            'source_code_write' => 'PROHIBITED_AND_ENFORCED', 'source_data_write' => 'TEMPORARY_ALLOWED',
            'source_database_mutation' => 'NOT_VERIFIED', 'destination_write' => 'NOT_APPLICABLE',
            'code' => ['method' => 'SHA256_CANONICAL_TREE_V1', 'scope' => 'MOODLE_CODE_ALL_ENTRIES',
                'interval' => 'BEFORE_EXPORT_TO_AFTER_EXPORT_AND_PACKAGE_AUDIT', 'result' => 'VERIFIED_UNCHANGED',
                'enforcement' => 'READ_ONLY_MOUNT_OBSERVED_ALL_ENTRIES_AND_EFFECTIVE_WRITE_ACCESS_DENIED',
                'before_sha256' => $before['sha256'], 'after_sha256' => $after['sha256'], 'entries' => $before['entries']],
            'data' => $this->dataPolicy(), 'database' => $this->databasePolicy(), 'destination' => $this->destinationPolicy()];
        $this->validate($evidence);

        return $evidence;
    }

    /**
     * Closed contract: no paths, producer booleans or unmeasured favorable states.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function validate(array $evidence): void
    {
        $code = $evidence['code'] ?? null;
        if (array_diff(array_keys($evidence), ['schema_version', 'source_code_write', 'source_data_write', 'source_database_mutation', 'destination_write', 'code', 'data', 'database', 'destination']) !== []
            || ($evidence['schema_version'] ?? null) !== 'collector-source-access.v1'
            || ($evidence['source_code_write'] ?? null) !== 'PROHIBITED_AND_ENFORCED'
            || ($evidence['source_data_write'] ?? null) !== 'TEMPORARY_ALLOWED'
            || ($evidence['source_database_mutation'] ?? null) !== 'NOT_VERIFIED'
            || ($evidence['destination_write'] ?? null) !== 'NOT_APPLICABLE'
            || ! is_array($code) || array_diff(array_keys($code), ['method', 'scope', 'interval', 'result', 'enforcement', 'before_sha256', 'after_sha256', 'entries']) !== []
            || ($code['method'] ?? null) !== 'SHA256_CANONICAL_TREE_V1' || ($code['scope'] ?? null) !== 'MOODLE_CODE_ALL_ENTRIES'
            || ($code['interval'] ?? null) !== 'BEFORE_EXPORT_TO_AFTER_EXPORT_AND_PACKAGE_AUDIT'
            || ($code['result'] ?? null) !== 'VERIFIED_UNCHANGED'
            || ($code['enforcement'] ?? null) !== 'READ_ONLY_MOUNT_OBSERVED_ALL_ENTRIES_AND_EFFECTIVE_WRITE_ACCESS_DENIED'
            || ! is_string($code['before_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $code['before_sha256']) !== 1
            || ($code['after_sha256'] ?? null) !== $code['before_sha256']
            || ! is_int($code['entries'] ?? null) || $code['entries'] < 1 || $code['entries'] > 1000000
            || ! $this->sameFields($this->dataPolicy(), $evidence['data'] ?? null)
            || ! $this->sameFields($this->databasePolicy(), $evidence['database'] ?? null)
            || ! $this->sameFields($this->destinationPolicy(), $evidence['destination'] ?? null)) {
            throw new RuntimeException('Source access evidence is missing, unsupported or unverified.');
        }
    }

    /** @return array<string, string> */
    private function dataPolicy(): array
    {
        // Moodle official backup writes caches/locks/temporary data and a stored_file.
        // The copied collector attempts stored_file deletion and controller destroy;
        // neither Moodle garbage collection nor cleanup success is observed here.
        return ['method' => 'MOODLE_742_OFFICIAL_BACKUP_RUNTIME_CONTRACT', 'scope' => 'MOODLEDATA_BACKUP_TEMP_CACHE_LOCKS_AND_FILEPOOL',
            'result' => 'NOT_VERIFIED', 'cleanup' => 'NOT_VERIFIED'];
    }

    /** @return array<string, string> */
    private function databasePolicy(): array
    {
        return ['method' => 'NO_DATABASE_MUTATION_OBSERVER', 'scope' => 'MOODLE_DATABASE', 'result' => 'NOT_VERIFIED'];
    }

    /** @return array<string, string> */
    private function destinationPolicy(): array
    {
        return ['method' => 'COLLECT_LAB_HAS_NO_DESTINATION', 'scope' => 'NONE', 'result' => 'NOT_APPLICABLE'];
    }

    /** @param array<string, string> $expected */
    private function sameFields(array $expected, mixed $actual): bool
    {
        if (! is_array($actual) || count($actual) !== count($expected)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (($actual[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, bool> */
    private function mounts(): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return [];
        }
        $content = @file_get_contents('/proc/self/mountinfo', false, null, 0, 1048577);
        if (! is_string($content) || $content === '' || strlen($content) > 1048576) {
            return [];
        }
        $mounts = [];
        foreach (explode("\n", trim($content)) as $line) {
            $fields = explode(' ', $line);
            if (count($fields) < 10 || ! in_array('-', $fields, true) || ! str_starts_with($fields[4], '/')) {
                return [];
            }
            $mountpoint = strtr($fields[4], ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
            // Mount flags, rather than chmod, enforce write denial even for owners.
            $readOnly = in_array('ro', explode(',', $fields[5]), true);
            $mounts[$mountpoint] = ($mounts[$mountpoint] ?? true) && $readOnly;
        }
        uksort($mounts, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $mounts;
    }

    /** @param array<string, bool> $mounts */
    private function readOnlyMount(string $path, array $mounts): bool
    {
        foreach ($mounts as $mountpoint => $readOnly) {
            if ($mountpoint === '/' || $path === $mountpoint || str_starts_with($path, $mountpoint.'/')) {
                return $readOnly;
            }
        }

        return false;
    }

    /**
     * @param  array<string|int, int>|false  $stat
     * @return array<string|int, int>
     */
    private function identity(array|false $stat): array
    {
        return $stat === false ? [] : array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime']));
    }
}
