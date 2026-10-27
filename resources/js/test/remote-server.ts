import type { RemoteServer } from '@/components/remote-access-panel';

const GIB = 1073741824;

/** A connected source server with a current agent: the base the checklist tests change. */
export const remoteServer = (
    changes: Partial<RemoteServer> = {},
): RemoteServer => ({
    uuid: 'srv',
    name: 'Pregrado',
    role: 'SOURCE',
    host: null,
    connection: {
        type: 'aws-ssm',
        status: 'VALID',
        instance_id: 'i-0123456789abcdef0',
        region: 'us-east-2',
        host: null,
        port: null,
        user: null,
        use_sudo: false,
        secret_version: null,
        has_secret_reference: false,
        host_key_type: null,
        host_key_fingerprint: null,
        aws_account: null,
    },
    agent_update_blocked: null,
    collector_deployed: true,
    sources: [],
    sources_detected_at: null,
    consolidator: {
        deployed: false,
        tree_sha256: null,
        deployment_state: null,
        deployment_error: null,
        updated_at: null,
    },
    destinations: [],
    destinations_detected_at: null,
    storage_probe: {
        checked_at: 'x',
        mode: 'write',
        ok: true,
        error_code: null,
    },
    moodle_detected: null,
    destination_repoint: null,
    evidence: {
        checked_at: 'x',
        status: 'VALID',
        agent_version: '1.17.0',
        checks: [
            { key: 'transport', result: 'SUCCESS', message: 'Respondió.' },
            { key: 'agent', result: 'SUCCESS', message: 'Agente verificado.' },
        ],
        probe: {
            aws_cli_version: '2.17.1',
            php_version: '8.3.12',
            docker_version: null,
            compose_version: null,
            disk_free_bytes: 50 * GIB,
            memory_bytes: 8 * GIB,
        },
    },
    operations: [],
    ...changes,
});
