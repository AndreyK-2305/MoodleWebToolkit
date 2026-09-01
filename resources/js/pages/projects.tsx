import { Head } from '@inertiajs/react';
import { FolderKanban } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export default function Projects() {
    return (
        <>
            <Head title="Proyectos" />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4 md:p-6">
                <div>
                    <p className="text-sm font-medium text-amber-600 dark:text-amber-400">
                        Iteración 1A
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Proyectos
                    </h1>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <div className="bg-muted flex size-10 items-center justify-center rounded-lg">
                            <FolderKanban className="text-muted-foreground size-5" />
                        </div>
                        <CardTitle>
                            Espacio preparado para la siguiente iteración
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-muted-foreground text-sm">
                            Los proyectos de migración aparecerán aquí. Esta
                            página no crea entidades ni flujos pertenecientes a
                            1B.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Projects.layout = {
    breadcrumbs: [{ title: 'Proyectos', href: '/projects' }],
};
