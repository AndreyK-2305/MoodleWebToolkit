import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    AlertCircle,
    CheckCircle2,
    Download,
    PlugZap,
    Server,
    TriangleAlert,
} from 'lucide-react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DestinationPreparation } from '@/components/server-preparation';
import { awaitingFollowUp } from '@/lib/remote-follow-up';
import { cn } from '@/lib/utils';

type ConnectionType = 'aws-ssm' | 'ssh';
type CheckResult = 'SUCCESS' | 'WARNING' | 'ERROR';

export type RemoteConnection = {
    type: ConnectionType;
    status: 'UNTESTED' | 'VALID' | 'INVALID';
    instance_id: string | null;
    region: string | null;
    host: string | null;
    port: number | null;
    user: string | null;
    use_sudo: boolean;
    secret_version: string | null;
    has_secret_reference: boolean;
    host_key_type: string | null;
    host_key_fingerprint: string | null;
};
export type RemoteSourceProfile = {
    id: string;
    ready: boolean;
    checked_at: string | null;
    check_error: string | null;
    detected: boolean;
    valid: boolean | null;
    profile_error: string | null;
    runtime: string | null;
    moodle_release: string | null;
    php_version: string | null;
    dataroot_free_bytes: number | null;
    state_free_bytes: number | null;
    run_as: string | null;
    cpus: number | null;
    aws_cli_v2: boolean | null;
    max_workers: number;
};
export type MoodleCandidate = {
    config_path: string;
    runtime: 'native' | 'compose';
    compose_dir: string | null;
    compose_service: string | null;
    compose_profile: string | null;
    run_as: string | null;
    config_owner: string | null;
    issue: string | null;
    too_long: boolean;
};
export type RemoteDestinationProfile = {
    id: string;
    ready: boolean;
    checked_at: string | null;
    check_error: string | null;
    blockers: string[];
    warnings: string[];
    detected: boolean;
    valid: boolean | null;
    profile_error: string | null;
    public_url: string | null;
    docker_version: string | null;
    memory_bytes: number | null;
    runtime_free_bytes: number | null;
    target_running: boolean;
    destination_written: boolean;
    in_use: boolean;
    target_volumes: number | null;
    copias_packages: number | null;
    created: {
        created_at?: string;
        runtime_created?: boolean;
        user_created?: boolean;
        replaced?: boolean;
        docker_group?: boolean;
        run_as?: string | null;
        admin_user?: string | null;
        admin_password_location?: string | null;
    } | null;
    preparation: {
        state: string | null;
        launched_at: string | null;
        finished_at: string | null;
        observed_at: string | null;
        exit_code: number | null;
        error_code: string | null;
        observe_error: string | null;
        last_error_line: string | null;
        markers: string[];
        tail: string;
    } | null;
    copias: {
        listed_at: string | null;
        write_locked: boolean;
        packages: {
            name: string;
            size: number | null;
            sidecar_sha256: string | null;
            source_package_uuid: string | null;
            source_id: string | null;
        }[];
    } | null;
    fetches: {
        source_package_uuid: string;
        package_name?: string;
        source_id?: string;
        state?: string;
        error_code?: string | null;
        error_line?: string | null;
        name?: string | null;
        already_present?: boolean | null;
        launched_at?: string;
        finished_at?: string | null;
        observe_error?: string | null;
    }[];
};
export type RemoteServer = {
    uuid: string;
    name: string;
    role: 'SOURCE' | 'DESTINATION' | 'AUXILIARY';
    host: string | null;
    connection: RemoteConnection | null;
    agent_update_blocked: string | null;
    collector_deployed: boolean;
    sources: RemoteSourceProfile[];
    sources_detected_at: string | null;
    consolidator: {
        deployed: boolean;
        tree_sha256: string | null;
        deployment_state: 'QUEUED' | 'RUNNING' | 'DEPLOYED' | 'FAILED' | null;
        deployment_error: string | null;
        updated_at: string | null;
    };
    destinations: RemoteDestinationProfile[];
    destinations_detected_at: string | null;
    storage_probe: {
        checked_at: string | null;
        mode: 'write' | 'readwrite';
        ok: boolean;
        error_code: string | null;
        written?: boolean;
        read_back?: boolean | null;
        confirmed?: boolean;
        deleted?: boolean;
    } | null;
    moodle_detected: {
        detected_at: string | null;
        error_code: string | null;
        php_present: boolean | null;
        docker_checked: boolean | null;
        candidates: MoodleCandidate[];
    } | null;
    destination_repoint: {
        at: string | null;
        tree_sha256: string | null;
        error_code: string | null;
        destinations: { id: string; repointed: boolean; reason: string }[];
    } | null;
    evidence: {
        checked_at: string | null;
        status: string | null;
        agent_version: string | null;
        checks: { key: string; result: CheckResult; message: string }[];
        probe: Record<string, string | number | null> | null;
    } | null;
    operations: {
        uuid: string;
        command_key: string;
        communication_state: string;
        functional_state: string;
        exit_code: number | null;
        manual_intervention_required: boolean;
        last_error: string | null;
        started_at: string | null;
        terminated_at: string | null;
        last_observed_at: string | null;
        collector: {
            stage: string | null;
            completed_courses: number | null;
            total_courses: number | null;
            failed_courses: number | null;
            percent: number | null;
            package_ready: boolean;
            package_size: number | null;
        } | null;
    }[];
};
export type AvailablePackage = {
    uuid: string;
    name: string;
    source_id: string;
    sha256: string;
    size: number;
    project: string | null;
};
export type RemoteAccess = {
    enabled: boolean;
    agent_version: string | null;
    default_region: string;
    available_packages: AvailablePackage[];
    servers: RemoteServer[];
};

const roleLabels: Record<RemoteServer['role'], string> = {
    SOURCE: 'Origen',
    DESTINATION: 'Destino',
    AUXILIARY: 'Auxiliar',
};
const statusLabels: Record<RemoteConnection['status'], string> = {
    UNTESTED: 'Sin probar',
    VALID: 'Verificada',
    INVALID: 'Con errores',
};

/** The badge of a connection: a server that only lacks its agent is not «with errors». */
export function connectionStatus(server: RemoteServer): {
    label: string;
    variant: 'default' | 'secondary' | 'destructive';
} {
    const connection = server.connection;
    if (!connection) {
        return { label: 'Sin configurar', variant: 'secondary' };
    }
    const failed = (server.evidence?.checks ?? []).filter(
        (check) => check.result === 'ERROR',
    );
    if (
        connection.status === 'INVALID' &&
        failed.length > 0 &&
        failed.every((check) => check.key === 'agent')
    ) {
        return {
            label: failed[0].message.includes('no está instalado')
                ? 'Falta instalar el agente'
                : 'Falta actualizar el agente',
            variant: 'secondary',
        };
    }
    return {
        label: statusLabels[connection.status],
        variant:
            connection.status === 'INVALID'
                ? 'destructive'
                : connection.status === 'VALID'
                  ? 'default'
                  : 'secondary',
    };
}

export default function RemoteAccessPanel({
    projectUuid,
    access,
    editable,
    role,
    title = 'Conexiones remotas',
    emptyMessage = 'Registre las instancias del proyecto para configurar sus servidores.',
}: {
    projectUuid: string;
    access: RemoteAccess;
    editable: boolean;
    /** Inside a wizard step: only the servers of that role. */
    role?: RemoteServer['role'];
    title?: string;
    emptyMessage?: string;
}) {
    const servers = role
        ? access.servers.filter((server) => server.role === role)
        : access.servers;
    // Deployments, preparations and transfers run on the servers; while one is in
    // progress the panel reads their state again instead of waiting for a reload.
    const busy = access.servers.some(
        (server) =>
            ['QUEUED', 'RUNNING'].includes(
                server.consolidator.deployment_state ?? '',
            ) ||
            server.destinations.some(
                (destination) =>
                    ['STARTING', 'RUNNING', 'CANCELLING'].includes(
                        destination.preparation?.state ?? '',
                    ) ||
                    destination.fetches.some((fetch) =>
                        ['STARTING', 'RUNNING'].includes(fetch.state ?? ''),
                    ) ||
                    awaitingFollowUp(destination, Date.now()),
            ),
    );
    const [wasBusy, setWasBusy] = useState(busy);
    useEffect(() => {
        if (!busy) {
            return;
        }
        const timer = window.setInterval(
            () => router.reload({ only: ['remoteAccess'] }),
            10000,
        );

        return () => window.clearInterval(timer);
    }, [busy]);
    // When the work ends, the cards below (profiles, packages) read the final state once more.
    useEffect(() => {
        if (wasBusy && !busy) {
            const timer = window.setTimeout(
                () => router.reload({ only: ['remoteAccess'] }),
                3000,
            );
            setWasBusy(false);

            return () => window.clearTimeout(timer);
        }
        if (busy && !wasBusy) {
            setWasBusy(true);
        }
    }, [busy, wasBusy]);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <PlugZap className="size-5" /> {title}
                </CardTitle>
                <p className="text-muted-foreground text-sm">
                    Cada servidor se alcanza por AWS Systems Manager
                    (recomendado) o por SSH con la llave del host fijada. La
                    plataforma no mantiene la conexión abierta: los procesos
                    corren en el servidor y se vuelven a consultar.
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                {servers.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        {emptyMessage}
                    </p>
                ) : (
                    servers.map((server) => (
                        <ServerConnection
                            key={server.uuid}
                            projectUuid={projectUuid}
                            server={server}
                            agentVersion={access.agent_version}
                            defaultRegion={access.default_region}
                            availablePackages={access.available_packages}
                            editable={editable}
                        />
                    ))
                )}
            </CardContent>
        </Card>
    );
}

function ServerConnection({
    projectUuid,
    server,
    agentVersion,
    defaultRegion,
    availablePackages,
    editable,
}: {
    projectUuid: string;
    server: RemoteServer;
    agentVersion: string | null;
    defaultRegion: string;
    availablePackages: AvailablePackage[];
    editable: boolean;
}) {
    const connection = server.connection;
    const base = `/projects/${projectUuid}/servers/${server.uuid}/connection`;
    const form = useForm({
        type: (connection?.type ?? 'aws-ssm') as ConnectionType,
        instance_id: connection?.instance_id ?? '',
        region: connection?.region ?? defaultRegion,
        host: connection?.host ?? server.host ?? '',
        port: connection?.port ?? 22,
        user: connection?.user ?? 'ubuntu',
        host_key: '',
        secret_reference: '',
        secret_version: connection?.secret_version ?? '1',
        use_sudo: connection?.use_sudo ?? true,
    });
    const actions = useForm({});
    const errors = form.errors as Record<string, string>;
    const ssh = form.data.type === 'ssh';

    const save = () => {
        form.transform((data) =>
            data.type === 'aws-ssm'
                ? {
                      type: data.type,
                      instance_id: data.instance_id,
                      region: data.region,
                  }
                : {
                      type: data.type,
                      host: data.host,
                      port: Number(data.port),
                      user: data.user,
                      host_key: data.host_key,
                      secret_reference: data.secret_reference,
                      secret_version: data.secret_version,
                      use_sudo: data.use_sudo,
                  },
        );
        form.put(base, { preserveScroll: true });
    };

    return (
        <section
            aria-label={`Conexión de ${server.name}`}
            className="border-border space-y-4 rounded-lg border p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <Server className="text-muted-foreground size-4" />
                    <h3 className="font-medium">{server.name}</h3>
                    <Badge variant="outline">{roleLabels[server.role]}</Badge>
                </div>
                <Badge variant={connectionStatus(server).variant}>
                    {connectionStatus(server).label}
                </Badge>
            </div>

            <form
                className="grid gap-4 md:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <div className="grid gap-2">
                    <Label htmlFor={`type-${server.uuid}`}>Transporte</Label>
                    <select
                        id={`type-${server.uuid}`}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm disabled:opacity-60"
                        value={form.data.type}
                        disabled={!editable}
                        onChange={(event) =>
                            form.setData(
                                'type',
                                event.target.value as ConnectionType,
                            )
                        }
                    >
                        <option value="aws-ssm">
                            AWS Systems Manager (SSM)
                        </option>
                        <option value="ssh">SSH</option>
                    </select>
                </div>
                {ssh ? (
                    <>
                        <Field
                            id={`host-${server.uuid}`}
                            label="Host"
                            value={form.data.host}
                            error={errors.host}
                            disabled={!editable}
                            onChange={(value) => form.setData('host', value)}
                        />
                        <Field
                            id={`port-${server.uuid}`}
                            label="Puerto"
                            type="number"
                            value={String(form.data.port)}
                            error={errors.port}
                            disabled={!editable}
                            onChange={(value) =>
                                form.setData('port', Number(value))
                            }
                        />
                        <Field
                            id={`user-${server.uuid}`}
                            label="Usuario"
                            value={form.data.user}
                            error={errors.user}
                            disabled={!editable}
                            onChange={(value) => form.setData('user', value)}
                        />
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor={`host-key-${server.uuid}`}>
                                Llave pública del host
                            </Label>
                            <Input
                                id={`host-key-${server.uuid}`}
                                placeholder={
                                    connection?.host_key_fingerprint
                                        ? `Fijada: ${connection.host_key_type} ${connection.host_key_fingerprint}`
                                        : 'ssh-ed25519 AAAA… (salida de ssh-keyscan)'
                                }
                                value={form.data.host_key}
                                disabled={!editable}
                                onChange={(event) =>
                                    form.setData('host_key', event.target.value)
                                }
                            />
                            <InputError message={errors.host_key} />
                        </div>
                        <Field
                            id={`secret-${server.uuid}`}
                            label="Referencia de la llave privada"
                            placeholder={
                                connection?.has_secret_reference
                                    ? 'Referencia guardada; escríbala para cambiarla'
                                    : 'origen-ssh-key'
                            }
                            value={form.data.secret_reference}
                            error={errors.secret_reference}
                            disabled={!editable}
                            onChange={(value) =>
                                form.setData('secret_reference', value)
                            }
                        />
                        <Field
                            id={`secret-version-${server.uuid}`}
                            label="Versión de la referencia"
                            value={form.data.secret_version}
                            error={errors.secret_version}
                            disabled={!editable}
                            onChange={(value) =>
                                form.setData('secret_version', value)
                            }
                        />
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id={`sudo-${server.uuid}`}
                                checked={form.data.use_sudo}
                                disabled={!editable}
                                onCheckedChange={(checked) =>
                                    form.setData('use_sudo', checked === true)
                                }
                            />
                            <Label htmlFor={`sudo-${server.uuid}`}>
                                Ejecutar el agente con sudo sin contraseña
                            </Label>
                        </div>
                    </>
                ) : (
                    <>
                        <Field
                            id={`instance-${server.uuid}`}
                            label="ID de instancia"
                            placeholder="i-0123456789abcdef0"
                            value={form.data.instance_id}
                            error={errors.instance_id}
                            disabled={!editable}
                            onChange={(value) =>
                                form.setData('instance_id', value)
                            }
                        />
                        <Field
                            id={`region-${server.uuid}`}
                            label="Región AWS"
                            value={form.data.region}
                            error={errors.region}
                            disabled={!editable}
                            onChange={(value) => form.setData('region', value)}
                        />
                    </>
                )}
                <InputError
                    className="md:col-span-2"
                    message={errors.configuration ?? errors.type}
                />
                {editable && (
                    <div className="md:col-span-2">
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={form.processing}
                        >
                            Guardar conexión
                        </Button>
                    </div>
                )}
            </form>

            {editable && connection && (
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={actions.processing}
                        onClick={() =>
                            actions.post(`${base}/install-agent`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        <Download /> Instalar agente {agentVersion}
                    </Button>
                    <Button
                        type="button"
                        disabled={actions.processing}
                        onClick={() =>
                            actions.post(`${base}/test`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        <PlugZap /> Probar conexión
                    </Button>
                </div>
            )}

            {!editable && connection && (
                <AgentUpdate
                    base={base}
                    server={server}
                    agentVersion={agentVersion}
                />
            )}

            {server.role === 'DESTINATION' && (
                <DestinationPreparation
                    projectUuid={projectUuid}
                    server={server}
                    agentVersion={agentVersion}
                    editable={editable}
                />
            )}
            {server.role === 'DESTINATION' &&
                connection?.status === 'VALID' && (
                    <DestinationRuntime
                        projectUuid={projectUuid}
                        server={server}
                        availablePackages={availablePackages}
                        editable={editable}
                    />
                )}
            {server.evidence && <Evidence evidence={server.evidence} />}
            {server.operations.length > 0 && (
                <Operations operations={server.operations} />
            )}
        </section>
    );
}

/**
 * While the project is in progress the panel is read-only, but an administrator can still
 * replace a server's agent (a fixed agent, for example) when nothing runs on that server.
 */
function AgentUpdate({
    base,
    server,
    agentVersion,
}: {
    base: string;
    server: RemoteServer;
    agentVersion: string | null;
}) {
    const { auth } = usePage<{ auth: { user: { role: string } } }>().props;
    const [open, setOpen] = useState(false);
    const form = useForm({ acknowledged: false });
    const errors = form.errors as Record<string, string>;

    if (auth.user.role !== 'ADMIN') {
        return null;
    }

    const blocked = server.agent_update_blocked;

    return (
        <div className="space-y-2 rounded-md border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-medium">Agente del servidor</span>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="ml-auto"
                    disabled={blocked !== null || form.processing}
                    onClick={() => {
                        form.reset();
                        form.clearErrors();
                        setOpen(true);
                    }}
                >
                    <Download /> Actualizar agente {agentVersion}
                </Button>
            </div>
            <p className="text-muted-foreground text-xs">
                {blocked !== null
                    ? `No disponible: ${blocked}. Se habilita cuando termina o cuando la Ejecución espera una acción del administrador.`
                    : 'El proyecto está en curso y no hay operaciones en este servidor: un administrador puede reemplazar su agente por la versión verificada de la plataforma.'}
            </p>
            {!open && <InputError message={errors.agent} />}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Actualizar el agente de {server.name}
                        </DialogTitle>
                        <DialogDescription>
                            Se instala en el servidor el agente {agentVersion}{' '}
                            verificado por SHA-256 y luego se le pide su
                            versión. La Ejecución en espera no cambia; si el
                            agente ya coincide, no se reinstala.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex items-start gap-2 text-sm">
                        <Checkbox
                            id={`agent-update-${server.uuid}`}
                            checked={form.data.acknowledged}
                            onCheckedChange={(checked) =>
                                form.setData('acknowledged', checked === true)
                            }
                        />
                        <Label htmlFor={`agent-update-${server.uuid}`}>
                            Entiendo que se reemplaza el agente de este servidor
                            mientras el proyecto está en curso.
                        </Label>
                    </div>
                    <InputError message={errors.acknowledged ?? errors.agent} />
                    <Button
                        type="button"
                        disabled={!form.data.acknowledged || form.processing}
                        onClick={() =>
                            form.post(`${base}/update-agent`, {
                                preserveScroll: true,
                                onSuccess: () => setOpen(false),
                            })
                        }
                    >
                        Sí, actualizar el agente
                    </Button>
                </DialogContent>
            </Dialog>
        </div>
    );
}

const deploymentLabels: Record<
    NonNullable<RemoteServer['consolidator']['deployment_state']>,
    string
> = {
    QUEUED: 'Despliegue en cola',
    RUNNING: 'Desplegando',
    DEPLOYED: 'Desplegado y verificado',
    FAILED: 'Despliegue fallido',
};
const destinationCodes: Record<string, string> = {
    env_missing: 'falta el .env del runtime',
    env_unprotected: 'el .env no está protegido (0600)',
    env_incomplete: 'el .env está incompleto',
    env_url_mismatch: 'la URL del .env difiere de la del perfil',
    tool_missing: 'el árbol del perfil no está desplegado',
    tool_tree_mismatch: 'el perfil usa otro árbol que el desplegado',
    flock_missing: 'falta flock',
    python3_missing: 'falta python3',
    runtime_not_owned: 'el runtime no pertenece al usuario RUN_AS del perfil',
    run_as_not_docker: 'el usuario RUN_AS no está en el grupo docker',
    public_url_not_https: 'la URL pública no es HTTPS',
    engine_modified: 'el motor del runtime fue modificado',
    docker_unavailable: 'Docker no responde',
    compose_v2_missing: 'falta Docker Compose v2',
    low_memory: 'menos de 4 GiB de memoria',
    low_disk: 'menos de 20 GiB libres',
    destination_written: 'el destino ya recibió escrituras',
    shared_server:
        'hay más de un perfil en este servidor: comparten los servicios del Consolidador',
};

/** The Consolidator on a destination server: deployment and the administrator's profiles. */
function DestinationRuntime({
    projectUuid,
    server,
    availablePackages,
    editable,
}: {
    projectUuid: string;
    server: RemoteServer;
    availablePackages: AvailablePackage[];
    editable: boolean;
}) {
    const actions = useForm({});
    const check = useForm({ destination: '' });
    const base = `/projects/${projectUuid}/servers/${server.uuid}`;
    const state = server.consolidator.deployment_state;
    const busy = state === 'QUEUED' || state === 'RUNNING';
    const gib = (value: number | null) =>
        value === null ? '—' : `${(value / 1073741824).toFixed(1)} GiB`;

    return (
        <div
            aria-label={`Consolidador en ${server.name}`}
            className="space-y-3 rounded-md border border-dashed p-3"
        >
            <div className="flex flex-wrap items-center gap-2">
                <h4 className="text-sm font-medium">Consolidador V8</h4>
                <Badge
                    variant={
                        state === 'FAILED'
                            ? 'destructive'
                            : server.consolidator.deployed
                              ? 'default'
                              : 'secondary'
                    }
                >
                    {state
                        ? deploymentLabels[state]
                        : server.consolidator.deployed
                          ? 'Desplegado y verificado'
                          : 'Sin desplegar'}
                </Badge>
                {state === 'FAILED' && server.consolidator.deployment_error && (
                    <span className="text-muted-foreground text-xs">
                        {server.consolidator.deployment_error}
                    </span>
                )}
            </div>
            <p className="text-muted-foreground text-xs">
                El perfil se crea desde «Preparar destino» y sigue al
                Consolidador en cada despliegue mientras el sitio no haya
                recibido escrituras. El dominio, el TLS y las claves del sitio
                quedan en el servidor.
            </p>
            {editable && (
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={actions.processing || busy}
                        onClick={() =>
                            actions.post(`${base}/consolidator/deploy`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        <Download /> Desplegar Consolidador
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={actions.processing}
                        onClick={() =>
                            actions.post(`${base}/destinations/detect`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Detectar perfiles de destino
                    </Button>
                </div>
            )}
            {server.destinations.length === 0 ? (
                <p className="text-muted-foreground text-xs">
                    {server.destinations_detected_at
                        ? 'El servidor no tiene perfiles de destino.'
                        : 'Aún no se han detectado perfiles de destino.'}
                </p>
            ) : (
                <ul className="grid gap-2">
                    {server.destinations.map((destination) => (
                        <li
                            key={destination.id}
                            className="border-border space-y-1 rounded-md border px-3 py-2 text-sm"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-xs">
                                    {destination.id}
                                </span>
                                <Badge
                                    variant={
                                        destination.ready
                                            ? 'default'
                                            : destination.checked_at
                                              ? 'destructive'
                                              : 'secondary'
                                    }
                                >
                                    {destination.ready
                                        ? 'Listo'
                                        : destination.checked_at
                                          ? 'No listo'
                                          : 'Sin comprobar'}
                                </Badge>
                                {destination.public_url && (
                                    <span className="text-muted-foreground text-xs">
                                        {destination.public_url}
                                    </span>
                                )}
                                {editable && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        className="ml-auto"
                                        disabled={check.processing}
                                        onClick={() => {
                                            check.transform(() => ({
                                                destination: destination.id,
                                            }));
                                            check.post(
                                                `${base}/destinations/check`,
                                                { preserveScroll: true },
                                            );
                                        }}
                                    >
                                        Comprobar perfil
                                    </Button>
                                )}
                            </div>
                            {destination.profile_error && (
                                <p className="text-xs text-red-600">
                                    Perfil inválido: {destination.profile_error}
                                </p>
                            )}
                            {destination.check_error && (
                                <p className="text-xs text-red-600">
                                    Error de la comprobación:{' '}
                                    {destination.check_error}
                                </p>
                            )}
                            {destination.blockers.includes(
                                'tool_tree_mismatch',
                            ) &&
                                server.consolidator.tree_sha256 && (
                                    <div className="space-y-1 rounded-md border border-amber-300 bg-amber-50 p-2 text-xs dark:border-amber-900 dark:bg-amber-950/30">
                                        <p>
                                            El perfil apunta a otra versión del
                                            Consolidador y no se re-apuntó solo
                                            (vea «Preparar destino»).
                                            Alternativa manual: el administrador
                                            del servidor lo actualiza
                                            conservando el .env y luego se
                                            comprueba de nuevo:
                                        </p>
                                        <code className="bg-background block rounded p-1 break-all">
                                            sudo bash mwt-destination-profile{' '}
                                            {destination.id} --public-url{' '}
                                            {destination.public_url ??
                                                'https://…'}{' '}
                                            --force --tree{' '}
                                            {server.consolidator.tree_sha256}
                                        </code>
                                        <p>
                                            Si el destino ya recibió una
                                            consolidación, después use «Preparar
                                            destino» para limpiarlo con la nueva
                                            versión.
                                        </p>
                                    </div>
                                )}
                            {[...destination.blockers, ...destination.warnings]
                                .length > 0 && (
                                <ul className="text-xs">
                                    {destination.blockers.map((code) => (
                                        <li key={code} className="text-red-600">
                                            {destinationCodes[code] ?? code}
                                        </li>
                                    ))}
                                    {destination.warnings.map((code) => (
                                        <li
                                            key={code}
                                            className="text-amber-600"
                                        >
                                            {destinationCodes[code] ?? code}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {destination.checked_at && (
                                <dl className="text-muted-foreground grid gap-x-4 gap-y-1 text-xs sm:grid-cols-3">
                                    <Fact
                                        label="Docker"
                                        value={destination.docker_version}
                                    />
                                    <Fact
                                        label="Memoria"
                                        value={gib(destination.memory_bytes)}
                                    />
                                    <Fact
                                        label="Libre"
                                        value={gib(
                                            destination.runtime_free_bytes,
                                        )}
                                    />
                                    <Fact
                                        label="Moodle destino"
                                        value={
                                            destination.target_running
                                                ? 'en ejecución'
                                                : 'detenido'
                                        }
                                    />
                                </dl>
                            )}
                            <Preparation
                                base={base}
                                destination={destination}
                                editable={editable}
                            />
                            <DestinationPackages
                                base={base}
                                destination={destination}
                                availablePackages={availablePackages}
                                editable={editable}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

const preparationLabels: Record<string, string> = {
    STARTING: 'Iniciando',
    RUNNING: 'Preparando',
    CANCELLING: 'Cancelando',
    SUCCEEDED: 'Destino preparado',
    FAILED: 'Preparación fallida',
    CANCELLED: 'Preparación cancelada',
    UNKNOWN: 'Estado desconocido',
};
const preparationMarkers: { key: string; label: string }[] = [
    { key: 'PREFLIGHT_OK', label: 'Preflight' },
    { key: 'DESTINO_LISTO', label: 'Moodle 5.2.1 instalado' },
    { key: 'EXPORT_TRANSPORT_OK', label: 'Intercambio /exports' },
    { key: 'TARGET_SHARED_EMAIL_POLICY_OK', label: 'Política de correo' },
];

/** The durable preparation of one destination profile: launch, progress, result and log tail. */
function Preparation({
    base,
    destination,
    editable,
}: {
    base: string;
    destination: RemoteDestinationProfile;
    editable: boolean;
}) {
    const form = useForm({ destination: destination.id });
    const prepare = useForm({
        destination: destination.id,
        reset: destination.in_use,
        confirmation: '',
    });
    const [open, setOpen] = useState(false);
    const { auth } = usePage<{ auth: { user: { role: string } } }>().props;
    const admin = auth.user.role === 'ADMIN';
    const preparation = destination.preparation;
    const active =
        preparation !== null &&
        ['STARTING', 'RUNNING', 'CANCELLING'].includes(preparation.state ?? '');
    // A destination the Consolidator already wrote to is only prepared again after cleaning it.
    const canPrepare = editable && destination.ready && !active;
    const confirmed =
        !destination.in_use || prepare.data.confirmation === 'CONFIRMAR';

    return (
        <div className="space-y-2 border-t pt-2">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-xs font-medium">Preparación</span>
                {preparation?.state ? (
                    <Badge
                        variant={
                            preparation.state === 'SUCCEEDED'
                                ? 'default'
                                : active
                                  ? 'secondary'
                                  : 'destructive'
                        }
                    >
                        {preparationLabels[preparation.state] ??
                            preparation.state}
                    </Badge>
                ) : (
                    <span className="text-muted-foreground text-xs">
                        Sin preparar
                    </span>
                )}
                {preparation?.error_code && (
                    <span className="text-muted-foreground text-xs">
                        {preparation.error_code}
                        {preparation.exit_code !== null &&
                            ` · código ${preparation.exit_code}`}
                    </span>
                )}
                {canPrepare && (
                    <Button
                        type="button"
                        size="sm"
                        className="ml-auto"
                        disabled={prepare.processing}
                        onClick={() => {
                            prepare.setData({
                                destination: destination.id,
                                reset: destination.in_use,
                                confirmation: '',
                            });
                            setOpen(true);
                        }}
                    >
                        Preparar destino
                    </Button>
                )}
                {editable && active && preparation?.state !== 'CANCELLING' && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="ml-auto"
                        disabled={form.processing}
                        onClick={() =>
                            form.post(`${base}/destinations/prepare/cancel`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Cancelar preparación
                    </Button>
                )}
            </div>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Preparar el destino {destination.id}
                        </DialogTitle>
                        <DialogDescription>
                            El Consolidador instala en el servidor un Moodle
                            5.2.1 vacío detrás del proxy TLS del administrador.
                            Se recomienda una instancia limpia, dedicada a esta
                            consolidación.
                        </DialogDescription>
                    </DialogHeader>
                    {destination.in_use ? (
                        <div className="space-y-3 text-sm">
                            <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-xs dark:border-amber-900 dark:bg-amber-950/30">
                                <p className="font-medium">
                                    Este destino ya tiene un sitio
                                </p>
                                <ul className="mt-1 list-disc pl-4">
                                    {destination.destination_written && (
                                        <li>
                                            El Consolidador ya escribió en él
                                            una consolidación.
                                        </li>
                                    )}
                                    {destination.target_running && (
                                        <li>
                                            Moodle destino está en ejecución.
                                        </li>
                                    )}
                                    {(destination.target_volumes ?? 0) > 0 && (
                                        <li>
                                            Conserva{' '}
                                            {destination.target_volumes}{' '}
                                            volúmenes de datos (base de datos y
                                            moodledata).
                                        </li>
                                    )}
                                    {(destination.copias_packages ?? 0) > 0 && (
                                        <li>
                                            Hay {destination.copias_packages}{' '}
                                            paquetes en copias/.
                                        </li>
                                    )}
                                </ul>
                            </div>
                            <fieldset className="space-y-3">
                                <legend className="sr-only">
                                    Cómo preparar el destino
                                </legend>
                                <label className="flex gap-2">
                                    <input
                                        type="radio"
                                        name={`prepare-${destination.id}`}
                                        value="reset"
                                        checked={prepare.data.reset}
                                        aria-labelledby={`prepare-reset-${destination.id}`}
                                        aria-describedby={`prepare-reset-${destination.id}-detail`}
                                        onChange={() =>
                                            prepare.setData('reset', true)
                                        }
                                    />
                                    <span>
                                        <span
                                            id={`prepare-reset-${destination.id}`}
                                            className="font-medium"
                                        >
                                            Limpiar el destino y preparar
                                        </span>
                                        <span
                                            id={`prepare-reset-${destination.id}-detail`}
                                            className="text-muted-foreground block text-xs"
                                        >
                                            Elimina los servicios, los volúmenes
                                            y el estado del sitio anterior, y
                                            restaura el Consolidador verificado.
                                            Conserva el .env del administrador;
                                            las copias ya respaldadas en S3 no
                                            se tocan. El Moodle queda nuevo: el
                                            servicio Google OAuth2 se configura
                                            de nuevo cuando la Ejecución lo
                                            pida.
                                        </span>
                                    </span>
                                </label>
                                <label className="flex gap-2">
                                    <input
                                        type="radio"
                                        name={`prepare-${destination.id}`}
                                        value="keep"
                                        checked={!prepare.data.reset}
                                        disabled={
                                            destination.destination_written
                                        }
                                        aria-labelledby={`prepare-keep-${destination.id}`}
                                        aria-describedby={`prepare-keep-${destination.id}-detail`}
                                        onChange={() =>
                                            prepare.setData('reset', false)
                                        }
                                    />
                                    <span>
                                        <span
                                            id={`prepare-keep-${destination.id}`}
                                            className="font-medium"
                                        >
                                            Preparar sobre el destino existente
                                        </span>
                                        <span
                                            id={`prepare-keep-${destination.id}-detail`}
                                            className="text-muted-foreground block text-xs"
                                        >
                                            {destination.destination_written
                                                ? 'No disponible: el destino ya recibió una consolidación.'
                                                : 'Propenso a colisiones con el sitio anterior si Caddy, el DNS y los servicios relacionados no están configurados correctamente.'}
                                        </span>
                                    </span>
                                </label>
                            </fieldset>
                            {admin ? (
                                <div className="space-y-1">
                                    <Label
                                        htmlFor={`confirm-${destination.id}`}
                                    >
                                        Escriba CONFIRMAR para continuar
                                    </Label>
                                    <Input
                                        id={`confirm-${destination.id}`}
                                        autoComplete="off"
                                        value={prepare.data.confirmation}
                                        onChange={(event) =>
                                            prepare.setData(
                                                'confirmation',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            ) : (
                                <p className="text-xs text-red-600">
                                    Solo un administrador de la plataforma puede
                                    preparar o limpiar un destino que ya está en
                                    uso.
                                </p>
                            )}
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            El destino está limpio: no tiene servicios,
                            volúmenes ni paquetes de un sitio anterior.
                        </p>
                    )}
                    <Button
                        type="button"
                        disabled={
                            prepare.processing ||
                            !confirmed ||
                            (destination.in_use && !admin)
                        }
                        onClick={() =>
                            prepare.post(`${base}/destinations/prepare`, {
                                preserveScroll: true,
                                onSuccess: () => setOpen(false),
                            })
                        }
                    >
                        {destination.in_use && prepare.data.reset
                            ? 'Limpiar y preparar'
                            : 'Preparar destino'}
                    </Button>
                </DialogContent>
            </Dialog>
            {!destination.ready && !preparation && (
                <p className="text-muted-foreground text-xs">
                    Disponible cuando el perfil supere su comprobación.
                </p>
            )}
            {preparation && (
                <>
                    <ol className="flex flex-wrap gap-2 text-xs">
                        {preparationMarkers.map((marker) => (
                            <li
                                key={marker.key}
                                className={cn(
                                    'flex items-center gap-1',
                                    preparation.markers.includes(marker.key)
                                        ? 'text-emerald-600'
                                        : 'text-muted-foreground',
                                )}
                            >
                                <CheckCircle2 className="size-3" />
                                {marker.label}
                            </li>
                        ))}
                    </ol>
                    {preparation.last_error_line && (
                        <p className="text-xs text-red-600">
                            {preparation.last_error_line}
                        </p>
                    )}
                    {preparation.observe_error && active && (
                        <p className="text-xs text-amber-600">
                            Sin contacto con el servidor (
                            {preparation.observe_error}); el proceso sigue allí
                            y se vuelve a consultar.
                        </p>
                    )}
                    {preparation.tail !== '' && (
                        <details>
                            <summary className="text-muted-foreground cursor-pointer text-xs">
                                Últimas líneas del registro
                            </summary>
                            <pre className="bg-muted mt-1 max-h-60 overflow-auto rounded p-2 text-xs whitespace-pre-wrap">
                                {preparation.tail}
                            </pre>
                        </details>
                    )}
                </>
            )}
        </div>
    );
}

const fetchLabels: Record<string, string> = {
    STARTING: 'Iniciando',
    RUNNING: 'Transfiriendo',
    CANCELLING: 'Cancelando',
    SUCCEEDED: 'En copias/',
    FAILED: 'Fallida',
    CANCELLED: 'Cancelada',
    UNKNOWN: 'Estado desconocido',
};

/** The sealed packages in a destination's copias/ and the transfers bringing them from S3. */
function DestinationPackages({
    base,
    destination,
    availablePackages,
    editable,
}: {
    base: string;
    destination: RemoteDestinationProfile;
    availablePackages: AvailablePackage[];
    editable: boolean;
}) {
    const fetch = useForm({ destination: destination.id, source_package: '' });
    const actions = useForm({ destination: destination.id });
    const copias = destination.copias;
    const locked =
        destination.destination_written || copias?.write_locked === true;
    const active = destination.fetches.some((item) =>
        ['STARTING', 'RUNNING', 'CANCELLING'].includes(item.state ?? ''),
    );
    const present = new Set(
        (copias?.packages ?? []).map((item) => item.sidecar_sha256),
    );
    const candidates = availablePackages.filter(
        (item) => !present.has(item.sha256),
    );
    const mib = (value: number | null | undefined) =>
        typeof value === 'number'
            ? value < 1048576
                ? `${Math.max(1, Math.round(value / 1024))} KiB`
                : `${(value / 1048576).toFixed(1)} MiB`
            : '—';

    return (
        <div className="space-y-2 border-t pt-2">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-xs font-medium">Paquetes en copias/</span>
                {locked && (
                    <Badge variant="secondary">
                        Fijados: el destino ya recibió escrituras
                    </Badge>
                )}
                {editable && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="ml-auto"
                        disabled={actions.processing}
                        onClick={() =>
                            actions.post(`${base}/destinations/packages/list`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Actualizar lista
                    </Button>
                )}
            </div>
            {!copias || copias.packages.length === 0 ? (
                <p className="text-muted-foreground text-xs">
                    {copias
                        ? 'copias/ está vacío.'
                        : 'Aún no se ha listado copias/.'}
                </p>
            ) : (
                <ul className="grid gap-1 text-xs">
                    {copias.packages.map((item) => (
                        <li
                            key={item.name}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <span className="font-mono">{item.name}</span>
                            <span className="text-muted-foreground">
                                {mib(item.size)}
                            </span>
                            {item.sidecar_sha256 ? (
                                <span className="text-muted-foreground font-mono">
                                    {item.sidecar_sha256.slice(0, 12)}
                                </span>
                            ) : (
                                <span className="text-red-600">
                                    sin sello .sha256
                                </span>
                            )}
                            {item.source_package_uuid === null && (
                                <span className="text-amber-600">
                                    paquete no registrado en la plataforma
                                </span>
                            )}
                            {editable && !locked && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="ml-auto h-6"
                                    disabled={actions.processing}
                                    onClick={() => {
                                        actions.transform(() => ({
                                            destination: destination.id,
                                            name: item.name,
                                        }));
                                        actions.post(
                                            `${base}/destinations/packages/remove`,
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    Retirar
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {editable && !locked && destination.ready && (
                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        fetch.post(`${base}/destinations/packages`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Label
                        htmlFor={`fetch-${destination.id}`}
                        className="text-xs"
                    >
                        Traer desde S3
                    </Label>
                    <select
                        id={`fetch-${destination.id}`}
                        className="border-input bg-background h-8 rounded-md border px-2 text-xs"
                        value={fetch.data.source_package}
                        onChange={(event) =>
                            fetch.setData('source_package', event.target.value)
                        }
                    >
                        <option value="">
                            Seleccione un paquete verificado
                        </option>
                        {candidates.map((item) => (
                            <option key={item.uuid} value={item.uuid}>
                                {item.name} · {item.project ?? '—'} ·{' '}
                                {mib(item.size)}
                            </option>
                        ))}
                    </select>
                    <Button
                        type="submit"
                        size="sm"
                        disabled={
                            fetch.processing ||
                            active ||
                            fetch.data.source_package === ''
                        }
                    >
                        Transferir
                    </Button>
                    {active && (
                        <p className="text-muted-foreground basis-full text-xs">
                            Hay una transferencia en curso hacia este destino;
                            podrá traer otro paquete cuando termine.
                        </p>
                    )}
                    {fetch.errors.source_package && (
                        <p
                            role="alert"
                            className="text-destructive basis-full text-xs"
                        >
                            {fetch.errors.source_package}
                        </p>
                    )}
                </form>
            )}
            {destination.fetches.length > 0 && (
                <ul className="grid gap-1 text-xs">
                    {destination.fetches.map((item) => (
                        <li
                            key={item.source_package_uuid}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <span>{item.package_name}</span>
                            <Badge
                                variant={
                                    item.state === 'SUCCEEDED'
                                        ? 'default'
                                        : [
                                                'STARTING',
                                                'RUNNING',
                                                'CANCELLING',
                                            ].includes(item.state ?? '')
                                          ? 'secondary'
                                          : 'destructive'
                                }
                            >
                                {fetchLabels[item.state ?? ''] ?? item.state}
                            </Badge>
                            {item.already_present && (
                                <span className="text-muted-foreground">
                                    ya estaba en el destino
                                </span>
                            )}
                            {item.error_code && (
                                <span className="text-red-600">
                                    {item.error_code}
                                </span>
                            )}
                            {item.observe_error && (
                                <span className="text-amber-600">
                                    sin contacto ({item.observe_error})
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Field({
    id,
    label,
    value,
    error,
    disabled,
    onChange,
    type = 'text',
    placeholder,
}: {
    id: string;
    label: string;
    value: string;
    error?: string;
    disabled: boolean;
    onChange: (value: string) => void;
    type?: string;
    placeholder?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                type={type}
                value={value}
                placeholder={placeholder}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
            />
            <InputError message={error} />
        </div>
    );
}

function Evidence({
    evidence,
}: {
    evidence: NonNullable<RemoteServer['evidence']>;
}) {
    const probe = evidence.probe;
    const gib = (value: string | number | null | undefined) =>
        typeof value === 'number'
            ? `${(value / 1073741824).toFixed(1)} GiB`
            : '—';

    return (
        <div className="space-y-2">
            <p className="text-muted-foreground text-xs">
                Última prueba{' '}
                {evidence.checked_at
                    ? new Date(evidence.checked_at).toLocaleString()
                    : '—'}{' '}
                · agente {evidence.agent_version ?? '—'}
            </p>
            <ul className="grid gap-2">
                {evidence.checks.map((check) => (
                    <li
                        key={check.key}
                        className="flex items-start gap-2 text-sm"
                    >
                        <CheckIcon result={check.result} />
                        <span>{check.message}</span>
                    </li>
                ))}
            </ul>
            {probe && (
                <dl className="text-muted-foreground grid gap-x-4 gap-y-1 text-xs sm:grid-cols-3">
                    <Fact label="Sistema" value={probe.os_name} />
                    <Fact label="CPU" value={probe.cpus} />
                    <Fact label="Memoria" value={gib(probe.memory_bytes)} />
                    <Fact label="Libre" value={gib(probe.disk_free_bytes)} />
                    <Fact label="PHP" value={probe.php_version} />
                    <Fact label="Docker" value={probe.docker_version} />
                </dl>
            )}
        </div>
    );
}

function Fact({
    label,
    value,
}: {
    label: string;
    value: string | number | null | undefined;
}) {
    return (
        <div className="flex gap-1">
            <dt>{label}:</dt>
            <dd className="text-foreground">{value ?? '—'}</dd>
        </div>
    );
}

function CheckIcon({ result }: { result: CheckResult }) {
    const Icon =
        result === 'SUCCESS'
            ? CheckCircle2
            : result === 'WARNING'
              ? TriangleAlert
              : AlertCircle;

    return (
        <Icon
            aria-label={result}
            className={cn(
                'mt-0.5 size-4 shrink-0',
                result === 'SUCCESS' && 'text-emerald-600',
                result === 'WARNING' && 'text-amber-600',
                result === 'ERROR' && 'text-red-600',
            )}
        />
    );
}

function Operations({
    operations,
}: {
    operations: RemoteServer['operations'];
}) {
    return (
        <div className="space-y-2">
            <h4 className="text-sm font-medium">Operaciones recientes</h4>
            <ul className="grid gap-2">
                {operations.map((operation) => (
                    <li
                        key={operation.uuid}
                        className="border-border flex flex-wrap items-center gap-2 rounded-md border px-3 py-2 text-sm"
                    >
                        <span className="font-mono text-xs">
                            {operation.command_key}
                        </span>
                        <Badge variant="outline">
                            {operation.communication_state}
                        </Badge>
                        <Badge
                            variant={
                                operation.functional_state === 'FAILED'
                                    ? 'destructive'
                                    : 'secondary'
                            }
                        >
                            {operation.functional_state}
                        </Badge>
                        {operation.exit_code !== null && (
                            <span className="text-muted-foreground text-xs">
                                código {operation.exit_code}
                            </span>
                        )}
                        {operation.collector && (
                            <span className="text-muted-foreground text-xs">
                                {operation.collector.stage ?? 'sin etapa'}
                                {operation.collector.total_courses !== null &&
                                    ` · ${operation.collector.completed_courses ?? 0}/${operation.collector.total_courses} cursos`}
                                {operation.collector.percent !== null &&
                                    ` (${operation.collector.percent}%)`}
                            </span>
                        )}
                        {operation.collector?.package_ready && (
                            <Badge>Paquete listo</Badge>
                        )}
                        {operation.manual_intervention_required && (
                            <Badge variant="destructive">
                                Requiere revisión
                            </Badge>
                        )}
                        {operation.last_observed_at && (
                            <span className="text-muted-foreground ml-auto text-xs">
                                Observada{' '}
                                {new Date(
                                    operation.last_observed_at,
                                ).toLocaleString()}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
