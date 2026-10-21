import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import type { RemoteServer } from '@/components/remote-access-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type RemoteCollectionOptions = {
    mode?: string | null;
    server_uuid?: string | null;
    source_profile?: string | null;
    workers?: number | null;
    package_name?: string | null;
};

/**
 * Chooses the source server and profile that the Collector exports from.
 * Only identifiers are sent; paths and credentials stay on the server.
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
    const current = options.mode === 'REMOTE' ? options : {};
    const initialServer =
        sources.find((server) => server.uuid === current.server_uuid) ??
        sources[0];
    const form = useForm({
        server_uuid: initialServer?.uuid ?? '',
        source_profile:
            current.source_profile ??
            initialServer?.sources.find((source) => source.ready)?.id ??
            '',
        workers: current.workers ?? 1,
        package_name: current.package_name ?? 'origen',
    });
    const server = sources.find((item) => item.uuid === form.data.server_uuid);
    const profile = server?.sources.find(
        (source) => source.id === form.data.source_profile,
    );
    const errors = form.errors as Record<string, string>;
    const [busy, setBusy] = useState<'deploy' | 'check' | null>(null);
    const connected = server?.connection?.status === 'VALID';
    const serverAction = (
        action: 'deploy' | 'check',
        path: string,
        data: Record<string, string> = {},
    ) =>
        router.post(
            `/projects/${projectUuid}/servers/${server?.uuid}/${path}`,
            data,
            {
                preserveScroll: true,
                onStart: () => setBusy(action),
                onFinish: () => setBusy(null),
            },
        );
    const readiness = server
        ? [
              {
                  label: 'Conexión',
                  ok: server.connection?.status === 'VALID',
              },
              { label: 'Recolector desplegado', ok: server.collector_deployed },
              { label: 'Perfil comprobado', ok: profile?.ready === true },
          ]
        : [];

    return (
        <Card>
            <CardHeader>
                <CardTitle>Recolección desde el servidor de origen</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="text-muted-foreground text-sm">
                    El Recolector 7.4.2 se ejecuta en el servidor mediante su
                    agente. Al guardar, la configuración queda versionada;
                    después ejecute el preflight, confirme e inicie la
                    ejecución.
                    {options.mode === 'REMOTE' &&
                        ' Esta es la configuración vigente del proyecto.'}
                </p>
                {sources.length === 0 ? (
                    <p className="text-sm">
                        Registre un servidor de origen en el paso de instancias
                        y configure su conexión para habilitar la recolección.
                    </p>
                ) : (
                    <form
                        className="grid gap-4 md:grid-cols-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.put(
                                `/projects/${projectUuid}/collector/remote-configuration`,
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="remote-server">
                                Servidor de origen
                            </Label>
                            <select
                                id="remote-server"
                                className="border-input rounded-md border p-2"
                                value={form.data.server_uuid}
                                disabled={!editable}
                                onChange={(event) => {
                                    const next = sources.find(
                                        (item) =>
                                            item.uuid === event.target.value,
                                    );
                                    form.setData({
                                        ...form.data,
                                        server_uuid: event.target.value,
                                        source_profile:
                                            next?.sources.find(
                                                (source) => source.ready,
                                            )?.id ?? '',
                                    });
                                }}
                            >
                                {sources.map((item) => (
                                    <option value={item.uuid} key={item.uuid}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="remote-profile">
                                Perfil de origen
                            </Label>
                            <Input
                                id="remote-profile"
                                list="remote-profiles"
                                value={form.data.source_profile}
                                disabled={!editable}
                                onChange={(event) =>
                                    form.setData(
                                        'source_profile',
                                        event.target.value,
                                    )
                                }
                            />
                            <datalist id="remote-profiles">
                                {server?.sources.map((source) => (
                                    <option value={source.id} key={source.id} />
                                ))}
                            </datalist>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="remote-package">
                                Nombre del paquete
                            </Label>
                            <Input
                                id="remote-package"
                                value={form.data.package_name}
                                disabled={!editable}
                                onChange={(event) =>
                                    form.setData(
                                        'package_name',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="remote-workers">Workers</Label>
                            <Input
                                id="remote-workers"
                                type="number"
                                min={1}
                                max={4}
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
                        <div className="flex flex-wrap gap-2 md:col-span-2">
                            {readiness.map((item) => (
                                <Badge
                                    key={item.label}
                                    variant={item.ok ? 'default' : 'outline'}
                                >
                                    {item.label}:{' '}
                                    {item.ok ? 'listo' : 'pendiente'}
                                </Badge>
                            ))}
                        </div>
                        {editable && server && (
                            <div className="flex flex-wrap gap-2 md:col-span-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={!connected || busy !== null}
                                    onClick={() =>
                                        serverAction(
                                            'deploy',
                                            'collector/deploy',
                                        )
                                    }
                                >
                                    {busy === 'deploy'
                                        ? 'Desplegando…'
                                        : server.collector_deployed
                                          ? 'Verificar despliegue del Recolector'
                                          : 'Desplegar Recolector 7.4.2'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        !connected ||
                                        busy !== null ||
                                        form.data.source_profile === ''
                                    }
                                    onClick={() =>
                                        serverAction('check', 'sources/check', {
                                            source: form.data.source_profile,
                                        })
                                    }
                                >
                                    {busy === 'check'
                                        ? 'Comprobando…'
                                        : 'Comprobar perfil de origen'}
                                </Button>
                                {!connected && (
                                    <p className="text-muted-foreground text-sm">
                                        Pruebe primero la conexión del servidor.
                                    </p>
                                )}
                            </div>
                        )}
                        <div className="md:col-span-2">
                            {Object.entries(errors).map(([key, error]) => (
                                <InputError key={key} message={error} />
                            ))}
                        </div>
                        {editable && (
                            <Button
                                className="md:col-span-2 md:justify-self-start"
                                disabled={
                                    form.processing ||
                                    form.data.server_uuid === '' ||
                                    form.data.source_profile === ''
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
