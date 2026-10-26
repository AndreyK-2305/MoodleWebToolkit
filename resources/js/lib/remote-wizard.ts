import type { RemoteServer } from '@/components/remote-access-panel';

/**
 * Steps of the project wizard with real servers. Each step reports its real
 * state from the saved configuration and the servers' evidence: whether it is
 * complete and, if not, what blocks moving on. A later step opens only when
 * every earlier one is complete.
 */

export type WizardStepKey =
    | 'basics'
    | 'sources'
    | 'destination'
    | 'preflight'
    | 'confirmation';

export type WizardStep = {
    key: WizardStepKey;
    label: string;
    complete: boolean;
    /** One line for the stepper: what was done, or what is missing. */
    status: string;
    /** What blocks moving on from this step, or null when it is complete. */
    blocker: string | null;
};

export type WizardServer = {
    uuid: string;
    name: string;
    role: 'SOURCE' | 'DESTINATION' | 'AUXILIARY';
    connected: boolean;
    /** The Recolector on a source, the Consolidator on a destination. */
    deployed: boolean;
    readyProfiles: string[];
};

export type RemoteWizardInput = {
    type: 'COLLECT' | 'CONSOLIDATE';
    basicsSaved: boolean;
    configurationVersion: number;
    options: {
        mode?: string | null;
        sources?: { server_uuid: string; source_profile: string }[] | null;
        server_uuid?: string | null;
        source_profile?: string | null;
        destination_profile?: string | null;
        source_packages?: unknown[] | null;
    };
    servers: WizardServer[];
    selection: { packages: string[] } | null;
    link: {
        mode: 'PROJECT' | 'COLLECT_NOW';
        picked_up_at: string | null;
        sources_total: number;
        sources_collected: number;
        destination_chosen: boolean;
        destination_profile: string | null;
    } | null;
    preflight: {
        configuration_version: number;
        checks: { result: 'SUCCESS' | 'WARNING' | 'ERROR' }[];
    } | null;
    confirmation: { configuration_version: number } | null;
};

const MIN_PACKAGES = 2;

export function remoteWizardSteps(input: RemoteWizardInput): WizardStep[] {
    const confirmed =
        input.confirmation?.configuration_version ===
        input.configurationVersion;
    const preflight = preflightStep(input);
    // A passing preflight or a confirmation already proved the servers ready; a
    // server busy with the execution afterwards does not reopen its step.
    const proven = preflight.complete || confirmed;
    const steps: WizardStep[] = [basicsStep(input)];

    if (input.type === 'COLLECT') {
        steps.push(collectionSourcesStep(input, proven));
    } else {
        steps.push(consolidationSourcesStep(input));
        steps.push(destinationStep(input, proven));
    }
    steps.push(preflight, {
        key: 'confirmation',
        label: 'Confirmación',
        complete: confirmed,
        status: confirmed ? 'Confirmado' : 'Pendiente',
        blocker: confirmed ? null : 'Confirme la configuración vigente.',
    });

    return steps;
}

/** Whether a step may be opened: every earlier step is complete. */
export function canOpenStep(steps: WizardStep[], index: number): boolean {
    return steps.slice(0, index).every((step) => step.complete);
}

/** The step the wizard opens on: the first one not complete yet. */
export function initialStep(steps: WizardStep[]): number {
    const open = steps.findIndex((step) => !step.complete);

    return open === -1 ? steps.length - 1 : open;
}

/** What blocks opening the step at `index`, from the first earlier step not complete. */
export function stepBlocker(steps: WizardStep[], index: number): string | null {
    const pending = steps.slice(0, index).find((step) => !step.complete);

    return pending ? `${pending.label}: ${pending.blocker}` : null;
}

function done(key: WizardStepKey, label: string, status: string): WizardStep {
    return { key, label, complete: true, status, blocker: null };
}

function pending(
    key: WizardStepKey,
    label: string,
    blocker: string,
    status = 'Pendiente',
): WizardStep {
    return { key, label, complete: false, status, blocker };
}

function basicsStep(input: RemoteWizardInput): WizardStep {
    // A consolidation registers its destination when its basics are saved.
    if (
        input.type === 'CONSOLIDATE' &&
        !input.servers.some((server) => server.role === 'DESTINATION')
    ) {
        return pending(
            'basics',
            'Datos básicos',
            'Guarde los datos básicos para registrar el servidor de destino.',
        );
    }

    return input.basicsSaved
        ? done('basics', 'Datos básicos', 'Guardados')
        : pending('basics', 'Datos básicos', 'Guarde los datos básicos.');
}

function collectionSourcesStep(
    input: RemoteWizardInput,
    proven: boolean,
): WizardStep {
    const label = 'Orígenes';
    const servers = input.servers.filter((server) => server.role === 'SOURCE');
    if (servers.length === 0) {
        return pending('sources', label, 'Agregue al menos un origen.');
    }
    const rows =
        input.options.mode !== 'REMOTE'
            ? []
            : input.options.sources?.length
              ? input.options.sources
              : input.options.server_uuid
                ? [
                      {
                          server_uuid: input.options.server_uuid,
                          source_profile: input.options.source_profile ?? '',
                      },
                  ]
                : [];
    const counted = `${servers.length} ${servers.length === 1 ? 'origen' : 'orígenes'}`;
    if (!proven) {
        for (const server of servers) {
            const problem = sourceProblem(server);
            if (problem) {
                return pending('sources', label, problem, counted);
            }
        }
    }
    if (rows.length === 0) {
        return pending(
            'sources',
            label,
            'Guarde la recolección: perfil y nombre del paquete de cada origen.',
            counted,
        );
    }
    const missing = servers.find(
        (server) => !rows.some((row) => row.server_uuid === server.uuid),
    );
    if (missing) {
        return pending(
            'sources',
            label,
            `Guarde de nuevo la recolección para incluir «${missing.name}».`,
            counted,
        );
    }
    if (!proven) {
        const unchecked = rows.find(
            (row) =>
                !servers
                    .find((server) => server.uuid === row.server_uuid)
                    ?.readyProfiles.includes(row.source_profile),
        );
        if (unchecked) {
            return pending(
                'sources',
                label,
                'Compruebe el perfil elegido de cada origen antes de continuar.',
                counted,
            );
        }
    }

    return done('sources', label, `${counted} listos`);
}

function sourceProblem(server: WizardServer): string | null {
    if (!server.connected) {
        return `Conecte y pruebe «${server.name}».`;
    }
    if (!server.deployed) {
        return `Despliegue el Recolector en «${server.name}».`;
    }
    if (server.readyProfiles.length === 0) {
        return `Detecte y compruebe el perfil de «${server.name}».`;
    }

    return null;
}

function consolidationSourcesStep(input: RemoteWizardInput): WizardStep {
    const label = 'Orígenes';
    const link = input.link;
    if (link?.mode === 'COLLECT_NOW') {
        return done(
            'sources',
            label,
            link.picked_up_at
                ? 'Paquetes de la recolección vinculada'
                : `Recolección vinculada: ${link.sources_collected}/${link.sources_total} orígenes`,
        );
    }
    const packages =
        input.selection?.packages.length ??
        (input.options.mode === 'REMOTE_CONSOLIDATION'
            ? (input.options.source_packages?.length ?? 0)
            : 0);
    if (packages >= MIN_PACKAGES) {
        return done('sources', label, `${packages} paquetes elegidos`);
    }

    return pending(
        'sources',
        label,
        'Elija una recolección completada o recolecte los orígenes ahora.',
    );
}

function destinationStep(
    input: RemoteWizardInput,
    proven: boolean,
): WizardStep {
    const label = 'Destino';
    const server = input.servers.find(
        (item) =>
            item.role === 'DESTINATION' &&
            (!input.options.server_uuid ||
                input.options.mode !== 'REMOTE_CONSOLIDATION' ||
                item.uuid === input.options.server_uuid),
    );
    if (!server) {
        return pending(
            'destination',
            label,
            'Guarde los datos básicos para registrar el servidor de destino.',
        );
    }
    const pendingLink =
        input.link?.mode === 'COLLECT_NOW' && input.link.picked_up_at === null;
    const savedProfile =
        input.options.mode === 'REMOTE_CONSOLIDATION'
            ? (input.options.destination_profile ?? null)
            : null;
    const profile =
        pendingLink && input.link?.destination_chosen
            ? (input.link.destination_profile ?? savedProfile)
            : savedProfile;
    if (!proven) {
        if (!server.connected) {
            return pending(
                'destination',
                label,
                'Conecte el servidor de destino y pruebe la conexión.',
            );
        }
        if (!server.deployed) {
            return pending(
                'destination',
                label,
                'Despliegue el Consolidador en el destino.',
            );
        }
        if (server.readyProfiles.length === 0) {
            return pending(
                'destination',
                label,
                'Detecte, compruebe o prepare un perfil de destino.',
            );
        }
    }
    if (profile === null) {
        return pending(
            'destination',
            label,
            'Guarde el destino: perfil y workers.',
        );
    }
    if (!proven && !server.readyProfiles.includes(profile)) {
        return pending(
            'destination',
            label,
            `Compruebe el perfil «${profile}» antes de continuar.`,
        );
    }

    return done('destination', label, `Perfil «${profile}»`);
}

function preflightStep(input: RemoteWizardInput): WizardStep {
    const label = 'Preflight';
    const preflight = input.preflight;
    if (!preflight) {
        return pending('preflight', label, 'Ejecute el preflight.');
    }
    if (preflight.configuration_version !== input.configurationVersion) {
        return pending(
            'preflight',
            label,
            'La configuración cambió: ejecute el preflight de nuevo.',
            'Desactualizado',
        );
    }
    const errors = preflight.checks.filter(
        (check) => check.result === 'ERROR',
    ).length;
    if (errors > 0) {
        return pending(
            'preflight',
            label,
            'Corrija los errores del preflight y ejecútelo de nuevo.',
            `${errors} ${errors === 1 ? 'error' : 'errores'}`,
        );
    }
    const warnings = preflight.checks.filter(
        (check) => check.result === 'WARNING',
    ).length;

    return done(
        'preflight',
        label,
        warnings > 0
            ? `${warnings} ${warnings === 1 ? 'advertencia' : 'advertencias'}`
            : 'Sin errores',
    );
}

/** What the steps need to know about a server, from its connection and evidence. */
export function wizardServer(server: RemoteServer): WizardServer {
    const source = server.role === 'SOURCE';

    return {
        uuid: server.uuid,
        name: server.name,
        role: server.role,
        connected: server.connection?.status === 'VALID',
        deployed: source
            ? server.collector_deployed
            : server.consolidator.deployed,
        readyProfiles: (source ? server.sources : server.destinations)
            .filter((profile) => profile.ready)
            .map((profile) => profile.id),
    };
}
