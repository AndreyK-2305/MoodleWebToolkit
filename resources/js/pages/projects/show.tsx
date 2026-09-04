import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    CircleDot,
    Plus,
    Play,
    Server,
    ShieldCheck,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type ProjectType = 'COLLECT' | 'CONSOLIDATE' | 'INTEGRATE';
type InstanceRole = 'SOURCE' | 'DESTINATION';
type InstanceDraft = {
    uuid: string | null;
    server_uuid: string | null;
    role: InstanceRole;
    server_name: string;
    server_host: string;
    name: string;
    base_url: string;
    moodle_version: string;
    validated: boolean;
    destination_kind: 'PREPARED' | 'EXISTING_CONSOLIDATED' | null;
};
type PreflightCheck = {
    id: string;
    description: string;
    result: 'SUCCESS' | 'WARNING' | 'ERROR';
    detail: string;
};
type Preflight = {
    configuration_version: number;
    configuration_hash: string;
    checked_at: string;
    checks: PreflightCheck[];
};
type ProjectOptions = {
    simulation_scenario?: string | null;
    processing_scenario?: string | null;
    artifact_name?: string | null;
    category_strategy?: string | null;
    user_conflict_strategy?: string | null;
    admin_strategy?: string | null;
    include_archived_courses?: boolean;
    conflict_strategy?: string | null;
    preserve_destination_admins?: boolean;
};
type ProjectData = {
    uuid: string;
    name: string;
    description: string | null;
    type: ProjectType;
    type_label: string;
    status: string;
    status_label: string;
    current_step: number;
    configuration_version: number;
    options: ProjectOptions;
    preflight: Preflight | null;
    confirmation: {
        configuration_version: number;
        confirmed_at: string;
        accepted_warning_ids: string[];
    } | null;
    instances: InstanceDraft[];
    can_edit: boolean;
    can_start: boolean;
    latest_execution: {
        uuid: string;
        attempt: number;
        status: string;
    } | null;
};
type Props = {
    project: ProjectData;
    projectTypes: { value: ProjectType; label: string }[];
};

const steps = [
    'Datos básicos',
    'Instancias simuladas',
    'Configuración',
    'Preflight',
    'Confirmación',
];

function blankInstance(
    role: InstanceRole,
    type: ProjectType,
    sequence: number,
): InstanceDraft {
    const destinationKind =
        role === 'DESTINATION'
            ? type === 'CONSOLIDATE'
                ? 'PREPARED'
                : 'EXISTING_CONSOLIDATED'
            : null;

    return {
        uuid: null,
        server_uuid: null,
        role,
        server_name:
            role === 'SOURCE'
                ? `Servidor origen ${sequence}`
                : 'Servidor destino',
        server_host: '',
        name:
            role === 'SOURCE' ? `Moodle origen ${sequence}` : 'Moodle destino',
        base_url: '',
        moodle_version: '4.5',
        validated: true,
        destination_kind: destinationKind,
    };
}

function defaultInstances(project: ProjectData): InstanceDraft[] {
    if (project.instances.length > 0) {
        return project.instances;
    }

    if (project.type === 'COLLECT') {
        return [blankInstance('SOURCE', project.type, 1)];
    }

    if (project.type === 'CONSOLIDATE') {
        return [
            blankInstance('SOURCE', project.type, 1),
            blankInstance('SOURCE', project.type, 2),
            blankInstance('DESTINATION', project.type, 1),
        ];
    }

    return [
        blankInstance('SOURCE', project.type, 1),
        blankInstance('DESTINATION', project.type, 1),
    ];
}

export default function ProjectShow({ project, projectTypes }: Props) {
    const [activeStep, setActiveStep] = useState(project.current_step);

    useEffect(() => {
        setActiveStep(project.current_step);
    }, [project.configuration_version, project.current_step]);

    return (
        <>
            <Head title={project.name} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <Link
                            href="/projects"
                            className="text-muted-foreground hover:text-foreground mb-2 inline-flex items-center gap-1 text-sm"
                        >
                            <ArrowLeft className="size-4" /> Proyectos
                        </Link>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {project.name}
                            </h1>
                            <Badge variant="outline">
                                {project.type_label}
                            </Badge>
                            <Badge
                                variant={
                                    project.status === 'READY'
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {project.status_label}
                            </Badge>
                        </div>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Configuración v{project.configuration_version}
                        </p>
                    </div>
                    {!project.can_edit && (
                        <Badge variant="secondary">Consulta solamente</Badge>
                    )}
                </div>

                <Alert className="border-amber-300 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30">
                    <ShieldCheck className="text-amber-700 dark:text-amber-400" />
                    <AlertTitle>Entorno completamente simulado</AlertTitle>
                    <AlertDescription>
                        No se realizan conexiones SSH/SFTP, no se solicitan
                        secretos. Confirmar sólo deja el proyecto listo; el
                        inicio asíncrono es una acción posterior e
                        independiente.
                    </AlertDescription>
                </Alert>

                <nav
                    aria-label="Pasos del wizard"
                    className="grid gap-2 sm:grid-cols-5"
                >
                    {steps.map((label, index) => {
                        const number = index + 1;
                        const selected = activeStep === number;

                        return (
                            <button
                                key={label}
                                type="button"
                                aria-current={selected ? 'step' : undefined}
                                onClick={() => setActiveStep(number)}
                                className={cn(
                                    'flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition-colors',
                                    selected
                                        ? 'border-amber-500 bg-amber-50 text-amber-950 dark:bg-amber-950/40 dark:text-amber-100'
                                        : 'border-border hover:bg-muted',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                        selected
                                            ? 'bg-amber-600 text-white'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {number}
                                </span>
                                <span className="hidden lg:inline">
                                    {label}
                                </span>
                            </button>
                        );
                    })}
                </nav>

                {activeStep === 1 && (
                    <BasicsStep
                        project={project}
                        projectTypes={projectTypes}
                        onNext={() => setActiveStep(2)}
                    />
                )}
                {activeStep === 2 && (
                    <InstancesStep
                        project={project}
                        onBack={() => setActiveStep(1)}
                        onNext={() => setActiveStep(3)}
                    />
                )}
                {activeStep === 3 && (
                    <OptionsStep
                        project={project}
                        onBack={() => setActiveStep(2)}
                        onNext={() => setActiveStep(4)}
                    />
                )}
                {activeStep === 4 && (
                    <PreflightStep
                        project={project}
                        onBack={() => setActiveStep(3)}
                        onNext={() => setActiveStep(5)}
                    />
                )}
                {activeStep === 5 && (
                    <ConfirmationStep
                        project={project}
                        onBack={() => setActiveStep(4)}
                    />
                )}
            </div>
        </>
    );
}

function BasicsStep({
    project,
    projectTypes,
    onNext,
}: {
    project: ProjectData;
    projectTypes: Props['projectTypes'];
    onNext: () => void;
}) {
    const form = useForm({
        name: project.name,
        type: project.type,
        description: project.description ?? '',
    });

    return (
        <Card>
            <CardHeader>
                <CardTitle>1. Tipo de operación y datos básicos</CardTitle>
            </CardHeader>
            <CardContent>
                <form
                    className="grid gap-4 md:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(`/projects/${project.uuid}/wizard/basics`, {
                            onSuccess: onNext,
                        });
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="wizard-name">Nombre</Label>
                        <Input
                            id="wizard-name"
                            disabled={!project.can_edit}
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="wizard-type">Operación</Label>
                        <select
                            id="wizard-type"
                            disabled={!project.can_edit}
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm disabled:opacity-60"
                            value={form.data.type}
                            onChange={(event) =>
                                form.setData(
                                    'type',
                                    event.target.value as ProjectType,
                                )
                            }
                        >
                            {projectTypes.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.type} />
                    </div>
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="wizard-description">
                            Descripción opcional
                        </Label>
                        <textarea
                            id="wizard-description"
                            disabled={!project.can_edit}
                            className="border-input bg-background min-h-24 rounded-md border px-3 py-2 text-sm disabled:opacity-60"
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                        <InputError message={form.errors.description} />
                    </div>
                    <div className="flex justify-end md:col-span-2">
                        {project.can_edit ? (
                            <Button disabled={form.processing}>
                                Guardar y continuar <ArrowRight />
                            </Button>
                        ) : (
                            <Button type="button" onClick={onNext}>
                                Continuar consulta <ArrowRight />
                            </Button>
                        )}
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function InstancesStep({
    project,
    onBack,
    onNext,
}: {
    project: ProjectData;
    onBack: () => void;
    onNext: () => void;
}) {
    const form = useForm<{ instances: InstanceDraft[] }>({
        instances: defaultInstances(project),
    });
    const fieldErrors = form.errors as Record<string, string>;
    const sourceCount = form.data.instances.filter(
        (instance) => instance.role === 'SOURCE',
    ).length;
    const canAddSource = project.type === 'CONSOLIDATE' || sourceCount === 0;

    const updateInstance = <K extends keyof InstanceDraft>(
        index: number,
        field: K,
        value: InstanceDraft[K],
    ) => {
        form.setData(
            'instances',
            form.data.instances.map((instance, current) =>
                current === index ? { ...instance, [field]: value } : instance,
            ),
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>2. Instancias simuladas</CardTitle>
                <p className="text-muted-foreground text-sm">
                    {project.type === 'COLLECT' &&
                        'Recolectar utiliza exactamente un origen y no requiere destino.'}
                    {project.type === 'CONSOLIDATE' &&
                        'Consolidar utiliza dos o más orígenes y referencia un destino ya preparado.'}
                    {project.type === 'INTEGRATE' &&
                        'Integrar utiliza un origen y referencia un Moodle consolidado existente.'}
                </p>
            </CardHeader>
            <CardContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`/projects/${project.uuid}/wizard/instances`, {
                            onSuccess: onNext,
                        });
                    }}
                >
                    {form.data.instances.map((instance, index) => (
                        <div
                            key={instance.uuid ?? `${instance.role}-${index}`}
                            className="border-border rounded-lg border p-4"
                        >
                            <div className="mb-4 flex items-center justify-between gap-3">
                                <div className="flex items-center gap-2">
                                    <Server className="size-5" />
                                    <h3 className="font-medium">
                                        {instance.role === 'SOURCE'
                                            ? 'Instancia origen'
                                            : 'Instancia destino'}
                                    </h3>
                                    <Badge variant="secondary">Simulada</Badge>
                                </div>
                                {project.can_edit &&
                                    instance.role === 'SOURCE' && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                form.setData(
                                                    'instances',
                                                    form.data.instances.filter(
                                                        (_, current) =>
                                                            current !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 /> Quitar
                                        </Button>
                                    )}
                            </div>
                            {instance.role === 'DESTINATION' && (
                                <Alert className="mb-4">
                                    <CircleDot />
                                    <AlertTitle>
                                        {instance.destination_kind ===
                                        'PREPARED'
                                            ? 'Destino preparado por el administrador'
                                            : 'Moodle consolidado existente'}
                                    </AlertTitle>
                                    <AlertDescription>
                                        La plataforma sólo guarda esta
                                        referencia simulada; no crea el servidor
                                        ni la instalación Moodle.
                                    </AlertDescription>
                                </Alert>
                            )}
                            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <InstanceField
                                    id={`server-name-${index}`}
                                    label="Nombre del servidor"
                                    value={instance.server_name}
                                    disabled={!project.can_edit}
                                    error={
                                        fieldErrors[
                                            `instances.${index}.server_name`
                                        ]
                                    }
                                    onChange={(value) =>
                                        updateInstance(
                                            index,
                                            'server_name',
                                            value,
                                        )
                                    }
                                />
                                <InstanceField
                                    id={`server-host-${index}`}
                                    label="Host simulado"
                                    value={instance.server_host}
                                    placeholder="moodle-origen.test"
                                    disabled={!project.can_edit}
                                    error={
                                        fieldErrors[
                                            `instances.${index}.server_host`
                                        ]
                                    }
                                    onChange={(value) =>
                                        updateInstance(
                                            index,
                                            'server_host',
                                            value,
                                        )
                                    }
                                />
                                <InstanceField
                                    id={`instance-name-${index}`}
                                    label="Nombre de la instancia"
                                    value={instance.name}
                                    disabled={!project.can_edit}
                                    error={
                                        fieldErrors[`instances.${index}.name`]
                                    }
                                    onChange={(value) =>
                                        updateInstance(index, 'name', value)
                                    }
                                />
                                <InstanceField
                                    id={`base-url-${index}`}
                                    label="URL simulada"
                                    value={instance.base_url}
                                    placeholder="https://moodle.test"
                                    disabled={!project.can_edit}
                                    error={
                                        fieldErrors[
                                            `instances.${index}.base_url`
                                        ]
                                    }
                                    onChange={(value) =>
                                        updateInstance(index, 'base_url', value)
                                    }
                                />
                                <InstanceField
                                    id={`version-${index}`}
                                    label="Versión Moodle"
                                    value={instance.moodle_version}
                                    placeholder="4.5"
                                    disabled={!project.can_edit}
                                    error={
                                        fieldErrors[
                                            `instances.${index}.moodle_version`
                                        ]
                                    }
                                    onChange={(value) =>
                                        updateInstance(
                                            index,
                                            'moodle_version',
                                            value,
                                        )
                                    }
                                />
                                <div className="flex items-end pb-2">
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={instance.validated}
                                            disabled={!project.can_edit}
                                            onCheckedChange={(checked) =>
                                                updateInstance(
                                                    index,
                                                    'validated',
                                                    Boolean(checked),
                                                )
                                            }
                                        />
                                        Validación simulada aprobada
                                    </label>
                                </div>
                            </div>
                        </div>
                    ))}
                    <InputError message={fieldErrors.instances} />

                    {project.can_edit && canAddSource && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                form.setData('instances', [
                                    ...form.data.instances,
                                    blankInstance(
                                        'SOURCE',
                                        project.type,
                                        sourceCount + 1,
                                    ),
                                ])
                            }
                        >
                            <Plus /> Agregar origen
                        </Button>
                    )}

                    <div className="flex flex-wrap justify-between gap-3 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onBack}
                        >
                            <ArrowLeft /> Atrás
                        </Button>
                        {project.can_edit ? (
                            <Button disabled={form.processing}>
                                Guardar y continuar <ArrowRight />
                            </Button>
                        ) : (
                            <Button type="button" onClick={onNext}>
                                Continuar consulta <ArrowRight />
                            </Button>
                        )}
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function InstanceField({
    id,
    label,
    value,
    error,
    placeholder,
    disabled,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    error?: string;
    placeholder?: string;
    disabled: boolean;
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                value={value}
                placeholder={placeholder}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
            />
            <InputError message={error} />
        </div>
    );
}

function OptionsStep({
    project,
    onBack,
    onNext,
}: {
    project: ProjectData;
    onBack: () => void;
    onNext: () => void;
}) {
    const options = project.options;
    const form = useForm({
        simulation_scenario: options.simulation_scenario ?? 'SUCCESS',
        processing_scenario: options.processing_scenario ?? 'SUCCESS',
        artifact_name: options.artifact_name ?? '',
        category_strategy: options.category_strategy ?? 'PRESERVE',
        user_conflict_strategy: options.user_conflict_strategy ?? 'REVIEW',
        admin_strategy: options.admin_strategy ?? 'EXCLUDE_SOURCE_ADMINS',
        include_archived_courses: options.include_archived_courses ?? false,
        conflict_strategy: options.conflict_strategy ?? 'REVIEW',
        preserve_destination_admins:
            options.preserve_destination_admins ?? true,
    });
    const fieldErrors = form.errors as Record<string, string>;

    return (
        <Card>
            <CardHeader>
                <CardTitle>3. Configuración funcional</CardTitle>
                <p className="text-muted-foreground text-sm">
                    Se muestran conceptos de migración, nunca variables internas
                    de scripts ni secretos.
                </p>
            </CardHeader>
            <CardContent>
                <form
                    className="space-y-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`/projects/${project.uuid}/wizard/options`, {
                            onSuccess: onNext,
                        });
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="simulation-scenario">
                            Escenario determinista de demostración
                        </Label>
                        <select
                            id="simulation-scenario"
                            disabled={!project.can_edit}
                            className="border-input bg-background h-9 max-w-xl rounded-md border px-3 text-sm disabled:opacity-60"
                            value={form.data.simulation_scenario}
                            onChange={(event) =>
                                form.setData(
                                    'simulation_scenario',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="SUCCESS">Éxito</option>
                            <option value="WARNING">
                                Advertencia de capacidad
                            </option>
                            <option value="ERROR">Error de conectividad</option>
                        </select>
                        <p className="text-muted-foreground text-xs">
                            Controla el resultado simulado del preflight sin
                            acceder a infraestructura real.
                        </p>
                        <InputError message={form.errors.simulation_scenario} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="processing-scenario">
                            Caso especial durante el procesamiento
                        </Label>
                        <select
                            id="processing-scenario"
                            disabled={!project.can_edit}
                            className="border-input bg-background h-9 max-w-xl rounded-md border px-3 text-sm disabled:opacity-60"
                            value={form.data.processing_scenario}
                            onChange={(event) =>
                                form.setData(
                                    'processing_scenario',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="SUCCESS">Sin incidencias</option>
                            <option value="WARNING">
                                Advertencia con aceptación
                            </option>
                            <option value="INTERVENTION">
                                Intervención manual
                            </option>
                            <option value="FAILURE">
                                Fallo con checkpoint
                            </option>
                        </select>
                        <p className="text-muted-foreground text-xs">
                            Controla de forma reproducible el motor simulado. La
                            verificación, revisión y el cierre se procesan en la
                            misma ejecución.
                        </p>
                        <InputError message={form.errors.processing_scenario} />
                    </div>

                    {project.type === 'COLLECT' && (
                        <div className="grid max-w-xl gap-2">
                            <Label htmlFor="artifact-name">
                                Nombre del paquete estructurado
                            </Label>
                            <Input
                                id="artifact-name"
                                disabled={!project.can_edit}
                                value={form.data.artifact_name}
                                onChange={(event) =>
                                    form.setData(
                                        'artifact_name',
                                        event.target.value,
                                    )
                                }
                                placeholder="facultad-ingenieria-2026"
                            />
                            <InputError message={form.errors.artifact_name} />
                        </div>
                    )}

                    {project.type === 'CONSOLIDATE' && (
                        <div className="grid gap-4 md:grid-cols-2">
                            <SelectField
                                id="category-strategy"
                                label="Estructura de categorías"
                                value={form.data.category_strategy}
                                disabled={!project.can_edit}
                                onChange={(value) =>
                                    form.setData('category_strategy', value)
                                }
                                options={[
                                    ['PRESERVE', 'Conservar estructura'],
                                    [
                                        'PREFIX_SOURCE',
                                        'Prefijar por instancia origen',
                                    ],
                                ]}
                            />
                            <SelectField
                                id="user-conflicts"
                                label="Conflictos de usuarios"
                                value={form.data.user_conflict_strategy}
                                disabled={!project.can_edit}
                                onChange={(value) =>
                                    form.setData(
                                        'user_conflict_strategy',
                                        value,
                                    )
                                }
                                options={[
                                    [
                                        'KEEP_DESTINATION',
                                        'Conservar identidad del destino',
                                    ],
                                    ['REVIEW', 'Enviar a revisión'],
                                ]}
                            />
                            <div className="md:col-span-2">
                                <Alert>
                                    <ShieldCheck />
                                    <AlertTitle>
                                        Administradores protegidos
                                    </AlertTitle>
                                    <AlertDescription>
                                        Los administradores globales de origen
                                        se excluyen automáticamente del
                                        privilegio global en destino.
                                    </AlertDescription>
                                </Alert>
                                <input
                                    type="hidden"
                                    value="EXCLUDE_SOURCE_ADMINS"
                                    readOnly
                                />
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.include_archived_courses}
                                    disabled={!project.can_edit}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'include_archived_courses',
                                            Boolean(checked),
                                        )
                                    }
                                />
                                Incluir cursos archivados
                            </label>
                        </div>
                    )}

                    {project.type === 'INTEGRATE' && (
                        <div className="grid gap-3 md:grid-cols-2">
                            <Alert>
                                <TriangleAlert />
                                <AlertTitle>Conflictos a revisión</AlertTitle>
                                <AlertDescription>
                                    Las colisiones simuladas se conservan para
                                    revisión; el wizard no las resuelve ni
                                    ejecuta cambios.
                                </AlertDescription>
                            </Alert>
                            <Alert>
                                <ShieldCheck />
                                <AlertTitle>
                                    Administradores del destino
                                </AlertTitle>
                                <AlertDescription>
                                    Se preservan los administradores del Moodle
                                    consolidado existente.
                                </AlertDescription>
                            </Alert>
                        </div>
                    )}

                    <InputError message={fieldErrors.configuration} />
                    <div className="flex flex-wrap justify-between gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onBack}
                        >
                            <ArrowLeft /> Atrás
                        </Button>
                        {project.can_edit ? (
                            <Button disabled={form.processing}>
                                Guardar y continuar <ArrowRight />
                            </Button>
                        ) : (
                            <Button type="button" onClick={onNext}>
                                Continuar consulta <ArrowRight />
                            </Button>
                        )}
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function SelectField({
    id,
    label,
    value,
    disabled,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    disabled: boolean;
    options: [string, string][];
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                disabled={disabled}
                className="border-input bg-background h-9 rounded-md border px-3 text-sm disabled:opacity-60"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {options.map(([optionValue, optionLabel]) => (
                    <option key={optionValue} value={optionValue}>
                        {optionLabel}
                    </option>
                ))}
            </select>
        </div>
    );
}

function PreflightStep({
    project,
    onBack,
    onNext,
}: {
    project: ProjectData;
    onBack: () => void;
    onNext: () => void;
}) {
    const form = useForm({});
    const preflight = project.preflight;
    const fieldErrors = form.errors as Record<string, string>;

    return (
        <Card>
            <CardHeader>
                <CardTitle>4. Preflight simulado</CardTitle>
                <p className="text-muted-foreground text-sm">
                    Cada comprobación informa un resultado estructurado. Los
                    errores bloquean y las advertencias requieren aceptación.
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                {preflight ? (
                    <div className="grid gap-3">
                        {preflight.checks.map((check) => (
                            <CheckRow key={check.id} check={check} />
                        ))}
                        <p className="text-muted-foreground text-xs">
                            Evaluado para configuración v
                            {preflight.configuration_version} ·{' '}
                            {new Date(preflight.checked_at).toLocaleString()}
                        </p>
                    </div>
                ) : (
                    <Alert>
                        <CircleDot />
                        <AlertTitle>Preflight pendiente</AlertTitle>
                        <AlertDescription>
                            Guarde la configuración y ejecute las comprobaciones
                            simuladas.
                        </AlertDescription>
                    </Alert>
                )}
                <InputError message={fieldErrors.preflight} />
                <div className="flex flex-wrap justify-between gap-3">
                    <Button type="button" variant="outline" onClick={onBack}>
                        <ArrowLeft /> Atrás
                    </Button>
                    <div className="flex flex-wrap gap-2">
                        {project.can_edit && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.processing}
                                onClick={() =>
                                    form.post(
                                        `/projects/${project.uuid}/wizard/preflight`,
                                        { onSuccess: onNext },
                                    )
                                }
                            >
                                {preflight
                                    ? 'Repetir preflight'
                                    : 'Ejecutar preflight'}
                            </Button>
                        )}
                        {preflight && (
                            <Button type="button" onClick={onNext}>
                                Revisar confirmación <ArrowRight />
                            </Button>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function CheckRow({ check }: { check: PreflightCheck }) {
    const Icon =
        check.result === 'SUCCESS'
            ? CheckCircle2
            : check.result === 'WARNING'
              ? TriangleAlert
              : AlertCircle;

    return (
        <div className="border-border flex gap-3 rounded-lg border p-4">
            <Icon
                className={cn(
                    'mt-0.5 size-5 shrink-0',
                    check.result === 'SUCCESS' && 'text-emerald-600',
                    check.result === 'WARNING' && 'text-amber-600',
                    check.result === 'ERROR' && 'text-red-600',
                )}
            />
            <div>
                <div className="flex flex-wrap items-center gap-2">
                    <p className="font-medium">{check.description}</p>
                    <Badge
                        variant={
                            check.result === 'ERROR' ? 'destructive' : 'outline'
                        }
                    >
                        {check.result}
                    </Badge>
                </div>
                <p className="text-muted-foreground mt-1 text-sm">
                    {check.detail}
                </p>
                <p className="text-muted-foreground mt-1 font-mono text-xs">
                    {check.id}
                </p>
            </div>
        </div>
    );
}

function ConfirmationStep({
    project,
    onBack,
}: {
    project: ProjectData;
    onBack: () => void;
}) {
    const warnings =
        project.preflight?.checks.filter(
            (check) => check.result === 'WARNING',
        ) ?? [];
    const errors =
        project.preflight?.checks.filter((check) => check.result === 'ERROR') ??
        [];
    const form = useForm({
        configuration_version: project.configuration_version,
        accepted_warning_ids:
            project.confirmation?.accepted_warning_ids ?? ([] as string[]),
    });
    const fieldErrors = form.errors as Record<string, string>;

    return (
        <Card>
            <CardHeader>
                <CardTitle>5. Confirmación</CardTitle>
                <p className="text-muted-foreground text-sm">
                    Confirmar deja el proyecto en READY. No crea ejecuciones ni
                    inicia procesos.
                </p>
            </CardHeader>
            <CardContent className="space-y-5">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Summary label="Operación" value={project.type_label} />
                    <Summary
                        label="Orígenes"
                        value={String(
                            project.instances.filter(
                                (instance) => instance.role === 'SOURCE',
                            ).length,
                        )}
                    />
                    <Summary
                        label="Destino"
                        value={
                            project.instances.find(
                                (instance) => instance.role === 'DESTINATION',
                            )?.name ?? 'No aplica'
                        }
                    />
                    <Summary
                        label="Configuración"
                        value={`v${project.configuration_version}`}
                    />
                </div>

                {warnings.length > 0 && (
                    <div className="space-y-3">
                        <h3 className="font-medium">
                            Advertencias que requieren aceptación
                        </h3>
                        {warnings.map((warning) => (
                            <label
                                key={warning.id}
                                className="border-border flex items-start gap-3 rounded-lg border p-4"
                            >
                                <Checkbox
                                    className="mt-0.5"
                                    disabled={
                                        !project.can_edit ||
                                        project.status === 'READY'
                                    }
                                    checked={form.data.accepted_warning_ids.includes(
                                        warning.id,
                                    )}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'accepted_warning_ids',
                                            checked
                                                ? [
                                                      ...form.data
                                                          .accepted_warning_ids,
                                                      warning.id,
                                                  ]
                                                : form.data.accepted_warning_ids.filter(
                                                      (id) => id !== warning.id,
                                                  ),
                                        )
                                    }
                                />
                                <span>
                                    <span className="block font-medium">
                                        {warning.description}
                                    </span>
                                    <span className="text-muted-foreground block text-sm">
                                        {warning.detail}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>
                )}

                {errors.length > 0 && (
                    <Alert variant="destructive">
                        <AlertCircle />
                        <AlertTitle>
                            La configuración no puede confirmarse
                        </AlertTitle>
                        <AlertDescription>
                            Corrija los errores y ejecute nuevamente el
                            preflight.
                        </AlertDescription>
                    </Alert>
                )}

                {project.status === 'READY' && (
                    <Alert className="border-emerald-300 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30">
                        <CheckCircle2 className="text-emerald-600" />
                        <AlertTitle>Proyecto listo</AlertTitle>
                        <AlertDescription>
                            La configuración quedó confirmada. Ya puede iniciar
                            una ejecución asíncrona independiente del wizard.
                        </AlertDescription>
                    </Alert>
                )}

                {project.status === 'READY' && project.can_start && (
                    <StartExecutionPanel project={project} />
                )}

                {project.latest_execution && (
                    <Link
                        href={`/projects/${project.uuid}/executions/${project.latest_execution.uuid}`}
                        className="text-primary inline-flex items-center gap-1 text-sm font-medium hover:underline"
                    >
                        Ver ejecución #{project.latest_execution.attempt} ·{' '}
                        {project.latest_execution.status}
                    </Link>
                )}

                <InputError message={form.errors.configuration_version} />
                <InputError message={fieldErrors.configuration} />
                <InputError message={fieldErrors.preflight} />
                <InputError message={form.errors.accepted_warning_ids} />

                <div className="flex flex-wrap justify-between gap-3">
                    <Button type="button" variant="outline" onClick={onBack}>
                        <ArrowLeft /> Atrás
                    </Button>
                    {project.can_edit && project.status !== 'READY' && (
                        <Button
                            type="button"
                            disabled={form.processing}
                            onClick={() =>
                                form.post(
                                    `/projects/${project.uuid}/wizard/confirm`,
                                )
                            }
                        >
                            <ShieldCheck /> Confirmar configuración
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function StartExecutionPanel({ project }: { project: ProjectData }) {
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const form = useForm({
        configuration_version: project.configuration_version,
    });
    const fieldErrors = form.errors as Record<string, string>;

    return (
        <div className="border-primary/30 bg-primary/5 rounded-lg border p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p className="font-medium">Motor asíncrono preparado</p>
                    <p className="text-muted-foreground text-sm">
                        Persistirá el intento y sus pasos antes de enviarlo a
                        Redis.
                    </p>
                </div>
                <Button
                    type="button"
                    disabled={form.processing}
                    onClick={() =>
                        form.post(`/projects/${project.uuid}/executions`, {
                            headers: { 'Idempotency-Key': idempotencyKey },
                        })
                    }
                >
                    <Play /> Iniciar ejecución
                </Button>
            </div>
            <InputError message={fieldErrors.idempotency_key} />
            <InputError message={fieldErrors.execution} />
            <InputError message={fieldErrors.project} />
            <InputError message={fieldErrors.confirmation} />
        </div>
    );
}

function Summary({ label, value }: { label: string; value: string }) {
    return (
        <div className="bg-muted/50 rounded-lg p-4">
            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                {label}
            </p>
            <p className="mt-1 font-medium">{value}</p>
        </div>
    );
}

ProjectShow.layout = {
    breadcrumbs: [
        { title: 'Proyectos', href: '/projects' },
        { title: 'Wizard', href: '#' },
    ],
};
