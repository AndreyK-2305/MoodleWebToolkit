import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    ArrowLeft,
    CheckCircle2,
    Circle,
    CircleDashed,
    Clock3,
    Radio,
    TriangleAlert,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { echo } from '@/lib/echo';
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
};

type CatchUpResponse = {
    execution: ExecutionData;
    events: FunctionalEvent[];
    has_more: boolean;
};

export default function ExecutionShow({
    project,
    execution: initialExecution,
    events: initialEvents,
}: Props) {
    const [execution, setExecution] = useState(initialExecution);
    const [events, setEvents] = useState(initialEvents);
    const [live, setLive] = useState(false);
    const lastSequence = useRef(
        initialEvents.at(-1)?.sequence ?? initialExecution.last_event_sequence,
    );
    const catchingUp = useRef(false);

    const mergeEvents = useCallback((incoming: FunctionalEvent[]) => {
        if (incoming.length === 0) {
            return;
        }

        setEvents((current) => {
            const indexed = new Map(
                [...current, ...incoming].map((event) => [
                    event.sequence,
                    event,
                ]),
            );
            const merged = [...indexed.values()].sort(
                (left, right) => left.sequence - right.sequence,
            );
            lastSequence.current = merged.at(-1)?.sequence ?? 0;

            return merged;
        });
    }, []);

    const catchUp = useCallback(async () => {
        if (catchingUp.current) {
            return;
        }

        catchingUp.current = true;

        try {
            let hasMore = true;

            while (hasMore) {
                const response = await fetch(
                    `/projects/${project.uuid}/executions/${initialExecution.uuid}/events?after=${lastSequence.current}`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    },
                );

                if (!response.ok) {
                    break;
                }

                const data = (await response.json()) as CatchUpResponse;
                const newestSequence = data.events.at(-1)?.sequence;

                if (newestSequence !== undefined) {
                    lastSequence.current = Math.max(
                        lastSequence.current,
                        newestSequence,
                    );
                }

                setExecution(data.execution);
                mergeEvents(data.events);
                hasMore = data.has_more && data.events.length > 0;
            }
        } finally {
            catchingUp.current = false;
        }
    }, [initialExecution.uuid, mergeEvents, project.uuid]);

    useEffect(() => {
        const channel = echo.private(`projects.${project.uuid}`);
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
            channel.stopListening('.execution.event', listener);
            echo.leave(`projects.${project.uuid}`);
        };
    }, [catchUp, initialExecution.uuid, project.uuid]);

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
                    <AlertTitle>Punto de entrega de 1D</AlertTitle>
                    <AlertDescription>
                        El worker completa una sola unidad simulada y conserva
                        la ejecución en RUNNING. Las unidades restantes
                        continuarán en cortes posteriores; aquí no se fuerza
                        REVIEW ni COMPLETED.
                    </AlertDescription>
                </Alert>

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
    if (status === 'SUCCESS') {
        return <CheckCircle2 className="mt-0.5 size-5 text-emerald-600" />;
    }

    if (status === 'RUNNING') {
        return <CircleDashed className="text-primary mt-0.5 size-5" />;
    }

    return <Circle className="text-muted-foreground mt-0.5 size-5" />;
}

ExecutionShow.layout = {
    breadcrumbs: [
        { title: 'Proyectos', href: '/projects' },
        { title: 'Ejecución', href: '#' },
    ],
};
