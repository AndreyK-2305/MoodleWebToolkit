<?php

$syntheticProfile = env('APP_ENV') === 'testing' && (bool) env('TOOL_RUNNER_SYNTHETIC_PROFILE', false);

return [
    'features' => [
        'recolector_742' => ['enabled' => (bool) env('TOOL_RECOLECTOR_742_ENABLED', false)],
        'consolidador_800' => ['enabled' => (bool) env('TOOL_CONSOLIDADOR_800_ENABLED', false)],
        'integrador_115' => ['enabled' => (bool) env('TOOL_INTEGRADOR_115_ENABLED', false)],
        'local_runner' => ['enabled' => (bool) env('TOOL_LOCAL_RUNNER_ENABLED', false)],
        'remote_agent' => ['enabled' => (bool) env('TOOL_REMOTE_AGENT_ENABLED', false)],
    ],
    'remote' => [
        'agent_source' => base_path('remote-agent/mwt-agent'),
        'agent_install_root' => env('TOOL_REMOTE_AGENT_ROOT', '/opt/mwt-agent'),
        'agent_state_dir' => env('TOOL_REMOTE_AGENT_STATE_DIR', '/var/lib/mwt-agent'),
        'agent_tools_dir' => env('TOOL_REMOTE_AGENT_TOOLS_DIR', '/opt/mwt-agent/tools'),
        'agent_sources_dir' => env('TOOL_REMOTE_AGENT_SOURCES_DIR', '/etc/mwt-agent/sources'),
        'agent_destinations_dir' => env('TOOL_REMOTE_AGENT_DESTINATIONS_DIR', '/etc/mwt-agent/destinations'),
        // Base64 characters per staging call; the installer already proved ~20 KB per SSM call.
        'deploy_chunk_bytes' => (int) env('TOOL_REMOTE_DEPLOY_CHUNK_BYTES', 16384),
        // Source packages uploaded by the source server's own role, under mwt/<project>/<source>/<sha256>.
        'package_bucket' => env('TOOL_REMOTE_PACKAGE_BUCKET'),
        'package_region' => env('TOOL_REMOTE_PACKAGE_REGION', 'us-east-2'),
        // Suggested for a new AWS SSM connection; the lab's instances live with the bucket.
        'default_region' => env('TOOL_REMOTE_DEFAULT_REGION', env('TOOL_REMOTE_PACKAGE_REGION', 'us-east-2')),
        'call_timeout_seconds' => (int) env('TOOL_REMOTE_CALL_TIMEOUT_SECONDS', 60),
        'max_output_bytes' => (int) env('TOOL_REMOTE_MAX_OUTPUT_BYTES', 65536),
        'minimum_free_bytes' => (int) env('TOOL_REMOTE_MINIMUM_FREE_BYTES', 10 * 1024 * 1024 * 1024),
        'ssm' => [
            'document' => 'AWS-RunShellScript',
            'poll_interval_milliseconds' => (int) env('TOOL_REMOTE_SSM_POLL_MILLISECONDS', 1000),
        ],
        'ssh' => [
            'binary' => env('TOOL_REMOTE_SSH_BINARY', '/usr/bin/ssh'),
            'connect_timeout_seconds' => (int) env('TOOL_REMOTE_SSH_CONNECT_TIMEOUT_SECONDS', 10),
            'scratch_root' => env('TOOL_REMOTE_SSH_SCRATCH_ROOT', storage_path('app/private/remote-ssh')),
        ],
        // Runs agent scripts with the local bash; only for the isolated test suite.
        'local_transport' => env('APP_ENV') === 'testing' && (bool) env('TOOL_REMOTE_LOCAL_TRANSPORT', false),
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
