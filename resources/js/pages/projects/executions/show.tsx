import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    ArrowLeft,
    Ban,
    CheckCircle2,
    Circle,
    CircleDashed,
    Clock3,
    Download,
    FileCheck2,
    FolderTree,
    Radio,
    RotateCcw,
    ShieldAlert,
    ShieldCheck,
    TriangleAlert,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { echo } from '@/lib/echo';
import {
    executionScopeReplacement,
    initialTrackingCursor,
    mergeSequencedEvents,
    realtimeLiveState,
    responseBelongsToExecution,
    resumePayload,
} from '@/lib/execution-tracking';
import { cn } from '@/lib/utils';

type Step = {
    key: string;
    name: string;
    position: number;
    status: string;
    progress: number | null;
    started_at: string | null;
    finished_at: string | null;
};

type ConsolidationData = {
    mode: 'REMOTE_CONSOLIDATION';
    server: string | null;
    destination_profile: string | null;
    workers: string | null;
    packages: {
        name: string | null;
        source_id: string | null;
        sha256: string | null;
    }[];
    runs: number;
    operation: {
        uuid: string;
        communication_state: string;
        functional_state: string;
        exit_code: number | null;
        manual_intervention_required: boolean;
    } | null;
    stage: string | null;
    stage_status: string | null;
    message: string | null;
    review_paths: string[];
    workers_status: {
        completed?: number;
        total?: number;
        failed?: number;
        pending?: number;
        workers_effective?: number;
        percent?: number;
    } | null;
    closure: {
        closure_status?: string;
        courses_verified?: number;
        total_courses_verified?: number;
        failed_courses?: number;
        sources?: number | null;
    } | null;
    site_package_sha256: string | null;
    write_locked: boolean;
    observed_at: string | null;
};

type ExecutionData = {
    uuid: string;
    attempt: number;
    status: string;
    progress: number | null;
    started_at: string | null;
    finished_at: string | null;
    last_event_sequence: number;
    steps: Step[];
    resumed_from_execution_uuid: string | null;
    retried_from_execution_uuid: string | null;
    consolidation: ConsolidationData | null;
    collector: {
        mode: 'LABORATORY' | 'REMOTE';
        producer_version: string;
        server?: string | null;
        source_profile?: string | null;
        sources?: {
            server: string | null;
            source_profile: string;
            package_name: string;
            report: {
                stage: string | null;
                completed_courses: number | null;
                total_courses: number | null;
                failed_courses: number | null;
                package_ready: boolean;
            } | null;
            operation: {
                communication_state: string;
                functional_state: string;
            } | null;
        }[];
        source_cleanup?: {
            state: 'CLEANED' | 'PENDING' | 'MANUAL';
            freed_bytes: number | null;
        } | null;
        report?: {
            stage: string | null;
            completed_courses: number | null;
            total_courses: number | null;
            failed_courses: number | null;
            package_ready: boolean;
        } | null;
        resume_supported: boolean;
        configuration_version: number;
        can_fresh_export: boolean;
        operation: {
            uuid: string;
            communication_state: string;
            functional_state: string;
            manual_intervention_required: boolean;
        } | null;
    } | null;
    conflicts: ConflictData[];
    checkpoints: CheckpointData[];
};

type ConflictData = {
    id: number;
    key: string;
    type: string;
    status: string;
    version: number;
    message: string | null;
    allowed_decisions: string[];
    resolved_at: string | null;
    intervention: {
        kind?: string;
        stage?: string | null;
        message?: string | null;
        review_paths?: string[];
        instructions?: string | null;
        callback_uri?: string | null;
        issues?: string[];
        needs?: {
            component: string;
            display_name: string | null;
            observed_state: string | null;
        }[];
        new_plugins?: string[];
        updated_plugins?: string[];
        removed_plugins?: string[];
        technical_issues?: string[];
        checkpoint_status?: string | null;
    } | null;
};

type CheckpointData = {
    id: number;
    step_key: string;
    type: string;
    validated: boolean;
    created_at: string | null;
};

type FunctionalEvent = {
    sequence: number;
    type: string;
    step_key: string | null;
    severity: 'INFO' | 'WARNING' | 'ERROR';
    progress: number | null;
    message: string | null;
    payload: Record<string, unknown> | null;
    created_at: string | null;
};

type AcademicNode = {
    id: string;
    type: 'category' | 'course';
    parent_id: string | null;
    short_name: string | null;
    name: string;
    current_name: string;
    current_parent_id: string | null;
    current_location: string;
    proposed_location: string | null;
    name_changed: boolean;
    categories?: AcademicNode[];
    courses?: AcademicNode[];
};

type VerificationData = {
    id: number;
    key: string;
    proposal_version: number;
    fingerprint: string | null;
    status: string;
    approved: boolean | null;
    summary: string | null;
    details: {
        checks?: Array<{
            key: string;
            severity: string;
            approved: boolean;
            message: string;
            observed: Record<string, unknown>;
        }>;
    } | null;
    checked_at: string | null;
};

type ReviewData = {
    source_packages: Array<{
        uuid: string;
        name: string;
        producer_version: string;
        schema_version: string;
        sha256: string;
        manifest_sha256: string;
        size_bytes: number;
        validation_state: string;
        courses: number | null;
        metadata_state: string | null;
        capabilities: Record<string, string>;
    }>;
    proposal_version: number;
    fingerprint: string | null;
    validated_proposal_version: number | null;
    validated_fingerprint: string | null;
    validation_current: boolean;
    tree: AcademicNode[];
    proposals: Array<{
        id: number;
        version: number;
        operation: string;
        node_id: string;
        node_type: string;
        old_value: Record<string, unknown>;
        new_value: Record<string, unknown>;
        status: string;
        proposed_by: string | null;
        created_at: string | null;
    }>;
    verifications: VerificationData[];
    artifacts: Array<{
        id: number;
        location?: string | null;
        type: string;
        filename: string;
        mime_type: string | null;
        size: number;
        sha256: string;
        created_at: string | null;
    }>;
    completion_summary: Record<string, unknown> | null;
    finalized_by: string | null;
    read_only: boolean;
};

type Props = {
    project: {
        uuid: string;
        name: string;
        type: string;
        status: string;
        status_label: string;
    };
    execution: ExecutionData;
    review: ReviewData;
    events: FunctionalEvent[];
    canControl: boolean;
    realtimeChannel: string;
};

type CatchUpResponse = {
    execution: ExecutionData;
    review: ReviewData;
    events: FunctionalEvent[];
    has_more: boolean;
    realtime_channel: string;
};

export default function ExecutionShow(props: Props) {
    return <ExecutionTracker key={props.execution.uuid} {...props} />;
}

function ExecutionTracker({
    project,
    execution: initialExecution,
    review: initialReview,
    events: initialEvents,
    canControl,
    realtimeChannel: initialRealtimeChannel,
}: Props) {
    const [execution, setExecution] = useState(initialExecution);
    const [review, setReview] = useState(initialReview);
    const [events, setEvents] = useState(initialEvents);
    const [realtimeChannel, setRealtimeChannel] = useState(
        initialRealtimeChannel,
    );
    const [live, setLive] = useState(false);
    const [updatesPaused, setUpdatesPaused] = useState(false);
    const [reauthOpen, setReauthOpen] = useState(false);
    const lastSequence = useRef(
        initialTrackingCursor(initialExecution, initialEvents),
    );
    const catchingUp = useRef(false);
    const catchUpPending = useRef(false);
    const requestGeneration = useRef(0);
    const inFlightRequest = useRef<AbortController | null>(null);
    const trackedExecutionUuid = useRef(initialExecution.uuid);

    useEffect(() => {
        const replacement = executionScopeReplacement(
            trackedExecutionUuid.current,
            initialExecution,
            initialEvents,
        );

        if (replacement === null) {
            return;
        }

        trackedExecutionUuid.current = replacement.uuid;
        requestGeneration.current += 1;
        inFlightRequest.current?.abort();
        inFlightRequest.current = null;
        catchingUp.current = false;
        catchUpPending.current = false;
        lastSequence.current = replacement.cursor;
        setExecution(initialExecution);
        setReview(initialReview);
        setEvents(replacement.events);
        setRealtimeChannel(initialRealtimeChannel);
        setLive(false);
        setUpdatesPaused(false);
        setReauthOpen(false);
    }, [
        initialEvents,
        initialExecution,
        initialRealtimeChannel,
        initialReview,
    ]);

    const mergeEvents = useCallback((incoming: FunctionalEvent[]) => {
        if (incoming.length === 0) {
            return;
        }

        setEvents((current) => {
            const merged = mergeSequencedEvents(current, incoming);
            lastSequence.current = merged.at(-1)?.sequence ?? 0;

            return merged;
        });
    }, []);

    const catchUp = useCallback(async () => {
        if (catchingUp.current) {
            // Preserve notifications arriving while an older HTTP snapshot is
            // in flight. They require another pull once that snapshot is read.
            catchUpPending.current = true;
            return;
        }

        catchingUp.current = true;
        const generation = requestGeneration.current;
        const controller = new AbortController();
        inFlightRequest.current = controller;

        try {
            let hasMore = true;

            while (hasMore) {
                catchUpPending.current = false;
                const response = await fetch(
                    `/projects/${project.uuid}/executions/${initialExecution.uuid}/events?after=${lastSequence.current}`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );

                if (
                    controller.signal.aborted ||
                    generation !== requestGeneration.current
                ) {
                    return;
                }

                if (
                    !response.ok ||
                    response.redirected ||
                    !response.headers
                        .get('content-type')
                        ?.includes('application/json')
                ) {
                    setUpdatesPaused(true);
                    setLive(false);
                    break;
                }

                const data = (await response.json()) as CatchUpResponse;

                if (
                    controller.signal.aborted ||
                    generation !== requestGeneration.current ||
                    !responseBelongsToExecution(initialExecution.uuid, data)
                ) {
                    return;
                }

                const newestSequence = data.events.at(-1)?.sequence;

                if (newestSequence !== undefined) {
                    lastSequence.current = Math.max(
                        lastSequence.current,
                        newestSequence,
                    );
                }

                setExecution(data.execution);
                setReview(data.review);
                setRealtimeChannel(data.realtime_channel);
                setUpdatesPaused(false);
                mergeEvents(data.events);
                hasMore =
                    (data.has_more && data.events.length > 0) ||
                    catchUpPending.current;
            }
        } catch {
            if (
                !controller.signal.aborted &&
                generation === requestGeneration.current
            ) {
                setUpdatesPaused(true);
                setLive(false);
            }
        } finally {
            if (inFlightRequest.current === controller) {
                inFlightRequest.current = null;
                catchingUp.current = false;
                catchUpPending.current = false;
            }
        }
    }, [initialExecution.uuid, mergeEvents, project.uuid]);

    useEffect(() => {
        const channel = echo.private(realtimeChannel);
        const connection = echo.connector.pusher.connection;
        const reflectConnectionState = (state: { current: string }) =>
            setLive((current) => realtimeLiveState(current, state.current));
        const markFailed = () =>
            setLive((current) => realtimeLiveState(current, 'failed'));
        const listener = (payload: {
            execution_uuid?: string;
            event?: FunctionalEvent;
        }) => {
            if (payload.execution_uuid !== initialExecution.uuid) {
                return;
            }

            if (
                payload.event &&
                payload.event.sequence > lastSequence.current + 1
            ) {
                void catchUp();

                return;
            }

            void catchUp();
        };

        channel
            .listen('.execution.event', listener)
            .subscribed(() => {
                setLive((current) => realtimeLiveState(current, 'subscribed'));
                void catchUp();
            })
            .error(markFailed);

        connection.bind('state_change', reflectConnectionState);

        void catchUp();
        const fallback = window.setInterval(() => void catchUp(), 15_000);

        return () => {
            window.clearInterval(fallback);
            requestGeneration.current += 1;
            inFlightRequest.current?.abort();
            inFlightRequest.current = null;
            catchingUp.current = false;
            catchUpPending.current = false;
            connection.unbind('state_change', reflectConnectionState);
            channel.stopListening('.execution.event', listener);
            echo.leave(realtimeChannel);
        };
    }, [catchUp, initialExecution.uuid, realtimeChannel]);

    useEffect(() => {
        if (!updatesPaused) {
            setReauthOpen(false);
        }
    }, [updatesPaused]);

    return (
        <>
            <Head title={`Ejecución #${execution.attempt}`} />
            <div className="space-y-6 p-4 md:p-6">
                <div>
                    <Link
                        href={`/projects/${project.uuid}`}
                        className="text-muted-foreground hover:text-foreground mb-2 inline-flex items-center gap-1 text-sm"
                    >
                        <ArrowLeft className="size-4" /> {project.name}
                    </Link>
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Ejecución #{execution.attempt}
                        </h1>
                        <Badge variant="secondary">{execution.status}</Badge>
                        {execution.collector && (
                            <Badge>
                                {execution.collector.mode === 'REMOTE'
                                    ? 'Servidor de origen · Recolector 7.4.2'
                                    : 'LABORATORY · Recolector 7.4.2'}
                            </Badge>
                        )}
                        {execution.consolidation && (
                            <Badge>Servidor de destino · Consolidador V8</Badge>
                        )}
                        <Badge variant={live ? 'default' : 'outline'}>
                            <Radio className="size-3" />
                            {live
                                ? 'Tiempo real conectado'
                                : 'Recuperación activa'}
                        </Badge>
                    </div>
                </div>

                {execution.status === 'COMPLETED' ? (
                    <Alert className="border-emerald-300 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30">
                        <ShieldCheck className="text-emerald-700 dark:text-emerald-400" />
                        <AlertTitle>
                            Proyecto completado · sólo lectura
                        </AlertTitle>
                        <AlertDescription>
                            El cierre es terminal. La información persistida y
                            los artefactos verificados continúan disponibles
                            para consulta y descarga autorizada.
                        </AlertDescription>
                    </Alert>
                ) : execution.status === 'VERIFYING' ? (
                    <Alert>
                        <CircleDashed />
                        <AlertTitle>Verificación asíncrona activa</AlertTitle>
                        <AlertDescription>
                            Puede cerrar esta pestaña: el worker continúa y la
                            pantalla se reconstruye desde PostgreSQL al volver.
                        </AlertDescription>
                    </Alert>
                ) : execution.status === 'REVIEW' ? (
                    <Alert>
                        <FolderTree />
                        <AlertTitle>
                            {execution.consolidation
                                ? 'Consolidación cerrada en el destino'
                                : execution.collector
                                  ? 'Paquete fuente auditado'
                                  : 'Revisión académica simulada'}
                        </AlertTitle>
                        <AlertDescription>
                            {execution.consolidation
                                ? 'El Consolidador verificó y cerró la consolidación; revise su cierre y la copia integral antes de finalizar.'
                                : execution.collector
                                  ? 'Revise el productor, los hashes y las salidas verificadas antes de finalizar.'
                                  : 'Revise la estructura, proponga únicamente cambios seguros y valide la versión exacta antes de cerrar.'}
                        </AlertDescription>
                    </Alert>
                ) : null}

                {updatesPaused && (
                    <Alert variant="destructive">
                        <ShieldAlert />
                        <AlertTitle>Actualizaciones pausadas</AlertTitle>
                        <AlertDescription className="space-y-3">
                            <p>
                                La sesión autenticada ya no puede recuperar
                                eventos. Lo mostrado se conserva, pero no se
                                presenta como información nueva ni se permiten
                                modificaciones.
                            </p>
                            <Button
                                variant="outline"
                                onClick={() => setReauthOpen(true)}
                            >
                                Reautenticar en esta pantalla
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}

                <Dialog open={reauthOpen} onOpenChange={setReauthOpen}>
                    <DialogContent className="h-[min(760px,90vh)] max-w-2xl grid-rows-[auto_1fr]">
                        <DialogHeader>
                            <DialogTitle>Recuperar acceso</DialogTitle>
                            <DialogDescription>
                                Complete la autenticación y cualquier segundo
                                factor habilitado. El seguimiento se pondrá al
                                día automáticamente sin descartar esta pantalla.
                            </DialogDescription>
                        </DialogHeader>
                        <iframe
                            title="Reautenticación"
                            src="/login"
                            className="border-border size-full rounded-md border"
                        />
                    </DialogContent>
                </Dialog>

                {canControl && execution.status !== 'COMPLETED' && (
                    <ExecutionActions
                        projectUuid={project.uuid}
                        execution={execution}
                        review={review}
                        disabled={updatesPaused}
                    />
                )}

                {execution.consolidation && (
                    <ConsolidationStatus
                        consolidation={execution.consolidation}
                        status={execution.status}
                    />
                )}
                {execution.collector && (
                    <CollectorReview
                        projectUuid={project.uuid}
                        execution={execution}
                        review={review}
                    />
                )}
                {!execution.collector &&
                    !execution.consolidation &&
                    (review.tree.length > 0 ||
                        execution.status === 'COMPLETED') && (
                        <ReviewWorkspace
                            key={`${execution.uuid}:${review.proposal_version}`}
                            projectUuid={project.uuid}
                            execution={execution}
                            review={review}
                            canEdit={canControl && !updatesPaused}
                        />
                    )}

                <div className="grid gap-6 lg:grid-cols-[1fr_1.4fr]">
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Activity className="size-5" /> Estado
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">
                                        Progreso
                                    </span>
                                    <span className="font-medium">
                                        {execution.progress === null
                                            ? 'Indeterminado'
                                            : `${execution.progress} %`}
                                    </span>
                                </div>
                                <div
                                    className="bg-muted h-3 overflow-hidden rounded-full"
                                    role="progressbar"
                                    aria-valuemin={0}
                                    aria-valuemax={100}
                                    aria-valuenow={
                                        execution.progress ?? undefined
                                    }
                                    aria-valuetext={
                                        execution.progress === null
                                            ? 'Progreso indeterminado'
                                            : `${execution.progress} por ciento`
                                    }
                                >
                                    <div
                                        className={cn(
                                            'bg-primary h-full rounded-full transition-[width]',
                                            execution.progress === null &&
                                                'w-1/3 animate-pulse',
                                        )}
                                        style={
                                            execution.progress === null
                                                ? undefined
                                                : {
                                                      width: `${execution.progress}%`,
                                                  }
                                        }
                                    />
                                </div>
                                <p className="text-muted-foreground flex items-center gap-2 text-xs">
                                    <Clock3 className="size-3.5" />
                                    {execution.started_at
                                        ? `Iniciada ${new Date(execution.started_at).toLocaleString()}`
                                        : 'Esperando a que el worker tome la unidad'}
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Pasos persistidos</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {execution.steps.map((step) => (
                                    <div
                                        key={step.key}
                                        className="border-border flex items-start gap-3 rounded-lg border p-3"
                                    >
                                        <StepIcon status={step.status} />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex justify-between gap-3">
                                                <p className="font-medium">
                                                    {step.name}
                                                </p>
                                                <Badge variant="outline">
                                                    {step.status}
                                                </Badge>
                                            </div>
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {step.progress === null
                                                    ? 'Progreso sin medida'
                                                    : `${step.progress} %`}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>Eventos funcionales</CardTitle>
                            <p className="text-muted-foreground text-sm">
                                Secuencias persistidas en PostgreSQL; la recarga
                                recupera cualquier evento perdido.
                            </p>
                        </CardHeader>
                        <CardContent>
                            {events.length === 0 ? (
                                <div className="text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm">
                                    La ejecución está en cola; todavía no
                                    existen eventos funcionales.
                                </div>
                            ) : (
                                <ol className="space-y-3">
                                    {events.map((event) => (
                                        <li
                                            key={event.sequence}
                                            className="border-border rounded-lg border p-4"
                                        >
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-mono text-xs">
                                                    #{event.sequence}
                                                </span>
                                                <Badge
                                                    variant={
                                                        event.severity ===
                                                        'ERROR'
                                                            ? 'destructive'
                                                            : 'outline'
                                                    }
                                                >
                                                    {event.type}
                                                </Badge>
                                                {event.progress !== null && (
                                                    <span className="text-muted-foreground text-xs">
                                                        {event.progress} %
                                                    </span>
                                                )}
                                            </div>
                                            {event.message && (
                                                <p className="mt-2 text-sm">
                                                    {event.message}
                                                </p>
                                            )}
                                            {event.created_at && (
                                                <p className="text-muted-foreground mt-2 text-xs">
                                                    {new Date(
                                                        event.created_at,
                                                    ).toLocaleString()}
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

function StepIcon({ status }: { status: string }) {
    if (status === 'SUCCESS' || status === 'REUSED') {
        return <CheckCircle2 className="mt-0.5 size-5 text-emerald-600" />;
    }

    if (status === 'RUNNING') {
        return <CircleDashed className="text-primary mt-0.5 size-5" />;
    }

    return <Circle className="text-muted-foreground mt-0.5 size-5" />;
}

function ReviewWorkspace({
    projectUuid,
    execution,
    review,
    canEdit,
}: {
    projectUuid: string;
    execution: ExecutionData;
    review: ReviewData;
    canEdit: boolean;
}) {
    const latestVerification = review.verifications[0];

    return (
        <div className="grid gap-6 xl:grid-cols-[1.4fr_1fr]">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <FolderTree className="size-5" /> Previsualización
                        académica
                    </CardTitle>
                    <p className="text-muted-foreground text-sm">
                        Tipo{' '}
                        {execution.status === 'COMPLETED'
                            ? 'cerrado'
                            : 'simulado'}{' '}
                        · versión de propuestas {review.proposal_version}
                    </p>
                </CardHeader>
                <CardContent className="space-y-4">
                    <p className="text-muted-foreground font-mono text-xs break-all">
                        Huella: {review.fingerprint ?? 'pendiente'}
                    </p>
                    <AcademicTree nodes={review.tree} />
                    {canEdit &&
                        execution.status === 'REVIEW' &&
                        review.fingerprint && (
                            <ProposalForm
                                projectUuid={projectUuid}
                                executionUuid={execution.uuid}
                                review={review}
                            />
                        )}
                    {!canEdit && execution.status === 'REVIEW' && (
                        <p className="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                            Acceso de consulta: sólo ADMIN y OPERATOR asignado
                            pueden proponer cambios.
                        </p>
                    )}
                </CardContent>
            </Card>

            <div className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Resultado de verificación</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {latestVerification ? (
                            <>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge
                                        variant={
                                            latestVerification.approved
                                                ? 'default'
                                                : 'destructive'
                                        }
                                    >
                                        {latestVerification.approved
                                            ? 'APROBADA'
                                            : 'RECHAZADA'}
                                    </Badge>
                                    <span className="text-muted-foreground text-xs">
                                        versión{' '}
                                        {latestVerification.proposal_version}
                                    </span>
                                </div>
                                <p className="text-sm">
                                    {latestVerification.summary}
                                </p>
                                {!review.validation_current &&
                                    execution.status === 'REVIEW' && (
                                        <Alert variant="destructive">
                                            <TriangleAlert />
                                            <AlertTitle>
                                                No se puede finalizar
                                            </AlertTitle>
                                            <AlertDescription>
                                                Corrija las propuestas o valide
                                                la versión vigente.
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                <ul className="space-y-2">
                                    {(
                                        latestVerification.details?.checks ?? []
                                    ).map((check) => (
                                        <li
                                            key={check.key}
                                            className="rounded-md border p-3 text-sm"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="font-medium">
                                                    {check.key}
                                                </span>
                                                <Badge
                                                    variant={
                                                        check.approved
                                                            ? 'outline'
                                                            : 'destructive'
                                                    }
                                                >
                                                    {check.approved
                                                        ? 'OK'
                                                        : check.severity}
                                                </Badge>
                                            </div>
                                            <p className="text-muted-foreground mt-1">
                                                {check.message}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                La verificación todavía no produjo resultados.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {review.proposals.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Historial de propuestas</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ol className="space-y-2">
                                {review.proposals.map((proposal) => (
                                    <li
                                        key={proposal.id}
                                        className="rounded-md border p-3 text-sm"
                                    >
                                        <div className="flex justify-between gap-2">
                                            <span className="font-medium">
                                                v{proposal.version} ·{' '}
                                                {proposal.operation}
                                            </span>
                                            <Badge variant="outline">
                                                {proposal.status}
                                            </Badge>
                                        </div>
                                        <p className="text-muted-foreground mt-1 font-mono text-xs">
                                            {proposal.node_id}
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {proposal.proposed_by ?? 'Sistema'}{' '}
                                            ·{' '}
                                            {proposal.created_at
                                                ? new Date(
                                                      proposal.created_at,
                                                  ).toLocaleString()
                                                : ''}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        </CardContent>
                    </Card>
                )}

                {execution.status === 'COMPLETED' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <ShieldCheck className="size-5" /> Resumen final
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm">
                                Finalizado por{' '}
                                {review.finalized_by ?? 'usuario autorizado'}.
                            </p>
                            <p className="text-muted-foreground text-xs">
                                Intento #{execution.attempt}
                                {execution.resumed_from_execution_uuid
                                    ? ` · reanudado desde ${execution.resumed_from_execution_uuid}`
                                    : ' · ejecución inicial'}
                            </p>
                            <div className="space-y-2">
                                {review.artifacts.map((artifact) => (
                                    <ArtifactDownloadLink
                                        key={artifact.id}
                                        projectUuid={projectUuid}
                                        executionUuid={execution.uuid}
                                        artifact={artifact}
                                    />
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </div>
    );
}

function AcademicTree({
    nodes,
    depth = 0,
}: {
    nodes: AcademicNode[];
    depth?: number;
}) {
    return (
        <ul className={cn('space-y-2', depth > 0 && 'ml-4 border-l pl-3')}>
            {nodes.map((node) => (
                <li key={node.id} className="space-y-2">
                    <div className="rounded-md border p-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant="outline">Categoría</Badge>
                            <span className="font-medium">{node.name}</span>
                            {node.name_changed && (
                                <Badge>Nombre propuesto</Badge>
                            )}
                        </div>
                        <p className="text-muted-foreground mt-1 font-mono text-xs">
                            {node.id}
                        </p>
                        <p className="text-muted-foreground mt-1 text-xs">
                            Actual: {node.current_location}
                        </p>
                        {node.proposed_location && (
                            <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                Propuesta: {node.proposed_location}
                            </p>
                        )}
                    </div>
                    {(node.courses ?? []).map((course) => (
                        <div
                            key={course.id}
                            className="bg-muted/40 ml-4 rounded-md border p-3"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant="secondary">Curso</Badge>
                                <span className="font-medium">
                                    {course.name}
                                </span>
                                <span className="text-muted-foreground font-mono text-xs">
                                    {course.short_name}
                                </span>
                            </div>
                            <p className="text-muted-foreground mt-1 font-mono text-xs">
                                {course.id}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Actual: {course.current_location}
                            </p>
                            {course.proposed_location && (
                                <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                    Propuesta: {course.proposed_location}
                                </p>
                            )}
                        </div>
                    ))}
                    <AcademicTree
                        nodes={node.categories ?? []}
                        depth={depth + 1}
                    />
                </li>
            ))}
        </ul>
    );
}

function ProposalForm({
    projectUuid,
    executionUuid,
    review,
}: {
    projectUuid: string;
    executionUuid: string;
    review: ReviewData;
}) {
    const nodes = flattenAcademicTree(review.tree);
    const categories = nodes.filter((node) => node.type === 'category');
    const courses = nodes.filter((node) => node.type === 'course');
    const form = useForm({
        operation: 'RENAME_CATEGORY',
        node_id: categories[0]?.id ?? '',
        value: '',
        expected_version: review.proposal_version,
        base_fingerprint: review.fingerprint ?? '',
    });
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const moving =
        form.data.operation === 'MOVE_CATEGORY' ||
        form.data.operation === 'MOVE_COURSE';
    const eligibleNodes =
        form.data.operation === 'RENAME_CATEGORY' ||
        form.data.operation === 'MOVE_CATEGORY'
            ? categories
            : courses;

    const changeOperation = (operation: string) => {
        const choices =
            operation === 'RENAME_CATEGORY' || operation === 'MOVE_CATEGORY'
                ? categories
                : courses;
        form.setData({
            ...form.data,
            operation,
            node_id: choices[0]?.id ?? '',
            value: '',
        });
    };

    return (
        <form
            className="space-y-3 rounded-lg border border-dashed p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(
                    `/projects/${projectUuid}/executions/${executionUuid}/proposals`,
                    {
                        headers: { 'Idempotency-Key': idempotencyKey },
                        preserveScroll: true,
                    },
                );
            }}
        >
            <p className="font-medium">Proponer ajuste seguro</p>
            <div className="grid gap-3 md:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="proposal-operation">Operación</Label>
                    <select
                        id="proposal-operation"
                        value={form.data.operation}
                        onChange={(event) =>
                            changeOperation(event.target.value)
                        }
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="RENAME_CATEGORY">
                            Renombrar categoría
                        </option>
                        <option value="MOVE_CATEGORY">Mover categoría</option>
                        <option value="MOVE_COURSE">Mover curso</option>
                        <option value="CHANGE_VISIBLE_NAME">
                            Cambiar nombre visible
                        </option>
                    </select>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="proposal-node">Nodo</Label>
                    <select
                        id="proposal-node"
                        value={form.data.node_id}
                        onChange={(event) =>
                            form.setData('node_id', event.target.value)
                        }
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        {eligibleNodes.map((node) => (
                            <option key={node.id} value={node.id}>
                                {node.name} ({node.id})
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="proposal-value">
                    {moving ? 'Categoría de destino' : 'Nuevo nombre visible'}
                </Label>
                {moving ? (
                    <select
                        id="proposal-value"
                        value={form.data.value}
                        onChange={(event) =>
                            form.setData('value', event.target.value)
                        }
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="">Seleccione destino</option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name} ({category.id})
                            </option>
                        ))}
                    </select>
                ) : (
                    <Input
                        id="proposal-value"
                        value={form.data.value}
                        maxLength={160}
                        onChange={(event) =>
                            form.setData('value', event.target.value)
                        }
                    />
                )}
            </div>
            {Object.values(form.errors).map((error) => (
                <p key={error} className="text-destructive text-sm">
                    {error}
                </p>
            ))}
            <Button
                disabled={
                    form.processing || !form.data.node_id || !form.data.value
                }
            >
                Guardar propuesta
            </Button>
        </form>
    );
}

function flattenAcademicTree(nodes: AcademicNode[]): AcademicNode[] {
    return nodes.flatMap((node) => [
        node,
        ...(node.courses ?? []),
        ...flattenAcademicTree(node.categories ?? []),
    ]);
}

function ArtifactDownloadLink({
    projectUuid,
    executionUuid,
    artifact,
}: {
    projectUuid: string;
    executionUuid: string;
    artifact: ReviewData['artifacts'][number];
}) {
    const [key] = useState(() => crypto.randomUUID());

    return (
        <a
            href={`/projects/${projectUuid}/executions/${executionUuid}/artifacts/${artifact.id}/download?key=${encodeURIComponent(key)}`}
            className="hover:bg-muted flex items-center justify-between gap-3 rounded-md border p-3 text-sm"
        >
            <span className="min-w-0">
                <span className="flex items-center gap-2 font-medium">
                    <Download className="size-4" /> {artifact.filename}
                </span>
                <span className="text-muted-foreground mt-1 block font-mono text-xs break-all">
                    {artifact.size} bytes · {artifact.sha256}
                </span>
                {artifact.location && (
                    <span className="text-muted-foreground mt-1 block font-mono text-xs break-all">
                        {artifact.location}
                    </span>
                )}
            </span>
            <Badge variant="outline">{artifact.type}</Badge>
        </a>
    );
}

function ExecutionActions({
    projectUuid,
    execution,
    review,
    disabled,
}: {
    projectUuid: string;
    execution: ExecutionData;
    review: ReviewData;
    disabled: boolean;
}) {
    const [cancelKey] = useState(() => crypto.randomUUID());
    const [resumeKey] = useState(() => crypto.randomUUID());
    const [validateKey] = useState(() => crypto.randomUUID());
    const [finalizeKey] = useState(() => crypto.randomUUID());
    const [freshKey] = useState(() => crypto.randomUUID());
    const [freshOpen, setFreshOpen] = useState(false);
    const [resumeProcessing, setResumeProcessing] = useState(false);
    const cancelForm = useForm({});
    const validateForm = useForm({});
    const finalizeForm = useForm({});
    const freshForm = useForm({
        configuration_version: execution.collector?.configuration_version ?? 0,
        accept_laboratory: false,
    });
    const currentResumePayload =
        execution.collector || execution.consolidation
            ? null
            : resumePayload(execution);
    const cancellable = [
        'QUEUED',
        'RUNNING',
        'WAITING_USER_ACTION',
        'VERIFYING',
    ].includes(execution.status);

    if (execution.status === 'COMPLETED') {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Acciones controladas</CardTitle>
                <p className="text-muted-foreground text-sm">
                    Todas vuelven a validar permisos, estado e idempotencia en
                    el backend.
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                {execution.conflicts
                    .filter((conflict) => conflict.status === 'OPEN')
                    .map((conflict) => (
                        <ConflictAction
                            key={conflict.id}
                            projectUuid={projectUuid}
                            executionUuid={execution.uuid}
                            conflict={conflict}
                            disabled={disabled}
                        />
                    ))}

                <div className="flex flex-wrap gap-2">
                    {execution.collector?.can_fresh_export && (
                        <Button
                            disabled={disabled || freshForm.processing}
                            onClick={() => setFreshOpen(true)}
                        >
                            <RotateCcw />{' '}
                            {execution.collector?.mode === 'REMOTE'
                                ? 'Nueva exportación'
                                : 'Nueva exportación LAB'}
                        </Button>
                    )}
                    {cancellable && (
                        <Button
                            variant="destructive"
                            disabled={disabled || cancelForm.processing}
                            onClick={() =>
                                cancelForm.post(
                                    `/projects/${projectUuid}/executions/${execution.uuid}/cancel`,
                                    {
                                        headers: {
                                            'Idempotency-Key': cancelKey,
                                        },
                                        preserveScroll: true,
                                    },
                                )
                            }
                        >
                            <Ban /> Solicitar cancelación
                        </Button>
                    )}
                    {execution.status === 'CANCELLING' && (
                        <Badge variant="outline">
                            Cancelación pendiente de confirmación segura
                        </Badge>
                    )}
                    {execution.status === 'FAILED' &&
                        currentResumePayload !== null && (
                            <Button
                                disabled={disabled || resumeProcessing}
                                onClick={() =>
                                    router.post(
                                        `/projects/${projectUuid}/executions/${execution.uuid}/resume`,
                                        currentResumePayload,
                                        {
                                            headers: {
                                                'Idempotency-Key': resumeKey,
                                            },
                                            onStart: () =>
                                                setResumeProcessing(true),
                                            onFinish: () =>
                                                setResumeProcessing(false),
                                        },
                                    )
                                }
                            >
                                <RotateCcw /> Reanudar desde checkpoint
                            </Button>
                        )}
                    {execution.status === 'REVIEW' &&
                        !execution.collector &&
                        !execution.consolidation &&
                        review.proposals.length > 0 && (
                            <Button
                                disabled={disabled || validateForm.processing}
                                onClick={() =>
                                    validateForm.post(
                                        `/projects/${projectUuid}/executions/${execution.uuid}/validate`,
                                        {
                                            headers: {
                                                'Idempotency-Key': validateKey,
                                            },
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                <FileCheck2 /> Validar ajustes
                            </Button>
                        )}
                    {execution.status === 'REVIEW' &&
                        review.validation_current && (
                            <Button
                                disabled={disabled || finalizeForm.processing}
                                onClick={() =>
                                    finalizeForm.post(
                                        `/projects/${projectUuid}/executions/${execution.uuid}/finalize`,
                                        {
                                            headers: {
                                                'Idempotency-Key': finalizeKey,
                                            },
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                <ShieldCheck /> Finalizar
                            </Button>
                        )}
                </div>
                {Object.values(freshForm.errors).map((error) => (
                    <p
                        key={error}
                        role="alert"
                        className="text-destructive text-sm"
                    >
                        {error}
                    </p>
                ))}
                <Dialog open={freshOpen} onOpenChange={setFreshOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {execution.collector?.mode === 'REMOTE'
                                    ? 'Nueva exportación desde el servidor de origen'
                                    : 'Nueva exportación de laboratorio'}
                            </DialogTitle>
                            <DialogDescription>
                                El Recolector requiere otro intento desde cero.
                                Se conservará toda la evidencia anterior y se
                                volverá a comprobar la configuración actual.
                            </DialogDescription>
                        </DialogHeader>
                        <label className="flex gap-2">
                            <input
                                type="checkbox"
                                checked={freshForm.data.accept_laboratory}
                                onChange={(event) =>
                                    freshForm.setData(
                                        'accept_laboratory',
                                        event.target.checked,
                                    )
                                }
                            />
                            {execution.collector?.mode === 'REMOTE'
                                ? 'Acepto una nueva exportación completa sobre el Moodle de origen'
                                : 'Acepto una nueva exportación sobre Moodle sintético'}
                        </label>
                        <Button
                            disabled={
                                disabled ||
                                freshForm.processing ||
                                !freshForm.data.accept_laboratory
                            }
                            onClick={() => {
                                freshForm.transform((data) => ({
                                    ...data,
                                    configuration_version:
                                        execution.collector
                                            ?.configuration_version ?? 0,
                                }));
                                freshForm.post(
                                    `/projects/${projectUuid}/executions/${execution.uuid}/fresh-export`,
                                    {
                                        headers: {
                                            'Idempotency-Key': freshKey,
                                        },
                                        onSuccess: () => setFreshOpen(false),
                                    },
                                );
                            }}
                        >
                            Confirmar nueva exportación
                        </Button>
                    </DialogContent>
                </Dialog>
            </CardContent>
        </Card>
    );
}

const consolidationStages: Record<string, string> = {
    '01-importar-paquetes': 'Importar paquetes',
    '02-oauth2': 'Google OAuth2',
    '03-plugins': 'Plugins',
    '03b-oauth2-final': 'Confirmar OAuth y proxy',
    '04-identidades': 'Identidades',
    '05-plan-usuarios': 'Plan de usuarios',
    '05b-readiness': 'Readiness',
    '06-aplicar-usuarios': 'Aplicar usuarios',
    '07-verificar-usuarios': 'Verificar usuarios',
    '08-plan-piloto': 'Plan del piloto',
    '09-aplicar-piloto': 'Restaurar el piloto',
    '09b-themes-piloto': 'Temas del piloto',
    '10-verificar-piloto': 'Verificar el piloto',
    '11-plan-lote': 'Plan del lote',
    '12-preparar-lote': 'Preparar el lote',
    '13-aplicar-lote': 'Restaurar el lote',
    '13b-themes-lote': 'Temas del lote',
    '14-verificar-lote': 'Verificar el lote',
    '15-cierre': 'Cierre',
    '16-paquete-sitio': 'Copia integral',
    completed: 'Consolidación cerrada',
};

/** The Consolidator's own state on the destination, as its last report left it. */
function ConsolidationStatus({
    consolidation,
    status,
}: {
    consolidation: ConsolidationData;
    status: string;
}) {
    const workers = consolidation.workers_status;
    const closure = consolidation.closure;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Consolidador en el destino</CardTitle>
                <p className="text-muted-foreground text-sm">
                    {consolidation.server ?? 'Servidor de destino'} · perfil{' '}
                    {consolidation.destination_profile} · workers{' '}
                    {consolidation.workers} · corrida {consolidation.runs}
                </p>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">Etapa:</span>
                    <span>
                        {consolidation.stage
                            ? (consolidationStages[consolidation.stage] ??
                              consolidation.stage)
                            : 'sin reporte todavía'}
                    </span>
                    {consolidation.stage_status && (
                        <Badge variant="outline">
                            {consolidation.stage_status}
                        </Badge>
                    )}
                    {consolidation.write_locked && (
                        <Badge variant="secondary">
                            El destino ya recibió escrituras
                        </Badge>
                    )}
                </div>
                {status === 'WAITING_USER_ACTION' && (
                    <Alert>
                        <ShieldAlert />
                        <AlertTitle>
                            Intervención requerida en el destino
                        </AlertTitle>
                        <AlertDescription className="space-y-2">
                            <p>
                                {consolidation.message ?? 'Revise el destino.'}
                            </p>
                            {consolidation.review_paths.length > 0 && (
                                <p className="text-xs">
                                    En el servidor, revise:{' '}
                                    {consolidation.review_paths.join(', ')}
                                </p>
                            )}
                            <p className="text-xs">
                                Cuando termine, confirme la intervención en
                                «Acciones controladas».
                            </p>
                        </AlertDescription>
                    </Alert>
                )}
                {status !== 'WAITING_USER_ACTION' && consolidation.message && (
                    <p className="text-muted-foreground">
                        {consolidation.message}
                    </p>
                )}
                {workers && typeof workers.total === 'number' && (
                    <p>
                        Cursos del lote: {workers.completed ?? 0} de{' '}
                        {workers.total} · fallidos {workers.failed ?? 0} ·
                        workers {workers.workers_effective ?? '—'}
                    </p>
                )}
                {closure && (
                    <p>
                        Cierre: {closure.closure_status ?? '—'} · cursos
                        verificados{' '}
                        {closure.total_courses_verified ??
                            closure.courses_verified ??
                            '—'}{' '}
                        · diferencias {closure.failed_courses ?? '—'}
                    </p>
                )}
                {consolidation.site_package_sha256 && (
                    <p className="font-mono text-xs break-all">
                        Copia integral SHA-256:{' '}
                        {consolidation.site_package_sha256}
                    </p>
                )}
                <div>
                    <p className="font-medium">Paquetes</p>
                    <ul className="text-muted-foreground grid gap-1 text-xs">
                        {consolidation.packages.map((item) => (
                            <li key={item.sha256 ?? item.name}>
                                {item.name} ({item.source_id}) ·{' '}
                                <span className="font-mono">
                                    {item.sha256?.slice(0, 12)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            </CardContent>
        </Card>
    );
}

function CollectorReview({
    projectUuid,
    execution,
    review,
}: {
    projectUuid: string;
    execution: ExecutionData;
    review: ReviewData;
}) {
    const operation = execution.collector?.operation;
    const remote = execution.collector?.mode === 'REMOTE';
    const report = execution.collector?.report;
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    {remote
                        ? 'Recolector en el servidor de origen'
                        : 'Recolector real · Laboratorio'}
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="text-muted-foreground text-sm">
                    El proceso continúa aunque cierre esta pantalla. El
                    seguimiento recupera los eventos persistidos al volver.
                </p>
                {remote && (
                    <ul className="space-y-1 text-sm">
                        {(execution.collector?.sources ?? []).map((source) => (
                            <li key={source.package_name}>
                                <span className="font-medium">
                                    {source.server ?? '—'}
                                </span>{' '}
                                · perfil {source.source_profile} · paquete{' '}
                                {source.package_name}
                                {source.report &&
                                    ` · cursos ${source.report.completed_courses ?? '—'} de ${source.report.total_courses ?? '—'}`}
                                {source.report?.package_ready &&
                                    ' · paquete listo'}
                                {source.operation &&
                                    ` · ${source.operation.communication_state} · ${source.operation.functional_state}`}
                            </li>
                        ))}
                    </ul>
                )}
                {remote && execution.collector?.source_cleanup && (
                    <p className="text-sm">
                        {execution.collector.source_cleanup.state === 'CLEANED'
                            ? `Espacio liberado en el servidor de origen: ${((execution.collector.source_cleanup.freed_bytes ?? 0) / 1048576).toFixed(1)} MiB; se conservan la evidencia y los registros.`
                            : execution.collector.source_cleanup.state ===
                                'MANUAL'
                              ? 'No se pudo liberar el espacio en el servidor de origen tras varios intentos; requiere revisión.'
                              : 'Liberando el espacio de la exportación en el servidor de origen…'}
                    </p>
                )}
                {remote && report && (
                    <p className="text-sm">
                        Etapa {report.stage ?? 'sin determinar'} · cursos{' '}
                        {report.completed_courses ?? '—'} de{' '}
                        {report.total_courses ?? '—'}
                        {report.failed_courses
                            ? ` · ${report.failed_courses} con error`
                            : ''}
                        {report.package_ready ? ' · paquete listo' : ''}
                    </p>
                )}
                {remote && execution.status === 'REVIEW' && (
                    <p className="text-sm">
                        El paquete se validó en el origen y la plataforma lo
                        verificó en el almacenamiento. Al finalizar se vuelve a
                        comprobar en S3 y se generan los informes de cierre.
                    </p>
                )}
                {remote && (
                    <p className="text-muted-foreground text-sm">
                        El paquete queda publicado en el bucket de la
                        plataforma, a disposición del administrador: la
                        herramienta no lo borra ni lo expira. Solo un
                        administrador puede descargarlo, mediante un enlace
                        firmado que vence en 15 minutos.
                    </p>
                )}
                {execution.retried_from_execution_uuid && (
                    <p className="text-sm">
                        Nueva exportación desde{' '}
                        {execution.retried_from_execution_uuid}
                    </p>
                )}
                {operation && (
                    <p className="text-sm">
                        Operación {operation.uuid} ·{' '}
                        {operation.communication_state} ·{' '}
                        {operation.functional_state}
                    </p>
                )}
                {operation?.manual_intervention_required && (
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertTitle>Reconciliación pendiente</AlertTitle>
                        <AlertDescription>
                            {remote
                                ? 'La evidencia de la operación remota requiere revisión autorizada. El estado disponible no acredita éxito ni permite volver a lanzarla.'
                                : 'La evidencia o limpieza requiere revisión autorizada en el laboratorio. El estado disponible no acredita éxito ni permite volver a lanzar la operación.'}
                        </AlertDescription>
                    </Alert>
                )}
                {['FAILED', 'CANCELLED'].includes(execution.status) && (
                    <p className="text-sm">
                        {remote
                            ? 'No existe un checkpoint seguro para este paquete. Puede iniciar una nueva ejecución desde el proyecto; la evidencia anterior se conserva.'
                            : 'No existe un checkpoint seguro para este paquete. Puede solicitar una nueva exportación conservando la evidencia anterior.'}
                    </p>
                )}
                {review.source_packages.map((source) => (
                    <div
                        key={source.uuid}
                        className="space-y-2 rounded-md border p-4"
                    >
                        <div className="flex flex-wrap gap-2">
                            <strong>{source.name}</strong>
                            <Badge variant="outline">
                                SourcePackage · {source.validation_state}
                            </Badge>
                        </div>
                        <p className="text-sm">
                            Productor {source.producer_version} · Schema{' '}
                            {source.schema_version} · {source.courses ?? '—'}{' '}
                            cursos · {source.size_bytes} bytes
                        </p>
                        <p className="text-muted-foreground font-mono text-xs break-all">
                            Paquete: {source.sha256}
                        </p>
                        <p className="text-muted-foreground font-mono text-xs break-all">
                            Manifiesto: {source.manifest_sha256}
                        </p>
                        <p className="text-sm">
                            Metadata {source.metadata_state ?? 'Sin determinar'}{' '}
                            · Inventario visual{' '}
                            {source.capabilities.theme_inventory ??
                                'Sin acreditar'}
                        </p>
                    </div>
                ))}
                <div className="space-y-2">
                    {review.artifacts.map((artifact) => (
                        <ArtifactDownloadLink
                            key={artifact.id}
                            projectUuid={projectUuid}
                            executionUuid={execution.uuid}
                            artifact={artifact}
                        />
                    ))}
                </div>
                {review.read_only && execution.status === 'COMPLETED' && (
                    <p className="text-sm">
                        Paquete e informes conservados en modo de consulta.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function ConflictAction({
    projectUuid,
    executionUuid,
    conflict,
    disabled,
}: {
    projectUuid: string;
    executionUuid: string;
    conflict: ConflictData;
    disabled: boolean;
}) {
    const [key] = useState(() => crypto.randomUUID());
    const decision = conflict.allowed_decisions[0] ?? '';
    const form = useForm({
        decision,
        conflict_version: conflict.version,
    });

    if (conflict.intervention) {
        return (
            <ConsolidationIntervention
                projectUuid={projectUuid}
                executionUuid={executionUuid}
                conflict={conflict}
                disabled={disabled}
            />
        );
    }

    return (
        <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30">
            <p className="font-medium">
                {conflict.type === 'WARNING_ACCEPTANCE'
                    ? 'Advertencia pendiente'
                    : 'Intervención pendiente'}
            </p>
            <p className="text-muted-foreground mt-1 text-sm">
                {conflict.message}
            </p>
            <Button
                className="mt-3"
                disabled={disabled || form.processing || decision === ''}
                onClick={() =>
                    form.post(
                        `/projects/${projectUuid}/executions/${executionUuid}/conflicts/${conflict.id}/resolve`,
                        {
                            headers: { 'Idempotency-Key': key },
                            preserveScroll: true,
                        },
                    )
                }
            >
                {decision === 'ACCEPT'
                    ? 'Aceptar advertencia y continuar'
                    : 'Confirmar intervención y continuar'}
            </Button>
        </div>
    );
}

const interventionTitles: Record<string, string> = {
    OAUTH2_CONFIGURATION: 'Configurar Google OAuth2 en el Moodle destino',
    PLUGIN_INTERVENTION: 'Plugins requeridos por los orígenes',
    CONSOLIDATOR_INTERVENTION: 'Intervención solicitada por el Consolidador',
};

/**
 * A typed intervention of the Consolidator: its own guidance for the stage and
 * the decisions it allows. Each decision is versioned, idempotent and audited.
 */
function ConsolidationIntervention({
    projectUuid,
    executionUuid,
    conflict,
    disabled,
}: {
    projectUuid: string;
    executionUuid: string;
    conflict: ConflictData;
    disabled: boolean;
}) {
    const [key] = useState(() => crypto.randomUUID());
    const [confirmed, setConfirmed] = useState(false);
    const [processing, setProcessing] = useState(false);
    const details = conflict.intervention ?? {};
    const plugins = conflict.type === 'PLUGIN_INTERVENTION';
    const decide = (decision: string) =>
        router.post(
            `/projects/${projectUuid}/executions/${executionUuid}/conflicts/${conflict.id}/resolve`,
            { decision, conflict_version: conflict.version },
            {
                headers: { 'Idempotency-Key': `${key}-${decision}` },
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    const list = (title: string, items: string[] | undefined) =>
        items && items.length > 0 ? (
            <div>
                <p className="text-xs font-medium">{title}</p>
                <ul className="text-xs">
                    {items.map((item) => (
                        <li key={item} className="font-mono">
                            {item}
                        </li>
                    ))}
                </ul>
            </div>
        ) : null;

    return (
        <div className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950/30">
            <p className="font-medium">
                {interventionTitles[conflict.type] ?? 'Intervención pendiente'}
            </p>
            {details.message && (
                <p className="text-muted-foreground">{details.message}</p>
            )}
            {details.callback_uri && (
                <p className="text-xs">
                    URI de redirección autorizada en Google Cloud:{' '}
                    <code className="break-all">{details.callback_uri}</code>
                </p>
            )}
            {details.instructions && (
                <details open>
                    <summary className="cursor-pointer text-xs font-medium">
                        Instrucciones del Consolidador
                    </summary>
                    <pre className="bg-background mt-1 max-h-72 overflow-auto rounded p-2 text-xs whitespace-pre-wrap">
                        {details.instructions}
                    </pre>
                </details>
            )}
            {list('Pendiente según la última validación', details.issues)}
            {details.needs && details.needs.length > 0 && (
                <div>
                    <p className="text-xs font-medium">
                        Componentes que necesitan los orígenes
                    </p>
                    <ul className="text-xs">
                        {details.needs.map((need) => (
                            <li key={need.component}>
                                {need.display_name ?? need.component}{' '}
                                <span className="font-mono">
                                    [{need.component}]
                                </span>
                                {need.observed_state &&
                                    ` · ${need.observed_state}`}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {list('Nuevos en el destino', details.new_plugins)}
            {list('Actualizados', details.updated_plugins)}
            {list('Eliminados', details.removed_plugins)}
            {list('Errores de la validación técnica', details.technical_issues)}
            {details.review_paths && details.review_paths.length > 0 && (
                <p className="text-muted-foreground text-xs">
                    En el servidor: {details.review_paths.join(', ')}
                </p>
            )}
            <label className="flex gap-2 text-xs">
                <input
                    type="checkbox"
                    checked={confirmed}
                    onChange={(event) => setConfirmed(event.target.checked)}
                />
                {plugins
                    ? 'Revisé que los plugins instalados cubren los datos de los orígenes'
                    : 'Completé la intervención indicada en el Moodle destino'}
            </label>
            <div className="flex flex-wrap gap-2">
                {conflict.allowed_decisions.includes('ACCEPT') && (
                    <Button
                        disabled={disabled || processing || !confirmed}
                        onClick={() => decide('ACCEPT')}
                    >
                        Aprobar plugins y continuar
                    </Button>
                )}
                {conflict.allowed_decisions.includes('CONFIRM_COMPLETED') && (
                    <Button
                        variant={plugins ? 'outline' : 'default'}
                        disabled={disabled || processing || !confirmed}
                        onClick={() => decide('CONFIRM_COMPLETED')}
                    >
                        {plugins
                            ? 'Revalidar plugins'
                            : 'Confirmar intervención y continuar'}
                    </Button>
                )}
            </div>
        </div>
    );
}

ExecutionShow.layout = {
    breadcrumbs: [
        { title: 'Proyectos', href: '/projects' },
        { title: 'Ejecución', href: '#' },
    ],
};
