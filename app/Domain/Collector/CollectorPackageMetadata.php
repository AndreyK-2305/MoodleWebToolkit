<?php

namespace App\Domain\Collector;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Exceptions\ToolOperationBlocked;

final class CollectorPackageMetadata
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array{producer_version: string, schema_version: string, source_id: string, name: string, capabilities: array<string, string>, metadata_state: string, courses: int}
     */
    public function recognize(array $manifest): array
    {
        $producer = $manifest['collector_version'] ?? null;
        if (! in_array($producer, ['7.4.1-linux', '7.4.2-linux'], true)
            || ($manifest['schema_version'] ?? null) !== '1.0'
            || ($manifest['package_type'] ?? null) !== 'moodle-consolidation-source'
            || ($manifest['package_status'] ?? null) !== 'sealed'
            || ($manifest['source_write_performed'] ?? null) !== false
            || ($manifest['destination_write_performed'] ?? null) !== false) {
            throw new ToolOperationBlocked('El productor, schema o estado del paquete no es compatible.');
        }
        $source = $manifest['source_id'] ?? null;
        $courses = $manifest['courses_expected'] ?? null;
        if (! is_string($source) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $source) !== 1
            || ! is_int($courses) || $courses < 1 || $courses > 1000000) {
            throw new ToolOperationBlocked('El manifiesto está incompleto; no se inventará la identidad ni el número de cursos.');
        }
        $capabilities = $manifest['capabilities'] ?? [];
        if (! is_array($capabilities) || array_diff(array_keys($capabilities), ['theme_inventory']) !== []
            || (array_key_exists('theme_inventory', $capabilities) && $capabilities['theme_inventory'] !== '1.0')
            || ($producer === '7.4.2-linux' && ($capabilities['theme_inventory'] ?? null) !== '1.0')) {
            throw new ToolOperationBlocked('Las capabilities declaradas son desconocidas, incompletas o incompatibles.');
        }
        $name = $manifest['source_name'] ?? $source;
        if (! is_string($name) || $name === '' || mb_strlen($name) > 160
            || preg_match('/[\x00-\x1f\x7f]/u', $name) !== 0
            || app(SensitiveValueRedactor::class)->redactString($name) !== $name) {
            throw new ToolOperationBlocked('El nombre del origen no es metadata publicable.');
        }

        return ['producer_version' => $producer, 'schema_version' => '1.0', 'source_id' => $source, 'name' => $name,
            'capabilities' => $capabilities, 'metadata_state' => $producer === '7.4.1-linux' ? 'LEGACY' : 'COMPLETE', 'courses' => $courses];
    }
}
