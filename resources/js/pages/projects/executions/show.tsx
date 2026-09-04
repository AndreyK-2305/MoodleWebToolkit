import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    ArrowLeft,
    Ban,
    CheckCircle2,
    Circle,
    CircleDashed,
    Clock3,
    Radio,
    RotateCcw,
    ShieldAlert,
    TriangleAlert,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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

type Props = {
    project: {
        uuid: string;
        name: string;
        status: string;
        status_label: string;
    };
    execution: ExecutionData;
    events: FunctionalEvent[];
    canControl: boolean;
    realtimeChannel: string;
};

type CatchUpResponse = {
    execution: ExecutionData;
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
    events: initialEvents,
    canControl,
    realtimeChannel: initialRealtimeChannel,
}: Props) {
    const [execution, setExecution] = useState(initialExecution);
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
        setEvents(replacement.events);
        setRealtimeChannel(initialRealtimeChannel);
        setLive(false);
        setUpdatesPaused(false);
        setReauthOpen(false);
    }, [initialEvents, initialExecution, initialRealtimeChannel]);

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
                setLive(true);
                void catchUp();
            })
            .error(() => setLive(false));

        void catchUp();
        const fallback = window.setInterval(() => void catchUp(), 15_000);

        return () => {
            window.clearInterval(fallback);
            requestGeneration.current += 1;
            inFlightRequest.current?.abort();
            inFlightRequest.current = null;
            catchingUp.current = false;
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

                <Alert className="border-amber-300 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30">
                    <TriangleAlert className="text-amber-700 dark:text-amber-400" />
                    <AlertTitle>Frontera explícita de 1E</AlertTitle>
                    <AlertDescription>
                        El procesamiento satisfactorio termina en RUNNING con
                        verificación y finalización pendientes de 1F. Esta fase
                        no fuerza REVIEW ni COMPLETED.
                    </AlertDescription>
                </Alert>

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

                {canControl && (
                    <ExecutionActions
                        key={execution.uuid}
                        projectUuid={project.uuid}
                        execution={execution}
                        disabled={updatesPaused}
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

function ExecutionActions({
    projectUuid,
    execution,
    disabled,
}: {
    projectUuid: string;
    execution: ExecutionData;
    disabled: boolean;
}) {
    const [cancelKey] = useState(() => crypto.randomUUID());
    const [resumeKey] = useState(() => crypto.randomUUID());
    const [resumeProcessing, setResumeProcessing] = useState(false);
    const cancelForm = useForm({});
    const currentResumePayload = resumePayload(execution);
    const cancellable = ['QUEUED', 'RUNNING', 'WAITING_USER_ACTION'].includes(
        execution.status,
    );

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
