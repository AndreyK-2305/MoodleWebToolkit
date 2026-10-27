import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import {
    CheckCircle2,
    CircleDashed,
    Loader2,
    Lock,
    TriangleAlert,
    XCircle,
} from 'lucide-react';
import InputError from '@/components/input-error';
import type {
    MoodleCandidate,
    RemoteServer,
} from '@/components/remote-access-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    checklistProgress,
    chooseCandidate,
    destinationChecklist,
    preferredCandidate,
    sourceChecklist,
    sourceProfileRequest,
} from '@/lib/server-preparation';
import type {
    ChecklistAction,
    ChecklistItem,
    ChecklistState,
} from '@/lib/server-preparation';
import { cn } from '@/lib/utils';

const stateIcons: Record<ChecklistState, typeof CheckCircle2> = {
    done: CheckCircle2,
    warning: TriangleAlert,
    failed: XCircle,
    running: Loader2,
    pending: CircleDashed,
    blocked: Lock,
};
const stateColors: Record<ChecklistState, string> = {
    done: 'text-emerald-600',
    warning: 'text-amber-600',
    failed: 'text-red-600',
    running: 'text-sky-600 animate-spin',
    pending: 'text-muted-foreground',
    blocked: 'text-muted-foreground/60',
};

function actionLabel(action: ChecklistAction, item: ChecklistItem): string {
    const retry = item.state === 'failed' || finishedState(item.state);
    switch (action) {
        case 'test':
            return retry ? 'Probar de nuevo' : 'Probar conexión';
        case 'install':
            return item.state === 'done'
                ? 'Reinstalar agente'
                : 'Instalar agente';
        case 'probe':
            return retry ? 'Probar S3 de nuevo' : 'Probar S3';
        case 'deploy':
            return item.state === 'done' ? 'Verificar despliegue' : 'Desplegar';
        case 'detect':
            return retry ? 'Buscar de nuevo' : 'Buscar Moodle';
        case 'check':
            return 'Comprobar de nuevo';
        default:
            return 'Reintentar';
    }
}

function finishedState(state: ChecklistState): boolean {
    return state === 'done' || state === 'warning';
}

function useServerAction(projectUuid: string, server: RemoteServer) {
    const [busy, setBusy] = useState<string | null>(null);
    const post = (
        key: string,
        path: string,
        data: Record<string, string | number | boolean> = {},
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

    return { busy, post };
}

function useIsAdmin(): boolean {
    const { auth } = usePage<{ auth: { user: { role: string } | null } }>()
        .props;

    return auth.user?.role === 'ADMIN';
}

function Checklist({
    title,
    items,
    busy,
    editable,
    onAction,
    children,
}: {
    title: string;
    items: ChecklistItem[];
    busy: string | null;
    editable: boolean;
    onAction: (item: ChecklistItem) => void;
    /** Extra content under an item, by key (the Moodle choice, a profile form). */
    children?: (item: ChecklistItem) => ReactNode;
}) {
    const progress = checklistProgress(items);

    return (
        <div className="space-y-2 rounded-md border p-3" aria-label={title}>
            <div className="flex flex-wrap items-center gap-2">
                <h4 className="text-sm font-medium">{title}</h4>
                <Badge variant={progress.complete ? 'default' : 'secondary'}>
                    {progress.done}/{progress.total}
                </Badge>
            </div>
            <ol className="space-y-2">
                {items.map((entry, index) => {
                    const Icon = stateIcons[entry.state];
                    const showAction =
                        editable &&
                        entry.action !== null &&
                        entry.action !== 'create' &&
                        entry.action !== 'prepare' &&
                        entry.state !== 'blocked' &&
                        entry.state !== 'running';

                    return (
                        <li
                            key={entry.key}
                            className="space-y-1"
                            data-state={entry.state}
                        >
                            <div className="flex items-start gap-2 text-sm">
                                <Icon
                                    className={cn(
                                        'mt-0.5 size-4 shrink-0',
                                        stateColors[entry.state],
                                    )}
                                />
                                <div className="min-w-0 flex-1">
                                    <span className="font-medium">
                                        {index + 1}. {entry.label}
                                    </span>
                                    <p
                                        className={cn(
                                            'text-xs break-words',
                                            entry.state === 'failed'
                                                ? 'text-red-600'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {entry.detail}
                                    </p>
                                </div>
                                {showAction && entry.action && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={
                                            entry.state === 'done'
                                                ? 'ghost'
                                                : 'outline'
                                        }
                                        disabled={busy !== null}
                                        onClick={() => onAction(entry)}
                                    >
                                        {busy === entry.key
                                            ? 'Trabajando…'
                                            : actionLabel(entry.action, entry)}
                                    </Button>
                                )}
                            </div>
                            {children?.(entry)}
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}

/** «Alternativa manual»: the root helpers keep writing the same profiles. */
export function ManualAlternative({ script }: { script: string }) {
    return (
        <p className="text-muted-foreground text-xs">
            <span className="font-medium">Alternativa manual:</span> el
            administrador del servidor puede crear el mismo perfil como root con{' '}
            <code>{script}</code> (ver la guía).
        </p>
    );
}

/**
 * «Preparar origen» for one source server and the profile its row uses. The same
 * component serves COLLECT projects and the collection «Recolectar ahora» creates.
 */
export function SourcePreparation({
    projectUuid,
    server,
    profileId,
    agentVersion,
    editable,
}: {
    projectUuid: string;
    server: RemoteServer;
    profileId: string;
    agentVersion: string | null;
    editable: boolean;
}) {
    const items = sourceChecklist(server, profileId, agentVersion);
    const { busy, post } = useServerAction(projectUuid, server);
    const run = (entry: ChecklistItem) => {
        switch (entry.action) {
            case 'test':
                return post(entry.key, 'connection/test');
            case 'install':
                return post(entry.key, 'connection/install-agent');
            case 'probe':
                return post(entry.key, 'storage/probe');
            case 'deploy':
                return post(entry.key, 'collector/deploy');
            case 'detect':
                return post(entry.key, 'sources/moodle-detect');
            case 'check':
                return post(entry.key, 'sources/check', { source: profileId });
        }
    };

    return (
        <div className="space-y-2">
            <Checklist
                title="Preparar origen"
                items={items}
                busy={busy}
                editable={editable}
                onAction={run}
            >
                {(entry) =>
                    editable &&
                    entry.key === 'profile' &&
                    entry.action === 'create' &&
                    entry.state !== 'blocked' ? (
                        <SourceProfileForm
                            projectUuid={projectUuid}
                            server={server}
                            profileId={profileId}
                        />
                    ) : null
                }
            </Checklist>
            <ManualAlternative script="sudo bash mwt-source-profile" />
        </div>
    );
}

function candidateLabel(candidate: MoodleCandidate): string {
    return candidate.runtime === 'compose'
        ? `${candidate.config_path} · Compose ${candidate.compose_service ?? '—'} en ${candidate.compose_dir ?? '—'}`
        : `${candidate.config_path} · nativo`;
}

const candidateIssues: Record<string, string> = {
    php_missing:
        'el servidor no tiene PHP; use el contenedor si Moodle corre en Compose',
    moodle_unverified:
        'solo se monta config.php; no se pudo ver el código de Moodle',
    compose_file_unsupported:
        'el proyecto Compose no usa compose.yaml, el nombre que admite el agente',
};

/** Choice among the detected installations, or a path typed by hand, then confirmation. */
function SourceProfileForm({
    projectUuid,
    server,
    profileId,
}: {
    projectUuid: string;
    server: RemoteServer;
    profileId: string;
}) {
    const admin = useIsAdmin();
    const candidates = (server.moodle_detected?.candidates ?? []).filter(
        (candidate) => !candidate.too_long,
    );
    const first = preferredCandidate(candidates);
    const form = useForm({
        config_path: first?.config_path ?? '',
        runtime: (first?.runtime ?? 'native') as 'native' | 'compose',
        run_as: first?.run_as ?? 'www-data',
        compose_dir: first?.compose_dir ?? '',
        compose_service: first?.compose_service ?? '',
        compose_profile: first?.compose_profile ?? '',
        force: false,
        confirmed: false,
    });
    const errors = form.errors as Record<string, string>;
    const [runtimeEdited, setRuntimeEdited] = useState(false);
    const choose = (candidate: MoodleCandidate) =>
        form.setData((data) => ({
            ...data,
            ...chooseCandidate(data, candidate, runtimeEdited),
        }));
    // Not a <form>: this sits inside the collection form, and a nested form would
    // submit (and save) the collection instead of creating the profile.
    const submit = () => {
        const request = sourceProfileRequest(
            projectUuid,
            server.uuid,
            profileId,
            form.data,
        );
        form.transform(() => request.payload);
        form.post(request.url, { preserveScroll: true });
    };

    if (!admin) {
        return (
            <p className="text-muted-foreground ml-6 text-xs">
                Un administrador de la plataforma confirma la instalación y crea
                el perfil.
            </p>
        );
    }

    return (
        <div
            role="group"
            aria-label={`Crear el perfil ${profileId}`}
            className="bg-muted/40 ml-6 space-y-3 rounded-md p-3"
            onKeyDown={(event) => {
                // Enter in a field must not reach the collection form around it.
                if (
                    event.key === 'Enter' &&
                    event.target instanceof HTMLInputElement
                ) {
                    event.preventDefault();
                }
            }}
        >
            {candidates.length > 0 && (
                <fieldset className="space-y-1">
                    <legend className="text-xs font-medium">
                        Instalaciones encontradas
                    </legend>
                    {candidates.map((candidate) => (
                        <label
                            key={`${candidate.runtime}:${candidate.compose_dir}:${candidate.config_path}`}
                            className="flex items-start gap-2 text-xs"
                        >
                            <input
                                type="radio"
                                name={`candidate-${server.uuid}`}
                                className="mt-0.5"
                                checked={
                                    form.data.config_path ===
                                        candidate.config_path &&
                                    form.data.runtime === candidate.runtime
                                }
                                onChange={() => choose(candidate)}
                            />
                            <span className="break-all">
                                {candidateLabel(candidate)}
                                {candidate.issue && (
                                    <span className="block text-amber-600">
                                        {candidateIssues[candidate.issue] ??
                                            candidate.issue}
                                    </span>
                                )}
                            </span>
                        </label>
                    ))}
                </fieldset>
            )}
            <div className="grid gap-3 md:grid-cols-2">
                <div className="grid gap-1 md:col-span-2">
                    <Label
                        htmlFor={`config-${server.uuid}`}
                        className="text-xs"
                    >
                        Ruta de config.php
                    </Label>
                    <Input
                        id={`config-${server.uuid}`}
                        placeholder="/var/www/moodle/config.php"
                        value={form.data.config_path}
                        onChange={(event) =>
                            form.setData('config_path', event.target.value)
                        }
                    />
                    <InputError message={errors.config_path} />
                </div>
                <div className="grid gap-1">
                    <Label
                        htmlFor={`runtime-${server.uuid}`}
                        className="text-xs"
                    >
                        Ejecución de PHP
                    </Label>
                    <select
                        id={`runtime-${server.uuid}`}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={form.data.runtime}
                        onChange={(event) => {
                            setRuntimeEdited(true);
                            form.setData(
                                'runtime',
                                event.target.value as 'native' | 'compose',
                            );
                        }}
                    >
                        <option value="native">PHP del servidor</option>
                        <option value="compose">
                            Servicio de Docker Compose
                        </option>
                    </select>
                </div>
                <div className="grid gap-1">
                    <Label
                        htmlFor={`run-as-${server.uuid}`}
                        className="text-xs"
                    >
                        Usuario de ejecución
                    </Label>
                    <Input
                        id={`run-as-${server.uuid}`}
                        value={form.data.run_as}
                        onChange={(event) =>
                            form.setData('run_as', event.target.value)
                        }
                    />
                    <InputError message={errors.run_as} />
                </div>
                {form.data.runtime === 'compose' && (
                    <>
                        <div className="grid gap-1 md:col-span-2">
                            <Label
                                htmlFor={`compose-dir-${server.uuid}`}
                                className="text-xs"
                            >
                                Directorio de Compose (con compose.yaml)
                            </Label>
                            <Input
                                id={`compose-dir-${server.uuid}`}
                                value={form.data.compose_dir}
                                onChange={(event) =>
                                    form.setData(
                                        'compose_dir',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={errors.compose_dir} />
                        </div>
                        <div className="grid gap-1">
                            <Label
                                htmlFor={`compose-service-${server.uuid}`}
                                className="text-xs"
                            >
                                Servicio de PHP
                            </Label>
                            <Input
                                id={`compose-service-${server.uuid}`}
                                value={form.data.compose_service}
                                onChange={(event) =>
                                    form.setData(
                                        'compose_service',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={errors.compose_service} />
                        </div>
                        <div className="grid gap-1">
                            <Label
                                htmlFor={`compose-profile-${server.uuid}`}
                                className="text-xs"
                            >
                                Perfil de Compose (opcional)
                            </Label>
                            <Input
                                id={`compose-profile-${server.uuid}`}
                                value={form.data.compose_profile}
                                onChange={(event) =>
                                    form.setData(
                                        'compose_profile',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </>
                )}
            </div>
            <div className="flex items-start gap-2 text-xs">
                <Checkbox
                    id={`force-${server.uuid}`}
                    checked={form.data.force}
                    onCheckedChange={(checked) =>
                        form.setData('force', checked === true)
                    }
                />
                <Label htmlFor={`force-${server.uuid}`} className="text-xs">
                    Reemplazar el perfil si ya existe en el servidor
                </Label>
            </div>
            <div className="flex items-start gap-2 text-xs">
                <Checkbox
                    id={`confirm-${server.uuid}`}
                    checked={form.data.confirmed}
                    onCheckedChange={(checked) =>
                        form.setData('confirmed', checked === true)
                    }
                />
                <Label htmlFor={`confirm-${server.uuid}`} className="text-xs">
                    Confirmo que esta es la instalación de Moodle del origen; el
                    agente escribe el perfil «{profileId}» como root en el
                    servidor y la acción queda auditada.
                </Label>
            </div>
            <InputError message={errors.source_profile ?? errors.source} />
            <Button
                type="button"
                size="sm"
                disabled={
                    form.processing ||
                    !form.data.confirmed ||
                    profileId === '' ||
                    form.data.config_path === ''
                }
                onClick={submit}
            >
                {form.processing ? 'Creando…' : 'Crear y comprobar el perfil'}
            </Button>
        </div>
    );
}

/** «Preparar destino»: from the connection to the destination ready for the Consolidator. */
export function DestinationPreparation({
    projectUuid,
    server,
    agentVersion,
    editable,
}: {
    projectUuid: string;
    server: RemoteServer;
    agentVersion: string | null;
    editable: boolean;
}) {
    const items = destinationChecklist(server, agentVersion);
    const { busy, post } = useServerAction(projectUuid, server);
    const profile =
        server.destinations.find((destination) => destination.ready) ??
        server.destinations[0];
    const run = (entry: ChecklistItem) => {
        switch (entry.action) {
            case 'test':
                return post(entry.key, 'connection/test');
            case 'install':
                return post(entry.key, 'connection/install-agent');
            case 'probe':
                return post(entry.key, 'storage/probe');
            case 'deploy':
                return post(entry.key, 'consolidator/deploy');
            case 'check':
                return profile
                    ? post(entry.key, 'destinations/check', {
                          destination: profile.id,
                      })
                    : undefined;
        }
    };

    return (
        <div className="space-y-2">
            <Checklist
                title="Preparar destino"
                items={items}
                busy={busy}
                editable={editable}
                onAction={run}
            >
                {(entry) => {
                    if (
                        editable &&
                        entry.key === 'profile' &&
                        entry.action === 'create' &&
                        entry.state !== 'blocked'
                    ) {
                        return (
                            <DestinationProfileForm
                                projectUuid={projectUuid}
                                server={server}
                            />
                        );
                    }
                    if (entry.key === 'prepare' && entry.action === 'prepare') {
                        return (
                            <p className="text-muted-foreground ml-6 text-xs">
                                Use «Preparar destino» en el perfil, más abajo:
                                si el destino ya tiene un sitio, el diálogo
                                pregunta si se limpia o se reutiliza.
                            </p>
                        );
                    }

                    return null;
                }}
            </Checklist>
            <ManualAlternative script="sudo bash mwt-destination-profile" />
        </div>
    );
}

function DestinationProfileForm({
    projectUuid,
    server,
}: {
    projectUuid: string;
    server: RemoteServer;
}) {
    const admin = useIsAdmin();
    const form = useForm({
        destination: 'campus',
        public_url: server.host ? `https://${server.host}` : 'https://',
        admin_email: '',
        admin_user: 'admin',
        http_port: 8090,
        confirmed: false,
    });
    const errors = form.errors as Record<string, string>;

    if (!admin) {
        return (
            <p className="text-muted-foreground ml-6 text-xs">
                Un administrador de la plataforma crea el perfil del destino.
            </p>
        );
    }

    return (
        <div
            role="group"
            aria-label="Crear el perfil del destino"
            className="bg-muted/40 ml-6 space-y-3 rounded-md p-3"
            onKeyDown={(event) => {
                if (
                    event.key === 'Enter' &&
                    event.target instanceof HTMLInputElement
                ) {
                    event.preventDefault();
                }
            }}
        >
            <div className="grid gap-3 md:grid-cols-2">
                <Field
                    id={`dst-id-${server.uuid}`}
                    label="Identificador del perfil"
                    value={form.data.destination}
                    error={errors.destination}
                    onChange={(value) => form.setData('destination', value)}
                />
                <Field
                    id={`dst-url-${server.uuid}`}
                    label="URL pública HTTPS del sitio"
                    value={form.data.public_url}
                    error={errors.public_url}
                    onChange={(value) => form.setData('public_url', value)}
                />
                <Field
                    id={`dst-email-${server.uuid}`}
                    label="Correo del administrador de Moodle"
                    value={form.data.admin_email}
                    error={errors.admin_email}
                    onChange={(value) => form.setData('admin_email', value)}
                />
                <Field
                    id={`dst-user-${server.uuid}`}
                    label="Usuario administrador"
                    value={form.data.admin_user}
                    error={errors.admin_user}
                    onChange={(value) => form.setData('admin_user', value)}
                />
                <Field
                    id={`dst-port-${server.uuid}`}
                    label="Puerto HTTP local (detrás del proxy TLS)"
                    value={String(form.data.http_port)}
                    error={errors.http_port}
                    onChange={(value) =>
                        form.setData('http_port', Number(value) || 0)
                    }
                />
            </div>
            <p className="text-muted-foreground text-xs">
                El runtime se copia del Consolidador desplegado y verificado. La
                clave generada del administrador de Moodle queda solo en el .env
                del servidor (0600, root); la plataforma no la recibe. El
                dominio, el DNS y el proxy TLS hacia 127.0.0.1 siguen a cargo
                del administrador del servidor.
            </p>
            <div className="flex items-start gap-2 text-xs">
                <Checkbox
                    id={`dst-confirm-${server.uuid}`}
                    checked={form.data.confirmed}
                    onCheckedChange={(checked) =>
                        form.setData('confirmed', checked === true)
                    }
                />
                <Label
                    htmlFor={`dst-confirm-${server.uuid}`}
                    className="text-xs"
                >
                    Confirmo que esta es la URL definitiva del sitio; el agente
                    crea el runtime y el perfil como root y la acción queda
                    auditada.
                </Label>
            </div>
            <InputError message={errors.destination_profile} />
            <Button
                type="button"
                size="sm"
                disabled={form.processing || !form.data.confirmed}
                onClick={() => {
                    form.transform((data) => ({
                        destination: data.destination,
                        public_url: data.public_url,
                        admin_email: data.admin_email,
                        admin_user: data.admin_user,
                        http_port: Number(data.http_port),
                    }));
                    form.post(
                        `/projects/${projectUuid}/servers/${server.uuid}/destinations/create`,
                        { preserveScroll: true },
                    );
                }}
            >
                {form.processing ? 'Creando…' : 'Crear y comprobar el perfil'}
            </Button>
        </div>
    );
}

function Field({
    id,
    label,
    value,
    error,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid gap-1">
            <Label htmlFor={id} className="text-xs">
                {label}
            </Label>
            <Input
                id={id}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
            <InputError message={error} />
        </div>
    );
}
