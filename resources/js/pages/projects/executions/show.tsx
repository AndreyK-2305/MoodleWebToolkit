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
            return;
        }

        catchingUp.current = true;
        const generation = requestGeneration.current;
        const controller = new AbortController();
        inFlightRequest.current = controller;

        try {
            let hasMore = true;

            while (hasMore) {
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
                hasMore = data.has_more && data.events.length > 0;
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
                        <AlertTitle>Revisión académica simulada</AlertTitle>
                        <AlertDescription>
                            Revise la estructura, proponga únicamente cambios
                            seguros y valide la versión exacta antes de cerrar.
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

                {(review.tree.length > 0 ||
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
    const [resumeProcessing, setResumeProcessing] = useState(false);
    const cancelForm = useForm({});
    const validateForm = useForm({});
    const finalizeForm = useForm({});
    const currentResumePayload = resumePayload(execution);
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

ExecutionShow.layout = {
    breadcrumbs: [
        { title: 'Proyectos', href: '/projects' },
        { title: 'Ejecución', href: '#' },
    ],
};
