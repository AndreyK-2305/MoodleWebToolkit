<?php

return [
    'features' => [
        'recolector_742' => ['enabled' => (bool) env('TOOL_RECOLECTOR_742_ENABLED', false)],
        'consolidador_800' => ['enabled' => (bool) env('TOOL_CONSOLIDADOR_800_ENABLED', false)],
        'integrador_115' => ['enabled' => (bool) env('TOOL_INTEGRADOR_115_ENABLED', false)],
        'local_runner' => ['enabled' => (bool) env('TOOL_LOCAL_RUNNER_ENABLED', false)],
    ],
    'workspaces' => [
        'root' => env('TOOL_WORKSPACES_ROOT', storage_path('app/private/workspaces')),
        'quota_bytes' => (int) env('TOOL_WORKSPACE_QUOTA_BYTES', 20 * 1024 * 1024 * 1024),
    ],
    'runner' => [
        'timeout_seconds' => (int) env('TOOL_RUNNER_TIMEOUT_SECONDS', 3600),
        'max_output_bytes' => (int) env('TOOL_RUNNER_MAX_OUTPUT_BYTES', 1048576),
        'enforce_os_limits' => (bool) env('TOOL_RUNNER_ENFORCE_OS_LIMITS', true),
        'limit_wrapper' => env('TOOL_RUNNER_LIMIT_WRAPPER', '/usr/bin/prlimit'),
        'limits' => [
            'cpu_seconds' => (int) env('TOOL_RUNNER_CPU_SECONDS', 86400),
            'memory_bytes' => (int) env('TOOL_RUNNER_MEMORY_BYTES', 8589934592),
            'processes' => (int) env('TOOL_RUNNER_MAX_PROCESSES', 128),
            'file_bytes' => (int) env('TOOL_RUNNER_MAX_FILE_BYTES', 1099511627776),
        ],
        'commands' => [],
    ],
];
