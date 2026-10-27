import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import type { RemoteServer } from '@/components/remote-access-panel';
import { SourcePreparation } from '@/components/server-preparation';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    automaticWorkers,
    validWorkers,
    workerChoices,
} from '@/lib/server-preparation';

type SourceRow = {
    server_uuid: string;
    source_profile: string;
    package_name: string;
    /** «auto» or 1 to CPU − 1 of the profile's last check. */
    workers: string;
};

type SavedRow = Omit<SourceRow, 'workers'> & { workers?: string };

export type RemoteCollectionOptions = {
    mode?: string | null;
    sources?: SavedRow[] | null;
    // Single-source revisions saved before several sources existed.
    server_uuid?: string | null;
    source_profile?: string | null;
    package_name?: string | null;
    // Revisions saved with one value for every source.
    workers?: number | null;
};

const MAX_SOURCES = 8;

function packageName(server: RemoteServer | undefined, index: number): string {
    const slug = (server?.name ?? '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return /^[a-z]/.test(slug) ? slug.slice(0, 64) : `origen-${index + 1}`;
}

/** The profile a row starts with: the first checked one, the first the server reported, else «moodle» to create. */
function firstProfile(server: RemoteServer | undefined): string {
    return (
        server?.sources.find((source) => source.ready)?.id ??
        server?.sources.find((source) => source.detected)?.id ??
        'moodle'
    );
}

function initialRows(
    options: RemoteCollectionOptions,
    sources: RemoteServer[],
): SourceRow[] {
    const shared =
        options.workers !== null && options.workers !== undefined
            ? String(options.workers)
            : 'auto';
    if (options.mode === 'REMOTE' && options.sources?.length) {
        return options.sources.map((row) => ({
            ...row,
            workers: row.workers ?? shared,
        }));
    }
    if (options.mode === 'REMOTE' && options.server_uuid) {
        return [
            {
                server_uuid: options.server_uuid,
                source_profile: options.source_profile ?? '',
                package_name: options.package_name ?? '',
                workers: shared,
            },
        ];
    }

    // One row per source server, with its first profile.
    return sources.slice(0, MAX_SOURCES).map((server, index) => ({
        server_uuid: server.uuid,
        source_profile: firstProfile(server),
        package_name: packageName(server, index),
        workers: 'auto',
    }));
}

/**
 * Chooses the source servers and profiles the Collector exports from, one
 * package per source, each with its «Preparar origen» checklist and its own
 * workers. Only identifiers are saved; paths stay on each server.
 */
export default function RemoteCollectionForm({
    projectUuid,
    options,
    servers,
    agentVersion,
    editable,
}: {
    projectUuid: string;
    options: RemoteCollectionOptions;
    servers: RemoteServer[];
    agentVersion: string | null;
    editable: boolean;
}) {
    const sources = servers.filter((server) => server.role === 'SOURCE');
    const form = useForm({ sources: initialRows(options, sources) });
    const errors = form.errors as Record<string, string>;
    // Profiles detected after the form opened fill the rows still without one.
    const detected = sources
        .map((server) => `${server.uuid}:${firstProfile(server)}`)
        .join('|');
    useEffect(() => {
        form.setData((data) => ({
            ...data,
            sources: data.sources.map((row) =>
                row.source_profile === ''
                    ? {
                          ...row,
                          source_profile: firstProfile(
                              sources.find(
                                  (server) => server.uuid === row.server_uuid,
                              ),
                          ),
                      }
                    : row,
            ),
        }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [detected]);
    const updateRow = (index: number, row: Partial<SourceRow>) =>
        form.setData(
            'sources',
            form.data.sources.map((current, position) =>
                position === index ? { ...current, ...row } : current,
            ),
        );
    const cpusOf = (row: SourceRow) =>
        sources
            .find((server) => server.uuid === row.server_uuid)
            ?.sources.find((source) => source.id === row.source_profile)?.cpus;

    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    Recolección desde los servidores de origen
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="text-muted-foreground text-sm">
                    El Recolector 7.4.2 se ejecuta en cada servidor mediante su
                    agente y produce un paquete por origen. Complete «Preparar
                    origen» en cada uno y elija sus workers. Al guardar, la
                    configuración queda versionada; después ejecute el
                    preflight, confirme e inicie la ejecución.
                    {options.mode === 'REMOTE' &&
                        ' Esta es la configuración vigente del proyecto.'}
                </p>
                {sources.length === 0 ? (
                    <p className="text-sm">
                        Agregue al menos un servidor de origen en el paso
                        «Orígenes» y configure su conexión para habilitar la
                        recolección.
                    </p>
                ) : (
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            // A value the reported CPUs no longer allow is sent as «auto».
                            form.transform((data) => ({
                                sources: data.sources.map((row) => ({
                                    ...row,
                                    workers: validWorkers(
                                        row.workers,
                                        cpusOf(row),
                                    ),
                                })),
                            }));
                            form.put(
                                `/projects/${projectUuid}/collector/remote-configuration`,
                                { preserveScroll: true },
                            );
                        }}
                    >
                        {form.data.sources.map((row, index) => {
                            const server = sources.find(
                                (item) => item.uuid === row.server_uuid,
                            );
                            const profile = server?.sources.find(
                                (source) => source.id === row.source_profile,
                            );
                            const listId = `remote-profiles-${index}`;

                            return (
                                <fieldset
                                    key={index}
                                    className="space-y-3 rounded-md border p-4"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <legend className="font-medium">
                                            Origen {index + 1}
                                        </legend>
                                        {editable &&
                                            form.data.sources.length > 1 && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        form.setData(
                                                            'sources',
                                                            form.data.sources.filter(
                                                                (_, position) =>
                                                                    position !==
                                                                    index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    Quitar origen
                                                </Button>
                                            )}
                                    </div>
                                    <div className="grid gap-4 md:grid-cols-3">
                                        <div className="grid gap-2">
                                            <Label
                                                htmlFor={`remote-server-${index}`}
                                            >
                                                Servidor
                                            </Label>
                                            <select
                                                id={`remote-server-${index}`}
                                                className="border-input w-full min-w-0 rounded-md border p-2"
                                                value={row.server_uuid}
                                                disabled={!editable}
                                                onChange={(event) => {
                                                    const next = sources.find(
                                                        (item) =>
                                                            item.uuid ===
                                                            event.target.value,
                                                    );
                                                    updateRow(index, {
                                                        server_uuid:
                                                            event.target.value,
                                                        source_profile:
                                                            firstProfile(next),
                                                        workers: 'auto',
                                                    });
                                                }}
                                            >
                                                {sources.map((item) => (
                                                    <option
                                                        value={item.uuid}
                                                        key={item.uuid}
                                                    >
                                                        {item.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="grid gap-2">
                                            <Label
                                                htmlFor={`remote-profile-${index}`}
                                            >
                                                Perfil de origen
                                            </Label>
                                            <Input
                                                id={`remote-profile-${index}`}
                                                list={listId}
                                                value={row.source_profile}
                                                disabled={!editable}
                                                onChange={(event) =>
                                                    updateRow(index, {
                                                        source_profile:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                            <datalist id={listId}>
                                                {server?.sources.map(
                                                    (source) => (
                                                        <option
                                                            value={source.id}
                                                            key={source.id}
                                                        />
                                                    ),
                                                )}
                                            </datalist>
                                        </div>
                                        <div className="grid gap-2">
                                            <Label
                                                htmlFor={`remote-package-${index}`}
                                            >
                                                Nombre del paquete
                                            </Label>
                                            <Input
                                                id={`remote-package-${index}`}
                                                value={row.package_name}
                                                disabled={!editable}
                                                onChange={(event) =>
                                                    updateRow(index, {
                                                        package_name:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                        </div>
                                    </div>
                                    {server && (
                                        <SourcePreparation
                                            projectUuid={projectUuid}
                                            server={server}
                                            profileId={row.source_profile}
                                            agentVersion={agentVersion}
                                            editable={editable}
                                        />
                                    )}
                                    {profile?.ready && (
                                        <div className="grid max-w-sm gap-2">
                                            <Label
                                                htmlFor={`remote-workers-${index}`}
                                            >
                                                Workers de este origen
                                            </Label>
                                            <select
                                                id={`remote-workers-${index}`}
                                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                                value={validWorkers(
                                                    row.workers,
                                                    profile.cpus,
                                                )}
                                                disabled={!editable}
                                                onChange={(event) =>
                                                    updateRow(index, {
                                                        workers:
                                                            event.target.value,
                                                    })
                                                }
                                            >
                                                <option value="auto">
                                                    {`Automático (${automaticWorkers(profile.cpus)})`}
                                                </option>
                                                {workerChoices(
                                                    profile.cpus,
                                                ).map((value) => (
                                                    <option
                                                        key={value}
                                                        value={String(value)}
                                                    >
                                                        {value}
                                                    </option>
                                                ))}
                                            </select>
                                            <p className="text-muted-foreground text-xs">
                                                {profile.cpus === null
                                                    ? 'Compruebe el perfil con el agente actual para conocer sus CPU.'
                                                    : `${profile.cpus} CPU: «Automático» usa CPU − 1 (mínimo 1; el Recolector admite hasta 4).`}
                                            </p>
                                            <InputError
                                                message={
                                                    errors[
                                                        `sources.${index}.workers`
                                                    ]
                                                }
                                            />
                                        </div>
                                    )}
                                </fieldset>
                            );
                        })}
                        {editable && form.data.sources.length < MAX_SOURCES && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    // The next server not used yet; a server can hold several profiles, so it may repeat.
                                    const next =
                                        sources.find(
                                            (server) =>
                                                !form.data.sources.some(
                                                    (row) =>
                                                        row.server_uuid ===
                                                        server.uuid,
                                                ),
                                        ) ?? sources[0];
                                    form.setData('sources', [
                                        ...form.data.sources,
                                        {
                                            server_uuid: next?.uuid ?? '',
                                            source_profile: firstProfile(next),
                                            package_name: packageName(
                                                next,
                                                form.data.sources.length,
                                            ),
                                            workers: 'auto',
                                        },
                                    ]);
                                }}
                            >
                                Agregar origen
                            </Button>
                        )}
                        <div>
                            {Object.entries(errors)
                                .filter(([key]) => !key.endsWith('.workers'))
                                .map(([key, error]) => (
                                    <InputError key={key} message={error} />
                                ))}
                        </div>
                        {editable && (
                            <Button
                                disabled={
                                    form.processing ||
                                    form.data.sources.some(
                                        (row) =>
                                            row.server_uuid === '' ||
                                            row.source_profile === '' ||
                                            row.package_name === '',
                                    )
                                }
                            >
                                Guardar recolección remota
                            </Button>
                        )}
                    </form>
                )}
            </CardContent>
        </Card>
    );
}
