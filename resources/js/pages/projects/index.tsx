import { Head, Link } from '@inertiajs/react';
import { ArrowRight, FolderKanban, Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate } from '@/lib/consolidation-sources';

type ProjectSummary = {
    uuid: string;
    name: string;
    type: 'COLLECT' | 'CONSOLIDATE' | 'INTEGRATE';
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
};

function NewProjectButton() {
    return (
        <Button asChild>
            <Link href="/projects/create">
                <Plus /> Nuevo proyecto
            </Link>
        </Button>
    );
}

/** The history of the visible projects; a new one starts at step 1 of the wizard. */
export default function ProjectsIndex({ projects, canCreate }: Props) {
    return (
        <>
            <Head title="Proyectos" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Proyectos
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Historial de recolecciones y consolidaciones.
                            Continúe una configuración o consulte su estado.
                        </p>
                    </div>
                    {canCreate && projects.data.length > 0 && (
                        <NewProjectButton />
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <FolderKanban className="size-5" /> Historial
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {projects.data.length === 0 ? (
                            <div className="border-border flex flex-col items-center gap-3 rounded-lg border border-dashed p-8 text-center">
                                <div>
                                    <p className="font-medium">
                                        Todavía no hay proyectos
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        {canCreate
                                            ? 'Un proyecto recolecta los Moodle de origen o los consolida en un Moodle destino. El asistente lo guía paso a paso.'
                                            : 'Un administrador debe asignarle un proyecto para poder consultarlo.'}
                                    </p>
                                </div>
                                {canCreate && <NewProjectButton />}
                            </div>
                        ) : (
                            <ul className="grid gap-3">
                                {projects.data.map((project) => (
                                    <li key={project.uuid}>
                                        <Link
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
                                                    Última actividad:{' '}
                                                    {formatDate(
                                                        project.updated_at,
                                                    )}{' '}
                                                    · Paso{' '}
                                                    {project.current_step} de 5
                                                </p>
                                            </div>
                                            <span className="flex items-center gap-2 text-sm font-medium">
                                                {project.can_edit
                                                    ? 'Continuar'
                                                    : 'Consultar'}
                                                <ArrowRight className="size-4" />
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
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
