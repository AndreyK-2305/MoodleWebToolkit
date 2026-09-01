import { Head } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export default function About() {
    return (
        <>
            <Head title="Acerca de" />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4 md:p-6">
                <div>
                    <p className="text-sm font-medium text-amber-600 dark:text-amber-400">
                        Iteración 1A
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Acerca de
                    </h1>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <div className="bg-muted flex size-10 items-center justify-center rounded-lg">
                            <Info className="text-muted-foreground size-5" />
                        </div>
                        <CardTitle>Moodle Consolidation Toolkit</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <p className="text-muted-foreground text-sm">
                            Plataforma web para administrar el kit de
                            recolección, consolidación e integración de
                            instancias Moodle.
                        </p>
                        <p className="text-muted-foreground text-sm">
                            Este desarrollo forma parte de un trabajo dirigido
                            de Ingeniería de Sistemas.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

About.layout = {
    breadcrumbs: [{ title: 'Acerca de', href: '/about' }],
};
