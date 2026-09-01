<?php
// Sella y comprime un paquete de origen para el consolidador v7.

declare(strict_types=1);

require_once(__DIR__ . '/identity-contract.php');

function source_seal_arg(string $name, ?string $default = null): ?string {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? [] as $argument) {
        if (str_starts_with((string)$argument, $prefix)) {
            return substr((string)$argument, strlen($prefix));
        }
    }
    return $default;
}

function source_seal_read_json(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException('Falta ' . $path . '.');
    }
    $decoded = json_decode(
        (string)file_get_contents($path),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($decoded)) {
        throw new RuntimeException($path . ' no contiene un objeto JSON.');
    }
    return $decoded;
}

function source_seal_write_json(string $path, array $value): void {
    $json = json_encode(
        $value,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );
    if (file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('No fue posible escribir ' . $path . '.');
    }
}

function source_seal_relative(string $root, string $path): string {
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    if (!str_starts_with($path, $root . '/')) {
        throw new RuntimeException('Ruta fuera del paquete: ' . $path . '.');
    }
    $relative = substr($path, strlen($root) + 1);
    if ($relative === '' ||
            str_starts_with($relative, '/') ||
            str_contains($relative, '../')) {
        throw new RuntimeException('Ruta insegura: ' . $relative . '.');
    }
    return $relative;
}

function source_seal_is_sha256(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
}

/**
 * @return array{bytes:int,mtime:int}
 */
function source_seal_file_metadata(string $path, string $label): array {
    clearstatcache(true, $path);
    if (!is_file($path) || is_link($path) || !is_readable($path)) {
        throw new RuntimeException($label . ' no existe o no es un archivo regular.');
    }
    $bytes = filesize($path);
    $mtime = filemtime($path);
    if ($bytes === false || $bytes < 1 || $mtime === false) {
        throw new RuntimeException($label . ' no conserva metadatos válidos.');
    }
    return ['bytes' => (int)$bytes, 'mtime' => (int)$mtime];
}

/**
 * Comprueba que ningún artefacto cambió mientras ZipArchive lo consumía.
 *
 * @param array<string,array{bytes:int,mtime:int}> $expected
 * @param array<string,string> $files
 */
function source_seal_assert_files_unchanged(array $expected, array $files): void {
    foreach ($files as $relative => $path) {
        $actual = source_seal_file_metadata($path, $relative);
        if (!isset($expected[$relative]) ||
                $expected[$relative]['bytes'] !== $actual['bytes'] ||
                $expected[$relative]['mtime'] !== $actual['mtime']) {
            throw new RuntimeException(
                'El artefacto cambió durante el sellado: ' . $relative . '.'
            );
        }
    }
}

$temporaryzip = null;
$temporarysha = null;
$zip = null;

try {
    $inputdir = realpath((string)source_seal_arg('inputdir', ''));
    $outputzip = trim((string)source_seal_arg('outputzip', ''));
    $sourceid = strtolower(trim((string)source_seal_arg('sourceid', '')));
    if ($inputdir === false ||
            !is_dir($inputdir) ||
            $outputzip === '' ||
            !preg_match('/^[a-z][a-z0-9_-]*$/', $sourceid)) {
        throw new RuntimeException('inputdir, outputzip o sourceid inválido.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('La extensión PHP ZipArchive no está disponible.');
    }

    $identitypath = $inputdir . '/identidades.json';
    $inventorypath = $inputdir . '/inventario-origen.json';
    $pluginspath = $inputdir . '/plugins.json';
    $identity = source_seal_read_json($identitypath);
    $inventory = source_seal_read_json($inventorypath);
    $plugins = source_seal_read_json($pluginspath);
    if (($identity['metadata']['source'] ?? '') !== $sourceid ||
            ($identity['metadata']['schema_version'] ?? '') !==
                COLLECTOR_IDENTITY_SCHEMA_VERSION ||
            ($inventory['source_id'] ?? '') !== $sourceid ||
            ($plugins['source_id'] ?? '') !== $sourceid ||
            ($inventory['write_performed'] ?? null) !== false ||
            ($plugins['write_performed'] ?? null) !== false) {
        throw new RuntimeException(
            'Los artefactos base no corresponden al mismo origen.'
        );
    }
    $identityerrors = collector_validate_identity_payload($identity);
    if ($identityerrors) {
        throw new RuntimeException(
            'identidades.json no cumple el contrato: ' .
            implode(' ', $identityerrors)
        );
    }

    $identitysha = hash_file('sha256', $identitypath);
    $inventorysha = hash_file('sha256', $inventorypath);
    $pluginssha = hash_file('sha256', $pluginspath);
    if (!source_seal_is_sha256($identitysha) ||
            !source_seal_is_sha256($inventorysha) ||
            !source_seal_is_sha256($pluginssha)) {
        throw new RuntimeException('No fue posible calcular un hash base.');
    }
    $files = [
        'identidades.json' => $identitypath,
        'inventario-origen.json' => $inventorypath,
        'plugins.json' => $pluginspath,
    ];
    $filehashes = [
        'identidades.json' => $identitysha,
        'inventario-origen.json' => $inventorysha,
        'plugins.json' => $pluginssha,
    ];
    $filemetadata = [
        'identidades.json' =>
            source_seal_file_metadata($identitypath, 'identidades.json'),
        'inventario-origen.json' =>
            source_seal_file_metadata($inventorypath, 'inventario-origen.json'),
        'plugins.json' =>
            source_seal_file_metadata($pluginspath, 'plugins.json'),
    ];
    $entries = [];
    $expectedkeys = [];
    $expectedcourseids = [];
    foreach ($inventory['courses'] ?? [] as $course) {
        $courseid = (int)($course['source_course_id'] ?? 0);
        $source = strtoupper((string)preg_replace(
            '/[^a-z0-9_-]+/i',
            '-',
            $sourceid
        ));
        $coursekey = 'COURSE-' . $source . '-' . strtoupper(substr(
            hash('sha256', $sourceid . '|course|' . $courseid),
            0,
            12
        ));
        if ($courseid < 1 ||
                isset($expectedkeys[$coursekey]) ||
                isset($expectedcourseids[$courseid])) {
            throw new RuntimeException(
                'El inventario contiene un curso inválido o repetido.'
            );
        }
        $expectedkeys[$coursekey] = true;
        $expectedcourseids[$courseid] = true;
        $basename = strtolower($coursekey);
        $checkpointpath =
            $inputdir . '/checkpoints/checkpoint-' . $basename . '.json';
        $checkpoint = source_seal_read_json($checkpointpath);
        $backuppath = $inputdir . '/' .
            ltrim((string)($checkpoint['backup_file'] ?? ''), '/\\');
        $detailpath = $inputdir . '/' .
            ltrim((string)($checkpoint['inventory_file'] ?? ''), '/\\');
        $backuprelative = source_seal_relative($inputdir, $backuppath);
        $detailrelative = source_seal_relative($inputdir, $detailpath);
        $checkpointrelative =
            source_seal_relative($inputdir, $checkpointpath);
        foreach ([
            $backuprelative => $backuppath,
            $detailrelative => $detailpath,
            $checkpointrelative => $checkpointpath,
        ] as $relative => $path) {
            if (isset($files[$relative])) {
                throw new RuntimeException(
                    'Dos cursos intentan usar el artefacto ' . $relative . '.'
                );
            }
            $files[$relative] = $path;
        }
        $backupmeta = source_seal_file_metadata($backuppath, $backuprelative);
        $detailmeta = source_seal_file_metadata($detailpath, $detailrelative);
        $checkpointmeta = source_seal_file_metadata(
            $checkpointpath,
            $checkpointrelative
        );
        $backupsha = $checkpoint['backup_sha256'] ?? null;
        $detailsha = $checkpoint['inventory_sha256'] ?? null;
        $checkpointsha = hash_file('sha256', $checkpointpath);
        if (!source_seal_is_sha256($backupsha) ||
                !source_seal_is_sha256($detailsha) ||
                !source_seal_is_sha256($checkpointsha) ||
                ($checkpoint['schema_version'] ?? '') !== '1.0' ||
                ($checkpoint['package_type'] ?? '') !==
                    'moodle-consolidation-source-course' ||
                ($checkpoint['source_id'] ?? '') !== $sourceid ||
                ($checkpoint['course_key'] ?? '') !== $coursekey ||
                (int)($checkpoint['source_course_id'] ?? 0) !== $courseid ||
                ($checkpoint['status'] ?? '') !== 'prepared' ||
                (int)($checkpoint['backup_bytes'] ?? -1) !==
                    $backupmeta['bytes'] ||
                (int)($checkpoint['backup_mtime'] ?? -1) !==
                    $backupmeta['mtime'] ||
                (int)($checkpoint['inventory_bytes'] ?? -1) !==
                    $detailmeta['bytes'] ||
                (int)($checkpoint['inventory_mtime'] ?? -1) !==
                    $detailmeta['mtime']) {
            throw new RuntimeException(
                'El checkpoint de ' . $coursekey . ' perdió integridad.'
            );
        }
        $filehashes[$backuprelative] = $backupsha;
        $filehashes[$detailrelative] = $detailsha;
        $filehashes[$checkpointrelative] = $checkpointsha;
        $filemetadata[$backuprelative] = $backupmeta;
        $filemetadata[$detailrelative] = $detailmeta;
        $filemetadata[$checkpointrelative] = $checkpointmeta;
        $entries[] = [
            'course_key' => $coursekey,
            'source_course_id' => $courseid,
            'source_course_idnumber' =>
                (string)($course['idnumber'] ?? ''),
            'source_shortname' => (string)($course['shortname'] ?? ''),
            'backup_file' => $backuprelative,
            'backup_sha256' => $backupsha,
            'backup_bytes' => $backupmeta['bytes'],
            'backup_origin' => (string)($checkpoint['backup_origin'] ?? 'generated'),
            'reuse_policy' => (string)($checkpoint['reuse_policy'] ?? 'prefer'),
            'existing_backup_name' => (string)($checkpoint['existing_backup_name'] ?? ''),
            'existing_backup_date' => (int)($checkpoint['existing_backup_date'] ?? 0),
            'existing_backup_mode' => (string)($checkpoint['existing_backup_mode'] ?? ''),
            'existing_backup_type' => (string)($checkpoint['existing_backup_type'] ?? ''),
            'existing_backup_settings_sha256' =>
                (string)($checkpoint['existing_backup_settings_sha256'] ?? ''),
            'existing_backup_profile_warnings' =>
                is_array($checkpoint['existing_backup_profile_warnings'] ?? null)
                    ? $checkpoint['existing_backup_profile_warnings']
                    : [],
            'backup_profile_sha256' => (string)($checkpoint['backup_profile_sha256'] ?? ''),
            'inventory_file' => $detailrelative,
            'inventory_sha256' => $detailsha,
            'checkpoint_file' => $checkpointrelative,
            'checkpoint_sha256' => $checkpointsha,
        ];
    }
    usort(
        $entries,
        static fn(array $left, array $right): int =>
            $left['source_course_id'] <=> $right['source_course_id']
    );
    if (count($entries) !== (int)($inventory['counts']['courses'] ?? -1)) {
        throw new RuntimeException(
            'El número de backups no coincide con el inventario del origen.'
        );
    }

    $manifestpath = $inputdir . '/manifest.json';
    $manifest = [
        'schema_version' => '1.0',
        'package_type' => 'moodle-consolidation-source',
        'collector_version' => '7.4.1-linux',
        'hash_strategy' => 'single-pass-v1',
        'generated_at_utc' => gmdate('c'),
        'source_id' => $sourceid,
        'source_name' => (string)($inventory['source_name'] ?? $sourceid),
        'source_wwwroot' => (string)($inventory['source_wwwroot'] ?? ''),
        'source_moodle_version' =>
            (string)($inventory['source_moodle_version'] ?? ''),
        'source_moodle_release' =>
            (string)($inventory['source_moodle_release'] ?? ''),
        'identity_scope' =>
            (string)($identity['metadata']['scope'] ?? ''),
        'identity_schema_version' =>
            (string)($identity['metadata']['schema_version'] ?? ''),
        'identity_file' => 'identidades.json',
        'identity_sha256' => $identitysha,
        'source_inventory_file' => 'inventario-origen.json',
        'source_inventory_sha256' => $inventorysha,
        'plugins_file' => 'plugins.json',
        'plugins_sha256' => $pluginssha,
        'courses_expected' => count($entries),
        'entries' => $entries,
        'package_status' => 'sealed',
        'source_write_performed' => false,
        'destination_write_performed' => false,
    ];
    source_seal_write_json($manifestpath, $manifest);
    $files['manifest.json'] = $manifestpath;
    $filehashes['manifest.json'] = hash_file('sha256', $manifestpath);
    if (!source_seal_is_sha256($filehashes['manifest.json'])) {
        throw new RuntimeException('No fue posible calcular SHA-256 del manifiesto.');
    }
    $filemetadata['manifest.json'] =
        source_seal_file_metadata($manifestpath, 'manifest.json');
    ksort($files, SORT_STRING);
    $checksumlines = [];
    foreach ($files as $relative => $_path) {
        $hash = $filehashes[$relative] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/', (string)$hash)) {
            throw new RuntimeException(
                'No fue posible reutilizar el hash de ' . $relative . '.'
            );
        }
        $checksumlines[] = $hash . '  ' . $relative;
    }
    $checksumspath = $inputdir . '/checksums.sha256';
    if (file_put_contents(
        $checksumspath,
        implode(PHP_EOL, $checksumlines) . PHP_EOL
    ) === false) {
        throw new RuntimeException('No fue posible escribir checksums.sha256.');
    }
    $files['checksums.sha256'] = $checksumspath;
    $filemetadata['checksums.sha256'] =
        source_seal_file_metadata($checksumspath, 'checksums.sha256');
    ksort($files, SORT_STRING);

    $outputdir = dirname($outputzip);
    if (!is_dir($outputdir) &&
            !mkdir($outputdir, 0770, true) &&
            !is_dir($outputdir)) {
        throw new RuntimeException('No fue posible crear ' . $outputdir . '.');
    }
    $temporaryzip = $outputzip . '.partial';
    $outputsha = $outputzip . '.sha256';
    $temporarysha = $outputsha . '.partial';
    if (is_file($temporaryzip)) {
        unlink($temporaryzip);
    }
    if (is_file($temporarysha)) {
        unlink($temporarysha);
    }
    $zip = new ZipArchive();
    $status = $zip->open(
        $temporaryzip,
        ZipArchive::CREATE | ZipArchive::OVERWRITE
    );
    if ($status !== true) {
        throw new RuntimeException(
            'ZipArchive no pudo crear el paquete: código ' . $status . '.'
        );
    }
    foreach ($files as $relative => $path) {
        if (!$zip->addFile($path, $relative)) {
            throw new RuntimeException('No fue posible agregar ' . $relative . '.');
        }
        if (str_ends_with($relative, '.mbz') &&
                !$zip->setCompressionName($relative, ZipArchive::CM_STORE)) {
            throw new RuntimeException(
                'No fue posible evitar la recompresión de ' . $relative . '.'
            );
        }
    }
    if (!$zip->close() || !is_readable($temporaryzip)) {
        throw new RuntimeException('No fue posible cerrar el ZIP del origen.');
    }
    $zip = null;
    source_seal_assert_files_unchanged($filemetadata, $files);

    $zipsha = hash_file('sha256', $temporaryzip);
    if (!source_seal_is_sha256($zipsha)) {
        throw new RuntimeException('No fue posible calcular SHA-256 del ZIP final.');
    }
    if (file_put_contents(
        $temporarysha,
        $zipsha . '  ' . basename($outputzip) . PHP_EOL
    ) === false) {
        throw new RuntimeException('No fue posible escribir el SHA-256 externo.');
    }
    if (is_file($outputsha) && !unlink($outputsha)) {
        throw new RuntimeException('No fue posible reemplazar el SHA-256 anterior.');
    }
    if (!rename($temporaryzip, $outputzip)) {
        throw new RuntimeException('No fue posible publicar el ZIP sellado.');
    }
    $temporaryzip = null;
    if (!rename($temporarysha, $outputsha)) {
        @unlink($outputzip);
        throw new RuntimeException('No fue posible publicar el SHA-256 externo.');
    }
    $temporarysha = null;

    fwrite(
        STDOUT,
        'SOURCE_PACKAGE_OK source=' . $sourceid .
        ' courses=' . count($entries) .
        ' sha256=' . $zipsha .
        ' sidecar=' . $outputsha .
        ' write=0' . PHP_EOL
    );
} catch (Throwable $error) {
    if ($zip instanceof ZipArchive) {
        try {
            @$zip->close();
        } catch (Throwable $ignored) {
            // El ZIP parcial se elimina abajo aunque el objeto ya esté cerrado.
        }
    }
    foreach ([$temporaryzip, $temporarysha] as $partial) {
        if (is_string($partial) && is_file($partial)) {
            @unlink($partial);
        }
    }
    fwrite(STDERR, 'SOURCE_PACKAGE_ERROR ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
