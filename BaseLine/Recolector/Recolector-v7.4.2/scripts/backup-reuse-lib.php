<?php
// Inspección conservadora de respaldos Moodle existentes.

declare(strict_types=1);

function collector_reuse_xpath_text(DOMXPath $xpath, string $expression): string {
    $value = $xpath->evaluate('string(' . $expression . ')');
    return is_string($value) ? trim($value) : '';
}

function collector_reuse_child_text(DOMElement $element, string $name): string {
    foreach ($element->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $name) {
            return trim((string)$child->textContent);
        }
    }
    return '';
}

/**
 * Firma de forma estable el perfil raíz declarado dentro de moodle_backup.xml.
 *
 * @param array<string,int> $settings
 */
function collector_reuse_settings_sha256(array $settings): string {
    ksort($settings, SORT_STRING);
    return hash('sha256', json_encode(
        $settings,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    ));
}

function collector_reuse_archive_pathname(mixed $entry): string {
    if (is_array($entry)) {
        $pathname = (string)($entry['pathname'] ?? '');
    } else if (is_object($entry)) {
        $pathname = (string)($entry->pathname ?? '');
    } else {
        return '';
    }
    $pathname = str_replace('\\', '/', trim($pathname));
    while (str_starts_with($pathname, './')) {
        $pathname = substr($pathname, 2);
    }
    return ltrim($pathname, '/');
}

/**
 * @param array<int,string> $required
 */
function collector_reuse_assert_required_entries(
    array $entries,
    array $required
): void {
    $available = array_fill_keys($entries, true);
    foreach ($required as $requiredentry) {
        if (!isset($available[$requiredentry])) {
            throw new RuntimeException(
                'missing_' . str_replace('/', '_', $requiredentry)
            );
        }
    }
}

/**
 * Lee solamente el manifiesto cuando el MBZ usa contenedor ZIP.
 */
function collector_reuse_zip_manifest(string $path, array $required): string {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('php_zip_unavailable');
    }
    $zip = new ZipArchive();
    $opened = false;
    try {
        $opened = $zip->open($path, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new RuntimeException('invalid_zip_' . (string)$opened);
        }
        $entries = [];
        foreach ($required as $requiredentry) {
            $index = $zip->locateName($requiredentry, ZipArchive::FL_NOCASE);
            if ($index !== false) {
                $actualname = $zip->getNameIndex($index);
                if (is_string($actualname)) {
                    $entries[] = collector_reuse_archive_pathname([
                        'pathname' => $actualname,
                    ]);
                }
            }
        }
        collector_reuse_assert_required_entries($entries, $required);
        $manifestindex = $zip->locateName(
            'moodle_backup.xml',
            ZipArchive::FL_NOCASE
        );
        $manifeststat = $manifestindex === false
            ? false
            : $zip->statIndex($manifestindex);
        if (!is_array($manifeststat) ||
                (int)($manifeststat['size'] ?? 0) < 1 ||
                (int)$manifeststat['size'] > 16 * 1024 * 1024) {
            throw new RuntimeException('invalid_moodle_backup_xml');
        }
        $manifest = $zip->getFromIndex($manifestindex);
        if (!is_string($manifest) || $manifest === '') {
            throw new RuntimeException('invalid_moodle_backup_xml');
        }
        return $manifest;
    } finally {
        if ($opened === true) {
            try {
                $zip->close();
            } catch (Throwable) {
                // El archivo se usa en modo de solo lectura.
            }
        }
    }
}

/**
 * Usa el empaquetador oficial de Moodle para MBZ TGZ u otros contenedores
 * admitidos por application/vnd.moodle.backup. Solo materializa el manifiesto.
 */
function collector_reuse_moodle_manifest(string $path, array $required): string {
    if (!function_exists('get_file_packer') ||
            !function_exists('make_temp_directory') ||
            !function_exists('fulldelete')) {
        throw new RuntimeException('moodle_backup_packer_unavailable');
    }
    $packer = get_file_packer('application/vnd.moodle.backup');
    if (!is_object($packer)) {
        throw new RuntimeException('moodle_backup_packer_unavailable');
    }
    $listing = $packer->list_files($path);
    if (!is_array($listing)) {
        throw new RuntimeException('invalid_moodle_backup_archive');
    }
    $entries = [];
    foreach ($listing as $entry) {
        $pathname = collector_reuse_archive_pathname($entry);
        if ($pathname !== '') {
            $entries[] = $pathname;
        }
    }
    collector_reuse_assert_required_entries($entries, $required);

    $temporary = make_temp_directory(
        'collector-reuse-inspect/' .
        hash('sha256', $path . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX))
    );
    try {
        $extracted = $packer->extract_to_pathname(
            $path,
            $temporary,
            ['moodle_backup.xml']
        );
        $manifestpath = $temporary . '/moodle_backup.xml';
        clearstatcache(true, $manifestpath);
        $manifestbytes = is_file($manifestpath) ? filesize($manifestpath) : false;
        if ($extracted === false || $manifestbytes === false ||
                $manifestbytes < 1 || $manifestbytes > 16 * 1024 * 1024 ||
                !is_readable($manifestpath)) {
            throw new RuntimeException('invalid_moodle_backup_xml');
        }
        $manifest = file_get_contents($manifestpath);
        if (!is_string($manifest) || $manifest === '') {
            throw new RuntimeException('invalid_moodle_backup_xml');
        }
        return $manifest;
    } finally {
        fulldelete($temporary);
    }
}

function collector_reuse_backup_manifest(
    string $path,
    string &$archiveformat
): string {
    $required = [
        'moodle_backup.xml',
        'course/course.xml',
        'users.xml',
        'files.xml',
        'gradebook.xml',
    ];
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('archive_open_failed');
    }
    try {
        $signature = fread($handle, 4);
    } finally {
        fclose($handle);
    }
    if (!is_string($signature) || strlen($signature) < 2) {
        throw new RuntimeException('archive_signature_unavailable');
    }
    if (str_starts_with($signature, 'PK')) {
        $archiveformat = 'zip';
        return collector_reuse_zip_manifest($path, $required);
    }
    $archiveformat = 'moodle-backup-packer';
    return collector_reuse_moodle_manifest($path, $required);
}

/**
 * @return array{
 *   valid:bool,reason:string,course_id:int,shortname:string,backup_date:int,
 *   bytes:int,mtime:int,settings:array<string,int>,settings_sha256:string,
 *   backup_type:string,backup_format:string,backup_mode:string,
 *   moodle_version:string,moodle_release:string,archive_format:string
 * }
 */
function collector_inspect_existing_backup(string $path): array {
    $result = [
        'valid' => false,
        'reason' => 'unknown',
        'course_id' => 0,
        'shortname' => '',
        'backup_date' => 0,
        'bytes' => 0,
        'mtime' => 0,
        'settings' => [],
        'settings_sha256' => '',
        'backup_type' => '',
        'backup_format' => '',
        'backup_mode' => '',
        'moodle_version' => '',
        'moodle_release' => '',
        'archive_format' => '',
    ];
    try {
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException('not_regular_readable_file');
        }
        $bytes = filesize($path);
        $mtime = filemtime($path);
        if ($bytes === false || $bytes < 1 || $mtime === false) {
            throw new RuntimeException('invalid_file_metadata');
        }
        if (!class_exists('DOMDocument')) {
            throw new RuntimeException('php_dom_unavailable');
        }
        $archiveformat = '';
        $manifest = collector_reuse_backup_manifest($path, $archiveformat);
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (!$document->loadXML($manifest, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new RuntimeException('malformed_moodle_backup_xml');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $courseid = (int)collector_reuse_xpath_text($xpath, '(//original_course_id)[1]');
        $shortname = collector_reuse_xpath_text($xpath, '(//original_course_shortname)[1]');
        $backupdate = (int)collector_reuse_xpath_text($xpath, '(//backup_date)[1]');
        if ($courseid < 1 || $shortname === '' || $backupdate < 1) {
            throw new RuntimeException('missing_course_identity_or_backup_date');
        }
        $settings = [];
        $nodes = $xpath->query('//settings/setting');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $level = collector_reuse_child_text($node, 'level');
                $name = collector_reuse_child_text($node, 'name');
                if ($name === '' || ($level !== '' && $level !== 'root')) {
                    continue;
                }
                $settings[$name] = (int)collector_reuse_child_text($node, 'value');
            }
        }
        foreach (['users', 'activities'] as $requiredsetting) {
            if (($settings[$requiredsetting] ?? 0) !== 1) {
                throw new RuntimeException('required_setting_' . $requiredsetting . '_disabled');
            }
        }
        ksort($settings, SORT_STRING);
        $result = [
            'valid' => true,
            'reason' => 'ok',
            'course_id' => $courseid,
            'shortname' => $shortname,
            'backup_date' => $backupdate,
            'bytes' => (int)$bytes,
            'mtime' => (int)$mtime,
            'settings' => $settings,
            'settings_sha256' => collector_reuse_settings_sha256($settings),
            'backup_type' => collector_reuse_xpath_text(
                $xpath,
                '(//details/detail/type)[1]'
            ),
            'backup_format' => collector_reuse_xpath_text(
                $xpath,
                '(//details/detail/format)[1]'
            ),
            'backup_mode' => collector_reuse_xpath_text(
                $xpath,
                '(//details/detail/mode)[1]'
            ),
            'moodle_version' => collector_reuse_xpath_text(
                $xpath,
                '(//moodle_version)[1]'
            ),
            'moodle_release' => collector_reuse_xpath_text(
                $xpath,
                '(//moodle_release)[1]'
            ),
            'archive_format' => $archiveformat,
        ];
    } catch (Throwable $error) {
        $result['reason'] = preg_replace(
            '/[^a-z0-9_.-]+/i',
            '_',
            trim($error->getMessage())
        ) ?: 'inspection_failed';
    }
    return $result;
}

function collector_reuse_inspection_matches_file(
    array $inspection,
    string $candidate
): bool {
    if (($inspection['valid'] ?? false) !== true ||
            (int)($inspection['bytes'] ?? 0) < 1 ||
            (int)($inspection['mtime'] ?? 0) < 1 ||
            !is_array($inspection['settings'] ?? null)) {
        return false;
    }
    clearstatcache(true, $candidate);
    $bytes = is_file($candidate) ? filesize($candidate) : false;
    $mtime = is_file($candidate) ? filemtime($candidate) : false;
    return $bytes !== false && $mtime !== false &&
        (int)$bytes === (int)$inspection['bytes'] &&
        (int)$mtime === (int)$inspection['mtime'];
}

/**
 * Los logs y los historiales son trazas de auditoría. Moodle puede bloquearlos
 * o desactivarlos aunque el perfil general los anuncie; su ausencia no elimina
 * usuarios, matrículas, entregas, calificaciones, intentos ni finalizaciones.
 * Por ello se informa la diferencia, pero no se rechaza un MBZ por ella.
 *
 * @return array<int,string>
 */
function collector_reuse_advisory_profile_settings(): array {
    return ['logs', 'histories'];
}

/**
 * @param array<string,mixed> $inspection
 * @param array<string,int> $expectedsettings
 * @return array<int,array{setting:string,expected:int,actual:int,impact:string}>
 */
function collector_reuse_advisory_profile_differences(
    array $inspection,
    array $expectedsettings
): array {
    $differences = [];
    $actualsettings = is_array($inspection['settings'] ?? null)
        ? $inspection['settings']
        : [];
    foreach (collector_reuse_advisory_profile_settings() as $settingname) {
        if (!array_key_exists($settingname, $expectedsettings)) {
            continue;
        }
        $expectedvalue = (int)$expectedsettings[$settingname];
        $actualvalue = (int)($actualsettings[$settingname] ?? 0);
        if ($actualvalue === $expectedvalue) {
            continue;
        }
        $differences[] = [
            'setting' => $settingname,
            'expected' => $expectedvalue,
            'actual' => $actualvalue,
            'impact' => 'audit_data_only',
        ];
    }
    return $differences;
}

/**
 * Devuelve una causa estable de rechazo o una cadena vacía si el MBZ puede
 * adoptarse para el curso y el perfil efectivo indicados.
 *
 * @param array<string,mixed> $inspection
 * @param array<string,int> $expectedsettings
 */
function collector_reuse_rejection_reason(
    array $inspection,
    string $candidate,
    int $courseid,
    string $shortname,
    int $sourcechangeepoch,
    string $excludedroot,
    array $expectedsettings
): string {
    if (($inspection['valid'] ?? false) !== true) {
        return (string)($inspection['reason'] ?? 'inspection_failed');
    }
    if ((int)($inspection['course_id'] ?? 0) !== $courseid) {
        return 'course_id_mismatch';
    }
    if ((string)($inspection['shortname'] ?? '') !== $shortname) {
        return 'course_shortname_mismatch';
    }
    if ((int)($inspection['backup_date'] ?? 0) < $sourcechangeepoch) {
        return 'backup_older_than_source';
    }

    $candidatepath = realpath($candidate);
    $excludedpath = realpath($excludedroot);
    if ($candidatepath === false) {
        return 'candidate_path_unavailable';
    }
    if ($excludedpath !== false &&
            ($candidatepath === $excludedpath ||
                str_starts_with(
                    $candidatepath,
                    $excludedpath . DIRECTORY_SEPARATOR
                ))) {
        return 'candidate_inside_work_directory';
    }

    $advisorysettings = array_fill_keys(
        collector_reuse_advisory_profile_settings(),
        true
    );
    foreach ($expectedsettings as $settingname => $expectedvalue) {
        // Moodle puede omitir del manifiesto algunos ajustes desactivados; una
        // ausencia equivale conservadoramente a cero.
        $actualvalue = (int)(($inspection['settings'] ?? [])[$settingname] ?? 0);
        if ($actualvalue !== (int)$expectedvalue) {
            if (isset($advisorysettings[$settingname])) {
                continue;
            }
            return 'backup_profile_mismatch_' . $settingname;
        }
    }
    return '';
}
