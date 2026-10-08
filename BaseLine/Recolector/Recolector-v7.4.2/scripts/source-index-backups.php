<?php
// Indexa MBZ existentes sin modificar el directorio proporcionado.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/bootstrap.php');
require(collector_moodle_config_path());
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/backup-reuse-lib.php');

function reuse_index_arg(string $name, string $default = ''): string {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? [] as $argument) {
        if (str_starts_with((string)$argument, $prefix)) {
            return substr((string)$argument, strlen($prefix));
        }
    }
    return $default;
}

function reuse_index_atomic_json(string $path, array $document): void {
    $temporary = $path . '.tmp.' . getmypid();
    $json = json_encode(
        $document,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    if (file_put_contents($temporary, $json, LOCK_EX) === false ||
            !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('No fue posible escribir el índice de respaldos existentes.');
    }
}

try {
    $directory = realpath(reuse_index_arg('directory'));
    $output = trim(reuse_index_arg('output'));
    $exclude = realpath(reuse_index_arg('exclude'));
    if ($directory === false || !is_dir($directory) ||
            !is_readable($directory) || !is_executable($directory) || $output === '') {
        throw new RuntimeException('directory u output inválido.');
    }
    $outputdir = dirname($output);
    if (!is_dir($outputdir) && !mkdir($outputdir, 0770, true) && !is_dir($outputdir)) {
        throw new RuntimeException('No fue posible crear el directorio del índice.');
    }
    $candidates = [];
    $candidategroups = [];
    $scanned = 0;
    $accepted = 0;
    $rejected = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->isLink() ||
                strtolower($item->getExtension()) !== 'mbz') {
            continue;
        }
        $path = $item->getRealPath();
        if ($path === false ||
                ($exclude !== false && ($path === $exclude || str_starts_with($path, $exclude . DIRECTORY_SEPARATOR)))) {
            continue;
        }
        $scanned++;
        $inspection = collector_inspect_existing_backup($path);
        if (!$inspection['valid']) {
            $rejected++;
            fwrite(
                STDOUT,
                'EXISTING_BACKUP_REJECTED file=' . basename($path) .
                ' reason=' . $inspection['reason'] . PHP_EOL
            );
            continue;
        }
        $accepted++;
        $courseid = (string)$inspection['course_id'];
        $candidategroups[$courseid][] = $inspection + ['path' => $path];
    }
    foreach ($candidategroups as $courseid => &$group) {
        usort($group, static function(array $left, array $right): int {
            $bydate = (int)$right['backup_date'] <=> (int)$left['backup_date'];
            if ($bydate !== 0) {
                return $bydate;
            }
            $bymtime = (int)$right['mtime'] <=> (int)$left['mtime'];
            if ($bymtime !== 0) {
                return $bymtime;
            }
            return strcmp((string)$left['path'], (string)$right['path']);
        });
        $candidates[$courseid] = $group[0];
    }
    unset($group);
    ksort($candidates, SORT_NUMERIC);
    ksort($candidategroups, SORT_NUMERIC);
    $alternatives = max(0, $accepted - count($candidategroups));
    reuse_index_atomic_json($output, [
        'schema_version' => '1.1',
        'package_type' => 'moodle-existing-backups-index',
        'generated_at_utc' => gmdate('c'),
        'directory' => $directory,
        'counts' => [
            'scanned' => $scanned,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'selected_courses' => count($candidates),
            'alternatives' => $alternatives,
        ],
        // Se conserva candidates con el candidato más reciente para lectores
        // de la RC2. La versión actual prueba candidate_groups en orden hasta
        // hallar el respaldo más reciente que cumpla el perfil efectivo.
        'candidates' => $candidates,
        'candidate_groups' => $candidategroups,
    ]);
    fwrite(
        STDOUT,
        'EXISTING_BACKUPS_INDEX_OK scanned=' . $scanned .
        ' accepted=' . $accepted .
        ' rejected=' . $rejected .
        ' selected_courses=' . count($candidates) .
        ' alternatives=' . $alternatives . PHP_EOL
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'EXISTING_BACKUPS_INDEX_ERROR ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
