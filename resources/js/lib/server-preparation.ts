import type { RemoteServer } from '@/components/remote-access-panel';

/**
 * The «Preparar origen» and «Preparar destino» checklists. Each item reads
 * its live state from the server's evidence (connection test, probe, S3
 * probe, deployments, profile checks) and names the one action that retries
 * it. An item waits while an earlier one it depends on is not done.
 */

export type ChecklistState =
    | 'done'
    | 'warning'
    | 'failed'
    | 'running'
    | 'pending'
    | 'blocked';

export type ChecklistAction =
    | 'test'
    | 'install'
    | 'probe'
    | 'deploy'
    | 'detect'
    | 'create'
    | 'check'
    | 'prepare';

export type ChecklistItem = {
    key: string;
    label: string;
    state: ChecklistState;
    /** What was found, or what is missing. */
    detail: string;
    /** What retries or completes the item; null when nothing does from here. */
    action: ChecklistAction | null;
};

const GIB = 1073741824;
/** The agent's state directory holds the Recolector's package until it is uploaded. */
const SOURCE_MIN_FREE = 10 * GIB;
/** Matches CheckRemoteDestination on the backend. */
const DESTINATION_MIN_FREE = 20 * GIB;
const DESTINATION_MIN_MEMORY = 3_500_000_000;

const RECOLECTOR_MAX_WORKERS = 4;

export function gib(bytes: number | null | undefined): string {
    return typeof bytes === 'number' ? `${(bytes / GIB).toFixed(1)} GiB` : '—';
}

/** «Automático»: CPU − 1, at least 1, never above what the Recolector 7.4.2 accepts. */
export function automaticWorkers(cpus: number | null | undefined): number {
    return typeof cpus === 'number'
        ? Math.max(1, Math.min(RECOLECTOR_MAX_WORKERS, cpus - 1))
        : 1;
}

/** The explicit choices next to «Automático»: 1 to CPU − 1. */
export function workerChoices(cpus: number | null | undefined): number[] {
    if (typeof cpus !== 'number') {
        return [];
    }

    return Array.from(
        { length: automaticWorkers(cpus) },
        (_, index) => index + 1,
    );
}

/** A saved value that the reported CPUs no longer allow falls back to «auto». */
export function validWorkers(
    workers: string,
    cpus: number | null | undefined,
): string {
    return workers === 'auto' || workerChoices(cpus).includes(Number(workers))
        ? workers
        : 'auto';
}

export function checklistProgress(items: ChecklistItem[]): {
    done: number;
    total: number;
    complete: boolean;
} {
    const done = items.filter((item) =>
        ['done', 'warning'].includes(item.state),
    ).length;

    return { done, total: items.length, complete: done === items.length };
}

function item(
    key: string,
    label: string,
    state: ChecklistState,
    detail: string,
    action: ChecklistAction | null = null,
): ChecklistItem {
    return { key, label, state, detail, action };
}

function finished(state: ChecklistState): boolean {
    return state === 'done' || state === 'warning';
}

function check(server: RemoteServer, key: string) {
    return server.evidence?.checks.find((entry) => entry.key === key) ?? null;
}

function connectionItem(server: RemoteServer): ChecklistItem {
    const label = 'Conexión SSM';
    if (!server.connection) {
        return item(
            'connection',
            label,
            'pending',
            'Guarde el ID de instancia y la región de este servidor.',
        );
    }
    const transport = check(server, 'transport');
    if (transport?.result === 'ERROR') {
        return item('connection', label, 'failed', transport.message, 'test');
    }
    if (transport?.result === 'SUCCESS') {
        return item('connection', label, 'done', transport.message, 'test');
    }

    return item(
        'connection',
        label,
        'pending',
        'Pruebe la conexión con el servidor.',
        'test',
    );
}

function agentItem(
    server: RemoteServer,
    agentVersion: string | null,
    connection: ChecklistItem,
): ChecklistItem {
    const label = 'Agente instalado o actualizado';
    if (!finished(connection.state)) {
        return item('agent', label, 'blocked', 'Requiere la conexión.');
    }
    const agent = check(server, 'agent');
    if (
        agent?.result === 'SUCCESS' &&
        server.evidence?.agent_version === agentVersion
    ) {
        return item('agent', label, 'done', agent.message, 'install');
    }
    if (agent?.result === 'SUCCESS') {
        return item(
            'agent',
            label,
            'failed',
            `El servidor tiene otra versión; instale el agente ${agentVersion ?? ''}.`.trim(),
            'install',
        );
    }

    return item(
        'agent',
        label,
        agent ? 'failed' : 'pending',
        agent?.message ?? `Instale el agente ${agentVersion ?? ''}.`.trim(),
        'install',
    );
}

function storageItem(
    server: RemoteServer,
    agent: ChecklistItem,
    label: string,
): ChecklistItem {
    if (!finished(agent.state)) {
        return item('storage', label, 'blocked', 'Requiere el agente.');
    }
    const probe = server.storage_probe;
    if (!probe) {
        return item(
            'storage',
            label,
            'pending',
            'Pruebe el bucket de paquetes con el rol de la instancia.',
            'probe',
        );
    }
    if (probe.ok) {
        return item(
            'storage',
            label,
            'done',
            probe.mode === 'readwrite'
                ? 'Escribe y lee el bucket; la plataforma confirmó el objeto de prueba.'
                : 'Sube al bucket; la plataforma confirmó el objeto de prueba.',
            'probe',
        );
    }

    return item(
        'storage',
        label,
        'failed',
        storageProblem(probe.error_code),
        'probe',
    );
}

const STORAGE_CODES: Record<string, string> = {
    aws_cli_unavailable: 'falta la AWS CLI en el servidor',
    storage_put_failed:
        'el rol de la instancia no pudo escribir en el bucket (revise su política IAM)',
    storage_read_failed:
        'el rol de la instancia no pudo leer del bucket (revise su política IAM)',
    storage_read_mismatch: 'el objeto leído no coincide con el escrito',
    storage_probe_mismatch:
        'la plataforma no encontró el objeto tal como lo escribió el servidor',
};

function storageProblem(code: string | null | undefined): string {
    if (!code) {
        return 'La prueba de S3 no terminó.';
    }
    if (code.startsWith('storage_head_')) {
        return `La plataforma no pudo leer el objeto de prueba con su identidad (${code}).`;
    }

    return `La prueba de S3 falló: ${STORAGE_CODES[code] ?? code}.`;
}

/** Steps of «Preparar origen» for one profile of a source server. */
export function sourceChecklist(
    server: RemoteServer,
    profileId: string,
    agentVersion: string | null,
): ChecklistItem[] {
    const connection = connectionItem(server);
    const agent = agentItem(server, agentVersion, connection);
    const probe = server.evidence?.probe ?? null;
    const profile = server.sources.find((source) => source.id === profileId);

    let requirements: ChecklistItem;
    const requirementsLabel = 'Requisitos (AWS CLI v2, PHP, disco)';
    if (!finished(agent.state)) {
        requirements = item(
            'requirements',
            requirementsLabel,
            'blocked',
            'Requiere el agente.',
        );
    } else {
        const aws = String(probe?.aws_cli_version ?? '');
        const php = probe?.php_version ? String(probe.php_version) : null;
        const docker = probe?.docker_version
            ? String(probe.docker_version)
            : null;
        const free =
            typeof probe?.disk_free_bytes === 'number'
                ? probe.disk_free_bytes
                : null;
        const problems: string[] = [];
        if (!aws.startsWith('2.')) {
            problems.push(
                aws ? `AWS CLI ${aws}: se requiere la v2` : 'falta AWS CLI v2',
            );
        }
        if (!php && !docker) {
            problems.push('no hay PHP ni Docker para ejecutar Moodle');
        }
        const facts = [
            `AWS CLI ${aws || '—'}`,
            php
                ? `PHP ${php}`
                : docker
                  ? `PHP en contenedor (Docker ${docker})`
                  : 'sin PHP',
            `disco libre ${gib(free)}`,
        ].join(' · ');
        if (problems.length > 0) {
            requirements = item(
                'requirements',
                requirementsLabel,
                'failed',
                `${problems.join('; ')}. ${facts}`,
                'test',
            );
        } else if (free !== null && free < SOURCE_MIN_FREE) {
            requirements = item(
                'requirements',
                requirementsLabel,
                'warning',
                `Poco disco para el paquete. ${facts}`,
                'test',
            );
        } else {
            requirements = item(
                'requirements',
                requirementsLabel,
                'done',
                facts,
                'test',
            );
        }
    }

    const storage = storageItem(server, agent, 'Puede subir a S3');
    const collector = !finished(agent.state)
        ? item(
              'collector',
              'Recolector desplegado',
              'blocked',
              'Requiere el agente.',
          )
        : server.collector_deployed
          ? item(
                'collector',
                'Recolector desplegado',
                'done',
                'Recolector 7.4.2 verificado por hash.',
                'deploy',
            )
          : item(
                'collector',
                'Recolector desplegado',
                'pending',
                'Despliegue el Recolector 7.4.2 verificado.',
                'deploy',
            );

    const moodleLabel = 'Moodle detectado';
    let moodle: ChecklistItem;
    if (!finished(agent.state)) {
        moodle = item('moodle', moodleLabel, 'blocked', 'Requiere el agente.');
    } else if (profile && (profile.detected || profile.checked_at)) {
        moodle = item(
            'moodle',
            moodleLabel,
            'done',
            `El perfil «${profile.id}» ya existe en el servidor (${profile.runtime ?? 'runtime sin comprobar'}).`,
            'detect',
        );
    } else {
        const detected = server.moodle_detected;
        const count = detected?.candidates.length ?? 0;
        moodle = item(
            'moodle',
            moodleLabel,
            detected?.error_code ? 'failed' : 'pending',
            detected?.error_code
                ? `La búsqueda falló (${detected.error_code}).`
                : detected
                  ? count > 0
                      ? `${count} ${count === 1 ? 'instalación encontrada' : 'instalaciones encontradas'}: elija una o escriba la ruta y confírmela.`
                      : 'No se encontró Moodle en las rutas habituales: escriba la ruta de config.php.'
                  : 'Busque las instalaciones de Moodle del servidor.',
            'detect',
        );
    }

    const profileLabel = 'Perfil creado y comprobado';
    let checked: ChecklistItem;
    // Confirming the chosen (or typed) installation is what creates the profile.
    if (!finished(agent.state)) {
        checked = item(
            'profile',
            profileLabel,
            'blocked',
            'Requiere el agente.',
            'create',
        );
    } else if (!profile) {
        checked = item(
            'profile',
            profileLabel,
            'pending',
            `Cree el perfil «${profileId || '…'}» desde la instalación elegida.`,
            'create',
        );
    } else if (profile.ready) {
        checked = item(
            'profile',
            profileLabel,
            'done',
            [
                `Moodle ${profile.moodle_release ?? '—'}`,
                `PHP ${profile.php_version ?? '—'}`,
                `${profile.cpus ?? '—'} CPU`,
                profile.aws_cli_v2 === false ? 'sin AWS CLI v2' : null,
            ]
                .filter(Boolean)
                .join(' · '),
            'check',
        );
    } else if (profile.checked_at) {
        checked = item(
            'profile',
            profileLabel,
            'failed',
            `La comprobación falló${profile.check_error ? ` (${profile.check_error})` : ''}: PHP ${profile.php_version ?? '—'}, Moodle ${profile.moodle_release ?? 'no detectado'}.`,
            'check',
        );
    } else {
        checked = item(
            'profile',
            profileLabel,
            'pending',
            'Compruebe el perfil.',
            'check',
        );
    }

    return [
        connection,
        agent,
        requirements,
        storage,
        collector,
        moodle,
        checked,
    ];
}

/** Steps of «Preparar destino» for a destination server. */
export function destinationChecklist(
    server: RemoteServer,
    agentVersion: string | null,
): ChecklistItem[] {
    const connection = connectionItem(server);
    connection.label = 'Conexión';
    const agent = agentItem(server, agentVersion, connection);
    agent.label = 'Agente';
    const probe = server.evidence?.probe ?? null;

    const requirementsLabel = 'Requisitos (Docker, Compose v2, memoria, disco)';
    let requirements: ChecklistItem;
    if (!finished(agent.state)) {
        requirements = item(
            'requirements',
            requirementsLabel,
            'blocked',
            'Requiere el agente.',
        );
    } else {
        const docker = probe?.docker_version
            ? String(probe.docker_version)
            : null;
        const compose = probe?.compose_version
            ? String(probe.compose_version)
            : null;
        const memory =
            typeof probe?.memory_bytes === 'number' ? probe.memory_bytes : null;
        const free =
            typeof probe?.disk_free_bytes === 'number'
                ? probe.disk_free_bytes
                : null;
        const problems: string[] = [];
        if (!docker) {
            problems.push('falta Docker');
        }
        if (!compose || !/^v?2\./.test(compose)) {
            problems.push('falta Docker Compose v2');
        }
        const facts = [
            `Docker ${docker ?? '—'}`,
            `Compose ${compose ?? '—'}`,
            `memoria ${gib(memory)}`,
            `disco libre ${gib(free)}`,
        ].join(' · ');
        const low =
            (memory !== null && memory < DESTINATION_MIN_MEMORY) ||
            (free !== null && free < DESTINATION_MIN_FREE);
        requirements = item(
            'requirements',
            requirementsLabel,
            problems.length > 0 ? 'failed' : low ? 'warning' : 'done',
            problems.length > 0
                ? `${problems.join('; ')}. ${facts}`
                : low
                  ? `Por debajo de 4 GiB de memoria o 20 GiB libres. ${facts}`
                  : facts,
            'test',
        );
    }

    const storage = storageItem(
        server,
        agent,
        'S3: lectura y escritura del respaldo',
    );

    const consolidatorLabel = 'Consolidador desplegado, perfil re-apuntado';
    const deployment = server.consolidator.deployment_state;
    const repoint = server.destination_repoint;
    let consolidator: ChecklistItem;
    if (!finished(agent.state)) {
        consolidator = item(
            'consolidator',
            consolidatorLabel,
            'blocked',
            'Requiere el agente.',
        );
    } else if (deployment === 'QUEUED' || deployment === 'RUNNING') {
        consolidator = item(
            'consolidator',
            consolidatorLabel,
            'running',
            'Desplegando el Consolidador 8.2.0…',
        );
    } else if (deployment === 'FAILED') {
        consolidator = item(
            'consolidator',
            consolidatorLabel,
            'failed',
            `El despliegue falló (${server.consolidator.deployment_error ?? 'error'}).`,
            'deploy',
        );
    } else if (!server.consolidator.deployed) {
        consolidator = item(
            'consolidator',
            consolidatorLabel,
            'pending',
            'Despliegue el Consolidador verificado.',
            'deploy',
        );
    } else {
        const kept = (repoint?.destinations ?? []).filter(
            (entry) => !entry.repointed && entry.reason !== 'already_current',
        );
        const moved = (repoint?.destinations ?? []).filter(
            (entry) => entry.repointed,
        );
        consolidator = item(
            'consolidator',
            consolidatorLabel,
            repoint?.error_code || kept.length > 0 ? 'warning' : 'done',
            repoint?.error_code
                ? `Desplegado; no se pudieron re-apuntar los perfiles (${repoint.error_code}).`
                : kept.length > 0
                  ? `Desplegado. ${kept.map((entry) => `«${entry.id}» no se re-apuntó: ${REPOINT_REASONS[entry.reason] ?? entry.reason}`).join('; ')}.`
                  : moved.length > 0
                    ? `Desplegado; ${moved.map((entry) => `«${entry.id}»`).join(', ')} apunta${moved.length === 1 ? '' : 'n'} a la nueva versión.`
                    : 'Desplegado y verificado por hash.',
            'deploy',
        );
    }

    const profileLabel = 'Perfil del destino creado y comprobado';
    const profiles = server.destinations;
    const ready = profiles.find((profile) => profile.ready);
    let profile: ChecklistItem;
    if (!server.consolidator.deployed) {
        profile = item(
            'profile',
            profileLabel,
            'blocked',
            'Requiere el Consolidador desplegado.',
        );
    } else if (ready) {
        profile = item(
            'profile',
            profileLabel,
            ready.warnings.length > 0 ? 'warning' : 'done',
            `«${ready.id}» · ${ready.public_url ?? '—'}${ready.created?.admin_password_location ? ` · clave del administrador en ${ready.created.admin_password_location}, solo root` : ''}`,
            'check',
        );
    } else if (profiles.length > 0) {
        const first = profiles[0];
        profile = item(
            'profile',
            profileLabel,
            first.checked_at ? 'failed' : 'pending',
            first.checked_at
                ? `«${first.id}» no está listo${first.check_error ? ` (${first.check_error})` : ''}${first.blockers.length > 0 ? `: ${first.blockers.join(', ')}` : ''}.`
                : `Compruebe el perfil «${first.id}».`,
            'check',
        );
    } else {
        profile = item(
            'profile',
            profileLabel,
            'pending',
            'Cree el perfil: URL pública HTTPS y correo del administrador.',
            'create',
        );
    }

    const preparation = (ready ?? profiles[0])?.preparation ?? null;
    const prepareLabel = 'Preparar destino';
    let prepare: ChecklistItem;
    if (!ready) {
        prepare = item(
            'prepare',
            prepareLabel,
            'blocked',
            'Requiere un perfil comprobado.',
        );
    } else if (
        ['STARTING', 'RUNNING', 'CANCELLING'].includes(preparation?.state ?? '')
    ) {
        prepare = item(
            'prepare',
            prepareLabel,
            'running',
            'La preparación continúa en el servidor.',
        );
    } else if (preparation?.state === 'SUCCEEDED') {
        prepare = item(
            'prepare',
            prepareLabel,
            'done',
            'Moodle 5.2.1 instalado y preflight del Consolidador superado.',
            'prepare',
        );
    } else if (
        preparation?.state === 'FAILED' ||
        preparation?.state === 'CANCELLED'
    ) {
        prepare = item(
            'prepare',
            prepareLabel,
            'failed',
            `La preparación terminó como ${preparation.state === 'FAILED' ? 'fallida' : 'cancelada'}${preparation.error_code ? ` (${preparation.error_code})` : ''}.`,
            'prepare',
        );
    } else {
        prepare = item(
            'prepare',
            prepareLabel,
            'pending',
            'Prepare el destino; si ya tiene un sitio, elija limpiarlo o reutilizarlo.',
            'prepare',
        );
    }

    return [
        connection,
        agent,
        requirements,
        storage,
        consolidator,
        profile,
        prepare,
    ];
}

export const REPOINT_REASONS: Record<string, string> = {
    destination_written:
        'el destino ya recibió escrituras; use «Preparar destino» con limpieza para pasar a la nueva versión',
    runtime_missing: 'falta su runtime',
    env_url_mismatch: 'la URL del .env difiere de la del perfil',
    profile_invalid: 'el perfil no es válido',
};

export type SourceProfileDraft = {
    config_path: string;
    runtime: 'native' | 'compose';
    run_as: string;
    compose_dir: string;
    compose_service: string;
    compose_profile: string;
    force: boolean;
};

type Candidate = {
    config_path: string;
    runtime: 'native' | 'compose';
    compose_dir: string | null;
    compose_service: string | null;
    compose_profile: string | null;
    run_as: string | null;
    issue: string | null;
};

/** The installation offered first: one without a known problem, Compose ahead of native on a tie. */
export function preferredCandidate<T extends Candidate>(
    candidates: T[],
): T | undefined {
    return (
        candidates.find(
            (candidate) => !candidate.issue && candidate.runtime === 'compose',
        ) ??
        candidates.find((candidate) => !candidate.issue) ??
        candidates[0]
    );
}

/**
 * Applies a detected installation to the draft. A Compose candidate brings its
 * directory, service and profile; a native one keeps the runtime the
 * administrator chose by hand, so picking a path never undoes that choice.
 */
export function chooseCandidate(
    draft: SourceProfileDraft,
    candidate: Candidate,
    runtimeEdited: boolean,
): SourceProfileDraft {
    if (candidate.runtime === 'compose') {
        return {
            ...draft,
            config_path: candidate.config_path,
            runtime: 'compose',
            run_as: candidate.run_as ?? draft.run_as,
            compose_dir: candidate.compose_dir ?? draft.compose_dir,
            compose_service: candidate.compose_service ?? draft.compose_service,
            compose_profile: candidate.compose_profile ?? '',
        };
    }

    return {
        ...draft,
        config_path: candidate.config_path,
        run_as: candidate.run_as ?? draft.run_as,
        runtime: runtimeEdited ? draft.runtime : 'native',
    };
}

/** The request «Crear y comprobar el perfil» sends: its own endpoint, never the collection's. */
export function sourceProfileRequest(
    projectUuid: string,
    serverUuid: string,
    profileId: string,
    draft: SourceProfileDraft,
): { url: string; payload: Record<string, string | boolean | null> } {
    return {
        url: `/projects/${projectUuid}/servers/${serverUuid}/sources/create`,
        payload: {
            source: profileId,
            config_path: draft.config_path,
            runtime: draft.runtime,
            run_as: draft.run_as,
            force: draft.force,
            ...(draft.runtime === 'compose'
                ? {
                      compose_dir: draft.compose_dir,
                      compose_service: draft.compose_service,
                      compose_profile: draft.compose_profile || null,
                  }
                : {}),
        },
    };
}
