import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, FolderKanban, Plus } from 'lucide-react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ProjectType = 'COLLECT' | 'CONSOLIDATE' | 'INTEGRATE';
type ProjectSummary = {
    uuid: string;
    name: string;
    type: ProjectType;
    type_label: string;
    status: string;
    status_label: string;
    current_step: number;
    can_edit: boolean;
    updated_at: string | null;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Props = {
    projects: { data: ProjectSummary[]; links: PageLink[] };
    canCreate: boolean;
    projectTypes: { value: ProjectType; label: string }[];
};

export default function ProjectsIndex({
    projects,
    canCreate,
    projectTypes,
}: Props) {
    const form = useForm({
        name: '',
        type: 'COLLECT' as ProjectType,
        description: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/projects');
    };

    return (
        <>
            <Head title="Proyectos" />
            <div className="space-y-6 p-4 md:p-6">
                <div>
                    <p className="text-sm font-medium text-amber-600 dark:text-amber-400">
                        Iteración 1D
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Proyectos
                    </h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Cree, consulte y continúe configuraciones persistentes
                        de migración.
                    </p>
                </div>

                {canCreate && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Plus className="size-5" /> Nuevo proyecto
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={submit}
                                className="grid gap-4 lg:grid-cols-3"
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="project-name">Nombre</Label>
                                    <Input
                                        id="project-name"
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Consolidación facultades 2026"
                                        required
                                    />
                                    <InputError message={form.errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="project-type">
                                        Operación
                                    </Label>
                                    <select
                                        id="project-type"
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                        value={form.data.type}
                                        onChange={(event) =>
                                            form.setData(
                                                'type',
                                                event.target
                                                    .value as ProjectType,
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
                                    <InputError message={form.errors.type} />
                                </div>
                                <div className="grid gap-2 lg:row-span-2">
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
                                    <InputError
                                        message={form.errors.description}
                                    />
                                </div>
                                <div className="lg:col-span-2">
                                    <Button disabled={form.processing}>
                                        Crear y configurar
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <FolderKanban className="size-5" /> Proyectos
                            visibles
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {projects.data.length === 0 ? (
                            <div className="border-border rounded-lg border border-dashed p-8 text-center">
                                <p className="font-medium">
                                    No hay proyectos visibles
                                </p>
                                <p className="text-muted-foreground mt-1 text-sm">
                                    {canCreate
                                        ? 'Cree el primero con el formulario superior.'
                                        : 'Un administrador debe asignarle un proyecto para poder consultarlo.'}
                                </p>
                            </div>
                        ) : (
                            <div className="grid gap-3">
                                {projects.data.map((project) => (
                                    <Link
                                        key={project.uuid}
                                        href={`/projects/${project.uuid}`}
                                        className="border-border hover:bg-muted/50 flex flex-col gap-3 rounded-lg border p-4 transition-colors sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="font-medium">
                                                    {project.name}
                                                </p>
                                                <Badge variant="outline">
                                                    {project.type_label}
                                                </Badge>
                                                <Badge
                                                    variant={
                                                        project.status ===
                                                        'READY'
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {project.status_label}
                                                </Badge>
                                            </div>
                                            <p className="text-muted-foreground mt-1 text-sm">
                                                Paso {project.current_step} de 5
                                                ·{' '}
                                                {project.can_edit
                                                    ? 'Puede continuar la configuración'
                                                    : 'Consulta solamente'}
                                            </p>
                                        </div>
                                        <span className="flex items-center gap-2 text-sm font-medium">
                                            {project.can_edit
                                                ? 'Continuar'
                                                : 'Consultar'}
                                            <ArrowRight className="size-4" />
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        )}

                        <div className="mt-4 flex flex-wrap gap-2">
                            {projects.links.map((link) =>
                                link.url ? (
                                    <Button
                                        key={link.label}
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        size="sm"
                                        asChild
                                    >
                                        <Link
                                            href={link.url}
                                            dangerouslySetInnerHTML={{
                                                __html: link.label,
                                            }}
                                        />
                                    </Button>
                                ) : null,
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ProjectsIndex.layout = {
    breadcrumbs: [{ title: 'Proyectos', href: '/projects' }],
};
