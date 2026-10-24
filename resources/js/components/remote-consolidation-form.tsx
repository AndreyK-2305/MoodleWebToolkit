import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import type {
    AvailablePackage,
    RemoteServer,
} from '@/components/remote-access-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

export type RemoteConsolidationOptions = {
    mode?: string | null;
    server_uuid?: string | null;
    destination_profile?: string | null;
    source_packages?: { uuid: string; sha256: string; name: string }[] | null;
    workers?: string | number | null;
};

const MIN_PACKAGES = 2;
const MAX_PACKAGES = 32;

/**
 * Chooses the destination profile and the sealed packages the Consolidator V8
 * consolidates there. Only identifiers are sent; the site's configuration and
 * secrets stay on the destination server.
 */
export default function RemoteConsolidationForm({
    projectUuid,
    options,
    servers,
    packages,
    editable,
}: {
    projectUuid: string;
    options: RemoteConsolidationOptions;
    servers: RemoteServer[];
    packages: AvailablePackage[];
    editable: boolean;
}) {
    const destinations = servers.filter(
        (server) => server.role === 'DESTINATION',
    );
    const current = options.mode === 'REMOTE_CONSOLIDATION';
    const form = useForm({
        server_uuid: current
            ? (options.server_uuid ?? '')
            : (destinations[0]?.uuid ?? ''),
        destination_profile: current
            ? (options.destination_profile ?? '')
            : (destinations[0]?.destinations.find((item) => item.ready)?.id ??
              ''),
        source_packages: current
            ? (options.source_packages ?? []).map((item) => item.uuid)
            : [],
        workers: current ? String(options.workers ?? 'auto') : 'auto',
    });
    const errors = form.errors as Record<string, string>;
    const server = destinations.find(
        (item) => item.uuid === form.data.server_uuid,
    );
    const profile = server?.destinations.find(
        (item) => item.id === form.data.destination_profile,
    );
    const inCopias = new Set(
        (profile?.copias?.packages ?? []).map((item) => item.sidecar_sha256),
    );
    const toggle = (uuid: string, checked: boolean) =>
        form.setData(
            'source_packages',
            checked
                ? [...form.data.source_packages, uuid]
                : form.data.source_packages.filter((item) => item !== uuid),
        );
    const count = form.data.source_packages.length;
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

    return (
        <Card>
            <CardHeader>
                <CardTitle>Consolidación en el servidor de destino</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="text-muted-foreground text-sm">
                    El Consolidador V8 se ejecuta en el destino mediante su
                    agente, sobre los paquetes que ya están en su{' '}
                    <code>copias/</code>. Al guardar, la configuración queda
                    versionada; después ejecute el preflight, confirme e inicie
                    la ejecución. La ejecución se detendrá para que el
                    administrador configure OAuth2 en Moodle.
                    {current &&
                        ' Esta es la configuración vigente del proyecto.'}
                </p>
                {destinations.length === 0 ? (
                    <p className="text-sm">
                        Registre el servidor de destino en el paso de instancias
                        y prepare su perfil para habilitar la consolidación.
                    </p>
                ) : (
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.put(
                                `/projects/${projectUuid}/consolidation/remote-configuration`,
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <div className="grid gap-4 md:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="consolidation-server">
                                    Servidor de destino
                                </Label>
                                <select
                                    id="consolidation-server"
                                    className="border-input rounded-md border p-2"
                                    value={form.data.server_uuid}
                                    disabled={!editable}
                                    onChange={(event) =>
                                        form.setData(
                                            'server_uuid',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {destinations.map((item) => (
                                        <option
                                            key={item.uuid}
                                            value={item.uuid}
                                        >
                                            {item.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="consolidation-profile">
                                    Perfil de destino
                                </Label>
                                <select
                                    id="consolidation-profile"
                                    className="border-input rounded-md border p-2"
                                    value={form.data.destination_profile}
                                    disabled={!editable}
                                    onChange={(event) =>
                                        form.setData(
                                            'destination_profile',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">
                                        Seleccione un perfil
                                    </option>
                                    {(server?.destinations ?? []).map(
                                        (item) => (
                                            <option
                                                key={item.id}
                                                value={item.id}
                                            >
                                                {item.id}
                                                {item.ready
                                                    ? ''
                                                    : ' (sin comprobar)'}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="consolidation-workers">
                                    Workers de restauración
                                </Label>
                                <select
                                    id="consolidation-workers"
                                    className="border-input rounded-md border p-2"
                                    value={form.data.workers}
                                    disabled={!editable}
                                    onChange={(event) =>
                                        form.setData(
                                            'workers',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="auto">
                                        Automático (CPU, máx. 4)
                                    </option>
                                    {['1', '2', '3', '4'].map((value) => (
                                        <option key={value} value={value}>
                                            {value}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium">
                                Paquetes sellados ({count} de {MIN_PACKAGES} a{' '}
                                {MAX_PACKAGES})
                            </legend>
                            {packages.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    No hay paquetes verificados en S3 que pueda
                                    usar. Recolecte primero desde los orígenes.
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
                                                checked={form.data.source_packages.includes(
                                                    item.uuid,
                                                )}
                                                disabled={!editable}
                                                onCheckedChange={(checked) =>
                                                    toggle(
                                                        item.uuid,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor={`package-${item.uuid}`}
                                            >
                                                {item.name}
                                            </Label>
                                            <span className="text-muted-foreground text-xs">
                                                {item.project ?? '—'} ·{' '}
                                                {item.sha256.slice(0, 12)}
                                            </span>
                                            {profile &&
                                                (inCopias.has(item.sha256) ? (
                                                    <Badge variant="outline">
                                                        en copias/
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="secondary">
                                                        falta en copias/
                                                    </Badge>
                                                ))}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </fieldset>
                        <InputError message={errors.consolidation} />
                        {editable && (
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    count < MIN_PACKAGES ||
                                    count > MAX_PACKAGES ||
                                    form.data.destination_profile === ''
                                }
                            >
                                Guardar configuración de consolidación
                            </Button>
                        )}
                    </form>
                )}
            </CardContent>
        </Card>
    );
}
