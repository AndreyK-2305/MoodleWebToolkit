import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type CollectorOptions = {
    profile_id?: string;
    workers?: number;
    package_name?: string;
    capacity_bytes?: number;
    safety_margin_percent?: number;
};
export type CollectorLab = {
    enabled: boolean;
    profiles: { id: string; name: string }[];
};

export default function CollectorConfigurationForm({
    projectUuid,
    options,
    laboratory,
    editable,
}: {
    projectUuid: string;
    options: CollectorOptions;
    laboratory: CollectorLab;
    editable: boolean;
}) {
    const form = useForm({
        profile_id: options.profile_id ?? laboratory.profiles[0]?.id ?? '',
        workers: options.workers ?? 1,
        package_name: options.package_name ?? 'recoleccion-lab',
        capacity_bytes: options.capacity_bytes ?? 536870912,
        safety_margin_percent: options.safety_margin_percent ?? 20,
    });
    const errors = form.errors as Record<string, string>;
    return (
        <Card>
            <CardHeader>
                <CardTitle>Recolector 7.4.2 · Laboratorio</CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-muted-foreground mb-4 text-sm">
                    Seleccione un origen sintético autorizado. La configuración
                    quedará versionada antes de ejecutar el preflight.
                </p>
                <form
                    className="grid gap-4 md:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(
                            `/projects/${projectUuid}/collector/configuration`,
                        );
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="collector-profile">
                            Origen de laboratorio
                        </Label>
                        <select
                            id="collector-profile"
                            className="border-input rounded-md border p-2"
                            value={form.data.profile_id}
                            disabled={!editable || !laboratory.enabled}
                            onChange={(event) =>
                                form.setData('profile_id', event.target.value)
                            }
                        >
                            {laboratory.profiles.map((profile) => (
                                <option value={profile.id} key={profile.id}>
                                    {profile.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="collector-name">
                            Nombre del paquete
                        </Label>
                        <Input
                            id="collector-name"
                            value={form.data.package_name}
                            disabled={!editable}
                            onChange={(event) =>
                                form.setData('package_name', event.target.value)
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="collector-workers">Workers</Label>
                        <Input
                            id="collector-workers"
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
                    <div className="grid gap-2">
                        <Label htmlFor="collector-capacity">
                            Capacidad estimada (MiB)
                        </Label>
                        <Input
                            id="collector-capacity"
                            type="number"
                            min={16}
                            max={20480}
                            value={form.data.capacity_bytes / 1048576}
                            disabled={!editable}
                            onChange={(event) =>
                                form.setData(
                                    'capacity_bytes',
                                    Number(event.target.value) * 1048576,
                                )
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="collector-margin">
                            Margen de capacidad (%)
                        </Label>
                        <Input
                            id="collector-margin"
                            type="number"
                            min={10}
                            max={100}
                            value={form.data.safety_margin_percent}
                            disabled={!editable}
                            onChange={(event) =>
                                form.setData(
                                    'safety_margin_percent',
                                    Number(event.target.value),
                                )
                            }
                        />
                    </div>
                    <div className="md:col-span-2">
                        {Object.entries(errors).map(([key, error]) => (
                            <InputError key={key} message={error} />
                        ))}
                    </div>
                    {editable && (
                        <Button
                            disabled={
                                form.processing ||
                                !laboratory.enabled ||
                                laboratory.profiles.length === 0
                            }
                        >
                            Guardar configuración LAB
                        </Button>
                    )}
                </form>
            </CardContent>
        </Card>
    );
}
