<?php
declare(strict_types=1);

const MANAGED_SCHEMA_VERSION = '1.0';

function fail(string $message, int $code = 1): never {
    fwrite(STDERR, "ERROR_CONFIG_ADMINISTRADA: {$message}\n");
    exit($code);
}

function parse_options(array $arguments): array {
    $options = [];
    foreach ($arguments as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            fail("Argumento inválido: {$argument}", 64);
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if ($name === '' || array_key_exists($name, $options)) {
            fail("Opción inválida o repetida: {$name}", 64);
        }
        $options[$name] = $value;
    }
    return $options;
}

function required_option(array $options, string $name): string {
    $value = (string)($options[$name] ?? '');
    if ($value === '') {
        fail("Falta --{$name}.", 64);
    }
    return $value;
}

function read_json_object(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        fail("No se puede leer {$path}.");
    }
    try {
        $value = json_decode(
            (string)file_get_contents($path),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        fail("JSON inválido en {$path}: {$exception->getMessage()}");
    }
    if (!is_array($value)) {
        fail("El documento {$path} debe ser un objeto JSON.");
    }
    return $value;
}

function validate_value(mixed $value, string $path, int $depth = 0): void {
    if ($depth > 8) {
        fail("El valor {$path} supera ocho niveles de anidamiento.");
    }
    if (is_null($value) || is_bool($value) || is_int($value) ||
            is_float($value) || is_string($value)) {
        if (is_string($value) && strlen($value) > 1048576) {
            fail("El valor {$path} supera 1 MiB.");
        }
        return;
    }
    if (!is_array($value)) {
        fail("El valor {$path} usa un tipo no permitido.");
    }
    if (count($value) > 10000) {
        fail("El valor {$path} contiene demasiados elementos.");
    }
    foreach ($value as $index => $item) {
        if (is_string($index) &&
                (strlen($index) > 255 || preg_match('/[\x00-\x1F\x7F]/', $index))) {
            fail("El valor {$path} contiene una clave no permitida.");
        }
        validate_value($item, "{$path}[{$index}]", $depth + 1);
    }
}

function normalize_value(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
        $value[$key] = normalize_value($item);
    }
    return $value;
}

function load_settings(string $path): array {
    $document = read_json_object($path);
    if (($document['schema_version'] ?? null) !== MANAGED_SCHEMA_VERSION) {
        fail("schema_version debe ser " . MANAGED_SCHEMA_VERSION . " en {$path}.");
    }
    if (!array_key_exists('settings', $document) ||
            !is_array($document['settings']) ||
            ($document['settings'] !== [] && array_is_list($document['settings']))) {
        fail("settings debe ser un objeto JSON en {$path}.");
    }
    $extra = array_diff(array_keys($document), ['schema_version', 'settings']);
    if ($extra) {
        fail('Campos superiores no permitidos: ' . implode(', ', $extra) . '.');
    }
    $reserved = [
        'dbtype', 'dblibrary', 'dbhost', 'dbport', 'dbname', 'dbuser',
        'dbpass', 'prefix', 'dboptions', 'dataroot', 'wwwroot', 'dirroot',
        'libdir', 'reverseproxy', 'sslproxy', 'admin',
    ];
    $settings = $document['settings'];
    if (count($settings) > 500) {
        fail('No se admiten más de 500 ajustes administrados.');
    }
    foreach ($settings as $name => $value) {
        if (!is_string($name) ||
                !preg_match('/^[a-z][a-z0-9_]{0,127}$/', $name)) {
            fail("Nombre de ajuste no permitido: {$name}.");
        }
        if (in_array($name, $reserved, true)) {
            fail("{$name} pertenece a la configuración base y no puede administrarse aquí.");
        }
        validate_value($value, "settings.{$name}");
        $settings[$name] = normalize_value($value);
    }
    ksort($settings, SORT_STRING);
    return $settings;
}

function compiled_php(array $settings): string {
    $lines = [
        '<?php',
        'declare(strict_types=1);',
        '',
        '// Generado por GESTIONAR-CONFIG.sh. No editar directamente.',
        'if (!isset($CFG) || !is_object($CFG)) {',
        "    throw new RuntimeException('CFG no está disponible para la configuración administrada.');",
        '}',
    ];
    foreach ($settings as $name => $value) {
        $export = var_export($value, true);
        $lines[] = '$CFG->' . $name . ' = ' . $export . ';';
    }
    $lines[] = '';
    return implode("\n", $lines);
}

function canonical_value(mixed $value): string {
    return json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
        JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    );
}

function changed_keys(?array $old, array $new): array {
    if ($old === null) {
        return array_keys($new);
    }
    $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
    sort($keys, SORT_STRING);
    return array_values(array_filter($keys, static function (string $key) use ($old, $new): bool {
        if (!array_key_exists($key, $old) || !array_key_exists($key, $new)) {
            return true;
        }
        return canonical_value($old[$key]) !== canonical_value($new[$key]);
    }));
}

function decode_base64_option(array $options, string $name): string {
    $encoded = required_option($options, $name);
    $decoded = base64_decode($encoded, true);
    if ($decoded === false || str_contains($decoded, "\0")) {
        fail("{$name} no contiene base64 UTF-8 válido.", 64);
    }
    return $decoded;
}

function verify_directory(string $directory): array {
    $directory = rtrim($directory, '/');
    $usesActive = !is_file("{$directory}/settings.json");
    $settingsPath = !$usesActive
        ? "{$directory}/settings.json"
        : "{$directory}/active/settings.json";
    $phpPath = is_file("{$directory}/current.php")
        ? "{$directory}/current.php"
        : "{$directory}/active/current.php";
    $manifestPath = is_file("{$directory}/manifest.json")
        ? "{$directory}/manifest.json"
        : "{$directory}/active/manifest.json";
    $settings = load_settings($settingsPath);
    $expectedPhp = compiled_php($settings);
    if (!is_file($phpPath) || !hash_equals($expectedPhp, (string)file_get_contents($phpPath))) {
        fail("{$phpPath} no corresponde exactamente a los ajustes declarados.");
    }
    $manifest = read_json_object($manifestPath);
    foreach (['version_id', 'settings_sha256', 'compiled_sha256', 'status'] as $field) {
        if (!isset($manifest[$field]) || !is_string($manifest[$field]) || $manifest[$field] === '') {
            fail("El manifiesto no contiene {$field} válido.");
        }
    }
    $settingsHash = hash_file('sha256', $settingsPath);
    $phpHash = hash_file('sha256', $phpPath);
    if (!hash_equals($manifest['settings_sha256'], $settingsHash) ||
            !hash_equals($manifest['compiled_sha256'], $phpHash)) {
        fail('Los hashes de la configuración administrada no coinciden con el manifiesto.');
    }
    if ($usesActive && $manifest['status'] === 'failed_rolled_back') {
        fail('La versión activa está marcada como fallida y revertida.');
    }
    return $manifest;
}

$mode = $argv[1] ?? '';
$options = parse_options(array_slice($argv, 2));

switch ($mode) {
    case 'validate':
        $settings = load_settings(required_option($options, 'input'));
        echo 'CONFIG_DECLARATIVA_VALIDA ajustes=' . count($settings) . "\n";
        break;

    case 'compile':
        echo compiled_php(load_settings(required_option($options, 'input')));
        break;

    case 'manifest':
        $input = required_option($options, 'input');
        $compiled = required_option($options, 'compiled');
        $version = required_option($options, 'version');
        $status = required_option($options, 'status');
        if (!preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/', $version)) {
            fail('version no tiene el formato esperado.', 64);
        }
        if (!in_array($status, ['applied', 'failed_rolled_back', 'initialized'], true)) {
            fail('status no es válido.', 64);
        }
        $settings = load_settings($input);
        if (!is_file($compiled) ||
                !hash_equals(compiled_php($settings), (string)file_get_contents($compiled))) {
            fail('El PHP compilado no corresponde al JSON candidato.');
        }
        $old = null;
        if (isset($options['old']) && $options['old'] !== '') {
            $old = load_settings($options['old']);
        }
        $previous = (string)($options['previous'] ?? '');
        if ($previous !== '' &&
                !preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/', $previous)) {
            fail('previous no tiene el formato esperado.', 64);
        }
        $manifest = [
            'schema_version' => MANAGED_SCHEMA_VERSION,
            'manager_version' => '7.3.0-linux',
            'version_id' => $version,
            'previous_version_id' => $previous,
            'created_at_utc' => gmdate('c'),
            'operator' => decode_base64_option($options, 'operator-base64'),
            'reason' => decode_base64_option($options, 'reason-base64'),
            'status' => $status,
            'setting_keys' => array_keys($settings),
            'changed_keys' => changed_keys($old, $settings),
            'settings_sha256' => hash_file('sha256', $input),
            'compiled_sha256' => hash_file('sha256', $compiled),
            'contains_values' => false,
        ];
        echo json_encode(
            $manifest,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
        break;

    case 'verify':
        $manifest = verify_directory(required_option($options, 'directory'));
        echo 'CONFIG_ADMINISTRADA_OK version=' . $manifest['version_id'] .
            ' status=' . $manifest['status'] . "\n";
        break;

    case 'show':
        $directory = rtrim(required_option($options, 'directory'), '/');
        $manifest = verify_directory($directory);
        $settingsPath = is_file("{$directory}/settings.json")
            ? "{$directory}/settings.json"
            : "{$directory}/active/settings.json";
        echo "Versión activa: {$manifest['version_id']}\n";
        echo "Estado: {$manifest['status']}\n";
        echo "Fecha UTC: {$manifest['created_at_utc']}\n";
        echo "Operador: {$manifest['operator']}\n";
        echo "Motivo: {$manifest['reason']}\n";
        echo "SHA-256 PHP: {$manifest['compiled_sha256']}\n";
        echo "Ajustes declarados:\n";
        echo json_encode(
            read_json_object($settingsPath),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
        break;

    case 'history':
        $directory = rtrim(required_option($options, 'directory'), '/');
        $manifests = glob("{$directory}/*/manifest.json") ?: [];
        rsort($manifests, SORT_STRING);
        foreach ($manifests as $manifestPath) {
            $manifest = read_json_object($manifestPath);
            printf(
                "%s | %s | %s | %s | %s\n",
                (string)($manifest['version_id'] ?? '?'),
                (string)($manifest['status'] ?? '?'),
                (string)($manifest['created_at_utc'] ?? '?'),
                (string)($manifest['operator'] ?? '?'),
                (string)($manifest['reason'] ?? '?')
            );
        }
        break;

    default:
        fail('Modo no reconocido: ' . ($mode === '' ? '(vacío)' : $mode), 64);
}
