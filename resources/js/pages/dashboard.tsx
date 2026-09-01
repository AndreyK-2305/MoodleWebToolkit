import { Head } from '@inertiajs/react';
import { Activity, Container, Radio, Users } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';

type Props = {
    userCounts: { total: number; active: number; admins: number } | null;
};

export default function Dashboard({ userCounts }: Props) {
    const cards = [
        { label: 'Aplicación', value: 'Operativa', icon: Activity },
        { label: 'Contenedores', value: '9 servicios', icon: Container },
        { label: 'Tiempo real', value: 'Reverb listo', icon: Radio },
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
                        Iteración 1A
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Base de la plataforma lista
                    </h1>
                    <p className="text-muted-foreground mt-1 max-w-2xl text-sm">
                        Autenticación cerrada, roles, infraestructura y tema
                        visual preparados para construir el dominio en la
                        siguiente iteración.
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
                    <h2 className="font-semibold">Alcance actual</h2>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Esta entrega no contiene proyectos, servidores,
                        instancias Moodle ni ejecuciones. Esos conceptos
                        comienzan en 1B.
                    </p>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = { breadcrumbs: [{ title: 'Inicio', href: dashboard() }] };
