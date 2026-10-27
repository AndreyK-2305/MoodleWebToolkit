import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    CircleDot,
    CircleHelp,
    Plus,
    Play,
    Server,
    ShieldCheck,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import CollectorConfigurationForm from '@/components/collector-configuration-form';
import type { CollectorLab } from '@/components/collector-configuration-form';
import RemoteAccessPanel from '@/components/remote-access-panel';
import RemoteCollectionForm from '@/components/remote-collection-form';
import {
    ConsolidationDestinationForm,
    ConsolidationSourcesForm,
} from '@/components/remote-consolidation-form';
import type {
    ConsolidationSources,
    RemoteConsolidationOptions,
} from '@/components/remote-consolidation-form';
import type { RemoteCollectionOptions } from '@/components/remote-collection-form';
import type { RemoteAccess } from '@/components/remote-access-panel';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    canOpenStep,
    initialStep,
    remoteWizardSteps,
    stepBlocker,
    wizardServer,
} from '@/lib/remote-wizard';
import type { WizardStep } from '@/lib/remote-wizard';
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
type ProjectOptions = RemoteCollectionOptions & {
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
    collectorLab?: CollectorLab;
    project: ProjectData;
    projectTypes: { value: ProjectType; label: string }[];
    remoteAccess?: RemoteAccess;
    consolidationSources?: ConsolidationSources | null;
    linkedConsolidations?: { uuid: string; name: string }[];
};

function wizardSteps(simulated: boolean, remoteMode: string | null): string[] {
    return [
        'Datos básicos',
        simulated ? 'Instancias simuladas' : 'Instancias',
        remoteMode === 'REMOTE'
            ? 'Recolección remota'
            : remoteMode === 'REMOTE_CONSOLIDATION'
              ? 'Consolidación remota'
              : 'Configuración',
        'Preflight',
        'Confirmación',
    ];
}

function blankInstance(
    role: InstanceRole,
    type: ProjectType,
    sequence: number,
    simulated: boolean,
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
        // The sources run Moodle 4.5; the Consolidator builds its destinations on Moodle 5.2.
        moodle_version: role === 'SOURCE' ? '4.5' : '5.2',
        // A real instance is reviewed by the administrator: they tick it themselves.
        validated: simulated,
        destination_kind: destinationKind,
    };
}

function defaultInstances(
    project: ProjectData,
    simulated: boolean,
): InstanceDraft[] {
    if (project.instances.length > 0) {
        return project.instances;
    }

    // A remote consolidation reads its sources from sealed packages: only the destination is registered.
    if (project.type === 'CONSOLIDATE' && !simulated) {
        return [blankInstance('DESTINATION', project.type, 1, simulated)];
    }

    if (project.type === 'COLLECT') {
        return [blankInstance('SOURCE', project.type, 1, simulated)];
    }

    if (project.type === 'CONSOLIDATE') {
        return [
            blankInstance('SOURCE', project.type, 1, simulated),
            blankInstance('SOURCE', project.type, 2, simulated),
            blankInstance('DESTINATION', project.type, 1, simulated),
        ];
    }

    return [
        blankInstance('SOURCE', project.type, 1, simulated),
        blankInstance('DESTINATION', project.type, 1, simulated),
    ];
}

export default function ProjectShow({
    project,
    projectTypes,
    collectorLab,
    remoteAccess,
    consolidationSources = null,
    linkedConsolidations = [],
}: Props) {
    // A preflight with errors keeps the wizard on step 4, where they are listed: the
    // confirmation stays closed until a new preflight passes.
    const blocked = blockingChecks(project.preflight).length > 0;
    const reachable = (step: number) => (blocked && step === 5 ? 4 : step);
    const [activeStep, setActiveStep] = useState(
        reachable(project.current_step),
    );
    // With remote access the servers are real; REMOTE mode is configured in its own card.
    const simulated = !remoteAccess?.enabled;
    const mode = project.options.mode;
    // With real servers a consolidation is always remote: its simulated
    // functional options would only mislead, even before the card is saved.
    const remoteConsolidation =
        mode === 'REMOTE_CONSOLIDATION' ||
        (!simulated && project.type === 'CONSOLIDATE');
    // Likewise a collection with real servers is configured in its remote card, saved or not;
    // only the collector laboratory keeps its own mode.
    const remoteMode =
        mode === 'REMOTE' ||
        remoteConsolidation ||
        (!simulated && project.type === 'COLLECT' && !mode);
    // With real servers a collection or consolidation follows the steps of the remote wizard.
    const remoteWizard =
        !simulated &&
        (project.type === 'COLLECT' || project.type === 'CONSOLIDATE');
    const steps = wizardSteps(
        simulated,
        remoteConsolidation
            ? 'REMOTE_CONSOLIDATION'
            : remoteMode
              ? 'REMOTE'
              : null,
    );

    useEffect(() => {
        setActiveStep(reachable(project.current_step));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [project.configuration_version, project.current_step, blocked]);

    return (
        <>
            <Head title={project.name} />
            <div className="space-y-6 p-4 md:p-6">
                {project.type === 'COLLECT' && collectorLab?.enabled && (
                    <CollectorConfigurationForm
                        projectUuid={project.uuid}
                        options={{}}
                        laboratory={collectorLab}
                        editable={project.can_edit}
                    />
                )}
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

                {linkedConsolidations.length > 0 && (
                    <Alert className="border-sky-300 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/30">
                        <CircleDot />
                        <AlertTitle>
                            Recolección para una consolidación
                        </AlertTitle>
                        <AlertDescription>
                            <p>
                                Cuando esta recolección se complete, sus
                                paquetes pasan solos a{' '}
                                {linkedConsolidations.map(
                                    (consolidation, index) => (
                                        <span key={consolidation.uuid}>
                                            {index > 0 && ', '}
                                            <Link
                                                href={`/projects/${consolidation.uuid}`}
                                                className="font-medium underline underline-offset-2"
                                            >
                                                {consolidation.name}
                                            </Link>
                                        </span>
                                    ),
                                )}
                                . Instale el agente en cada origen, despliegue
                                el Recolector, compruebe los perfiles, ejecute
                                el preflight, confirme e inicie.
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                {remoteWizard && remoteAccess ? (
                    <RemoteWizard
                        project={project}
                        projectTypes={projectTypes}
                        access={remoteAccess}
                        sources={consolidationSources}
                    />
                ) : (
                    <>
                        {remoteAccess?.enabled ? (
                            <RemoteAccessPanel
                                projectUuid={project.uuid}
                                access={remoteAccess}
                                editable={project.can_edit}
                            />
                        ) : (
                            <Alert className="border-amber-300 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30">
                                <ShieldCheck className="text-amber-700 dark:text-amber-400" />
                                <AlertTitle>
                                    Entorno completamente simulado
                                </AlertTitle>
                                <AlertDescription>
                                    No se realizan conexiones SSH/SFTP, no se
                                    solicitan secretos. Confirmar sólo deja el
                                    proyecto listo; el inicio asíncrono es una
                                    acción posterior e independiente.
                                </AlertDescription>
                            </Alert>
                        )}

                        <nav
                            aria-label="Pasos del wizard"
                            className="grid gap-2 sm:grid-cols-5"
                        >
                            {steps.map((label, index) => {
                                const number = index + 1;
                                const selected = activeStep === number;
                                const closed = reachable(number) !== number;

                                return (
                                    <button
                                        key={label}
                                        type="button"
                                        aria-current={
                                            selected ? 'step' : undefined
                                        }
                                        disabled={closed}
                                        title={
                                            closed
                                                ? 'Corrija los errores del preflight para continuar'
                                                : undefined
                                        }
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
                                simulated={simulated}
                                onBack={() => setActiveStep(1)}
                                onNext={() => setActiveStep(3)}
                            />
                        )}
                        {activeStep === 3 && remoteMode && (
                            <RemoteOptionsStep
                                consolidation={remoteConsolidation}
                                waiting={pendingSources(consolidationSources)}
                                configured={
                                    mode ===
                                    (remoteConsolidation
                                        ? 'REMOTE_CONSOLIDATION'
                                        : 'REMOTE')
                                }
                                onBack={() => setActiveStep(2)}
                                onNext={() => setActiveStep(4)}
                            />
                        )}
                        {activeStep === 3 && !remoteMode && (
                            <OptionsStep
                                project={project}
                                onBack={() => setActiveStep(2)}
                                onNext={() => setActiveStep(4)}
                            />
                        )}
                        {activeStep === 4 && (
                            <PreflightStep
                                project={project}
                                simulated={simulated && !remoteMode}
                                onBack={() => setActiveStep(3)}
                                onNext={() => setActiveStep(5)}
                            />
                        )}
                        {activeStep === 5 && (
                            <ConfirmationStep
                                project={project}
                                remoteConsolidation={remoteConsolidation}
                                waiting={pendingSources(consolidationSources)}
                                onBack={() => setActiveStep(4)}
                            />
                        )}
                    </>
                )}
            </div>
        </>
    );
}

const destinationLabelHelp =
    'Es solo una etiqueta para identificar este servidor dentro del proceso. Si se deja vacío se usa el nombre del proyecto. No tiene relación con el nombre que tendrá el sitio consolidado.';
const sourceLabelHelp =
    'Es solo una etiqueta para identificar este origen dentro del proceso. Si se deja vacía se usa el nombre del proyecto con el número del origen. No cambia nada en el servidor.';

/**
 * The wizard with real servers. The stepper leads the page, each step shows
 * its real state and the work happens inside the steps: the remote cards are
 * the steps' content. A step opens once every earlier step is complete.
 */
function RemoteWizard({
    project,
    projectTypes,
    access,
    sources,
}: {
    project: ProjectData;
    projectTypes: Props['projectTypes'];
    access: RemoteAccess;
    sources: ConsolidationSources | null;
}) {
    const type = project.type === 'COLLECT' ? 'COLLECT' : 'CONSOLIDATE';
    const options = project.options as RemoteConsolidationOptions &
        RemoteCollectionOptions;
    const steps = remoteWizardSteps({
        type,
        basicsSaved: project.current_step > 1,
        configurationVersion: project.configuration_version,
        options,
        servers: access.servers.map(wizardServer),
        selection: sources?.selection ?? null,
        link: sources?.link ?? null,
        preflight: project.preflight,
        confirmation: project.confirmation,
    });
    const [active, setActive] = useState(() => initialStep(steps));
    const step = steps[Math.min(active, steps.length - 1)];
    const index = steps.indexOf(step);
    const go = (target: number) => {
        if (
            target >= 0 &&
            target < steps.length &&
            canOpenStep(steps, target)
        ) {
            setActive(target);
        }
    };
    const back = () => go(index - 1);
    const next = () => go(index + 1);
    const waiting = type === 'CONSOLIDATE' ? pendingSources(sources) : null;
    const destination = access.servers.find(
        (server) => server.role === 'DESTINATION',
    );

    return (
        <div className="space-y-6">
            <nav aria-label="Pasos del asistente">
                <ol
                    className={cn(
                        'grid gap-2',
                        steps.length === 5
                            ? 'sm:grid-cols-5'
                            : 'sm:grid-cols-4',
                    )}
                >
                    {steps.map((item, position) => {
                        const selected = position === index;
                        const open = canOpenStep(steps, position);

                        return (
                            <li key={item.key}>
                                <button
                                    type="button"
                                    aria-current={selected ? 'step' : undefined}
                                    disabled={!open}
                                    title={
                                        open
                                            ? undefined
                                            : (stepBlocker(steps, position) ??
                                              undefined)
                                    }
                                    onClick={() => go(position)}
                                    className={cn(
                                        'flex h-full w-full items-start gap-2 rounded-lg border px-3 py-2 text-left text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                                        selected
                                            ? 'border-amber-500 bg-amber-50 text-amber-950 dark:bg-amber-950/40 dark:text-amber-100'
                                            : 'border-border hover:bg-muted',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                            item.complete
                                                ? 'bg-emerald-600 text-white'
                                                : selected
                                                  ? 'bg-amber-600 text-white'
                                                  : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {item.complete ? (
                                            <CheckCircle2 className="size-4" />
                                        ) : (
                                            position + 1
                                        )}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block font-medium">
                                            {item.label}
                                        </span>
                                        <span className="text-muted-foreground block text-xs">
                                            {item.status}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ol>
            </nav>

            {step.key === 'basics' && (
                <BasicsStep
                    project={project}
                    projectTypes={projectTypes}
                    destinationLabel={
                        destination && destination.name !== project.name
                            ? destination.name
                            : ''
                    }
                    onNext={() => setActive(1)}
                />
            )}
            {step.key === 'sources' && type === 'COLLECT' && (
                <StepFrame
                    number={index + 1}
                    title="Orígenes"
                    description="Agregue cada servidor de origen, conéctelo por AWS Systems Manager, despliegue el Recolector y compruebe su perfil. Después guarde la recolección: un paquete por origen."
                    step={step}
                    onBack={back}
                    onNext={next}
                >
                    <CollectionSourcesStep project={project} access={access} />
                </StepFrame>
            )}
            {step.key === 'sources' && type === 'CONSOLIDATE' && (
                <StepFrame
                    number={index + 1}
                    title="Orígenes"
                    description="Elija de dónde salen los paquetes sellados que se consolidan, uno por origen."
                    step={step}
                    onBack={back}
                    onNext={next}
                >
                    <ConsolidationSourcesForm
                        projectUuid={project.uuid}
                        options={options}
                        servers={access.servers}
                        packages={access.available_packages}
                        sources={sources}
                        defaultRegion={access.default_region}
                        editable={project.can_edit}
                    />
                </StepFrame>
            )}
            {step.key === 'destination' && (
                <StepFrame
                    number={index + 1}
                    title="Destino"
                    description="Conecte el servidor de destino, instale su agente, despliegue el Consolidador y prepare o compruebe un perfil. Después elija el perfil y los workers."
                    step={step}
                    onBack={back}
                    onNext={next}
                >
                    <RemoteAccessPanel
                        projectUuid={project.uuid}
                        access={access}
                        editable={project.can_edit}
                        role="DESTINATION"
                        title="Conexión del destino"
                        emptyMessage="Guarde los datos básicos para registrar el servidor de destino."
                    />
                    <ConsolidationDestinationForm
                        projectUuid={project.uuid}
                        options={options}
                        servers={access.servers}
                        sources={sources}
                        editable={project.can_edit}
                    />
                </StepFrame>
            )}
            {step.key === 'preflight' && (
                <>
                    {waiting && <WaitingForSources detail={waiting} />}
                    <PreflightStep
                        number={index + 1}
                        project={project}
                        simulated={false}
                        onBack={back}
                        onNext={next}
                    />
                </>
            )}
            {step.key === 'confirmation' && (
                <ConfirmationStep
                    number={index + 1}
                    project={project}
                    remoteConsolidation={type === 'CONSOLIDATE'}
                    waiting={waiting}
                    onBack={back}
                />
            )}
        </div>
    );
}

/** A remote step: its title, its content and the way on, which the step's state blocks. */
function StepFrame({
    number,
    title,
    description,
    step,
    onBack,
    onNext,
    children,
}: {
    number: number;
    title: string;
    description: string;
    step: WizardStep;
    onBack: () => void;
    onNext: () => void;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex flex-wrap items-center gap-2">
                    {number}. {title}
                    <Badge variant={step.complete ? 'default' : 'secondary'}>
                        {step.complete ? 'Completo' : 'Pendiente'}
                    </Badge>
                </CardTitle>
                <p className="text-muted-foreground text-sm">{description}</p>
            </CardHeader>
            <CardContent className="space-y-6">
                {children}
                <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                    <Button type="button" variant="outline" onClick={onBack}>
                        <ArrowLeft /> Atrás
                    </Button>
                    <div className="flex flex-wrap items-center justify-end gap-3">
                        {step.blocker && (
                            <p
                                id={`step-${step.key}-blocker`}
                                className="text-muted-foreground text-sm"
                            >
                                {step.blocker}
                            </p>
                        )}
                        <Button
                            type="button"
                            disabled={!step.complete}
                            aria-describedby={
                                step.blocker
                                    ? `step-${step.key}-blocker`
                                    : undefined
                            }
                            onClick={onNext}
                        >
                            Continuar <ArrowRight />
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

/** A help icon whose explanation opens on hover and on keyboard focus. */
function HelpTip({ label, text }: { label: string; text: string }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    className="text-muted-foreground hover:text-foreground focus-visible:ring-ring inline-flex rounded-full focus-visible:ring-2 focus-visible:outline-none"
                >
                    <CircleHelp className="size-4" />
                </button>
            </TooltipTrigger>
            <TooltipContent className="max-w-xs">{text}</TooltipContent>
        </Tooltip>
    );
}

type SourceDraft = { server_uuid: string | null; role: 'SOURCE'; name: string };

/**
 * COLLECT sources step: the source servers by label, then each one's
 * connection, Recolector and profile, then the collection itself.
 */
function CollectionSourcesStep({
    project,
    access,
}: {
    project: ProjectData;
    access: RemoteAccess;
}) {
    const servers = access.servers.filter((server) => server.role === 'SOURCE');
    // New or removed servers start the forms below again with their rows.
    const registered = servers.map((server) => server.uuid).join('|');

    return (
        <>
            <SourceLabels
                key={registered}
                project={project}
                servers={servers.map((server) => ({
                    server_uuid: server.uuid,
                    role: 'SOURCE',
                    name: server.name,
                }))}
            />
            <RemoteAccessPanel
                projectUuid={project.uuid}
                access={access}
                editable={project.can_edit}
                role="SOURCE"
                title="Conexión de cada origen"
                emptyMessage="Guarde los orígenes para conectar cada servidor."
            />
            {servers.length > 0 && (
                <RemoteCollectionForm
                    key={registered}
                    projectUuid={project.uuid}
                    options={project.options}
                    agentVersion={access.agent_version}
                    servers={access.servers}
                    editable={project.can_edit}
                />
            )}
        </>
    );
}

function SourceLabels({
    project,
    servers,
}: {
    project: ProjectData;
    servers: SourceDraft[];
}) {
    const form = useForm<{ instances: SourceDraft[] }>({
        instances:
            servers.length > 0
                ? servers
                : [{ server_uuid: null, role: 'SOURCE', name: '' }],
    });
    const errors = form.errors as Record<string, string>;
    const rows = form.data.instances;
    const changed =
        JSON.stringify(rows) !== JSON.stringify(servers) ||
        servers.length === 0;

    return (
        <fieldset className="space-y-3 rounded-lg border p-4">
            <legend className="px-1 font-medium">Servidores de origen</legend>
            {rows.map((row, index) => (
                <div
                    key={row.server_uuid ?? `new-${index}`}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="grid min-w-60 flex-1 gap-2">
                        <div className="flex items-center gap-1.5">
                            <Label htmlFor={`source-label-${index}`}>
                                Nombre de referencia del origen {index + 1}
                            </Label>
                            <HelpTip
                                label={`Qué es el nombre de referencia del origen ${index + 1}`}
                                text={sourceLabelHelp}
                            />
                        </div>
                        <Input
                            id={`source-label-${index}`}
                            value={row.name}
                            placeholder={`${project.name} · origen ${index + 1}`}
                            disabled={!project.can_edit}
                            onChange={(event) =>
                                form.setData((data) => ({
                                    instances: data.instances.map(
                                        (item, position) =>
                                            position === index
                                                ? {
                                                      ...item,
                                                      name: event.target.value,
                                                  }
                                                : item,
                                    ),
                                }))
                            }
                        />
                        <InputError
                            message={
                                errors[`instances.${index}.name`] ??
                                errors[`instances.${index}.server_uuid`]
                            }
                        />
                    </div>
                    {project.can_edit && rows.length > 1 && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                form.setData((data) => ({
                                    instances: data.instances.filter(
                                        (_, position) => position !== index,
                                    ),
                                }))
                            }
                        >
                            <Trash2 /> Quitar
                        </Button>
                    )}
                </div>
            ))}
            <InputError message={errors.instances} />
            {project.can_edit && (
                <div className="flex flex-wrap gap-2">
                    {rows.length < 8 && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                form.setData((data) => ({
                                    instances: [
                                        ...data.instances,
                                        {
                                            server_uuid: null,
                                            role: 'SOURCE',
                                            name: '',
                                        },
                                    ],
                                }))
                            }
                        >
                            <Plus /> Agregar origen
                        </Button>
                    )}
                    <Button
                        type="button"
                        disabled={form.processing || !changed}
                        onClick={() =>
                            form.put(
                                `/projects/${project.uuid}/wizard/instances`,
                                { preserveScroll: true },
                            )
                        }
                    >
                        Guardar orígenes
                    </Button>
                </div>
            )}
            {rows.some((row) => row.server_uuid === null) && (
                <p className="text-muted-foreground text-xs">
                    Al guardar, cada origen nuevo aparece abajo para conectar su
                    servidor.
                </p>
            )}
        </fieldset>
    );
}

function BasicsStep({
    project,
    projectTypes,
    destinationLabel = null,
    onNext,
}: {
    project: ProjectData;
    projectTypes: Props['projectTypes'];
    /** With real servers, the label of the destination of a consolidation. */
    destinationLabel?: string | null;
    onNext: () => void;
}) {
    const form = useForm({
        name: project.name,
        type: project.type,
        description: project.description ?? '',
        destination_label: destinationLabel ?? '',
    });
    const labelled =
        destinationLabel !== null && form.data.type === 'CONSOLIDATE';

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
                        form.transform(({ destination_label, ...data }) =>
                            labelled ? { ...data, destination_label } : data,
                        );
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
                        <p className="text-muted-foreground text-xs">
                            {form.data.type === 'COLLECT'
                                ? 'Recolectar: saca un paquete sellado de cada Moodle de origen.'
                                : form.data.type === 'CONSOLIDATE'
                                  ? 'Consolidar: une en un Moodle destino los paquetes de varios orígenes; puede recolectarlos desde aquí.'
                                  : 'Integrar ya no está disponible para proyectos nuevos.'}
                        </p>
                        <InputError message={form.errors.type} />
                    </div>
                    {labelled && (
                        <div className="grid gap-2 md:col-span-2">
                            <div className="flex items-center gap-1.5">
                                <Label htmlFor="wizard-destination-label">
                                    Nombre de referencia del servidor de destino
                                    (opcional)
                                </Label>
                                <HelpTip
                                    label="Qué es el nombre de referencia del servidor de destino"
                                    text={destinationLabelHelp}
                                />
                            </div>
                            <Input
                                id="wizard-destination-label"
                                disabled={!project.can_edit}
                                placeholder={form.data.name || project.name}
                                value={form.data.destination_label}
                                onChange={(event) =>
                                    form.setData(
                                        'destination_label',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={
                                    (form.errors as Record<string, string>)
                                        .destination_label
                                }
                            />
                        </div>
                    )}
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
    simulated,
    onBack,
    onNext,
}: {
    project: ProjectData;
    simulated: boolean;
    onBack: () => void;
    onNext: () => void;
}) {
    const form = useForm<{ instances: InstanceDraft[] }>({
        instances: defaultInstances(project, simulated),
    });
    const fieldErrors = form.errors as Record<string, string>;
    const sourceCount = form.data.instances.filter(
        (instance) => instance.role === 'SOURCE',
    ).length;
    // Collecting from several sources is a remote collection; at most eight of them.
    // A remote consolidation has no source instances: its sources are the packages.
    const remoteConsolidation = project.type === 'CONSOLIDATE' && !simulated;
    const canAddSource =
        (project.type === 'CONSOLIDATE' && simulated) ||
        (project.type === 'COLLECT' && sourceCount < 8);

    const updateInstance = <K extends keyof InstanceDraft>(
        index: number,
        field: K,
        value: InstanceDraft[K],
    ) => {
        // From the latest data, not this render's: quick clicks on several
        // fields would otherwise overwrite each other with a stale copy.
        form.setData((data) => ({
            ...data,
            instances: data.instances.map((instance, current) =>
                current === index ? { ...instance, [field]: value } : instance,
            ),
        }));
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    {simulated ? '2. Instancias simuladas' : '2. Instancias'}
                </CardTitle>
                <p className="text-muted-foreground text-sm">
                    {project.type === 'COLLECT' &&
                        (simulated
                            ? 'Recolectar utiliza exactamente un origen y no requiere destino.'
                            : 'Recolectar utiliza uno o más orígenes, cada uno con su paquete, y no requiere destino.')}
                    {project.type === 'CONSOLIDATE' &&
                        (simulated
                            ? 'Consolidar utiliza dos o más orígenes y referencia un destino ya preparado.'
                            : 'Consolidar registra aquí solo el servidor de destino.')}
                    {project.type === 'INTEGRATE' &&
                        'Integrar utiliza un origen y referencia un Moodle consolidado existente.'}
                </p>
            </CardHeader>
            <CardContent>
                {remoteConsolidation && (
                    <Alert className="mb-4">
                        <CircleDot />
                        <AlertTitle>
                            Los orígenes se eligen en la tarjeta de la
                            consolidación
                        </AlertTitle>
                        <AlertDescription>
                            Aquí solo se registra el servidor de destino. En la
                            tarjeta «Consolidación en el servidor de destino»
                            elija de dónde salen los orígenes: de una
                            recolección ya completada o recolectándolos ahora.
                            {sourceCount > 0 &&
                                ' Quite las instancias origen registradas antes: ya no se usan.'}
                        </AlertDescription>
                    </Alert>
                )}
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
                                    {simulated && (
                                        <Badge variant="secondary">
                                            Simulada
                                        </Badge>
                                    )}
                                </div>
                                {project.can_edit &&
                                    instance.role === 'SOURCE' && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                form.setData((data) => ({
                                                    ...data,
                                                    instances:
                                                        data.instances.filter(
                                                            (_, current) =>
                                                                current !==
                                                                index,
                                                        ),
                                                }))
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
                                        {simulated
                                            ? 'La plataforma sólo guarda esta referencia simulada; no crea el servidor ni la instalación Moodle.'
                                            : 'El administrador mantiene el servidor, su dominio y el proxy TLS; la plataforma conecta con él y prepara Moodle con el Consolidador.'}
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
                                    label={simulated ? 'Host simulado' : 'Host'}
                                    value={instance.server_host}
                                    placeholder={
                                        instance.role === 'SOURCE'
                                            ? 'moodle-origen.test'
                                            : 'moodle-destino.test'
                                    }
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
                                    label={
                                        simulated
                                            ? 'URL simulada'
                                            : 'URL de Moodle'
                                    }
                                    value={instance.base_url}
                                    placeholder={
                                        instance.role === 'SOURCE'
                                            ? 'https://moodle-origen.test'
                                            : 'https://moodle-destino.test'
                                    }
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
                                    placeholder={
                                        instance.role === 'SOURCE'
                                            ? '4.5'
                                            : '5.2'
                                    }
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
                                        {simulated
                                            ? 'Validación simulada aprobada'
                                            : 'Instancia revisada por el administrador'}
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
                                form.setData((data) => ({
                                    ...data,
                                    instances: [
                                        ...data.instances,
                                        blankInstance(
                                            'SOURCE',
                                            project.type,
                                            sourceCount + 1,
                                            simulated,
                                        ),
                                    ],
                                }))
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

function RemoteOptionsStep({
    consolidation,
    configured,
    waiting,
    onBack,
    onNext,
}: {
    consolidation: boolean;
    configured: boolean;
    waiting: string | null;
    onBack: () => void;
    onNext: () => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    3.{' '}
                    {consolidation
                        ? 'Consolidación remota'
                        : 'Recolección remota'}
                </CardTitle>
                <p className="text-muted-foreground text-sm">
                    {consolidation
                        ? 'La configuración de este proyecto se define en la tarjeta «Consolidación en el servidor de destino»: destino, perfil, de dónde salen los orígenes y workers.'
                        : 'La configuración de este proyecto se define en la tarjeta «Recolección desde los servidores de origen»: servidor, perfil, workers y nombre del paquete.'}{' '}
                    No hay escenarios simulados que elegir.
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                {waiting && <WaitingForSources detail={waiting} />}
                {!configured && !waiting && (
                    <Alert>
                        <CircleDot />
                        <AlertTitle>Configuración pendiente</AlertTitle>
                        <AlertDescription>
                            {consolidation
                                ? 'Guarde primero la tarjeta «Consolidación en el servidor de destino», más arriba en esta página.'
                                : 'Guarde primero la tarjeta «Recolección desde los servidores de origen», más arriba en esta página.'}
                        </AlertDescription>
                    </Alert>
                )}
                <div className="flex flex-wrap justify-between gap-3">
                    <Button type="button" variant="outline" onClick={onBack}>
                        <ArrowLeft /> Atrás
                    </Button>
                    <Button type="button" onClick={onNext}>
                        Continuar al preflight
                    </Button>
                </div>
            </CardContent>
        </Card>
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
    number = 4,
    project,
    simulated,
    onBack,
    onNext,
}: {
    number?: number;
    project: ProjectData;
    simulated: boolean;
    onBack: () => void;
    onNext: () => void;
}) {
    const form = useForm({});
    const preflight = project.preflight;
    const fieldErrors = form.errors as Record<string, string>;
    const blocking = blockingChecks(preflight);

    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    {number}. {simulated ? 'Preflight simulado' : 'Preflight'}
                </CardTitle>
                <p className="text-muted-foreground text-sm">
                    Cada comprobación informa un resultado estructurado. Los
                    errores bloquean y las advertencias requieren aceptación.
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                {blocking.length > 0 && (
                    <BlockingChecks checks={blocking} simulated={simulated} />
                )}
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
                            {simulated
                                ? 'Guarde la configuración y ejecute las comprobaciones simuladas.'
                                : 'Guarde la configuración y ejecute las comprobaciones con la evidencia registrada de cada servidor.'}
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
                                        {
                                            // An error blocks the confirmation: stay here, where it is explained.
                                            onSuccess: (page) => {
                                                const fresh = (
                                                    page.props as {
                                                        project?: ProjectData;
                                                    }
                                                ).project?.preflight;
                                                if (
                                                    fresh &&
                                                    blockingChecks(fresh)
                                                        .length === 0
                                                ) {
                                                    onNext();
                                                }
                                            },
                                        },
                                    )
                                }
                            >
                                {preflight
                                    ? 'Repetir preflight'
                                    : 'Ejecutar preflight'}
                            </Button>
                        )}
                        {preflight && (
                            <Button
                                type="button"
                                disabled={blocking.length > 0}
                                aria-describedby={
                                    blocking.length > 0
                                        ? 'preflight-blocked'
                                        : undefined
                                }
                                onClick={onNext}
                            >
                                Revisar confirmación <ArrowRight />
                            </Button>
                        )}
                        {preflight && blocking.length > 0 && (
                            <p
                                id="preflight-blocked"
                                className="text-muted-foreground self-center text-sm"
                            >
                                Corrija los errores de arriba y repita el
                                preflight para continuar.
                            </p>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

const checkResults: Record<PreflightCheck['result'], string> = {
    SUCCESS: 'Correcto',
    WARNING: 'Advertencia',
    ERROR: 'Error',
};

function blockingChecks(preflight: Preflight | null): PreflightCheck[] {
    return preflight?.checks.filter((check) => check.result === 'ERROR') ?? [];
}

/** The errors that block the confirmation, each with what to correct. */
function BlockingChecks({
    checks,
    simulated,
}: {
    checks: PreflightCheck[];
    simulated: boolean;
}) {
    return (
        <Alert variant="destructive">
            <AlertCircle />
            <AlertTitle>
                {checks.length === 1
                    ? 'Hay 1 error que impide confirmar el proyecto'
                    : `Hay ${checks.length} errores que impiden confirmar el proyecto`}
            </AlertTitle>
            <AlertDescription>
                <ul className="list-disc space-y-1 pl-5">
                    {checks.map((check) => (
                        <li key={check.id}>
                            <span className="font-medium">
                                {check.description}:
                            </span>{' '}
                            {check.detail}
                        </li>
                    ))}
                </ul>
                <p className="mt-2">
                    {simulated
                        ? 'Corrija la configuración y vuelva a ejecutar el preflight.'
                        : 'Corrija cada punto (en esta página o en el servidor) y vuelva a ejecutar el preflight.'}
                </p>
            </AlertDescription>
        </Alert>
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
                        {checkResults[check.result]}
                    </Badge>
                </div>
                <p className="text-muted-foreground mt-1 text-sm">
                    {check.detail}
                </p>
            </div>
        </div>
    );
}

function ConfirmationStep({
    number = 5,
    project,
    remoteConsolidation,
    waiting,
    onBack,
}: {
    number?: number;
    project: ProjectData;
    remoteConsolidation: boolean;
    waiting: string | null;
    onBack: () => void;
}) {
    const warnings =
        project.preflight?.checks.filter(
            (check) => check.result === 'WARNING',
        ) ?? [];
    const errors = blockingChecks(project.preflight);
    const form = useForm({
        configuration_version: project.configuration_version,
        accepted_warning_ids:
            project.confirmation?.accepted_warning_ids ?? ([] as string[]),
    });
    const fieldErrors = form.errors as Record<string, string>;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{number}. Confirmación</CardTitle>
                <p className="text-muted-foreground text-sm">
                    Confirmar deja el proyecto en READY. No crea ejecuciones ni
                    inicia procesos.
                </p>
            </CardHeader>
            <CardContent className="space-y-5">
                {waiting && <WaitingForSources detail={waiting} />}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Summary label="Operación" value={project.type_label} />
                    {remoteConsolidation ? (
                        <Summary
                            label="Paquetes de origen"
                            value={String(
                                (project.options as RemoteConsolidationOptions)
                                    .source_packages?.length ?? 0,
                            )}
                        />
                    ) : (
                        <Summary
                            label="Orígenes"
                            value={String(
                                project.instances.filter(
                                    (instance) => instance.role === 'SOURCE',
                                ).length,
                            )}
                        />
                    )}
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
                    <div className="space-y-2">
                        <p className="font-medium">
                            La configuración no puede confirmarse
                        </p>
                        <BlockingChecks
                            checks={errors}
                            simulated={!project.options.mode}
                        />
                    </div>
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
                            disabled={form.processing || errors.length > 0}
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

/** What a consolidation still waits for before it can start, or null. */
function pendingSources(sources: ConsolidationSources | null): string | null {
    const link = sources?.link;
    if (link?.mode === 'COLLECT_NOW' && link.picked_up_at === null) {
        return (
            link.waiting ?? 'Esperando a que la recolección vinculada termine.'
        );
    }
    if (
        sources &&
        ['FETCHING', 'FAILED', 'BLOCKED'].includes(sources.copias.state)
    ) {
        return sources.copias.detail;
    }

    return null;
}

function WaitingForSources({ detail }: { detail: string }) {
    return (
        <Alert className="border-sky-300 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/30">
            <CircleDot />
            <AlertTitle>La consolidación aún no puede iniciar</AlertTitle>
            <AlertDescription>{detail}</AlertDescription>
        </Alert>
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
