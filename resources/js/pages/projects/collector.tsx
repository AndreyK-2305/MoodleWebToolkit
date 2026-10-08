import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CollectorConfigurationForm from '@/components/collector-configuration-form';
import type {
    CollectorLab,
    CollectorOptions,
} from '@/components/collector-configuration-form';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type Check = {
    id: string;
    description: string;
    result: 'SUCCESS' | 'WARNING' | 'ERROR';
    detail: string;
};
type Props = {
    collectorLab: CollectorLab;
    project: {
        uuid: string;
        name: string;
        status: string;
        configuration_version: number;
        options: CollectorOptions;
        can_edit: boolean;
        can_start: boolean;
        preflight: { checks: Check[]; configuration_hash: string } | null;
        latest_execution: { uuid: string; status: string } | null;
    };
};

export default function CollectorProject({ project, collectorLab }: Props) {
    const preflight = useForm({});
    const [accepted, setAccepted] = useState<string[]>([]);
    const confirm = useForm({
        configuration_version: project.configuration_version,
        accepted_warning_ids: [] as string[],
    });
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const start = useForm({
        configuration_version: project.configuration_version,
    });
    const checks = project.preflight?.checks ?? [];
    const warnings = checks.filter((check) => check.result === 'WARNING');
    const canConfirm =
        checks.length > 0 &&
        !checks.some((check) => check.result === 'ERROR') &&
        warnings.every((check) => accepted.includes(check.id));
    return (
        <>
            <Head title={project.name} />
            <div className="space-y-6 p-6">
                <Link href="/projects">Volver a proyectos</Link>
                <div className="flex items-center gap-3">
                    <h1 className="text-2xl font-semibold">{project.name}</h1>
                    <Badge>LABORATORY</Badge>
                    <Badge variant="outline">{project.status}</Badge>
                </div>
                <CollectorConfigurationForm
                    projectUuid={project.uuid}
                    options={project.options}
                    laboratory={collectorLab}
                    editable={project.can_edit}
                />
                <Card>
                    <CardHeader>
                        <CardTitle>
                            Preflight real · Revisión{' '}
                            {project.configuration_version}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {checks.map((check) => (
                            <div
                                key={check.id}
                                className="rounded-md border p-3"
                            >
                                <Badge
                                    variant={
                                        check.result === 'ERROR'
                                            ? 'destructive'
                                            : 'outline'
                                    }
                                >
                                    {check.result}
                                </Badge>
                                <p className="font-medium">
                                    {check.description}
                                </p>
                                <p className="text-muted-foreground text-sm">
                                    {check.detail}
                                </p>
                            </div>
                        ))}
                        {project.can_edit && (
                            <Button
                                disabled={preflight.processing}
                                onClick={() =>
                                    preflight.post(
                                        `/projects/${project.uuid}/wizard/preflight`,
                                    )
                                }
                            >
                                Ejecutar preflight real
                            </Button>
                        )}
                        {warnings.map((check) => (
                            <label key={check.id} className="flex gap-2">
                                <input
                                    type="checkbox"
                                    checked={accepted.includes(check.id)}
                                    onChange={(event) =>
                                        setAccepted(
                                            event.target.checked
                                                ? [...accepted, check.id]
                                                : accepted.filter(
                                                      (id) => id !== check.id,
                                                  ),
                                        )
                                    }
                                />
                                Acepto: {check.description}
                            </label>
                        ))}
                        {project.can_edit && (
                            <Button
                                disabled={!canConfirm || confirm.processing}
                                onClick={() => {
                                    confirm.transform((data) => ({
                                        ...data,
                                        configuration_version:
                                            project.configuration_version,
                                        accepted_warning_ids: accepted,
                                    }));
                                    confirm.post(
                                        `/projects/${project.uuid}/wizard/confirm`,
                                    );
                                }}
                            >
                                Confirmar configuración
                            </Button>
                        )}
                        {Object.entries(confirm.errors).map(([key, error]) => (
                            <InputError key={key} message={error} />
                        ))}
                    </CardContent>
                </Card>
                {project.can_start && (
                    <Button
                        disabled={start.processing}
                        onClick={() => {
                            start.transform((data) => ({
                                ...data,
                                configuration_version:
                                    project.configuration_version,
                            }));
                            start.post(`/projects/${project.uuid}/executions`, {
                                headers: {
                                    'Idempotency-Key': idempotencyKey,
                                },
                            });
                        }}
                    >
                        Iniciar recolección LAB
                    </Button>
                )}
                {Object.entries(start.errors).map(([key, error]) => (
                    <InputError key={key} message={error} />
                ))}
                {project.latest_execution && (
                    <Link
                        href={`/projects/${project.uuid}/executions/${project.latest_execution.uuid}`}
                    >
                        Abrir seguimiento · {project.latest_execution.status}
                    </Link>
                )}
            </div>
        </>
    );
}
