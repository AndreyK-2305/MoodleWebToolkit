import { describe, expect, it } from 'vitest';
import {
    canOpenStep,
    initialStep,
    remoteWizardSteps,
    stepBlocker,
} from './remote-wizard';
import type { RemoteWizardInput, WizardServer } from './remote-wizard';

const server = (changes: Partial<WizardServer> = {}): WizardServer => ({
    uuid: 'dest',
    name: 'Destino',
    role: 'DESTINATION',
    connected: true,
    deployed: true,
    readyProfiles: ['consolidado'],
    ...changes,
});

const consolidation = (
    changes: Partial<RemoteWizardInput> = {},
): RemoteWizardInput => ({
    type: 'CONSOLIDATE',
    basicsSaved: true,
    configurationVersion: 3,
    options: {},
    servers: [server()],
    selection: null,
    link: null,
    preflight: null,
    confirmation: null,
    ...changes,
});

const collection = (
    changes: Partial<RemoteWizardInput> = {},
): RemoteWizardInput => ({
    ...consolidation(),
    type: 'COLLECT',
    servers: [
        server({
            uuid: 'a',
            name: 'Pregrado',
            role: 'SOURCE',
            readyProfiles: ['pregrado'],
        }),
    ],
    ...changes,
});

const savedCollection = {
    mode: 'REMOTE',
    sources: [{ server_uuid: 'a', source_profile: 'pregrado' }],
};

describe('remoteWizardSteps', () => {
    it('gives a consolidation five steps and a collection four', () => {
        expect(remoteWizardSteps(consolidation()).map((s) => s.key)).toEqual([
            'basics',
            'sources',
            'destination',
            'preflight',
            'confirmation',
        ]);
        expect(remoteWizardSteps(collection()).map((s) => s.key)).toEqual([
            'basics',
            'sources',
            'preflight',
            'confirmation',
        ]);
    });

    it('opens on the first step that is not complete', () => {
        const steps = remoteWizardSteps(consolidation({ basicsSaved: false }));
        expect(initialStep(steps)).toBe(0);
        expect(steps[0].blocker).toBe('Guarde los datos básicos.');
        expect(canOpenStep(steps, 1)).toBe(false);
        expect(stepBlocker(steps, 2)).toBe(
            'Datos básicos: Guarde los datos básicos.',
        );
    });

    it('completes the sources with a selection or a linked collection', () => {
        expect(remoteWizardSteps(consolidation())[1].complete).toBe(false);
        const selected = remoteWizardSteps(
            consolidation({ selection: { packages: ['p1', 'p2'] } }),
        )[1];
        expect(selected.complete).toBe(true);
        expect(selected.status).toBe('2 paquetes elegidos');
        expect(
            remoteWizardSteps(
                consolidation({ selection: { packages: ['p1'] } }),
            )[1].complete,
        ).toBe(false);
        const linked = remoteWizardSteps(
            consolidation({
                link: {
                    mode: 'COLLECT_NOW',
                    picked_up_at: null,
                    sources_total: 2,
                    sources_collected: 1,
                    destination_chosen: false,
                    destination_profile: null,
                },
            }),
        )[1];
        expect(linked.complete).toBe(true);
        expect(linked.status).toBe('Recolección vinculada: 1/2 orígenes');
    });

    it('explains what the destination still needs, in order', () => {
        const blocker = (destination: Partial<WizardServer>) =>
            remoteWizardSteps(
                consolidation({
                    selection: { packages: ['p1', 'p2'] },
                    servers: [server(destination)],
                }),
            )[2].blocker;
        expect(blocker({ connected: false })).toContain('Conecte');
        expect(blocker({ deployed: false })).toContain('Consolidador');
        expect(blocker({ readyProfiles: [] })).toContain('perfil');
        expect(blocker({})).toBe('Guarde el destino: perfil y workers.');
        expect(
            remoteWizardSteps(consolidation({ servers: [] }))[2].blocker,
        ).toContain('datos básicos');
        // Without its destination the basics of a consolidation are not saved yet.
        const unsaved = remoteWizardSteps(consolidation({ servers: [] }));
        expect(unsaved[0].complete).toBe(false);
        expect(initialStep(unsaved)).toBe(0);
    });

    it('completes the destination once saved with a checked profile', () => {
        const options = {
            mode: 'REMOTE_CONSOLIDATION',
            server_uuid: 'dest',
            destination_profile: 'consolidado',
            source_packages: [{}, {}],
        };
        const steps = remoteWizardSteps(consolidation({ options }));
        expect(steps[1].complete).toBe(true);
        expect(steps[2].complete).toBe(true);
        expect(steps[2].status).toBe('Perfil «consolidado»');
        expect(initialStep(steps)).toBe(3);
        expect(
            remoteWizardSteps(
                consolidation({
                    options,
                    servers: [server({ readyProfiles: ['otro'] })],
                }),
            )[2].blocker,
        ).toContain('«consolidado»');
    });

    it('takes the destination kept by a pending linked collection', () => {
        const steps = remoteWizardSteps(
            consolidation({
                link: {
                    mode: 'COLLECT_NOW',
                    picked_up_at: null,
                    sources_total: 2,
                    sources_collected: 0,
                    destination_chosen: true,
                    destination_profile: 'consolidado',
                },
            }),
        );
        expect(steps[2].complete).toBe(true);
    });

    it('asks a collection to connect, deploy and check every source', () => {
        const blocker = (source: Partial<WizardServer>, options = {}) =>
            remoteWizardSteps(
                collection({
                    options,
                    servers: [
                        server({
                            uuid: 'a',
                            name: 'Pregrado',
                            role: 'SOURCE',
                            readyProfiles: ['pregrado'],
                            ...source,
                        }),
                    ],
                }),
            )[1].blocker;
        expect(remoteWizardSteps(collection({ servers: [] }))[1].blocker).toBe(
            'Agregue al menos un origen.',
        );
        expect(blocker({ connected: false })).toBe(
            'Conecte y pruebe «Pregrado».',
        );
        expect(blocker({ deployed: false })).toContain('Recolector');
        expect(blocker({ readyProfiles: [] })).toContain('perfil');
        expect(blocker({})).toContain('Guarde la recolección');
        expect(blocker({}, savedCollection)).toBeNull();
        expect(blocker({}, { ...savedCollection, sources: [] })).toContain(
            'Guarde la recolección',
        );
    });

    it('asks to save again when a source is not in the collection', () => {
        const steps = remoteWizardSteps(
            collection({
                options: savedCollection,
                servers: [
                    ...collection().servers,
                    server({
                        uuid: 'b',
                        name: 'Posgrado',
                        role: 'SOURCE',
                        readyProfiles: ['posgrado'],
                    }),
                ],
            }),
        );
        expect(steps[1].blocker).toContain('«Posgrado»');
    });

    it('reports a stale or failing preflight and a confirmation', () => {
        const base = { options: savedCollection };
        const stale = remoteWizardSteps(
            collection({
                ...base,
                preflight: { configuration_version: 2, checks: [] },
            }),
        )[2];
        expect(stale.status).toBe('Desactualizado');
        const failing = remoteWizardSteps(
            collection({
                ...base,
                preflight: {
                    configuration_version: 3,
                    checks: [{ result: 'ERROR' }, { result: 'WARNING' }],
                },
            }),
        )[2];
        expect(failing.complete).toBe(false);
        expect(failing.status).toBe('1 error');
        const confirmed = remoteWizardSteps(
            collection({
                ...base,
                // A source busy with the execution no longer reports a ready profile.
                servers: [
                    server({ uuid: 'a', role: 'SOURCE', readyProfiles: [] }),
                ],
                preflight: {
                    configuration_version: 3,
                    checks: [{ result: 'WARNING' }],
                },
                confirmation: { configuration_version: 3 },
            }),
        );
        expect(confirmed.every((step) => step.complete)).toBe(true);
        expect(confirmed[2].status).toBe('1 advertencia');
        expect(initialStep(confirmed)).toBe(3);
    });
});
