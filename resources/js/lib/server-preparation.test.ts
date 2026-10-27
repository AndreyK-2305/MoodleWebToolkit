import { describe, expect, it } from 'vitest';
import type {
    RemoteDestinationProfile,
    RemoteServer,
    RemoteSourceProfile,
} from '@/components/remote-access-panel';
import {
    automaticWorkers,
    checklistProgress,
    chooseCandidate,
    destinationChecklist,
    preferredCandidate,
    sourceChecklist,
    sourceProfileRequest,
    validWorkers,
    workerChoices,
} from './server-preparation';
import type { SourceProfileDraft } from './server-preparation';
import { remoteServer as server } from '@/test/remote-server';

const GIB = 1073741824;

const profile = (
    changes: Partial<RemoteSourceProfile> = {},
): RemoteSourceProfile => ({
    id: 'pregrado',
    ready: true,
    checked_at: 'x',
    check_error: null,
    detected: true,
    valid: true,
    profile_error: null,
    runtime: 'native',
    moodle_release: '4.5.2 (Build: 20250210)',
    php_version: '8.3.12',
    dataroot_free_bytes: 10 * GIB,
    state_free_bytes: 10 * GIB,
    run_as: 'www-data',
    cpus: 4,
    aws_cli_v2: true,
    max_workers: 3,
    ...changes,
});

const destination = (
    changes: Partial<RemoteDestinationProfile> = {},
): RemoteDestinationProfile => ({
    id: 'campus',
    ready: true,
    checked_at: 'x',
    check_error: null,
    blockers: [],
    warnings: [],
    detected: true,
    valid: true,
    profile_error: null,
    public_url: 'https://campus.example.edu',
    docker_version: '27.3.1',
    memory_bytes: 8 * GIB,
    runtime_free_bytes: 50 * GIB,
    target_running: false,
    destination_written: false,
    in_use: false,
    target_volumes: 0,
    copias_packages: 0,
    created: null,
    preparation: null,
    copias: null,
    fetches: [],
    ...changes,
});

const states = (items: { key: string; state: string }[]) =>
    Object.fromEntries(items.map((item) => [item.key, item.state]));

describe('sourceChecklist', () => {
    it('is complete when every item of the source is ready', () => {
        const items = sourceChecklist(
            server({ sources: [profile()] }),
            'pregrado',
            '1.17.0',
        );

        expect(items.map((item) => item.key)).toEqual([
            'connection',
            'agent',
            'requirements',
            'storage',
            'collector',
            'moodle',
            'profile',
        ]);
        expect(checklistProgress(items)).toEqual({
            done: 7,
            total: 7,
            complete: true,
        });
        expect(items[6].detail).toContain('4 CPU');
    });

    it('blocks everything after a failed connection and offers to retry it', () => {
        const items = sourceChecklist(
            server({
                connection: { ...server().connection!, status: 'INVALID' },
                evidence: {
                    ...server().evidence!,
                    checks: [
                        {
                            key: 'transport',
                            result: 'ERROR',
                            message: 'Sin respuesta.',
                        },
                    ],
                },
            }),
            'pregrado',
            '1.17.0',
        );

        expect(items[0]).toMatchObject({ state: 'failed', action: 'test' });
        expect(items.slice(1).every((item) => item.state === 'blocked')).toBe(
            true,
        );
    });

    it('asks to update an older agent', () => {
        const items = sourceChecklist(
            server({
                evidence: { ...server().evidence!, agent_version: '1.16.0' },
            }),
            'pregrado',
            '1.17.0',
        );

        expect(items[1]).toMatchObject({ state: 'failed', action: 'install' });
    });

    it('fails the requirements without AWS CLI v2 and warns on low disk', () => {
        const probe = server().evidence!.probe!;
        const withProbe = (changes: Record<string, string | number | null>) =>
            sourceChecklist(
                server({
                    evidence: {
                        ...server().evidence!,
                        probe: { ...probe, ...changes },
                    },
                }),
                'pregrado',
                '1.17.0',
            )[2];

        expect(withProbe({ aws_cli_version: '1.29.0' }).state).toBe('failed');
        expect(withProbe({ php_version: null }).state).toBe('failed');
        expect(
            withProbe({ php_version: null, docker_version: '27.3.1' }).state,
        ).toBe('done');
        expect(withProbe({ disk_free_bytes: 2 * GIB }).state).toBe('warning');
    });

    it('shows the S3 probe failure with its retry', () => {
        const item = sourceChecklist(
            server({
                storage_probe: {
                    checked_at: 'x',
                    mode: 'write',
                    ok: false,
                    error_code: 'storage_put_failed',
                },
            }),
            'pregrado',
            '1.17.0',
        )[3];

        expect(item).toMatchObject({ state: 'failed', action: 'probe' });
        expect(item.detail).toContain('IAM');
    });

    it('proposes the detected installations, then the profile to create', () => {
        const items = sourceChecklist(
            server({
                moodle_detected: {
                    detected_at: 'x',
                    error_code: null,
                    php_present: true,
                    docker_checked: false,
                    candidates: [
                        {
                            config_path: '/var/www/moodle/config.php',
                            runtime: 'native',
                            compose_dir: null,
                            compose_service: null,
                            compose_profile: null,
                            run_as: 'www-data',
                            config_owner: 'root',
                            issue: null,
                            too_long: false,
                        },
                    ],
                },
            }),
            'pregrado',
            '1.17.0',
        );

        expect(states(items)).toMatchObject({
            moodle: 'pending',
            profile: 'pending',
        });
        expect(items[5].detail).toContain('1 instalación encontrada');
        expect(items[6].action).toBe('create');
    });

    it('fails a checked profile that is not ready and offers to check it again', () => {
        const item = sourceChecklist(
            server({
                sources: [
                    profile({
                        ready: false,
                        check_error: null,
                        moodle_release: null,
                    }),
                ],
            }),
            'pregrado',
            '1.17.0',
        )[6];

        expect(item).toMatchObject({ state: 'failed', action: 'check' });
    });
});

describe('workers', () => {
    it('resolves «Automático» to CPU − 1, at least 1 and at most 4', () => {
        expect([1, 2, 4, 16].map(automaticWorkers)).toEqual([1, 1, 3, 4]);
        expect(automaticWorkers(null)).toBe(1);
    });

    it('offers 1 to CPU − 1 and falls back to auto otherwise', () => {
        expect(workerChoices(4)).toEqual([1, 2, 3]);
        expect(workerChoices(1)).toEqual([1]);
        expect(workerChoices(null)).toEqual([]);
        expect(validWorkers('3', 4)).toBe('3');
        expect(validWorkers('4', 4)).toBe('auto');
        expect(validWorkers('2', null)).toBe('auto');
        expect(validWorkers('auto', null)).toBe('auto');
    });
});

describe('destinationChecklist', () => {
    const deployed = (changes: Partial<RemoteServer> = {}) =>
        server({
            role: 'DESTINATION',
            storage_probe: {
                checked_at: 'x',
                mode: 'readwrite',
                ok: true,
                error_code: null,
            },
            evidence: {
                ...server().evidence!,
                probe: {
                    docker_version: '27.3.1',
                    compose_version: '2.29.7',
                    memory_bytes: 8 * GIB,
                    disk_free_bytes: 60 * GIB,
                },
            },
            consolidator: {
                deployed: true,
                tree_sha256: 'b'.repeat(64),
                deployment_state: 'DEPLOYED',
                deployment_error: null,
                updated_at: 'x',
            },
            ...changes,
        });

    it('asks to create the profile when the server has none', () => {
        const items = destinationChecklist(deployed(), '1.17.0');

        expect(states(items)).toEqual({
            connection: 'done',
            agent: 'done',
            requirements: 'done',
            storage: 'done',
            consolidator: 'done',
            profile: 'pending',
            prepare: 'blocked',
        });
        expect(items[5].action).toBe('create');
    });

    it('requires Compose v2 and warns on low memory', () => {
        const probe = (changes: Record<string, string | number | null>) =>
            destinationChecklist(
                deployed({
                    evidence: {
                        ...deployed().evidence!,
                        probe: { ...deployed().evidence!.probe!, ...changes },
                    },
                }),
                '1.17.0',
            )[2].state;

        expect(probe({ compose_version: '1.29.2' })).toBe('failed');
        expect(probe({ memory_bytes: 2 * GIB })).toBe('warning');
    });

    it('says why a profile was not re-pointed after a deployment', () => {
        const item = destinationChecklist(
            deployed({
                destination_repoint: {
                    at: 'x',
                    tree_sha256: 'b'.repeat(64),
                    error_code: null,
                    destinations: [
                        {
                            id: 'campus',
                            repointed: false,
                            reason: 'destination_written',
                        },
                    ],
                },
            }),
            '1.17.0',
        )[4];

        expect(item.state).toBe('warning');
        expect(item.detail).toContain('ya recibió escrituras');
    });

    it('follows the deployment and the preparation', () => {
        expect(
            destinationChecklist(
                deployed({
                    consolidator: {
                        ...deployed().consolidator,
                        deployment_state: 'RUNNING',
                    },
                }),
                '1.17.0',
            )[4].state,
        ).toBe('running');
        const prepared = destinationChecklist(
            deployed({
                destinations: [
                    destination({
                        created: {
                            admin_password_location:
                                '/srv/mwt-destinations/campus/.env (MOODLE_ADMIN_PASSWORD)',
                        },
                        preparation: {
                            state: 'SUCCEEDED',
                            launched_at: null,
                            finished_at: null,
                            observed_at: null,
                            exit_code: 0,
                            error_code: null,
                            observe_error: null,
                            last_error_line: null,
                            markers: [],
                            tail: '',
                        },
                    }),
                ],
            }),
            '1.17.0',
        );

        expect(checklistProgress(prepared).complete).toBe(true);
        expect(prepared[5].detail).toContain('solo root');
    });
});

describe('source profile form', () => {
    const native = {
        config_path: '/opt/moodle-lab/code/config.php',
        runtime: 'native' as const,
        compose_dir: null,
        compose_service: null,
        compose_profile: null,
        run_as: 'www-data',
        issue: 'php_missing',
    };
    const compose = {
        ...native,
        config_path: '/var/www/html/config.php',
        runtime: 'compose' as const,
        compose_dir: '/opt/moodle-lab/runtime',
        compose_service: 'php',
        compose_profile: 'tools',
        issue: null,
    };
    const draft: SourceProfileDraft = {
        config_path: '',
        runtime: 'compose',
        run_as: 'www-data',
        compose_dir: '/opt/moodle-lab/runtime',
        compose_service: 'php',
        compose_profile: 'tools',
        force: false,
    };

    it('offers the Compose service first when the native install has a problem', () => {
        expect(preferredCandidate([native, compose])).toBe(compose);
        expect(preferredCandidate([native])).toBe(native);
    });

    it('keeps the runtime the administrator chose when picking a native path', () => {
        expect(chooseCandidate(draft, native, true)).toMatchObject({
            config_path: native.config_path,
            runtime: 'compose',
            compose_dir: '/opt/moodle-lab/runtime',
        });
        expect(chooseCandidate(draft, native, false).runtime).toBe('native');
        expect(
            chooseCandidate({ ...draft, runtime: 'native' }, compose, true),
        ).toMatchObject({ runtime: 'compose', compose_profile: 'tools' });
    });

    it('posts to its own endpoint, never to the collection configuration', () => {
        const request = sourceProfileRequest('p', 's', 'lab', {
            ...draft,
            config_path: compose.config_path,
        });

        expect(request.url).toBe('/projects/p/servers/s/sources/create');
        expect(request.payload).toEqual({
            source: 'lab',
            config_path: '/var/www/html/config.php',
            runtime: 'compose',
            run_as: 'www-data',
            force: false,
            compose_dir: '/opt/moodle-lab/runtime',
            compose_service: 'php',
            compose_profile: 'tools',
        });
        expect(
            sourceProfileRequest('p', 's', 'lab', {
                ...draft,
                runtime: 'native',
            }).payload,
        ).not.toHaveProperty('compose_dir');
    });
});

describe('written destination on an earlier Consolidator', () => {
    const upgraded = (changes: Partial<RemoteDestinationProfile> = {}) =>
        server({
            role: 'DESTINATION',
            consolidator: {
                deployed: true,
                tree_sha256: 'c'.repeat(64),
                version: '8.2.0',
                deployment_state: 'DEPLOYED',
                deployment_error: null,
                updated_at: 'x',
            },
            destination_repoint: {
                at: 'x',
                tree_sha256: 'c'.repeat(64),
                error_code: null,
                destinations: [
                    {
                        id: 'campus',
                        repointed: false,
                        reason: 'destination_written',
                    },
                ],
            },
            destinations: [
                destination({
                    ready: false,
                    blockers: ['tool_tree_mismatch'],
                    warnings: ['destination_written'],
                    destination_written: true,
                    in_use: true,
                    upgradable: true,
                    preparation: {
                        state: 'SUCCEEDED',
                        launched_at: null,
                        finished_at: null,
                        observed_at: null,
                        exit_code: 0,
                        error_code: null,
                        observe_error: null,
                        last_error_line: null,
                        markers: [],
                        tail: '',
                    },
                    ...changes,
                }),
            ],
        });

    it('explains the old version in Spanish and offers cleaning instead of blocking', () => {
        const items = destinationChecklist(upgraded(), '1.17.3');

        expect(items[5].state).toBe('warning');
        expect(items[5].detail).toContain('versión anterior del Consolidador');
        expect(items[5].detail).toContain('la 8.2.0');
        expect(items[5].detail).not.toContain('tool_tree_mismatch');
        // An earlier successful preparation does not count: it was for the old version.
        expect(items[6]).toMatchObject({ state: 'pending', action: 'prepare' });
        expect(items[6].detail).toContain('Limpiar el destino y preparar');
    });

    it('follows the cleaning preparation and does not offer it for other blockers', () => {
        const running = destinationChecklist(
            upgraded({
                preparation: {
                    state: 'RUNNING',
                    launched_at: null,
                    finished_at: null,
                    observed_at: null,
                    exit_code: null,
                    error_code: null,
                    observe_error: null,
                    last_error_line: null,
                    markers: [],
                    tail: '',
                },
            }),
            '1.17.3',
        );
        expect(running[6].state).toBe('running');

        const blocked = destinationChecklist(
            upgraded({
                upgradable: false,
                blockers: ['tool_tree_mismatch', 'env_missing'],
            }),
            '1.17.3',
        );
        expect(blocked[5].state).toBe('failed');
        expect(blocked[5].detail).toContain(
            'usa una versión anterior del Consolidador',
        );
        expect(blocked[5].detail).toContain('falta el .env');
        expect(blocked[6].state).toBe('blocked');
    });
});
