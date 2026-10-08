<?php
// Contrato puro y reutilizable para la metadata de themes del recolector.

declare(strict_types=1);

const COLLECTOR_THEME_SCHEMA_VERSION = '1.0';

function collector_theme_canonicalize(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('collector_theme_canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = collector_theme_canonicalize($item);
    }
    return $value;
}

function collector_theme_sha256(mixed $value): string {
    return hash('sha256', json_encode(
        collector_theme_canonicalize($value),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRESERVE_ZERO_FRACTION |
        JSON_THROW_ON_ERROR
    ));
}

function collector_theme_name(string $value): string {
    $value = strtolower(trim($value));
    if (str_starts_with($value, 'theme_')) {
        $value = substr($value, strlen('theme_'));
    }
    return preg_match('/^[a-z][a-z0-9_]*$/', $value) === 1 ? $value : '';
}

function collector_theme_component(string $value): string {
    $name = collector_theme_name($value);
    return $name === '' ? '' : 'theme_' . $name;
}

function collector_theme_setting_is_sensitive(string $name): bool {
    return preg_match(
        '/(?:pass(?:word|wd)?|secret|token|api[_-]?key|private[_-]?key|' .
        'client[_-]?secret|credential|signing[_-]?key|access[_-]?key)/i',
        $name
    ) === 1;
}

function collector_theme_setting_is_file_reference(string $name): bool {
    return preg_match(
        '/(?:logo|favicon|background|image|picture|banner|header|footer|file)/i',
        $name
    ) === 1;
}

function collector_theme_setting(string $name, mixed $value): array {
    if (collector_theme_setting_is_sensitive($name)) {
        return ['state' => 'redacted'];
    }
    if (!is_scalar($value) && $value !== null) {
        return ['state' => 'not_available'];
    }
    return [
        'state' => collector_theme_setting_is_file_reference($name)
            ? 'file_reference'
            : 'config_value',
        'value' => $value,
    ];
}

function collector_theme_profile(string $component, mixed $configuration): array {
    $name = collector_theme_name($component);
    if ($name === '') {
        throw new InvalidArgumentException('Componente de theme inválido.');
    }
    if ($configuration === false || $configuration === null) {
        $configuration = [];
    } else if (is_object($configuration)) {
        $configuration = get_object_vars($configuration);
    }
    if (!is_array($configuration)) {
        return [
            'component' => 'theme_' . $name,
            'state' => 'error',
            'settings' => [],
            'settings_count' => 0,
            'error' => 'La configuración del theme no es un objeto legible.',
        ];
    }
    ksort($configuration, SORT_STRING);
    $settings = [];
    foreach ($configuration as $settingname => $value) {
        $settings[(string)$settingname] = collector_theme_setting(
            (string)$settingname,
            $value
        );
    }
    return [
        'component' => 'theme_' . $name,
        'state' => $settings === [] ? 'empty' : 'complete',
        'settings' => $settings,
        'settings_count' => count($settings),
    ];
}

function collector_theme_course_metadata(string $theme): array {
    $theme = collector_theme_name($theme);
    return [
        'schema_version' => COLLECTOR_THEME_SCHEMA_VERSION,
        'state' => $theme === '' ? 'empty' : 'complete',
        'name' => $theme,
        'source' => 'course.theme',
    ];
}

function collector_theme_course_metadata_sha256(string $theme): string {
    return collector_theme_sha256(collector_theme_course_metadata($theme));
}

/**
 * El theme visual tiene huella propia y no altera el estado académico que
 * decide si un MBZ/checkpoint puede reutilizarse.
 */
function collector_theme_academic_course_inventory(array $inventory): array {
    if (is_array($inventory['course'] ?? null)) {
        unset($inventory['course']['theme']);
    }
    return $inventory;
}
