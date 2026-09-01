<?php
// Utilidades compartidas del Integrador Incremental Moodle v1.

declare(strict_types=1);

defined('MOODLE_INTERNAL') || die();

const INC_VERSION = '1.1.5-linux';
const INC_SCHEMA = '1.0';
const INC_COMPATIBLE_PLAN_VERSIONS = [
    '1.0.0-linux',
    '1.0.1-linux',
    '1.0.2-linux',
    '1.0.3-linux',
    '1.0.4-linux',
    '1.1.0-linux',
    '1.1.1-linux',
    '1.1.2-linux',
    '1.1.3-linux',
    '1.1.4-linux',
    '1.1.5-linux',
];

function inc_norm(string $value): string {
    return core_text::strtolower(trim($value));
}

function inc_read_json(string $path): array {
    if (!is_readable($path) || !is_file($path)) {
        throw new RuntimeException('No se puede leer ' . $path . '.');
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('No se pudo abrir ' . $path . '.');
    }
    $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException($path . ' no contiene un objeto JSON.');
    }
    return $value;
}

function inc_write_json(string $path, array $value): void {
    $directory = dirname($path);
    if (!is_dir($directory) &&
            !mkdir($directory, 0770, true) &&
            !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear ' . $directory . '.');
    }
    $temporary = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    $encoded = json_encode(
        $value,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    if (file_put_contents($temporary, $encoded, LOCK_EX) === false ||
            !chmod($temporary, 0660) ||
            !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('No se pudo escribir atómicamente ' . $path . '.');
    }
}

function inc_is_sha256(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
}

function inc_slug(string $value, int $limit = 48): string {
    $value = inc_norm($value);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    if ($value === '' || !preg_match('/^[a-z]/', $value)) {
        $value = 'origen-' . substr(hash('sha256', $value), 0, 8);
    }
    return substr($value, 0, $limit);
}

function inc_token(string $value, int $length = 16): string {
    return substr(hash('sha256', $value), 0, $length);
}

function inc_course_key(string $sourceid, int $courseid): string {
    return 'COURSE-' . strtoupper(preg_replace('/[^a-z0-9_-]+/i', '-', $sourceid)) .
        '-' . strtoupper(substr(hash('sha256', $sourceid . '|course|' . $courseid), 0, 12));
}

function inc_course_marker(string $sourceid, int $courseid): string {
    return 'INC-V1-COURSE-' . strtoupper(substr(
        hash('sha256', $sourceid . '|course|' . $courseid),
        0,
        24
    ));
}

function inc_category_marker(string $batchid, int $sourcecategoryid): string {
    return 'INC-V1-CAT-' . strtoupper(substr(
        hash('sha256', $batchid . '|category|' . $sourcecategoryid),
        0,
        24
    ));
}

function inc_parent_category_marker(string $batchid): string {
    return 'INC-V1-ROOT-' . strtoupper(substr(hash('sha256', $batchid), 0, 24));
}

function inc_safe_component(string $value, string $label): string {
    $value = trim($value);
    if (!preg_match('/^[a-z][a-z0-9_-]*$/', $value)) {
        throw new RuntimeException($label . ' no es un identificador válido.');
    }
    return $value;
}

function inc_safe_workdir(string $path): string {
    $path = rtrim($path, '/\\');
    if ($path === '' || !str_starts_with($path, '/exports/integrator/')) {
        throw new RuntimeException(
            'El directorio de trabajo debe estar bajo /exports/integrator/.'
        );
    }
    foreach (explode('/', substr($path, 1)) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException('El directorio de trabajo es inseguro.');
        }
    }
    return $path;
}

function inc_load_package(string $package): array {
    $package = rtrim($package, '/\\');
    $manifest = inc_read_json($package . '/manifest.json');
    $identity = inc_read_json($package . '/identidades.json');
    $inventory = inc_read_json($package . '/inventario-origen.json');
    $plugins = inc_read_json($package . '/plugins.json');
    $sourceid = inc_safe_component((string)($manifest['source_id'] ?? ''), 'source_id');
    if (($manifest['schema_version'] ?? '') !== '1.0' ||
            ($manifest['package_type'] ?? '') !== 'moodle-consolidation-source' ||
            ($manifest['package_status'] ?? '') !== 'sealed' ||
            ($manifest['collector_version'] ?? '') !== '7.4.1-linux' ||
            ($manifest['identity_schema_version'] ?? '') !== '1.2' ||
            ($identity['metadata']['source'] ?? '') !== $sourceid ||
            ($inventory['source_id'] ?? '') !== $sourceid ||
            ($plugins['source_id'] ?? '') !== $sourceid ||
            ($manifest['source_write_performed'] ?? null) !== false ||
            ($manifest['destination_write_performed'] ?? null) !== false) {
        throw new RuntimeException(
            'El paquete extraído no conserva el contrato del Recolector 7.4.1.'
        );
    }
    $validation = inc_read_json(dirname($package) . '/validation.json');
    if (($validation['result'] ?? '') !== 'ok' ||
            !inc_is_sha256($validation['outer_zip']['sha256'] ?? null)) {
        throw new RuntimeException('El paquete no tiene una validación integral aprobada.');
    }
    return [
        'root' => $package,
        'manifest' => $manifest,
        'identity' => $identity,
        'inventory' => $inventory,
        'plugins' => $plugins,
        'package_sha256' => (string)$validation['outer_zip']['sha256'],
    ];
}

function inc_manifest_entries(array $package): array {
    $result = [];
    foreach ($package['manifest']['entries'] ?? [] as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException('manifest.entries contiene una fila inválida.');
        }
        $courseid = (int)($entry['source_course_id'] ?? 0);
        $coursekey = (string)($entry['course_key'] ?? '');
        if ($courseid < 1 || $coursekey !== inc_course_key(
                (string)$package['manifest']['source_id'],
                $courseid
            ) || isset($result[$courseid])) {
            throw new RuntimeException('El manifiesto contiene un curso inválido o repetido.');
        }
        foreach (['backup_file', 'inventory_file', 'checkpoint_file'] as $field) {
            $relative = (string)($entry[$field] ?? '');
            if ($relative === '' || str_contains($relative, '..') ||
                    str_starts_with($relative, '/') || str_contains($relative, '\\')) {
                throw new RuntimeException('El manifiesto contiene una ruta insegura.');
            }
            $entry['_paths'][$field] = $package['root'] . '/' . $relative;
            if (!is_readable($entry['_paths'][$field])) {
                throw new RuntimeException('Falta ' . $relative . '.');
            }
        }
        $result[$courseid] = $entry;
    }
    ksort($result, SORT_NUMERIC);
    return $result;
}

function inc_identity_users(array $package): array {
    $result = [];
    foreach ($package['identity']['users'] ?? [] as $row) {
        $id = (int)($row['source_user_id'] ?? 0);
        if ($id < 1 || isset($result[$id])) {
            throw new RuntimeException('identidades.json repite un usuario de origen.');
        }
        $email = inc_norm((string)($row['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException(
                'El usuario de origen ' . $id . ' no tiene un correo institucional válido.'
            );
        }
        $row['email'] = $email;
        $result[$id] = $row;
    }
    return $result;
}

function inc_role_target(string $shortname): string {
    return match (inc_norm($shortname)) {
        'student' => 'student',
        'teacher', 'editingteacher' => 'editingteacher',
        'manager', 'coursecreator', 'siteadmin' => 'manager',
        default => 'personalizado',
    };
}

function inc_username_candidate(
    string $sourceid,
    string $sourceusername,
    int $sourceuserid,
    array &$reserved
): string {
    $base = inc_norm($sourceusername);
    $base = preg_replace('/[^a-z0-9._@-]+/', '-', $base) ?? '';
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'usuario-' . $sourceuserid;
    }
    $candidates = [
        $base,
        inc_slug($sourceid, 24) . '-' . $base,
        inc_slug($sourceid, 20) . '-' . $base . '-' . $sourceuserid,
    ];
    for ($attempt = 0; $attempt < 1000; $attempt++) {
        $candidate = $candidates[min($attempt, 2)];
        if ($attempt > 2) {
            $candidate .= '-' . $attempt;
        }
        $candidate = core_text::substr($candidate, 0, 100);
        if (!isset($reserved[inc_norm($candidate)])) {
            $reserved[inc_norm($candidate)] = true;
            return $candidate;
        }
    }
    throw new RuntimeException('No fue posible reservar un username único.');
}

function inc_allocate_course_name(
    string $original,
    string $sourcename,
    int $courseid,
    array &$reserved
): string {
    $original = trim($original);
    $sourcename = trim($sourcename);
    if ($original === '') {
        $original = 'Curso ' . $courseid;
    }
    if ($sourcename === '') {
        throw new RuntimeException('El nombre del origen está vacío.');
    }

    $suffix = ' - [' . $sourcename . ']';
    for ($attempt = 0; $attempt < 1000; $attempt++) {
        if ($attempt === 0) {
            $extra = '';
        } else if ($attempt === 1) {
            $extra = ' [ID ' . $courseid . ']';
        } else {
            $extra = ' [ID ' . $courseid . '-' . $attempt . ']';
        }

        $tail = $suffix . $extra;
        $available = 254 - core_text::strlen($tail);
        if ($available < 1) {
            throw new RuntimeException('El nombre del origen es demasiado largo.');
        }
        $candidate = core_text::substr($original, 0, $available) . $tail;
        $normalized = inc_norm($candidate);
        if (!isset($reserved[$normalized])) {
            $reserved[$normalized] = true;
            return $candidate;
        }
    }

    throw new RuntimeException(
        'No se pudo reservar el fullname del curso después de 1000 intentos.'
    );
}

function inc_allocate_shortname(
    string $sourceid,
    string $original,
    int $courseid,
    array &$reserved
): string {
    $prefix = core_text::strtoupper(inc_slug($sourceid, 32)) . '-';
    $base = trim($original) !== '' ? trim($original) : 'COURSE-' . $courseid;
    for ($attempt = 0; $attempt < 1000; $attempt++) {
        if ($attempt === 0) {
            $extra = '';
        } else if ($attempt === 1) {
            $extra = '-' . $courseid;
        } else {
            $extra = '-' . $courseid . '-' . $attempt;
        }
        $candidate = core_text::substr(
            $prefix . $base,
            0,
            255 - core_text::strlen($extra)
        ) . $extra;
        $normalized = inc_norm($candidate);
        if (!isset($reserved[$normalized])) {
            $reserved[$normalized] = true;
            return $candidate;
        }
    }

    throw new RuntimeException(
        'No se pudo reservar el shortname del curso después de 1000 intentos.'
    );
}

function inc_user_marker(string $sourceid, int $sourceuserid, string $email): string {
    return 'INCUSR-' . strtoupper(substr(
        hash('sha256', $sourceid . '|' . $sourceuserid . '|' . inc_norm($email)),
        0,
        20
    ));
}

function inc_source_user_ids_from_inventory(array $document): array {
    $inventory = $document['inventory'] ?? [];
    $ids = [];
    $walk = static function (mixed $value) use (&$walk, &$ids): void {
        if (!is_array($value)) {
            return;
        }
        if (array_key_exists('source_user_id', $value)) {
            $id = (int)$value['source_user_id'];
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                $walk($item);
            }
        }
    };
    $walk($inventory);
    $ids = array_map('intval', array_keys($ids));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function inc_target_user_record(int $userid): array {
    global $DB;
    $record = $DB->get_record(
        'user',
        ['id' => $userid, 'deleted' => 0],
        'id,username,email,auth,firstaccess,suspended,confirmed',
        MUST_EXIST
    );
    return [
        'id' => (int)$record->id,
        'username' => (string)$record->username,
        'email' => inc_norm((string)$record->email),
        'auth' => (string)$record->auth,
        'firstaccess' => (int)$record->firstaccess,
        'suspended' => (int)$record->suspended,
        'confirmed' => (int)$record->confirmed,
    ];
}

function inc_plan_hash(array $plan): string {
    return inc_document_hash($plan, 'plan_sha256');
}

function inc_document_hash(array $document, string $hashfield): string {
    unset($document[$hashfield]);
    $canonical = static function (mixed $value) use (&$canonical): mixed {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $canonical($item);
        }
        return $value;
    };
    return hash('sha256', json_encode(
        $canonical($document),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ));
}

function inc_load_plan(string $workdir): array {
    $plan = inc_read_json($workdir . '/plan.json');
    $hash = (string)($plan['plan_sha256'] ?? '');
    if (($plan['schema_version'] ?? '') !== INC_SCHEMA ||
            !in_array(
                (string)($plan['tool_version'] ?? ''),
                INC_COMPATIBLE_PLAN_VERSIONS,
                true
            ) ||
            ($plan['status'] ?? '') !== 'applicable' ||
            !inc_is_sha256($hash) || !hash_equals($hash, inc_plan_hash($plan))) {
        throw new RuntimeException('plan.json no es aplicable o perdió integridad.');
    }
    return $plan;
}

function inc_cli_options(array $defaults): array {
    global $argv;
    $result = $defaults;
    foreach (array_slice($argv ?? [], 1) as $argument) {
        if (!str_starts_with((string)$argument, '--') ||
                !str_contains((string)$argument, '=')) {
            throw new RuntimeException('Argumento no reconocido: ' . $argument . '.');
        }
        [$name, $value] = explode('=', substr((string)$argument, 2), 2);
        if (!array_key_exists($name, $result)) {
            throw new RuntimeException('Opción no reconocida: --' . $name . '.');
        }
        $result[$name] = $value;
    }
    return $result;
}
