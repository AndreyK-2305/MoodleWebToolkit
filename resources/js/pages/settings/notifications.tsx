import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { edit, update } from '@/routes/notifications';

type Preferences = {
    receive_email: boolean;
    interventions: boolean;
    outcomes: boolean;
    backups: boolean;
    progress: boolean;
};
type Group = Exclude<keyof Preferences, 'receive_email'>;

const groups: { key: Group; title: string; description: string }[] = [
    {
        key: 'interventions',
        title: 'Intervenciones',
        description:
            'Cuando una consolidación se detiene y espera una decisión en la plataforma.',
    },
    {
        key: 'outcomes',
        title: 'Finalizaciones y fallos',
        description:
            'Cada origen recolectado, la consolidación lista para revisión, completada o fallida.',
    },
    {
        key: 'backups',
        title: 'Respaldos',
        description:
            'Cuando el respaldo de la copia integral en S3 queda verificado o falla.',
    },
    {
        key: 'progress',
        title: 'Resumen de avance',
        description:
            'Un resumen periódico mientras la ejecución está en curso, si el administrador lo activó.',
    },
];

export default function NotificationPreferences({
    preferences,
}: {
    preferences: Preferences;
}) {
    const form = useForm<Preferences>(preferences);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(update.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Notificaciones" />

            <h1 className="sr-only">Notificaciones</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Notificaciones"
                    description="Elija qué avisos de los proyectos en los que participa recibe por correo"
                />

                <form onSubmit={submit} className="space-y-6">
                    <label className="flex items-start gap-3">
                        <Checkbox
                            id="receive_email"
                            checked={form.data.receive_email}
                            onCheckedChange={(checked) =>
                                form.setData('receive_email', checked === true)
                            }
                        />
                        <span className="grid gap-1">
                            <span className="text-sm font-medium">
                                Recibir correos de la plataforma
                            </span>
                            <span className="text-muted-foreground text-sm">
                                Los correos incluyen el proyecto, el evento y un
                                enlace a la ejecución; nunca datos personales ni
                                credenciales.
                            </span>
                        </span>
                    </label>

                    <fieldset
                        className="space-y-4 border-l pl-4"
                        disabled={!form.data.receive_email}
                    >
                        <legend className="sr-only">Grupos de avisos</legend>
                        {groups.map((group) => (
                            <label
                                key={group.key}
                                className="flex items-start gap-3 aria-disabled:opacity-50"
                                aria-disabled={!form.data.receive_email}
                            >
                                <Checkbox
                                    id={group.key}
                                    checked={form.data[group.key]}
                                    disabled={!form.data.receive_email}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            group.key,
                                            checked === true,
                                        )
                                    }
                                />
                                <span className="grid gap-1">
                                    <span className="text-sm font-medium">
                                        {group.title}
                                    </span>
                                    <span className="text-muted-foreground text-sm">
                                        {group.description}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </fieldset>

                    <Button disabled={form.processing}>Guardar</Button>
                </form>
            </div>
        </>
    );
}

NotificationPreferences.layout = {
    breadcrumbs: [{ title: 'Notificaciones', href: edit() }],
};
