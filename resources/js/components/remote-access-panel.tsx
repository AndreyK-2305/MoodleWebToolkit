import { useForm } from '@inertiajs/react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
export type RemoteServer = {
    uuid: string;
    name: string;
    role: 'SOURCE' | 'DESTINATION' | 'AUXILIARY';
    host: string | null;
    connection: RemoteConnection | null;
    collector_deployed: boolean;
    sources: { id: string; ready: boolean; checked_at: string | null }[];
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
export type RemoteAccess = {
    enabled: boolean;
    agent_version: string | null;
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

export default function RemoteAccessPanel({
    projectUuid,
    access,
    editable,
}: {
    projectUuid: string;
    access: RemoteAccess;
    editable: boolean;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <PlugZap className="size-5" /> Conexiones remotas
                </CardTitle>
                <p className="text-muted-foreground text-sm">
                    Cada servidor se alcanza por AWS Systems Manager
                    (recomendado) o por SSH con la llave del host fijada. La
                    plataforma no mantiene la conexión abierta: los procesos
                    corren en el servidor y se vuelven a consultar.
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                {access.servers.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        Registre las instancias del proyecto para configurar sus
                        servidores.
                    </p>
                ) : (
                    access.servers.map((server) => (
                        <ServerConnection
                            key={server.uuid}
                            projectUuid={projectUuid}
                            server={server}
                            agentVersion={access.agent_version}
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
    editable,
}: {
    projectUuid: string;
    server: RemoteServer;
    agentVersion: string | null;
    editable: boolean;
}) {
    const connection = server.connection;
    const base = `/projects/${projectUuid}/servers/${server.uuid}/connection`;
    const form = useForm({
        type: (connection?.type ?? 'aws-ssm') as ConnectionType,
        instance_id: connection?.instance_id ?? '',
        region: connection?.region ?? 'us-east-1',
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
                <Badge
                    variant={
                        connection?.status === 'INVALID'
                            ? 'destructive'
                            : connection?.status === 'VALID'
                              ? 'default'
                              : 'secondary'
                    }
                >
                    {connection
                        ? statusLabels[connection.status]
                        : 'Sin configurar'}
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

            {server.evidence && <Evidence evidence={server.evidence} />}
            {server.operations.length > 0 && (
                <Operations operations={server.operations} />
            )}
        </section>
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
