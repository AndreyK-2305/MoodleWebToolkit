import { Link, useForm } from '@inertiajs/react';
import { CircleDot, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import type {
    AvailablePackage,
    RemoteServer,
} from '@/components/remote-access-panel';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    blankLinkedSource,
    formatDate,
    formatSize,
    initialSourceMode,
    linkedSourcesProblem,
    packageNameFor,
    preselectedPackages,
} from '@/lib/consolidation-sources';
import type {
    CompletedCollection,
    LinkedSource,
    SourceMode,
} from '@/lib/consolidation-sources';
import { cn } from '@/lib/utils';

export type RemoteConsolidationOptions = {
    mode?: string | null;
    server_uuid?: string | null;
    destination_profile?: string | null;
    source_packages?: { uuid: string; sha256: string; name: string }[] | null;
    workers?: string | number | null;
    source_project_uuid?: string | null;
};

export type ConsolidationSourceLink = {
    mode: 'PROJECT' | 'COLLECT_NOW';
    project: {
        uuid: string;
        name: string;
        status: string;
        status_label: string;
    };
    execution: { uuid: string; attempt: number; status: string } | null;
    sources_total: number;
    sources_collected: number;
    picked_up_at: string | null;
    pickup_error: string | null;
    waiting: string | null;
    destination_profile: string | null;
    destination_chosen: boolean;
};

export type ConsolidationCopias = {
    state: 'NOT_CONFIGURED' | 'BLOCKED' | 'FETCHING' | 'FAILED' | 'READY';
    ready: number;
    total: number;
    current: string | null;
    missing: string[];
    detail: string;
};

export type ConsolidationSources = {
    link: ConsolidationSourceLink | null;
    collections: CompletedCollection[];
    copias: ConsolidationCopias;
    selection: {
        source_project_uuid: string | null;
        packages: string[];
    } | null;
    min_sources: number;
    max_sources: number;
};

const MIN_PACKAGES = 2;
const MAX_PACKAGES = 32;

const modes: { value: SourceMode; title: string; description: string }[] = [
    {
        value: 'PROJECT',
        title: 'De un proyecto de recolección completado',
        description:
            'Use los paquetes que una recolección ya terminó y selló en S3, uno por origen.',
    },
    {
        value: 'COLLECT_NOW',
        title: 'Recolectar ahora',
        description:
            'Indique los servidores de origen: se crea una recolección vinculada y, cuando termine, esta consolidación toma sus paquetes.',
    },
];

type FormProps = {
    projectUuid: string;
    options: RemoteConsolidationOptions;
    servers: RemoteServer[];
    sources: ConsolidationSources | null;
    editable: boolean;
};

/** The destination saved in the configuration, or the first one registered. */
function savedDestination(
    options: RemoteConsolidationOptions,
    servers: RemoteServer[],
): RemoteServer | undefined {
    const destinations = servers.filter(
        (server) => server.role === 'DESTINATION',
    );

    return (
        destinations.find((server) => server.uuid === options.server_uuid) ??
        destinations[0]
    );
}

/**
 * Sources step of a remote consolidation: where the sealed packages come
 * from. Either a completed collection, loose packages («Avanzado») or a
 * collection created now and linked to this consolidation. It is saved on
 * its own; the destination step completes the configuration.
 */
export function ConsolidationSourcesForm({
    projectUuid,
    options,
    servers,
    packages,
    sources,
    defaultRegion,
    editable,
}: FormProps & { packages: AvailablePackage[]; defaultRegion: string }) {
    const current = options.mode === 'REMOTE_CONSOLIDATION';
    const collections = sources?.collections ?? [];
    const link = sources?.link ?? null;
    const selection = sources?.selection ?? null;
    const pendingLink =
        link?.mode === 'COLLECT_NOW' && link.picked_up_at === null;
    const chosenProject = selection
        ? selection.source_project_uuid
        : current
          ? (options.source_project_uuid ?? null)
          : null;
    const [mode, setMode] = useState<SourceMode>(() =>
        initialSourceMode({
            linkMode: pendingLink ? 'COLLECT_NOW' : null,
            sourceProjectUuid: chosenProject,
            configured: current || selection !== null,
            collections: collections.length,
        }),
    );
    const firstCollection =
        collections.find((item) => item.uuid === chosenProject) ??
        collections[0];
    // The saved packages reopen as they were; a collection not saved yet starts with all of its packages.
    const savedPackages = selection
        ? selection.packages
        : current
          ? (options.source_packages ?? []).map((item) => item.uuid)
          : null;
    const keepSaved =
        savedPackages !== null &&
        (!chosenProject || chosenProject === firstCollection?.uuid);
    const form = useForm({
        source_project: firstCollection?.uuid ?? '',
        source_packages: keepSaved
            ? savedPackages
            : preselectedPackages(firstCollection),
    });
    const linked = useForm<{
        sources: LinkedSource[];
        collection_workers: number;
    }>({
        sources: [
            blankLinkedSource(1, defaultRegion),
            blankLinkedSource(2, defaultRegion),
        ],
        collection_workers: 2,
    });
    const errors = form.errors as Record<string, string>;
    const linkedErrors = linked.errors as Record<string, string>;
    // Packages already in the saved destination's copias/ are marked in the lists.
    const destination = savedDestination(options, servers);
    const profile = current
        ? destination?.destinations.find(
              (item) => item.id === options.destination_profile,
          )
        : undefined;
    const inCopias = profile
        ? new Set(
              (profile.copias?.packages ?? []).map(
                  (item) => item.sidecar_sha256,
              ),
          )
        : null;
    const collection = collections.find(
        (item) => item.uuid === form.data.source_project,
    );
    const toggle = (uuid: string, checked: boolean) =>
        form.setData(
            'source_packages',
            checked
                ? [...form.data.source_packages, uuid]
                : form.data.source_packages.filter((item) => item !== uuid),
        );
    const count = form.data.source_packages.length;

    const chooseMode = (next: SourceMode) => {
        setMode(next);
        form.clearErrors();
        if (next === 'PROJECT') {
            form.setData((data) => ({
                ...data,
                source_packages: preselectedPackages(collection),
            }));
        }
        if (next === 'MANUAL') {
            form.setData((data) => ({ ...data, source_packages: [] }));
        }
    };
    const chooseCollection = (uuid: string) =>
        form.setData((data) => ({
            ...data,
            source_project: uuid,
            source_packages: preselectedPackages(
                collections.find((item) => item.uuid === uuid),
            ),
        }));
    const saveSources = () => {
        form.transform((data) => ({
            source_packages: data.source_packages,
            ...(mode === 'PROJECT'
                ? { source_project: data.source_project }
                : {}),
        }));
        form.put(`/projects/${projectUuid}/consolidation/sources`, {
            preserveScroll: true,
        });
    };
    const updateSource = <K extends keyof LinkedSource>(
        index: number,
        field: K,
        value: LinkedSource[K],
    ) =>
        linked.setData((data) => ({
            ...data,
            sources: data.sources.map((source, position) => {
                if (position !== index) {
                    return source;
                }
                const next = { ...source, [field]: value };
                // The package name follows the source's name until the user edits it.
                if (
                    field === 'name' &&
                    source.package_name ===
                        packageNameFor(source.name, index + 1)
                ) {
                    next.package_name = packageNameFor(
                        String(value),
                        index + 1,
                    );
                }

                return next;
            }),
        }));
    const minSources = sources?.min_sources ?? MIN_PACKAGES;
    const maxSources = sources?.max_sources ?? 8;
    const linkedProblem = linkedSourcesProblem(
        linked.data.sources,
        minSources,
        maxSources,
    );
    // The destination is chosen in its own step; a destination saved before is kept.
    const createLinked = () =>
        linked.post(
            `/projects/${projectUuid}/consolidation/linked-collection`,
            { preserveScroll: true },
        );

    return (
        <div className="space-y-5">
            {link && <SourceLinkPanel link={link} />}

            <fieldset className="space-y-3">
                <legend className="text-base font-medium">
                    ¿De dónde salen los orígenes?
                </legend>
                <div className="grid gap-3 md:grid-cols-2">
                    {modes.map((item) => (
                        <label
                            key={item.value}
                            className={cn(
                                'flex cursor-pointer items-start gap-3 rounded-lg border p-4 text-sm',
                                mode === item.value
                                    ? 'border-amber-500 bg-amber-50 dark:bg-amber-950/30'
                                    : 'border-border hover:bg-muted',
                            )}
                        >
                            <input
                                type="radio"
                                name="consolidation-source-mode"
                                className="mt-1"
                                checked={mode === item.value}
                                disabled={!editable}
                                onChange={() => chooseMode(item.value)}
                            />
                            <span>
                                <span className="block font-medium">
                                    {item.title}
                                </span>
                                <span className="text-muted-foreground block">
                                    {item.description}
                                </span>
                            </span>
                        </label>
                    ))}
                </div>
                <button
                    type="button"
                    disabled={!editable}
                    className="text-muted-foreground text-xs underline-offset-2 hover:underline"
                    onClick={() =>
                        chooseMode(mode === 'MANUAL' ? 'PROJECT' : 'MANUAL')
                    }
                >
                    {mode === 'MANUAL'
                        ? 'Volver a las dos opciones principales'
                        : 'Avanzado: elegir paquetes sueltos de varias recolecciones'}
                </button>
            </fieldset>

            {mode === 'PROJECT' && (
                <ProjectMode
                    collections={collections}
                    collection={collection}
                    selected={form.data.source_packages}
                    sourceProject={form.data.source_project}
                    inCopias={inCopias}
                    editable={editable}
                    onCollection={chooseCollection}
                    onToggle={toggle}
                />
            )}

            {mode === 'MANUAL' && (
                <ManualMode
                    packages={packages}
                    selected={form.data.source_packages}
                    inCopias={inCopias}
                    editable={editable}
                    onToggle={toggle}
                />
            )}

            {mode !== 'COLLECT_NOW' && (
                <>
                    <InputError message={errors.consolidation} />
                    <InputError message={errors.source_packages} />
                    {editable && (
                        <Button
                            type="button"
                            onClick={saveSources}
                            disabled={
                                form.processing ||
                                count < MIN_PACKAGES ||
                                count > MAX_PACKAGES ||
                                (mode === 'PROJECT' && !collection)
                            }
                        >
                            Guardar orígenes
                        </Button>
                    )}
                </>
            )}

            {mode === 'COLLECT_NOW' &&
                (pendingLink ? (
                    <p className="text-muted-foreground text-sm">
                        La recolección vinculada ya existe. Ábrala para conectar
                        los orígenes e iniciarla; cuando se complete, esta
                        consolidación toma sus paquetes sola. Mientras tanto
                        puede preparar el destino.
                    </p>
                ) : (
                    <CollectNowMode
                        sources={linked.data.sources}
                        workers={linked.data.collection_workers}
                        errors={linkedErrors}
                        problem={linkedProblem}
                        maxSources={maxSources}
                        defaultRegion={defaultRegion}
                        editable={editable}
                        processing={linked.processing}
                        onSource={updateSource}
                        onAdd={() =>
                            linked.setData((data) => ({
                                ...data,
                                sources: [
                                    ...data.sources,
                                    blankLinkedSource(
                                        data.sources.length + 1,
                                        defaultRegion,
                                    ),
                                ],
                            }))
                        }
                        onRemove={(index) =>
                            linked.setData((data) => ({
                                ...data,
                                sources: data.sources.filter(
                                    (_, position) => position !== index,
                                ),
                            }))
                        }
                        onWorkers={(value) =>
                            linked.setData('collection_workers', value)
                        }
                        onSubmit={createLinked}
                    />
                ))}
        </div>
    );
}

/**
 * Destination step of a remote consolidation: the destination profile and
 * the restore workers. Saving it completes the configuration with the
 * sources of the previous step; the platform then brings the packages to
 * its copias/.
 */
export function ConsolidationDestinationForm({
    projectUuid,
    options,
    servers,
    sources,
    editable,
}: FormProps) {
    const destinations = servers.filter(
        (server) => server.role === 'DESTINATION',
    );
    const current = options.mode === 'REMOTE_CONSOLIDATION';
    const link = sources?.link ?? null;
    const form = useForm({
        server_uuid: current
            ? (options.server_uuid ?? '')
            : (destinations[0]?.uuid ?? ''),
        destination_profile: current
            ? (options.destination_profile ?? '')
            : (link?.destination_profile ??
              destinations[0]?.destinations.find((item) => item.ready)?.id ??
              ''),
        workers: current ? String(options.workers ?? 'auto') : 'auto',
    });
    const errors = form.errors as Record<string, string>;
    const server = destinations.find(
        (item) => item.uuid === form.data.server_uuid,
    );
    // A destination that finishes preparing reports its profile later: choose it once it arrives.
    const available = (server?.destinations ?? [])
        .map((item) => `${item.id}:${item.ready}`)
        .join('|');
    useEffect(() => {
        if (form.data.destination_profile !== '' || !server) {
            return;
        }
        const first =
            server.destinations.find((item) => item.ready) ??
            server.destinations[0];
        if (first) {
            form.setData('destination_profile', first.id);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [available]);
    const hasSources =
        current || sources?.selection != null || link?.mode === 'COLLECT_NOW';

    if (destinations.length === 0) {
        return (
            <p className="text-sm">
                Guarde los datos básicos para registrar el servidor de destino.
            </p>
        );
    }

    return (
        <div className="space-y-4">
            <p className="text-muted-foreground text-sm">
                El Consolidador V8 se ejecuta en el destino mediante su agente.
                Al guardar, la plataforma trae los paquetes elegidos a su{' '}
                <code>copias/</code>; también lo hace al ejecutar el preflight.
                La ejecución se detendrá para que el administrador configure
                OAuth2 en Moodle.
                {current && ' Esta es la configuración vigente del proyecto.'}
            </p>
            <DestinationFields
                destinations={destinations}
                server={server}
                data={form.data}
                editable={editable}
                onChange={(field, value) => form.setData(field, value)}
            />
            <InputError message={errors.server_uuid} />
            <InputError message={errors.destination_profile} />
            <InputError message={errors.consolidation} />
            {sources && current && (
                <CopiasProgressPanel copias={sources.copias} />
            )}
            {editable && (
                <div className="space-y-1">
                    <Button
                        type="button"
                        disabled={
                            form.processing ||
                            !hasSources ||
                            form.data.destination_profile === ''
                        }
                        onClick={() =>
                            form.put(
                                `/projects/${projectUuid}/consolidation/remote-configuration`,
                                { preserveScroll: true },
                            )
                        }
                    >
                        Guardar destino
                    </Button>
                    {!hasSources && (
                        <p className="text-muted-foreground text-xs">
                            Elija primero los orígenes (paso 2).
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

function DestinationFields({
    destinations,
    server,
    data,
    editable,
    onChange,
}: {
    destinations: RemoteServer[];
    server: RemoteServer | undefined;
    data: { server_uuid: string; destination_profile: string; workers: string };
    editable: boolean;
    onChange: (
        field: 'server_uuid' | 'destination_profile' | 'workers',
        value: string,
    ) => void;
}) {
    return (
        <div className="grid gap-4 md:grid-cols-3">
            <div className="grid gap-2">
                <Label htmlFor="consolidation-server">
                    Servidor de destino
                </Label>
                <select
                    id="consolidation-server"
                    className="border-input w-full min-w-0 rounded-md border p-2"
                    value={data.server_uuid}
                    disabled={!editable}
                    onChange={(event) =>
                        onChange('server_uuid', event.target.value)
                    }
                >
                    {destinations.map((item) => (
                        <option key={item.uuid} value={item.uuid}>
                            {item.name}
                        </option>
                    ))}
                </select>
            </div>
            <div className="grid gap-2">
                <Label htmlFor="consolidation-profile">Perfil de destino</Label>
                <select
                    id="consolidation-profile"
                    className="border-input w-full min-w-0 rounded-md border p-2"
                    value={data.destination_profile}
                    disabled={!editable}
                    onChange={(event) =>
                        onChange('destination_profile', event.target.value)
                    }
                >
                    <option value="">Seleccione un perfil</option>
                    {(server?.destinations ?? []).map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.id}
                            {item.ready ? '' : ' (sin comprobar)'}
                        </option>
                    ))}
                </select>
            </div>
            <div className="grid gap-2">
                <Label htmlFor="consolidation-workers">
                    Workers de restauración
                </Label>
                <select
                    id="consolidation-workers"
                    className="border-input w-full min-w-0 rounded-md border p-2"
                    value={data.workers}
                    disabled={!editable}
                    onChange={(event) =>
                        onChange('workers', event.target.value)
                    }
                >
                    <option value="auto">Automático (CPU, máx. 4)</option>
                    {['1', '2', '3', '4'].map((value) => (
                        <option key={value} value={value}>
                            {value}
                        </option>
                    ))}
                </select>
            </div>
        </div>
    );
}

/** The linked or chosen collection and what the consolidation waits for. */
function SourceLinkPanel({ link }: { link: ConsolidationSourceLink }) {
    const done = link.picked_up_at !== null;

    return (
        <Alert
            className={cn(
                done
                    ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30'
                    : 'border-sky-300 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/30',
            )}
        >
            <CircleDot />
            <AlertTitle>
                {link.mode === 'COLLECT_NOW'
                    ? `Recolección vinculada (${link.sources_collected}/${link.sources_total} orígenes)`
                    : 'Orígenes de una recolección completada'}
            </AlertTitle>
            <AlertDescription className="space-y-1">
                <p>
                    <Link
                        href={`/projects/${link.project.uuid}`}
                        className="font-medium underline underline-offset-2"
                    >
                        {link.project.name}
                    </Link>{' '}
                    · {link.project.status_label}
                    {link.execution &&
                        ` · ejecución #${link.execution.attempt} ${link.execution.status}`}
                </p>
                {link.mode === 'COLLECT_NOW' && (
                    <p>
                        {done
                            ? 'Los paquetes de la recolección ya están en esta configuración; continúe con el destino y el preflight.'
                            : (link.waiting ??
                              'Esperando a que la recolección vinculada termine.')}
                    </p>
                )}
                {link.mode === 'COLLECT_NOW' &&
                    !done &&
                    link.execution === null && (
                        <p className="text-muted-foreground">
                            En la recolección: instale el agente en cada origen,
                            despliegue el Recolector, compruebe los perfiles,
                            ejecute el preflight, confirme e inicie.
                        </p>
                    )}
            </AlertDescription>
        </Alert>
    );
}

function CopiasProgressPanel({ copias }: { copias: ConsolidationCopias }) {
    if (copias.state === 'NOT_CONFIGURED') {
        return null;
    }

    return (
        <div className="border-border flex flex-wrap items-center gap-2 rounded-lg border p-3 text-sm">
            <span className="font-medium">Paquetes en copias/:</span>
            <Badge
                variant={
                    copias.state === 'READY'
                        ? 'default'
                        : copias.state === 'FAILED'
                          ? 'destructive'
                          : 'secondary'
                }
            >
                {copias.ready} de {copias.total}
            </Badge>
            <span className="text-muted-foreground">{copias.detail}</span>
        </div>
    );
}

function ProjectMode({
    collections,
    collection,
    selected,
    sourceProject,
    inCopias,
    editable,
    onCollection,
    onToggle,
}: {
    collections: CompletedCollection[];
    collection: CompletedCollection | undefined;
    selected: string[];
    sourceProject: string;
    inCopias: Set<string | null> | null;
    editable: boolean;
    onCollection: (uuid: string) => void;
    onToggle: (uuid: string, checked: boolean) => void;
}) {
    if (collections.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                Aún no hay recolecciones completadas con paquetes en S3 que
                pueda usar. Elija «Recolectar ahora» para recolectar los
                orígenes desde esta consolidación.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            <div className="grid gap-2 md:max-w-md">
                <Label htmlFor="consolidation-collection">
                    Recolección completada
                </Label>
                <select
                    id="consolidation-collection"
                    className="border-input w-full min-w-0 rounded-md border p-2"
                    value={sourceProject}
                    disabled={!editable}
                    onChange={(event) => onCollection(event.target.value)}
                >
                    {collections.map((item) => (
                        <option key={item.uuid} value={item.uuid}>
                            {item.name} · {item.packages.length} orígenes
                        </option>
                    ))}
                </select>
            </div>
            {collection && (
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">
                        Paquetes de la recolección ({selected.length} de{' '}
                        {collection.packages.length} elegidos)
                    </legend>
                    <ul className="grid gap-2">
                        {collection.packages.map((item) => (
                            <li
                                key={item.uuid}
                                className="border-border flex flex-wrap items-center gap-2 rounded-md border p-2 text-sm"
                            >
                                <Checkbox
                                    id={`collection-package-${item.uuid}`}
                                    checked={selected.includes(item.uuid)}
                                    disabled={!editable}
                                    onCheckedChange={(checked) =>
                                        onToggle(item.uuid, checked === true)
                                    }
                                />
                                <Label
                                    htmlFor={`collection-package-${item.uuid}`}
                                >
                                    {item.source_id}
                                </Label>
                                <span className="text-muted-foreground text-xs">
                                    {item.name} · {formatDate(item.created_at)}{' '}
                                    · {formatSize(item.size)}
                                </span>
                                {item.older > 0 && (
                                    <Badge variant="outline">
                                        el más reciente; hay {item.older}{' '}
                                        {item.older === 1
                                            ? 'anterior'
                                            : 'anteriores'}
                                    </Badge>
                                )}
                                <CopiasBadge
                                    inCopias={inCopias}
                                    sha256={item.sha256}
                                />
                            </li>
                        ))}
                    </ul>
                </fieldset>
            )}
        </div>
    );
}

function ManualMode({
    packages,
    selected,
    inCopias,
    editable,
    onToggle,
}: {
    packages: AvailablePackage[];
    selected: string[];
    inCopias: Set<string | null> | null;
    editable: boolean;
    onToggle: (uuid: string, checked: boolean) => void;
}) {
    return (
        <fieldset className="space-y-2">
            <legend className="text-sm font-medium">
                Paquetes sellados ({selected.length} de {MIN_PACKAGES} a{' '}
                {MAX_PACKAGES}), uno por origen
            </legend>
            {packages.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    No hay paquetes verificados en S3 que pueda usar.
                </p>
            ) : (
                <ul className="grid gap-2">
                    {packages.map((item) => (
                        <li
                            key={item.uuid}
                            className="flex flex-wrap items-center gap-2 text-sm"
                        >
                            <Checkbox
                                id={`package-${item.uuid}`}
                                checked={selected.includes(item.uuid)}
                                disabled={!editable}
                                onCheckedChange={(checked) =>
                                    onToggle(item.uuid, checked === true)
                                }
                            />
                            <Label htmlFor={`package-${item.uuid}`}>
                                {item.name}
                            </Label>
                            <span className="text-muted-foreground text-xs">
                                {item.project ?? '—'} ·{' '}
                                {item.sha256.slice(0, 12)}
                            </span>
                            <CopiasBadge
                                inCopias={inCopias}
                                sha256={item.sha256}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </fieldset>
    );
}

function CopiasBadge({
    inCopias,
    sha256,
}: {
    inCopias: Set<string | null> | null;
    sha256: string;
}) {
    if (!inCopias) {
        return null;
    }

    return inCopias.has(sha256) ? (
        <Badge variant="outline">en copias/</Badge>
    ) : (
        <Badge variant="secondary">se traerá a copias/</Badge>
    );
}

function CollectNowMode({
    sources,
    workers,
    errors,
    problem,
    maxSources,
    defaultRegion,
    editable,
    processing,
    onSource,
    onAdd,
    onRemove,
    onWorkers,
    onSubmit,
}: {
    sources: LinkedSource[];
    workers: number;
    errors: Record<string, string>;
    problem: string | null;
    maxSources: number;
    defaultRegion: string;
    editable: boolean;
    processing: boolean;
    onSource: <K extends keyof LinkedSource>(
        index: number,
        field: K,
        value: LinkedSource[K],
    ) => void;
    onAdd: () => void;
    onRemove: (index: number) => void;
    onWorkers: (value: number) => void;
    onSubmit: () => void;
}) {
    const fields: {
        key: keyof LinkedSource;
        label: string;
        placeholder: string;
    }[] = [
        { key: 'name', label: 'Nombre del origen', placeholder: 'Pregrado' },
        {
            key: 'instance_id',
            label: 'ID de instancia (SSM)',
            placeholder: 'i-0123456789abcdef0',
        },
        { key: 'region', label: 'Región AWS', placeholder: defaultRegion },
        {
            key: 'source_profile',
            label: 'Perfil de origen',
            placeholder: 'pregrado',
        },
        {
            key: 'package_name',
            label: 'Nombre del paquete',
            placeholder: 'pregrado',
        },
    ];

    return (
        <div className="space-y-4">
            <p className="text-muted-foreground text-sm">
                Se creará un proyecto de recolección vinculado, con las mismas
                personas asignadas, que recolecta estos orígenes con su propia
                ejecución. Cuando se complete, esta consolidación toma sus
                paquetes y los trae a <code>copias/</code>.
            </p>
            {sources.map((source, index) => (
                <div
                    key={index}
                    className="border-border space-y-3 rounded-lg border p-4"
                >
                    <div className="flex items-center justify-between">
                        <h4 className="text-sm font-medium">
                            Origen {index + 1}
                        </h4>
                        {editable && sources.length > 1 && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => onRemove(index)}
                            >
                                <Trash2 /> Quitar
                            </Button>
                        )}
                    </div>
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        {fields.map((field) => (
                            <div key={field.key} className="grid gap-1">
                                <Label
                                    htmlFor={`linked-${index}-${field.key}`}
                                    className="text-xs"
                                >
                                    {field.label}
                                </Label>
                                <Input
                                    id={`linked-${index}-${field.key}`}
                                    value={source[field.key]}
                                    placeholder={field.placeholder}
                                    disabled={!editable}
                                    onChange={(event) =>
                                        onSource(
                                            index,
                                            field.key,
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={
                                        errors[`sources.${index}.${field.key}`]
                                    }
                                />
                            </div>
                        ))}
                    </div>
                </div>
            ))}
            <div className="flex flex-wrap items-end gap-4">
                {editable && sources.length < maxSources && (
                    <Button type="button" variant="outline" onClick={onAdd}>
                        <Plus /> Agregar origen
                    </Button>
                )}
                <div className="grid gap-1">
                    <Label htmlFor="linked-workers" className="text-xs">
                        Workers de cada exportación
                    </Label>
                    <select
                        id="linked-workers"
                        className="border-input rounded-md border p-2 text-sm"
                        value={workers}
                        disabled={!editable}
                        onChange={(event) =>
                            onWorkers(Number(event.target.value))
                        }
                    >
                        {[1, 2, 3, 4].map((value) => (
                            <option key={value} value={value}>
                                {value}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            <InputError message={errors.sources} />
            <InputError message={errors.linked_collection} />
            <InputError message={errors.collection_workers} />
            {editable && (
                <div className="space-y-1">
                    <Button
                        type="button"
                        disabled={processing || problem !== null}
                        onClick={onSubmit}
                    >
                        Crear la recolección vinculada
                    </Button>
                    {problem && (
                        <p className="text-muted-foreground text-xs">
                            {problem}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
