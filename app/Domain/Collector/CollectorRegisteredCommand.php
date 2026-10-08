<?php

namespace App\Domain\Collector;

use App\Exceptions\ToolOperationBlocked;

final class CollectorRegisteredCommand
{
    public const KEY = 'collector.742.lab';

    /** @return array<string, mixed> */
    public function definition(): array
    {
        if (! config('toolkit.features.recolector_742.enabled') || ! config('toolkit.features.local_runner.enabled')) {
            throw new ToolOperationBlocked('El comando COLLECT LAB requiere habilitación explícita.');
        }
        $uuid = '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D';

        return [
            'executable' => (string) config('collector.php_binary'),
            'fixed_arguments' => ['-c', (string) config('collector.php_ini'), base_path('bin/collector-bridge.php')],
            'parameters' => ['project_uuid' => ['pattern' => $uuid], 'execution_uuid' => ['pattern' => $uuid], 'runtime_sha256' => ['pattern' => '/^[a-f0-9]{64}$/D']],
            'environment' => [
                'REGISTERED_TARGET_PHP_INI_SCAN_DIR' => (string) config('collector.php_scan_dir'),
                'COLLECTOR_WORKSPACE_ROOT' => (string) config('toolkit.workspaces.root'),
                'COLLECTOR_REFERENCE_ROOT' => (string) config('collector.secret_root'),
            ],
            'startup_timeout_seconds' => 10, 'heartbeat_interval_seconds' => 10,
            'stall_timeout_seconds' => 7200, 'wall_timeout_seconds' => null, 'cancellation_grace_seconds' => 10,
            'resource_limits' => ['cpu_seconds' => null, 'memory_bytes' => 2147483648, 'processes' => 128, 'file_bytes' => 21474836480],
            'cancellable' => true,
            'artifact_descriptors' => [
                ['relative_path' => 'source-package.zip', 'category' => 'SOURCE_PACKAGE', 'name' => 'source-package.zip', 'mime_types' => ['application/zip'], 'max_size_bytes' => 21474836480, 'required' => true, 'sensitivity' => 'CONFIDENTIAL'],
                ['relative_path' => 'source-package.zip.sha256', 'category' => 'REPORT', 'name' => 'source-package.zip.sha256', 'mime_types' => ['text/plain'], 'max_size_bytes' => 256, 'required' => true],
                ['relative_path' => 'manifest.json', 'category' => 'MANIFEST', 'name' => 'manifest.json', 'mime_types' => ['application/json', 'text/plain'], 'max_size_bytes' => 1048576, 'required' => true],
                ['relative_path' => 'inventory.json', 'category' => 'REPORT', 'name' => 'inventory.json', 'mime_types' => ['application/json', 'text/plain'], 'max_size_bytes' => 1048576, 'required' => true],
                ['relative_path' => 'visual-inventory.json', 'category' => 'REPORT', 'name' => 'visual-inventory.json', 'mime_types' => ['application/json', 'text/plain'], 'max_size_bytes' => 1048576, 'required' => true],
                ['relative_path' => 'validation.json', 'category' => 'REPORT', 'name' => 'validation.json', 'mime_types' => ['application/json', 'text/plain'], 'max_size_bytes' => 65536, 'required' => true],
            ],
        ];
    }
}
