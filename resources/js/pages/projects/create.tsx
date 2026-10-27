import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, CircleHelp } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

type ProjectType = 'COLLECT' | 'CONSOLIDATE';
type Props = {
    projectTypes: { value: ProjectType; label: string }[];
    /** With real servers, a consolidation names its destination in this step. */
    destinationLabel: boolean;
};

const destinationLabelHelp =
    'Es solo una etiqueta para identificar este servidor dentro del proceso. Si se deja vacío se usa el nombre del proyecto. No tiene relación con el nombre que tendrá el sitio consolidado.';

/**
 * Step 1 of the wizard before the project exists: saving it creates the
 * project and the wizard continues on the project page at step 2.
 */
export default function ProjectsCreate({
    projectTypes,
    destinationLabel,
}: Props) {
    const form = useForm({
        name: '',
        type: projectTypes[0]?.value ?? 'COLLECT',
        description: '',
        destination_label: '',
    });
    const labelled = destinationLabel && form.data.type === 'CONSOLIDATE';

    return (
        <>
            <Head title="Nuevo proyecto" />
            <div className="space-y-6 p-4 md:p-6">
                <div>
                    <Link
                        href="/projects"
                        className="text-muted-foreground hover:text-foreground mb-2 inline-flex items-center gap-1 text-sm"
                    >
                        <ArrowLeft className="size-4" /> Proyectos
                    </Link>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Nuevo proyecto
                    </h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Al guardar los datos básicos se crea el proyecto y el
                        asistente continúa con los orígenes.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            1. Tipo de operación y datos básicos
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="grid gap-4 md:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.transform(
                                    ({ destination_label, ...data }) =>
                                        labelled
                                            ? { ...data, destination_label }
                                            : data,
                                );
                                form.post('/projects');
                            }}
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="project-name">Nombre</Label>
                                <Input
                                    id="project-name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    placeholder="Consolidación facultades 2026"
                                    required
                                    autoFocus
                                />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="project-type">Operación</Label>
                                <select
                                    id="project-type"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={form.data.type}
                                    onChange={(event) =>
                                        form.setData(
                                            'type',
                                            event.target.value as ProjectType,
                                        )
                                    }
                                >
                                    {projectTypes.map((type) => (
                                        <option
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-muted-foreground text-xs">
                                    {form.data.type === 'COLLECT'
                                        ? 'Recolectar: saca un paquete sellado de cada Moodle de origen.'
                                        : 'Consolidar: une en un Moodle destino los paquetes de varios orígenes; puede recolectarlos desde aquí.'}
                                </p>
                                <InputError message={form.errors.type} />
                            </div>
                            {labelled && (
                                <div className="grid gap-2 md:col-span-2">
                                    <div className="flex items-center gap-1.5">
                                        <Label htmlFor="project-destination-label">
                                            Nombre de referencia del servidor de
                                            destino (opcional)
                                        </Label>
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <button
                                                    type="button"
                                                    aria-label="Qué es el nombre de referencia del servidor de destino"
                                                    className="text-muted-foreground hover:text-foreground focus-visible:ring-ring inline-flex rounded-full focus-visible:ring-2 focus-visible:outline-none"
                                                >
                                                    <CircleHelp className="size-4" />
                                                </button>
                                            </TooltipTrigger>
                                            <TooltipContent className="max-w-xs">
                                                {destinationLabelHelp}
                                            </TooltipContent>
                                        </Tooltip>
                                    </div>
                                    <Input
                                        id="project-destination-label"
                                        placeholder={form.data.name}
                                        value={form.data.destination_label}
                                        onChange={(event) =>
                                            form.setData(
                                                'destination_label',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.destination_label}
                                    />
                                </div>
                            )}
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="project-description">
                                    Descripción opcional
                                </Label>
                                <textarea
                                    id="project-description"
                                    className="border-input bg-background min-h-24 rounded-md border px-3 py-2 text-sm"
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.description} />
                            </div>
                            <div className="flex justify-end md:col-span-2">
                                <Button disabled={form.processing}>
                                    Guardar y continuar <ArrowRight />
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ProjectsCreate.layout = {
    breadcrumbs: [
        { title: 'Proyectos', href: '/projects' },
        { title: 'Nuevo proyecto', href: '/projects/create' },
    ],
};
