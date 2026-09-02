import { Head } from '@inertiajs/react';
import {
    Activity,
    CheckCircle2,
    FolderKanban,
    Settings2,
    Users,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';

type Props = {
    userCounts: { total: number; active: number; admins: number } | null;
    projectCounts: { total: number; configuring: number; ready: number };
};

export default function Dashboard({ userCounts, projectCounts }: Props) {
    const cards = [
        { label: 'Aplicación', value: 'Operativa', icon: Activity },
        {
            label: 'Proyectos visibles',
            value: String(projectCounts.total),
            icon: FolderKanban,
        },
        {
            label: 'En configuración',
            value: String(projectCounts.configuring),
            icon: Settings2,
        },
        {
            label: 'Listos',
            value: String(projectCounts.ready),
            icon: CheckCircle2,
        },
        ...(userCounts
            ? [
                  {
                      label: 'Usuarios activos',
                      value: `${userCounts.active} / ${userCounts.total}`,
                      icon: Users,
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Inicio" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4 md:p-6">
                <div>
                    <p className="text-sm font-medium text-amber-600 dark:text-amber-400">
                        Iteración 1C
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Gestión de migraciones Moodle
                    </h1>
                    <p className="text-muted-foreground mt-1 max-w-2xl text-sm">
                        Configure proyectos persistentes mediante el wizard y
                        valide sus datos con un preflight completamente
                        simulado.
                    </p>
                </div>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {cards.map(({ label, value, icon: Icon }) => (
                        <Card key={label}>
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardTitle className="text-sm font-medium">
                                    {label}
                                </CardTitle>
                                <Icon className="text-muted-foreground size-4" />
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-semibold">
                                    {value}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                <div className="border-border bg-card rounded-xl border p-6">
                    <h2 className="font-semibold">Alcance de esta entrega</h2>
                    <p className="text-muted-foreground mt-2 text-sm">
                        La confirmación deja los proyectos en READY. No crea
                        ejecuciones, no despacha jobs y no se conecta a
                        servidores; esas capacidades corresponden a 1D y cortes
                        posteriores.
                    </p>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = { breadcrumbs: [{ title: 'Inicio', href: dashboard() }] };
