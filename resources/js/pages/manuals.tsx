import { Head } from '@inertiajs/react';
import { BookOpenText } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export default function Manuals() {
    return (
        <>
            <Head title="Manuales" />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4 md:p-6">
                <div>
                    <p className="text-sm font-medium text-amber-600 dark:text-amber-400">
                        Iteración 1A
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Manuales
                    </h1>
                </div>

                <Card className="max-w-3xl">
                    <CardHeader>
                        <div className="bg-muted flex size-10 items-center justify-center rounded-lg">
                            <BookOpenText className="text-muted-foreground size-5" />
                        </div>
                        <CardTitle>Documentación de uso</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-muted-foreground text-sm">
                            La documentación operativa y los manuales de la
                            plataforma estarán disponibles aquí.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Manuals.layout = {
    breadcrumbs: [{ title: 'Manuales', href: '/manuals' }],
};
