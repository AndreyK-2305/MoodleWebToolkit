import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import type {
    RemoteServer,
    RemoteSourceProfile,
} from '@/components/remote-access-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type SourceRow = {
    server_uuid: string;
    source_profile: string;
    package_name: string;
};

export type RemoteCollectionOptions = {
    mode?: string | null;
    sources?: SourceRow[] | null;
    // Single-source revisions saved before several sources existed.
    server_uuid?: string | null;
    source_profile?: string | null;
    package_name?: string | null;
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

/** The profile a row starts with: the first checked one, else the first the server reported. */
function firstProfile(server: RemoteServer | undefined): string {
    return (
        server?.sources.find((source) => source.ready)?.id ??
        server?.sources.find((source) => source.detected)?.id ??
        ''
    );
}

function initialRows(
    options: RemoteCollectionOptions,
    sources: RemoteServer[],
): SourceRow[] {
    if (options.mode === 'REMOTE' && options.sources?.length) {
        return options.sources;
    }
    if (options.mode === 'REMOTE' && options.server_uuid) {
        return [
            {
                server_uuid: options.server_uuid,
                source_profile: options.source_profile ?? '',
                package_name: options.package_name ?? '',
            },
        ];
    }

    // One row per source server, with its first profile.
    return sources.slice(0, MAX_SOURCES).map((server, index) => ({
        server_uuid: server.uuid,
        source_profile: firstProfile(server),
        package_name: packageName(server, index),
    }));
}

/**
 * Chooses the source servers and profiles the Collector exports from, one
 * package per source. Only identifiers are sent; paths and credentials stay
 * on each server.
 */
export default function RemoteCollectionForm({
    projectUuid,
    options,
    servers,
    editable,
}: {
    projectUuid: string;
    options: RemoteCollectionOptions;
    servers: RemoteServer[];
    editable: boolean;
}) {
    const sources = servers.filter((server) => server.role === 'SOURCE');
    const form = useForm({
        sources: initialRows(options, sources),
        workers: options.mode === 'REMOTE' ? (options.workers ?? 1) : 1,
    });
    const errors = form.errors as Record<string, string>;
    const [busy, setBusy] = useState<string | null>(null);
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
    const serverAction = (
        key: string,
        server: RemoteServer,
        path: string,
        data: Record<string, string> = {},
    ) =>
        router.post(
            `/projects/${projectUuid}/servers/${server.uuid}/${path}`,
            data,
            {
                preserveScroll: true,
                onStart: () => setBusy(key),
                onFinish: () => setBusy(null),
            },
        );

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
                    agente y produce un paquete por origen. Al guardar, la
                    configuración queda versionada; después ejecute el
                    preflight, confirme e inicie la ejecución.
                    {options.mode === 'REMOTE' &&
                        ' Esta es la configuración vigente del proyecto.'}
                </p>
                {sources.length === 0 ? (
                    <p className="text-sm">
                        Registre al menos un servidor de origen en el paso de
                        instancias y configure su conexión para habilitar la
                        recolección.
                    </p>
                ) : (
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
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
                            const connected =
                                server?.connection?.status === 'VALID';
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
                                                className="border-input rounded-md border p-2"
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
                                        <div className="flex flex-wrap gap-2">
                                            {[
                                                {
                                                    label: 'Conexión',
                                                    ok: connected,
                                                },
                                                {
                                                    label: 'Recolector desplegado',
                                                    ok: server.collector_deployed,
                                                },
                                                {
                                                    label: 'Perfil comprobado',
                                                    ok: profile?.ready === true,
                                                },
                                            ].map((item) => (
                                                <Badge
                                                    key={item.label}
                                                    variant={
                                                        item.ok
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                >
                                                    {item.label}:{' '}
                                                    {item.ok
                                                        ? 'listo'
                                                        : 'pendiente'}
                                                </Badge>
                                            ))}
                                        </div>
                                    )}
                                    {profile && (
                                        <ProfileDetails profile={profile} />
                                    )}
                                    {server &&
                                        server.sources_detected_at !== null &&
                                        server.sources.every(
                                            (source) => !source.detected,
                                        ) && (
                                            <p className="text-muted-foreground text-sm">
                                                {server.name} no tiene perfiles
                                                de origen. Su administrador debe
                                                crearlos (guía
                                                GUIA-PERFIL-ORIGEN.md).
                                            </p>
                                        )}
                                    {editable && server && (
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    !connected || busy !== null
                                                }
                                                onClick={() =>
                                                    serverAction(
                                                        `deploy-${index}`,
                                                        server,
                                                        'collector/deploy',
                                                    )
                                                }
                                            >
                                                {busy === `deploy-${index}`
                                                    ? 'Desplegando…'
                                                    : server.collector_deployed
                                                      ? 'Verificar despliegue'
                                                      : 'Desplegar Recolector 7.4.2'}
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    !connected || busy !== null
                                                }
                                                onClick={() =>
                                                    serverAction(
                                                        `detect-${index}`,
                                                        server,
                                                        'sources/detect',
                                                    )
                                                }
                                            >
                                                {busy === `detect-${index}`
                                                    ? 'Detectando…'
                                                    : 'Detectar perfiles'}
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    !connected ||
                                                    busy !== null ||
                                                    row.source_profile === ''
                                                }
                                                onClick={() =>
                                                    serverAction(
                                                        `check-${index}`,
                                                        server,
                                                        'sources/check',
                                                        {
                                                            source: row.source_profile,
                                                        },
                                                    )
                                                }
                                            >
                                                {busy === `check-${index}`
                                                    ? 'Comprobando…'
                                                    : 'Comprobar perfil'}
                                            </Button>
                                            {!connected && (
                                                <p className="text-muted-foreground text-sm">
                                                    Pruebe primero la conexión
                                                    de {server.name}.
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </fieldset>
                            );
                        })}
                        <div className="flex flex-wrap items-end gap-4">
                            {editable &&
                                form.data.sources.length < MAX_SOURCES && (
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
                                                    server_uuid:
                                                        next?.uuid ?? '',
                                                    source_profile:
                                                        firstProfile(next),
                                                    package_name: packageName(
                                                        next,
                                                        form.data.sources
                                                            .length,
                                                    ),
                                                },
                                            ]);
                                        }}
                                    >
                                        Agregar origen
                                    </Button>
                                )}
                            <div className="grid gap-2">
                                <Label htmlFor="remote-workers">
                                    Workers por origen
                                </Label>
                                <Input
                                    id="remote-workers"
                                    type="number"
                                    min={1}
                                    max={4}
                                    className="w-24"
                                    value={form.data.workers}
                                    disabled={!editable}
                                    onChange={(event) =>
                                        form.setData(
                                            'workers',
                                            Number(event.target.value),
                                        )
                                    }
                                />
                            </div>
                        </div>
                        <div>
                            {Object.entries(errors).map(([key, error]) => (
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

const PROFILE_ERRORS: Record<string, string> = {
    source_profile_unsafe:
        'el archivo no pertenece a root o lo pueden modificar otros usuarios',
    source_profile_invalid: 'el archivo tiene claves o valores no admitidos',
    source_not_found: 'el perfil no existe en el servidor',
    source_runtime_unavailable:
        'no se pudo ejecutar PHP en el runtime del perfil',
};

function gib(bytes: number | null): string {
    return bytes === null ? '—' : `${(bytes / 1073741824).toFixed(1)} GiB`;
}

function ProfileDetails({ profile }: { profile: RemoteSourceProfile }) {
    const error = profile.check_error ?? profile.profile_error;
    return (
        <div className="text-muted-foreground space-y-1 text-sm">
            {profile.valid === false && (
                <p className="text-destructive">
                    Perfil inválido:{' '}
                    {PROFILE_ERRORS[profile.profile_error ?? ''] ??
                        profile.profile_error}
                    .
                </p>
            )}
            {profile.checked_at ? (
                <p>
                    Moodle {profile.moodle_release ?? 'no detectado'} · PHP{' '}
                    {profile.php_version ?? '—'} · runtime{' '}
                    {profile.runtime ?? '—'} · moodledata libre{' '}
                    {gib(profile.dataroot_free_bytes)} · espacio del agente{' '}
                    {gib(profile.state_free_bytes)}
                </p>
            ) : (
                <p>El perfil todavía no se ha comprobado.</p>
            )}
            {profile.checked_at && !profile.ready && error && (
                <p className="text-destructive">
                    Última comprobación fallida:{' '}
                    {PROFILE_ERRORS[error] ?? error}.
                </p>
            )}
        </div>
    );
}
