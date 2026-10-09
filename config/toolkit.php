<?php

$syntheticProfile = env('APP_ENV') === 'testing' && (bool) env('TOOL_RUNNER_SYNTHETIC_PROFILE', false);

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
        'host_id' => env('TOOL_RUNNER_HOST_ID'),
        'timeout_seconds' => (int) env('TOOL_RUNNER_TIMEOUT_SECONDS', 3600),
        'heartbeat_interval_seconds' => env('TOOL_RUNNER_HEARTBEAT_INTERVAL_SECONDS', 10),
        'stall_timeout_seconds' => env('TOOL_RUNNER_STALL_TIMEOUT_SECONDS'),
        'max_output_bytes' => (int) env('TOOL_RUNNER_MAX_OUTPUT_BYTES', 1048576),
        'durable_log_max_bytes' => env('TOOL_RUNNER_DURABLE_LOG_MAX_BYTES', 1048576),
        'stream_pending_max_bytes' => env('TOOL_RUNNER_STREAM_PENDING_MAX_BYTES', 65536),
        'enforce_os_limits' => (bool) env('TOOL_RUNNER_ENFORCE_OS_LIMITS', true),
        'limit_wrapper' => env('TOOL_RUNNER_LIMIT_WRAPPER', '/usr/bin/prlimit'),
        'session_wrapper' => env('TOOL_RUNNER_SESSION_WRAPPER', '/usr/bin/setsid'),
        'limits' => [
            'cpu_seconds' => env('TOOL_RUNNER_CPU_SECONDS'),
            'memory_bytes' => (int) env('TOOL_RUNNER_MEMORY_BYTES', 8589934592),
            'processes' => (int) env('TOOL_RUNNER_MAX_PROCESSES', 128),
            'file_bytes' => (int) env('TOOL_RUNNER_MAX_FILE_BYTES', 1099511627776),
        ],
        'cancel_grace_seconds' => (int) env('TOOL_RUNNER_CANCEL_GRACE_SECONDS', 3),
        'allow_force_kill' => (bool) env('TOOL_RUNNER_ALLOW_FORCE_KILL', false),
        'supervisor_start_timeout_seconds' => (int) env('TOOL_SUPERVISOR_START_TIMEOUT_SECONDS', 3),
        'synthetic_profile' => $syntheticProfile,
        'commands' => $syntheticProfile ? require base_path('tests/Support/registered-command-fixtures.php') : [],
    ],
];
