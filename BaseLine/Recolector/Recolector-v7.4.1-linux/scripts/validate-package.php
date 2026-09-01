<?php
// Auditoría exhaustiva e independiente de un paquete del recolector Moodle.

declare(strict_types=1);

require_once(__DIR__ . '/identity-contract.php');

const COLLECTOR_VALIDATOR_VERSION = '7.4.1-linux';

function audit_arg(string $name, ?string $default = null): ?string {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? [] as $argument) {
        if (str_starts_with((string)$argument, $prefix)) {
            return substr((string)$argument, strlen($prefix));
        }
    }
    return $default;
}

function audit_is_sha256(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
}

function audit_failure(
    array &$report,
    string $type,
    string $message,
    ?string $path = null,
    mixed $expected = null,
    mixed $actual = null
): void {
    $issue = ['type' => $type, 'message' => $message];
    if ($path !== null && $path !== '') {
        $issue['path'] = $path;
    }
    if ($expected !== null) {
        $issue['expected'] = $expected;
    }
    if ($actual !== null) {
        $issue['actual'] = $actual;
    }
    $report['failures'][] = $issue;
}

function audit_warning(
    array &$report,
    string $type,
    string $message,
    ?string $path = null
): void {
    $issue = ['type' => $type, 'message' => $message];
    if ($path !== null && $path !== '') {
        $issue['path'] = $path;
    }
    $report['warnings'][] = $issue;
}

function audit_assert_safe_path(string $path): void {
    if ($path === '' || trim($path) === '' ||
            str_contains($path, '\\') ||
            str_starts_with($path, '/') ||
            preg_match('/^[a-zA-Z]:/', $path) === 1 ||
            preg_match('/[<>:"|?*\x00-\x1F]/', $path) === 1) {
        throw new RuntimeException('Ruta insegura: ' . $path . '.');
    }
    if (class_exists('Normalizer') &&
            Normalizer::normalize($path, Normalizer::FORM_C) !== $path) {
        throw new RuntimeException('Ruta Unicode no normalizada: ' . $path . '.');
    }
    $segments = explode('/', $path);
    foreach ($segments as $segment) {
        if ($segment === '' || trim($segment) === '' ||
                $segment === '.' || $segment === '..' ||
                rtrim($segment, " .") !== $segment ||
                preg_match(
                    '/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?$/i',
                    $segment
                ) === 1) {
            throw new RuntimeException('Segmento inseguro en ' . $path . '.');
        }
    }
}

/**
 * @return array{sha256:string,bytes:int}
 */
function audit_hash_zip_entry(ZipArchive $zip, string $path): array {
    $stream = $zip->getStream($path);
    if ($stream === false) {
        throw new RuntimeException('No fue posible abrir la entrada.');
    }
    $context = hash_init('sha256');
    $bytes = 0;
    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 8 * 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Falló la lectura de la entrada.');
            }
            if ($chunk === '') {
                if (feof($stream)) {
                    break;
                }
                throw new RuntimeException('La lectura de la entrada no avanzó.');
            }
            hash_update($context, $chunk);
            $bytes += strlen($chunk);
        }
    } finally {
        fclose($stream);
    }
    return ['sha256' => hash_final($context), 'bytes' => $bytes];
}

function audit_read_zip_entry(ZipArchive $zip, string $path): string {
    $stream = $zip->getStream($path);
    if ($stream === false) {
        throw new RuntimeException('No fue posible abrir ' . $path . '.');
    }
    $content = '';
    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Falló la lectura de ' . $path . '.');
            }
            if ($chunk === '') {
                if (feof($stream)) {
                    break;
                }
                throw new RuntimeException('La lectura no avanzó en ' . $path . '.');
            }
            $content .= $chunk;
        }
    } finally {
        fclose($stream);
    }
    return $content;
}

function audit_read_json(ZipArchive $zip, string $path): array {
    $decoded = json_decode(
        audit_read_zip_entry($zip, $path),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($decoded)) {
        throw new RuntimeException($path . ' no contiene un objeto JSON.');
    }
    return $decoded;
}

function audit_canonicalize(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('audit_canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = audit_canonicalize($item);
    }
    return $value;
}

function audit_value_sha256(mixed $value): string {
    return hash('sha256', json_encode(
        audit_canonicalize($value),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRESERVE_ZERO_FRACTION |
        JSON_THROW_ON_ERROR
    ));
}

/**
 * Recupera los tipos numéricos definidos por el esquema del inventario.
 *
 * La RC1 escribía 100.0 como 100 aunque el hash de origen se había calculado
 * sobre un float. Normalizar solamente los dos campos que el recolector crea
 * explícitamente como float permite auditar esos paquetes sin relajar el resto
 * del contrato.
 */
function audit_normalize_course_inventory_types(mixed $inventory): mixed {
    if (!is_array($inventory)) {
        return $inventory;
    }
    foreach (($inventory['relations']['assignment_grades'] ?? []) as $index => $row) {
        if (is_array($row) &&
                array_key_exists('grade', $row) &&
                (is_int($row['grade']) || is_float($row['grade']))) {
            $inventory['relations']['assignment_grades'][$index]['grade'] =
                (float)$row['grade'];
        }
    }
    foreach (($inventory['relations']['quiz_attempts'] ?? []) as $index => $row) {
        if (is_array($row) &&
                array_key_exists('sumgrades', $row) &&
                $row['sumgrades'] !== null &&
                (is_int($row['sumgrades']) || is_float($row['sumgrades']))) {
            $inventory['relations']['quiz_attempts'][$index]['sumgrades'] =
                (float)$row['sumgrades'];
        }
    }
    return $inventory;
}

function audit_write_report(string $path, array $report): void {
    $json = json_encode(
        $report,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );
    $temporary = $path . '.tmp.' . getmypid();
    if (file_put_contents($temporary, $json . PHP_EOL) === false ||
            !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('No fue posible escribir el reporte ' . $path . '.');
    }
}

$started = microtime(true);
$zip = null;
$reportpath = trim((string)audit_arg('report', ''));
$report = [
    'schema_version' => '1.0',
    'validator_version' => COLLECTOR_VALIDATOR_VERSION,
    'generated_at_utc' => gmdate('c'),
    'package' => '',
    'result' => 'error',
    'outer_zip' => [
        'bytes' => 0,
        'sha256' => '',
        'sidecar_status' => 'not_checked',
    ],
    'counts' => [
        'zip_files' => 0,
        'checksums_listed' => 0,
        'files_checked' => 0,
        'files_ok' => 0,
        'files_failed' => 0,
        'bytes_hashed_inside_zip' => 0,
        'courses_manifest' => 0,
        'courses_cross_checked' => 0,
    ],
    'failures' => [],
    'warnings' => [],
];

try {
    $zippath = realpath(trim((string)audit_arg('zip', '')));
    $sidecarpath = trim((string)audit_arg('sidecar', ''));
    if ($zippath === false || !is_file($zippath) || !is_readable($zippath) ||
            $reportpath === '') {
        throw new RuntimeException('Use --zip y --report con rutas válidas.');
    }
    $report['package'] = basename($zippath);
    $zipbytes = filesize($zippath);
    $zipsha = hash_file('sha256', $zippath);
    if ($zipbytes === false || $zipbytes < 1 || !audit_is_sha256($zipsha)) {
        throw new RuntimeException('No fue posible calcular SHA-256 del ZIP.');
    }
    $report['outer_zip']['bytes'] = (int)$zipbytes;
    $report['outer_zip']['sha256'] = $zipsha;
    fwrite(
        STDOUT,
        'VALIDACION_ZIP_SHA sha256=' . $zipsha .
        ' bytes=' . (int)$zipbytes . PHP_EOL
    );

    if ($sidecarpath !== '' && is_readable($sidecarpath)) {
        $sidecar = file($sidecarpath, FILE_IGNORE_NEW_LINES);
        if ($sidecar === false || count($sidecar) !== 1 ||
                preg_match('/^([a-f0-9]{64})  (.+)$/', $sidecar[0], $matches) !== 1) {
            $report['outer_zip']['sidecar_status'] = 'invalid';
            audit_failure(
                $report,
                'sidecar_invalid',
                'El archivo SHA-256 externo no tiene el formato esperado.',
                basename($sidecarpath)
            );
        } else if ($matches[2] !== basename($zippath)) {
            $report['outer_zip']['sidecar_status'] = 'wrong_package';
            audit_failure(
                $report,
                'sidecar_wrong_package',
                'El SHA-256 externo referencia otro paquete.',
                basename($sidecarpath),
                basename($zippath),
                $matches[2]
            );
        } else if (!hash_equals($matches[1], $zipsha)) {
            $report['outer_zip']['sidecar_status'] = 'mismatch';
            audit_failure(
                $report,
                'zip_sha256_mismatch',
                'El SHA-256 del ZIP no coincide con el archivo externo.',
                basename($zippath),
                $matches[1],
                $zipsha
            );
        } else {
            $report['outer_zip']['sidecar_status'] = 'ok';
        }
    } else {
        $report['outer_zip']['sidecar_status'] = 'missing';
        audit_warning(
            $report,
            'sidecar_missing',
            'No existe el SHA-256 externo; se calculó el hash actual sin compararlo.'
        );
    }

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('La extensión PHP ZipArchive no está disponible.');
    }
    $zip = new ZipArchive();
    $openstatus = $zip->open($zippath, ZipArchive::CHECKCONS);
    if ($openstatus !== true) {
        throw new RuntimeException(
            'ZipArchive no pudo abrir el paquete: código ' . $openstatus . '.'
        );
    }

    $entries = [];
    $casepaths = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        $stat = $zip->statIndex($index);
        if ($name === false || $stat === false) {
            audit_failure(
                $report,
                'zip_entry_unreadable',
                'No fue posible leer los metadatos de una entrada.',
                'index:' . $index
            );
            continue;
        }
        if (str_ends_with($name, '/')) {
            continue;
        }
        try {
            audit_assert_safe_path($name);
        } catch (Throwable $error) {
            audit_failure($report, 'unsafe_path', $error->getMessage(), $name);
        }
        $casekey = strtolower($name);
        if (isset($casepaths[$casekey])) {
            audit_failure(
                $report,
                'duplicate_path',
                'El ZIP repite una ruta exacta o equivalente sin distinguir mayúsculas.',
                $name,
                $casepaths[$casekey],
                $name
            );
            continue;
        }
        $casepaths[$casekey] = $name;
        $entries[$name] = [
            'index' => $index,
            'bytes' => (int)($stat['size'] ?? -1),
            'compressed_bytes' => (int)($stat['comp_size'] ?? -1),
            'compression_method' => (int)($stat['comp_method'] ?? -1),
        ];

        if (method_exists($zip, 'getExternalAttributesIndex')) {
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes) &&
                    (($attributes >> 16) & 0xF000) === 0xA000) {
                audit_failure(
                    $report,
                    'symbolic_link',
                    'El ZIP contiene un enlace simbólico no permitido.',
                    $name
                );
            }
        }
    }
    $report['counts']['zip_files'] = count($entries);

    $checksums = [];
    if (!isset($entries['checksums.sha256'])) {
        audit_failure(
            $report,
            'checksums_missing',
            'El ZIP no contiene checksums.sha256.',
            'checksums.sha256'
        );
    } else {
        try {
            $checksumtext = audit_read_zip_entry($zip, 'checksums.sha256');
            foreach (preg_split('/\r?\n/', $checksumtext) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^([a-fA-F0-9]{64})  (.+)$/', $line, $matches) !== 1) {
                    audit_failure(
                        $report,
                        'checksum_line_invalid',
                        'checksums.sha256 contiene una línea inválida.',
                        'checksums.sha256',
                        null,
                        $line
                    );
                    continue;
                }
                $path = $matches[2];
                try {
                    audit_assert_safe_path($path);
                } catch (Throwable $error) {
                    audit_failure($report, 'unsafe_checksum_path', $error->getMessage(), $path);
                    continue;
                }
                if ($path === 'checksums.sha256') {
                    audit_failure(
                        $report,
                        'checksums_self_reference',
                        'checksums.sha256 no puede incluirse a sí mismo.',
                        $path
                    );
                    continue;
                }
                if (isset($checksums[$path])) {
                    audit_failure(
                        $report,
                        'checksum_duplicate',
                        'checksums.sha256 repite un archivo.',
                        $path
                    );
                    continue;
                }
                $checksums[$path] = strtolower($matches[1]);
            }
        } catch (Throwable $error) {
            audit_failure(
                $report,
                'checksums_unreadable',
                $error->getMessage(),
                'checksums.sha256'
            );
        }
    }
    $report['counts']['checksums_listed'] = count($checksums);

    $covered = array_fill_keys(array_keys($checksums), true);
    $covered['checksums.sha256'] = true;
    foreach (array_keys($entries) as $path) {
        if (!isset($covered[$path])) {
            audit_failure(
                $report,
                'unexpected_zip_entry',
                'El ZIP contiene un archivo no cubierto por checksums.sha256.',
                $path
            );
        }
    }
    foreach (array_keys($covered) as $path) {
        if (!isset($entries[$path])) {
            audit_failure(
                $report,
                'missing_zip_entry',
                'Falta un archivo declarado por checksums.sha256.',
                $path
            );
        }
    }

    ksort($checksums, SORT_STRING);
    $actualhashes = [];
    $position = 0;
    foreach ($checksums as $path => $expectedhash) {
        $position++;
        fwrite(
            STDOUT,
            'VALIDANDO_ARCHIVO [' . $position . '/' . count($checksums) .
            '] ' . $path . PHP_EOL
        );
        if (!isset($entries[$path])) {
            $report['counts']['files_failed']++;
            continue;
        }
        try {
            $calculated = audit_hash_zip_entry($zip, $path);
            $actualhashes[$path] = $calculated['sha256'];
            $report['counts']['files_checked']++;
            $report['counts']['bytes_hashed_inside_zip'] += $calculated['bytes'];
            if ($calculated['bytes'] !== $entries[$path]['bytes']) {
                $report['counts']['files_failed']++;
                audit_failure(
                    $report,
                    'entry_size_mismatch',
                    'El tamaño leído no coincide con el directorio central del ZIP.',
                    $path,
                    $entries[$path]['bytes'],
                    $calculated['bytes']
                );
            } else if (!hash_equals($expectedhash, $calculated['sha256'])) {
                $report['counts']['files_failed']++;
                audit_failure(
                    $report,
                    'sha256_mismatch',
                    'El SHA-256 interno no coincide.',
                    $path,
                    $expectedhash,
                    $calculated['sha256']
                );
            } else {
                $report['counts']['files_ok']++;
            }
        } catch (Throwable $error) {
            $report['counts']['files_failed']++;
            audit_failure(
                $report,
                'entry_read_error',
                $error->getMessage(),
                $path
            );
        }
    }

    $manifest = null;
    if (!isset($entries['manifest.json'])) {
        audit_failure(
            $report,
            'manifest_missing',
            'El ZIP no contiene manifest.json.',
            'manifest.json'
        );
    } else {
        try {
            $manifest = audit_read_json($zip, 'manifest.json');
        } catch (Throwable $error) {
            audit_failure(
                $report,
                'manifest_invalid',
                $error->getMessage(),
                'manifest.json'
            );
        }
    }

    $sourceid = '';
    $manifestentries = [];
    $manifestpaths = array_fill_keys([
        'identidades.json',
        'inventario-origen.json',
        'plugins.json',
        'manifest.json',
        'checksums.sha256',
    ], true);
    $courseids = [];
    $coursekeys = [];
    if (is_array($manifest)) {
        $sourceid = (string)($manifest['source_id'] ?? '');
        $scope = (string)($manifest['identity_scope'] ?? '');
        $sourcewwwroot = (string)($manifest['source_wwwroot'] ?? '');
        if (($manifest['schema_version'] ?? '') !== '1.0' ||
                ($manifest['package_type'] ?? '') !==
                    'moodle-consolidation-source' ||
                ($manifest['package_status'] ?? '') !== 'sealed' ||
                preg_match('/^[a-z][a-z0-9_-]*$/', $sourceid) !== 1 ||
                trim((string)($manifest['source_name'] ?? '')) === '' ||
                preg_match('/[\x00-\x1F]/', (string)($manifest['source_name'] ?? '')) === 1 ||
                !in_array($scope, ['lab', 'all'], true) ||
                (int)($manifest['courses_expected'] ?? 0) < 1 ||
                ($manifest['source_write_performed'] ?? null) !== false ||
                ($manifest['destination_write_performed'] ?? null) !== false) {
            audit_failure(
                $report,
                'manifest_contract_invalid',
                'El manifiesto no conserva el contrato sellado.',
                'manifest.json'
            );
        }
        if (($manifest['identity_schema_version'] ?? '') !==
                COLLECTOR_IDENTITY_SCHEMA_VERSION) {
            audit_failure(
                $report,
                'manifest_identity_schema_invalid',
                'El manifiesto no declara el contrato de identidades esperado.',
                'manifest.json:identity_schema_version',
                COLLECTOR_IDENTITY_SCHEMA_VERSION,
                $manifest['identity_schema_version'] ?? null
            );
        }
        if ($sourcewwwroot !== '' && preg_match('#^https?://#', $sourcewwwroot) !== 1) {
            audit_failure(
                $report,
                'manifest_source_url_invalid',
                'La URL de origen del manifiesto no es válida.',
                'manifest.json'
            );
        }
        foreach ([
            'identity_file' => 'identidades.json',
            'source_inventory_file' => 'inventario-origen.json',
            'plugins_file' => 'plugins.json',
        ] as $field => $expectedpath) {
            if (($manifest[$field] ?? null) !== $expectedpath) {
                audit_failure(
                    $report,
                    'manifest_base_path_invalid',
                    'El manifiesto cambió una ruta base.',
                    'manifest.json:' . $field,
                    $expectedpath,
                    $manifest[$field] ?? null
                );
            }
        }
        foreach ([
            'identity_sha256' => 'identidades.json',
            'source_inventory_sha256' => 'inventario-origen.json',
            'plugins_sha256' => 'plugins.json',
        ] as $field => $path) {
            $value = $manifest[$field] ?? null;
            if (!audit_is_sha256($value)) {
                audit_failure(
                    $report,
                    'manifest_base_sha256_invalid',
                    'El manifiesto contiene un hash base inválido.',
                    'manifest.json:' . $field
                );
            } else if (!isset($checksums[$path]) ||
                    !hash_equals($checksums[$path], $value)) {
                audit_failure(
                    $report,
                    'manifest_base_sha256_mismatch',
                    'El hash base del manifiesto no coincide con checksums.sha256.',
                    $path,
                    $checksums[$path] ?? null,
                    $value
                );
            }
        }

        $rawentries = $manifest['entries'] ?? null;
        if (!is_array($rawentries) || !array_is_list($rawentries)) {
            audit_failure(
                $report,
                'manifest_entries_invalid',
                'manifest.entries no es una lista.',
                'manifest.json'
            );
            $rawentries = [];
        }
        foreach ($rawentries as $item) {
            if (!is_array($item)) {
                audit_failure(
                    $report,
                    'manifest_course_invalid',
                    'El manifiesto contiene una entrada de curso inválida.',
                    'manifest.json'
                );
                continue;
            }
            $coursekey = (string)($item['course_key'] ?? '');
            $courseid = (int)($item['source_course_id'] ?? 0);
            if (preg_match('/^COURSE-[A-Z0-9_-]+-[A-F0-9]{12}$/', $coursekey) !== 1 ||
                    $courseid < 1 || isset($coursekeys[$coursekey]) ||
                    isset($courseids[$courseid])) {
                audit_failure(
                    $report,
                    'manifest_course_duplicate_or_invalid',
                    'El manifiesto contiene un curso inválido o repetido.',
                    $coursekey !== '' ? $coursekey : 'manifest.json'
                );
                continue;
            }
            $coursekeys[$coursekey] = true;
            $courseids[$courseid] = true;
            $validitem = true;
            foreach (['backup_file', 'inventory_file', 'checkpoint_file'] as $field) {
                $path = (string)($item[$field] ?? '');
                try {
                    audit_assert_safe_path($path);
                } catch (Throwable $error) {
                    audit_failure($report, 'manifest_path_invalid', $error->getMessage(), $path);
                    $validitem = false;
                    continue;
                }
                if (isset($manifestpaths[$path])) {
                    audit_failure(
                        $report,
                        'manifest_path_duplicate',
                        'El manifiesto repite un artefacto.',
                        $path
                    );
                    $validitem = false;
                    continue;
                }
                $manifestpaths[$path] = true;
            }
            foreach ([
                'backup_sha256' => 'backup_file',
                'inventory_sha256' => 'inventory_file',
                'checkpoint_sha256' => 'checkpoint_file',
            ] as $hashfield => $pathfield) {
                $value = $item[$hashfield] ?? null;
                $path = (string)($item[$pathfield] ?? '');
                if (!audit_is_sha256($value)) {
                    audit_failure(
                        $report,
                        'manifest_course_sha256_invalid',
                        'El manifiesto contiene un hash de curso inválido.',
                        $coursekey . ':' . $hashfield
                    );
                    $validitem = false;
                } else if (!isset($checksums[$path]) ||
                        !hash_equals($checksums[$path], $value)) {
                    audit_failure(
                        $report,
                        'manifest_course_sha256_mismatch',
                        'El hash de curso no coincide con checksums.sha256.',
                        $path,
                        $checksums[$path] ?? null,
                        $value
                    );
                    $validitem = false;
                }
            }
            $backuppath = (string)($item['backup_file'] ?? '');
            if (isset($entries[$backuppath])) {
                if ((int)($item['backup_bytes'] ?? -1) !==
                        $entries[$backuppath]['bytes']) {
                    audit_failure(
                        $report,
                        'manifest_backup_size_mismatch',
                        'El tamaño del backup no coincide con el manifiesto.',
                        $backuppath,
                        $item['backup_bytes'] ?? null,
                        $entries[$backuppath]['bytes']
                    );
                }
                if (str_ends_with($backuppath, '.mbz') &&
                        $entries[$backuppath]['compression_method'] !==
                            ZipArchive::CM_STORE) {
                    audit_failure(
                        $report,
                        'mbz_recompressed',
                        'El backup .mbz fue recomprimido dentro del ZIP exterior.',
                        $backuppath,
                        ZipArchive::CM_STORE,
                        $entries[$backuppath]['compression_method']
                    );
                }
            }
            if ($validitem) {
                $manifestentries[] = $item;
            }
        }
        $report['counts']['courses_manifest'] = count($rawentries);
        if (count($rawentries) !== (int)($manifest['courses_expected'] ?? -1)) {
            audit_failure(
                $report,
                'manifest_course_count_mismatch',
                'El manifiesto no conserva el número esperado de cursos.',
                'manifest.json',
                $manifest['courses_expected'] ?? null,
                count($rawentries)
            );
        }
        foreach (array_keys($entries) as $path) {
            if (!isset($manifestpaths[$path])) {
                audit_failure(
                    $report,
                    'manifest_unexpected_entry',
                    'El ZIP contiene un archivo ajeno al manifiesto.',
                    $path
                );
            }
        }
        foreach (array_keys($manifestpaths) as $path) {
            if (!isset($entries[$path])) {
                audit_failure(
                    $report,
                    'manifest_missing_entry',
                    'Falta un archivo declarado por el manifiesto.',
                    $path
                );
            }
        }
    }

    $identity = null;
    $sourceinventory = null;
    $plugins = null;
    foreach ([
        'identidades.json' => 'identity',
        'inventario-origen.json' => 'sourceinventory',
        'plugins.json' => 'plugins',
    ] as $path => $variable) {
        if (!isset($entries[$path])) {
            continue;
        }
        try {
            ${$variable} = audit_read_json($zip, $path);
        } catch (Throwable $error) {
            audit_failure($report, 'base_json_invalid', $error->getMessage(), $path);
        }
    }
    if (is_array($identity)) {
        if (($identity['metadata']['source'] ?? '') !== $sourceid ||
                ($identity['metadata']['scope'] ?? '') !==
                    ($manifest['identity_scope'] ?? null) ||
                ($identity['metadata']['schema_version'] ?? '') !==
                    ($manifest['identity_schema_version'] ?? null)) {
            audit_failure(
                $report,
                'identity_metadata_mismatch',
                'identidades.json no corresponde al origen o contrato del manifiesto.',
                'identidades.json'
            );
        }
        foreach (collector_validate_identity_payload($identity) as $contracterror) {
            audit_failure(
                $report,
                'identity_contract_invalid',
                $contracterror,
                'identidades.json'
            );
        }
    }
    if (is_array($sourceinventory) &&
            (($sourceinventory['source_id'] ?? '') !== $sourceid ||
             ($sourceinventory['write_performed'] ?? null) !== false)) {
        audit_failure(
            $report,
            'source_inventory_mismatch',
            'inventario-origen.json no corresponde al origen sellado.',
            'inventario-origen.json'
        );
    }
    if (is_array($plugins) &&
            (($plugins['source_id'] ?? '') !== $sourceid ||
             ($plugins['write_performed'] ?? null) !== false)) {
        audit_failure(
            $report,
            'plugins_mismatch',
            'plugins.json no corresponde al origen sellado.',
            'plugins.json'
        );
    }

    $inventorycourseids = [];
    if (is_array($sourceinventory)) {
        $inventorycourses = $sourceinventory['courses'] ?? [];
        if (!is_array($inventorycourses)) {
            audit_failure(
                $report,
                'source_inventory_courses_invalid',
                'inventario-origen.json no contiene una lista de cursos.',
                'inventario-origen.json'
            );
            $inventorycourses = [];
        }
        foreach ($inventorycourses as $course) {
            $courseid = is_array($course)
                ? (int)($course['source_course_id'] ?? 0)
                : 0;
            if ($courseid < 1 || isset($inventorycourseids[$courseid])) {
                audit_failure(
                    $report,
                    'source_inventory_course_invalid',
                    'El inventario de origen contiene un curso inválido o repetido.',
                    'inventario-origen.json'
                );
                continue;
            }
            $inventorycourseids[$courseid] = $course;
        }
        if ((int)($sourceinventory['counts']['courses'] ?? -1) !==
                count($inventorycourses) || count($inventorycourses) !==
                count($courseids)) {
            audit_failure(
                $report,
                'source_inventory_course_count_mismatch',
                'El inventario de origen y el manifiesto difieren en cursos.',
                'inventario-origen.json',
                count($courseids),
                count($inventorycourses)
            );
        }
        foreach (array_keys($courseids) as $courseid) {
            if (!isset($inventorycourseids[$courseid])) {
                audit_failure(
                    $report,
                    'source_inventory_course_missing',
                    'Falta en el inventario un curso del manifiesto.',
                    'course:' . $courseid
                );
            }
        }
    }

    foreach ($manifestentries as $item) {
        $coursekey = (string)$item['course_key'];
        $courseid = (int)$item['source_course_id'];
        $checkpointpath = (string)$item['checkpoint_file'];
        $inventorypath = (string)$item['inventory_file'];
        if (!isset($entries[$checkpointpath], $entries[$inventorypath])) {
            continue;
        }
        try {
            $checkpoint = audit_read_json($zip, $checkpointpath);
            $courseinventory = audit_read_json($zip, $inventorypath);
            if (($checkpoint['schema_version'] ?? '') !== '1.0' ||
                    ($checkpoint['package_type'] ?? '') !==
                        'moodle-consolidation-source-course' ||
                    ($checkpoint['source_id'] ?? '') !== $sourceid ||
                    ($checkpoint['course_key'] ?? '') !== $coursekey ||
                    (int)($checkpoint['source_course_id'] ?? 0) !== $courseid ||
                    ($checkpoint['status'] ?? '') !== 'prepared' ||
                    ($checkpoint['destination_write_performed'] ?? null) !== false ||
                    ($checkpoint['backup_file'] ?? '') !== $item['backup_file'] ||
                    ($checkpoint['inventory_file'] ?? '') !== $inventorypath ||
                    ($checkpoint['backup_sha256'] ?? '') !==
                        $item['backup_sha256'] ||
                    ($checkpoint['inventory_sha256'] ?? '') !==
                        $item['inventory_sha256'] ||
                    (int)($checkpoint['backup_bytes'] ?? -1) !==
                        (int)$item['backup_bytes']) {
                audit_failure(
                    $report,
                    'checkpoint_contract_mismatch',
                    'El checkpoint no coincide con su entrada del manifiesto.',
                    $checkpointpath
                );
            }
            if (array_key_exists('inventory_bytes', $checkpoint) &&
                    (int)$checkpoint['inventory_bytes'] !==
                        $entries[$inventorypath]['bytes']) {
                audit_failure(
                    $report,
                    'checkpoint_inventory_size_mismatch',
                    'El tamaño del inventario no coincide con el checkpoint.',
                    $inventorypath,
                    $checkpoint['inventory_bytes'],
                    $entries[$inventorypath]['bytes']
                );
            }
            $normalizedinventory = audit_normalize_course_inventory_types(
                $courseinventory['inventory'] ?? null
            );
            $statehash = audit_value_sha256($normalizedinventory);
            if (($courseinventory['schema_version'] ?? '') !== '1.0' ||
                    ($courseinventory['package_type'] ?? '') !==
                        'moodle-consolidation-course-inventory' ||
                    ($courseinventory['source_id'] ?? '') !== $sourceid ||
                    ($courseinventory['course_key'] ?? '') !== $coursekey ||
                    ($courseinventory['write_performed'] ?? null) !== false ||
                    (int)($courseinventory['inventory']['course']['source_course_id'] ?? 0) !==
                        $courseid ||
                    ($courseinventory['source_state_sha256'] ?? '') !== $statehash ||
                    ($checkpoint['source_state_sha256'] ?? '') !== $statehash) {
                audit_failure(
                    $report,
                    'course_inventory_mismatch',
                    'El inventario detallado no coincide con su checkpoint.',
                    $inventorypath
                );
            }
            if (isset($inventorycourseids[$courseid])) {
                $summary = $inventorycourseids[$courseid];
                if ((string)($summary['shortname'] ?? '') !==
                        (string)($item['source_shortname'] ?? '') ||
                        (string)($summary['idnumber'] ?? '') !==
                        (string)($item['source_course_idnumber'] ?? '')) {
                    audit_failure(
                        $report,
                        'course_summary_mismatch',
                        'Los datos básicos del curso difieren entre inventario y manifiesto.',
                        $coursekey
                    );
                }
            }
            $report['counts']['courses_cross_checked']++;
        } catch (Throwable $error) {
            audit_failure(
                $report,
                'course_cross_check_error',
                $error->getMessage(),
                $coursekey
            );
        }
    }
} catch (Throwable $error) {
    audit_failure(
        $report,
        'audit_exception',
        $error->getMessage(),
        $report['package'] !== '' ? $report['package'] : null
    );
} finally {
    if ($zip instanceof ZipArchive) {
        try {
            $zip->close();
        } catch (Throwable $ignored) {
            // El reporte conserva el error de apertura o lectura original.
        }
    }
}

$report['duration_seconds'] = round(microtime(true) - $started, 3);
$report['counts']['failures'] = count($report['failures']);
$report['counts']['warnings'] = count($report['warnings']);
$report['result'] = $report['failures'] === [] ? 'ok' : 'error';

try {
    audit_write_report($reportpath, $report);
} catch (Throwable $error) {
    fwrite(STDERR, 'VALIDACION_REPORTE_ERROR ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

if ($report['result'] === 'ok') {
    fwrite(
        STDOUT,
        'VALIDACION_OK archivos=' . $report['counts']['files_checked'] .
        ' cursos=' . $report['counts']['courses_cross_checked'] .
        ' advertencias=' . $report['counts']['warnings'] .
        ' reporte=' . $reportpath . PHP_EOL
    );
    exit(0);
}

foreach ($report['failures'] as $failure) {
    fwrite(
        STDERR,
        'VALIDACION_FALLO tipo=' . (string)($failure['type'] ?? 'unknown') .
        ' ruta=' . (string)($failure['path'] ?? '-') .
        ' mensaje=' . (string)($failure['message'] ?? '') . PHP_EOL
    );
}
fwrite(
    STDERR,
    'VALIDACION_ERROR fallos=' . $report['counts']['failures'] .
    ' reporte=' . $reportpath . PHP_EOL
);
exit(1);
