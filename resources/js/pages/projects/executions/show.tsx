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
import { CategoryMapEditor } from '@/components/category-map-editor';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { CategoryMapDetails } from '@/lib/category-map';
import { echo } from '@/lib/echo';
import {
    executionScopeReplacement,
    initialTrackingCursor,
    mergeSequencedEvents,
    progressDisplay,
    realtimeLiveState,
    responseBelongsToExecution,
    resumePayload,
} from '@/lib/execution-tracking';
import {
    chooseCanonical,
    confidenceLabel,
    decisionGap,
    identityFacts,
    identityMembers,
    initialDecision,
    evidentCases,
    keepsDuplicateEmail,
    matchReasons,
    memberLabel,
    mergeEvident,
    MIN_JUSTIFICATION,
    resolutionLabels,
} from '@/lib/identity-review';
import type {
    IdentityCandidate,
    IdentityDecision,
} from '@/lib/identity-review';
import {
    answersSentFor,
    conflictSignature,
    interventionPanel,
} from '@/lib/intervention-state';
import type {
    AnsweredConflict,
    InterventionPanel,
} from '@/lib/intervention-state';
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
    server_message: string | null;
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
    backup: {
        state: string | null;
        sha256: string | null;
        size: number | null;
        location: { region: string; bucket: string; key: string } | null;
        released: number | null;
        freed_bytes: number | null;
    };
    can_backup: boolean;
    configuration_version: number | null;
    can_retry: boolean;
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
    intervention:
        | (CategoryMapDetails & {
              kind?: string;
              stage?: string | null;
              message?: string | null;
              server_message?: string | null;
              review_paths?: string[];
              instructions?: string | null;
              server_instructions?: string | null;
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
              plugins_none?: boolean;
              candidates?: IdentityCandidate[];
              answer_summary?: AnswerSummary;
              candidate_total?: number | null;
              candidates_complete?: boolean;
              themes?: string[];
              theme_selected?: string | null;
              theme_default?: string | null;
              legacy_sources?: {
                  source: string;
                  collector_version: string | null;
              }[];
              legacy_accepted?: boolean;
              unknown?: string[];
              inspections?: Record<
                  string,
                  {
                      state: string;
                      repository: string;
                      commit: string;
                      message: string | null;
                  }
              >;
              proposals?: Record<
                  string,
                  {
                      version: number;
                      requires: number | null;
                      release: string | null;
                      path: string | null;
                      tree_sha256: string | null;
                      dependencies: string[];
                  }
              >;
          })
        | null;
};

type AnswerSummary = {
    answered_at: string | null;
    merge: number;
    keep_separate: number;
    ignore: number;
    theme: string | null;
    promoted: number;
    categories?: number;
    merged?: number;
};

const resolutionOptions = Object.entries(resolutionLabels) as [
    Exclude<IdentityDecision['resolution'], ''>,
    { label: string; help: string },
][];

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

const CATCH_UP_TIMEOUT_MS = 30_000;

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
    const adoptedSnapshot = useRef(initialExecution);

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
        adoptedSnapshot.current = initialExecution;
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
                        cache: 'no-store',
                        headers: { Accept: 'application/json' },
                        // A request that never settles would otherwise hold
                        // catchingUp and silence every later notification.
                        signal: AbortSignal.any([
                            controller.signal,
                            AbortSignal.timeout(CATCH_UP_TIMEOUT_MS),
                        ]),
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
        } catch (error) {
            if (
                controller.signal.aborted ||
                generation !== requestGeneration.current
            ) {
                return;
            }

            // A slow server is not an expired session: release the slot and
            // let the next notification or poll try again.
            if (
                !(error instanceof DOMException) ||
                error.name !== 'TimeoutError'
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
        if (adoptedSnapshot.current === initialExecution) {
            return;
        }

        adoptedSnapshot.current = initialExecution;

        if (trackedExecutionUuid.current !== initialExecution.uuid) {
            return;
        }

        // Inertia 3 keeps component state on router.post/router.reload, so a
        // redirect back here brings fresh props that useState would ignore.
        if (initialExecution.last_event_sequence >= lastSequence.current) {
            setExecution(initialExecution);
            setReview(initialReview);
            setRealtimeChannel(initialRealtimeChannel);
            mergeEvents(initialEvents);
        }

        void catchUp();
    }, [
        catchUp,
        initialEvents,
        initialExecution,
        initialRealtimeChannel,
        initialReview,
        mergeEvents,
    ]);

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
        // Hidden tabs throttle timers and a back/forward cache restore keeps
        // the old screen: both must ask the server again when shown.
        const refreshWhenVisible = () => {
            if (document.visibilityState === 'visible') {
                void catchUp();
            }
        };
        const refreshOnRestore = (event: PageTransitionEvent) => {
            if (event.persisted) {
                void catchUp();
            }
        };
        document.addEventListener('visibilitychange', refreshWhenVisible);
        window.addEventListener('pageshow', refreshOnRestore);

        return () => {
            window.clearInterval(fallback);
            document.removeEventListener(
                'visibilitychange',
                refreshWhenVisible,
            );
            window.removeEventListener('pageshow', refreshOnRestore);
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

    const backupRunning = execution.consolidation?.backup.state === 'RUNNING';

    useEffect(() => {
        if (!backupRunning) {
            return;
        }

        // The upload ends on the destination; follow it closely until the
        // platform registers (or rejects) the copy.
        const follow = window.setInterval(() => void catchUp(), 5_000);
        // In the 2026-10-24 test the state stayed on «Respaldando…» until a manual
        // reload: the page props are read again too, as a reload would.
        const reload = window.setInterval(
            () =>
                router.reload({
                    only: ['execution', 'review', 'events'],
                }),
            20_000,
        );

        return () => {
            window.clearInterval(follow);
            window.clearInterval(reload);
        };
    }, [backupRunning, catchUp]);

    const panel = interventionPanel(execution, events);
    const preparing = panel.kind === 'preparing';

    useEffect(() => {
        if (!preparing) {
            return;
        }

        // The next intervention is read from the destination after the Execution
        // waits; follow closely until its form arrives.
        const follow = window.setInterval(() => void catchUp(), 3_000);

        return () => window.clearInterval(follow);
    }, [preparing, catchUp]);

    // The close runs as a queued job after the request: in the 2026-10-25 test
    // the page kept «REVIEW» and its button for several seconds afterwards.
    const finalizing =
        execution.status === 'REVIEW' &&
        events.some((event) => event.type === 'finalization.queued');

    useEffect(() => {
        if (!finalizing) {
            return;
        }

        const follow = window.setInterval(() => void catchUp(), 3_000);
        const reload = window.setInterval(
            () =>
                router.reload({
                    only: ['execution', 'review', 'events'],
                }),
            15_000,
        );

        return () => {
            window.clearInterval(follow);
            window.clearInterval(reload);
        };
    }, [finalizing, catchUp]);

    const syncedSignature = useRef(
        conflictSignature(initialExecution.conflicts),
    );
    const signature = conflictSignature(execution.conflicts);

    useEffect(() => {
        // In the 2026-10-24 test the page props (history.state) still held only the
        // answered conflict: read them again once the tracking brings a newer set,
        // so a back/forward restore or a remount shows the same form.
        if (
            signature === syncedSignature.current ||
            signature === conflictSignature(initialExecution.conflicts) ||
            execution.last_event_sequence <=
                initialExecution.last_event_sequence
        ) {
            return;
        }

        syncedSignature.current = signature;
        router.reload({
            only: ['execution', 'review', 'events'],
            async: true,
        });
    }, [
        signature,
        execution.last_event_sequence,
        initialExecution.conflicts,
        initialExecution.last_event_sequence,
    ]);

    const progress = progressDisplay(execution.status, execution.progress);

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
                        panel={panel}
                        disabled={updatesPaused}
                        finalizing={finalizing}
                    />
                )}

                {execution.consolidation && (
                    <ConsolidationStatus
                        projectUuid={project.uuid}
                        executionUuid={execution.uuid}
                        consolidation={execution.consolidation}
                        status={execution.status}
                        panel={panel.kind}
                        canControl={canControl && !updatesPaused}
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
                                        {progress.label}
                                    </span>
                                </div>
                                <div
                                    className="bg-muted h-3 overflow-hidden rounded-full"
                                    role="progressbar"
                                    aria-valuemin={0}
                                    aria-valuemax={100}
                                    aria-valuenow={progress.value ?? undefined}
                                    aria-valuetext={progress.label}
                                >
                                    <div
                                        className={cn(
                                            'bg-primary h-full rounded-full transition-[width]',
                                            progress.value === null &&
                                                (progress.active
                                                    ? 'w-1/3 animate-pulse'
                                                    : 'w-0'),
                                        )}
                                        style={
                                            progress.value === null
                                                ? undefined
                                                : {
                                                      width: `${progress.value}%`,
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
    panel,
    disabled,
    finalizing,
}: {
    projectUuid: string;
    execution: ExecutionData;
    review: ReviewData;
    panel: InterventionPanel<ConflictData>;
    disabled: boolean;
    finalizing: boolean;
}) {
    const [cancelKey] = useState(() => crypto.randomUUID());
    const [resumeKey] = useState(() => crypto.randomUUID());
    const [validateKey] = useState(() => crypto.randomUUID());
    const [finalizeKey] = useState(() => crypto.randomUUID());
    const [freshKey] = useState(() => crypto.randomUUID());
    const [freshOpen, setFreshOpen] = useState(false);
    const [finalizeOpen, setFinalizeOpen] = useState(false);
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
                {panel.kind === 'open' &&
                    panel.conflicts.map((conflict) => (
                        <ConflictAction
                            key={`${conflict.id}:${conflict.version}`}
                            projectUuid={projectUuid}
                            executionUuid={execution.uuid}
                            conflict={conflict}
                            disabled={disabled}
                        />
                    ))}
                {panel.kind === 'answered' && (
                    <AnswersSent
                        summary={panel.conflict.intervention?.answer_summary}
                    />
                )}
                {panel.kind === 'preparing' && <NextInterventionPending />}

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
                    {finalizing && (
                        <p
                            role="status"
                            className="text-muted-foreground flex items-center gap-2 text-sm"
                        >
                            <ShieldCheck /> Cerrando el proyecto: se verifican y
                            sellan los artefactos. La página se actualizará
                            sola.
                        </p>
                    )}
                    {execution.status === 'REVIEW' &&
                        !finalizing &&
                        review.validation_current && (
                            <Button
                                disabled={disabled || finalizeForm.processing}
                                onClick={() => setFinalizeOpen(true)}
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
                <Dialog open={finalizeOpen} onOpenChange={setFinalizeOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>¿Finalizar el proyecto?</DialogTitle>
                            <DialogDescription>
                                El cierre es definitivo: el proyecto queda en
                                sólo lectura y ya no admite cambios, nuevos
                                intentos ni respaldos. Los artefactos
                                verificados siguen disponibles para consulta y
                                descarga.
                            </DialogDescription>
                        </DialogHeader>
                        {execution.consolidation &&
                            execution.consolidation.backup.state !==
                                'SUCCEEDED' && (
                                <Alert variant="destructive">
                                    <ShieldAlert />
                                    <AlertTitle>
                                        La copia integral no está respaldada
                                    </AlertTitle>
                                    <AlertDescription>
                                        Después del cierre ya no podrá
                                        respaldarla en S3 desde la plataforma.
                                    </AlertDescription>
                                </Alert>
                            )}
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setFinalizeOpen(false)}
                            >
                                Volver a la revisión
                            </Button>
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
                                            onFinish: () =>
                                                setFinalizeOpen(false),
                                        },
                                    )
                                }
                            >
                                <ShieldCheck /> Sí, cerrar en sólo lectura
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
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

/** The Execution waits again and its next intervention is still being read from the destination. */
function NextInterventionPending() {
    return (
        <div
            role="status"
            className="flex items-start gap-2 rounded-lg border p-4 text-sm"
        >
            <CircleDashed className="mt-0.5 size-4 shrink-0 animate-spin" />
            <div>
                <p className="font-medium">
                    El Consolidador prepara la siguiente decisión
                </p>
                <p className="text-muted-foreground">
                    Aparecerá aquí en unos segundos, sin recargar la página.
                </p>
            </div>
        </div>
    );
}

const consolidationStages: Record<string, string> = {
    '01-importar-paquetes': 'Importar paquetes',
    '02-oauth2': 'Google OAuth2',
    '03-plugins': 'Plugins',
    '03b-oauth2-final': 'Confirmar OAuth y proxy',
    '04-identidades': 'Identidades',
    '05-plan-usuarios': 'Plan de usuarios',
    '05a-mapa-categorias': 'Mapa de categorías',
    '05b-readiness': 'Readiness',
    '06-aplicar-usuarios': 'Aplicar usuarios',
    '07-verificar-usuarios': 'Verificar usuarios',
    '07b-categorias-piloto': 'Categorías del piloto',
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
    projectUuid,
    executionUuid,
    consolidation,
    status,
    panel,
    canControl,
}: {
    projectUuid: string;
    executionUuid: string;
    consolidation: ConsolidationData;
    status: string;
    panel: InterventionPanel<ConflictData>['kind'];
    canControl: boolean;
}) {
    const backupForm = useForm({});
    const retryForm = useForm({ configuration_version: 0 });
    const [retryKey] = useState(() => crypto.randomUUID());
    const workers = consolidation.workers_status;
    const closure = consolidation.closure;
    // The request itself already means «in progress»; the server state
    // confirms it once the redirect or the next catch-up arrives.
    const backupState = backupForm.processing
        ? 'RUNNING'
        : consolidation.backup.state;

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
                                {consolidation.message ??
                                    'El Consolidador espera una decisión.'}
                            </p>
                            <p className="text-xs">
                                {panel === 'open'
                                    ? 'Respóndala más abajo, en «Acciones controladas».'
                                    : panel === 'preparing'
                                      ? 'La decisión aparecerá más abajo en unos segundos.'
                                      : 'Su respuesta quedó registrada; el destino la está aplicando.'}
                            </p>
                            <ServerDetails
                                message={consolidation.server_message}
                                paths={consolidation.review_paths}
                            />
                        </AlertDescription>
                    </Alert>
                )}
                {status !== 'WAITING_USER_ACTION' && consolidation.message && (
                    <p className="text-muted-foreground">
                        {consolidation.message}
                    </p>
                )}
                {consolidation.can_retry && (
                    <Alert>
                        <ShieldAlert />
                        <AlertTitle>
                            Nuevo intento de la consolidación
                        </AlertTitle>
                        <AlertDescription className="space-y-2">
                            <p>
                                El Consolidador conserva sus checkpoints en el
                                destino: un nuevo intento continúa desde la
                                etapa en que se detuvo. Corrija antes en el
                                servidor lo que indica el mensaje.
                            </p>
                            {canControl && (
                                <Button
                                    size="sm"
                                    disabled={retryForm.processing}
                                    onClick={() => {
                                        retryForm.transform(() => ({
                                            configuration_version:
                                                consolidation.configuration_version ??
                                                0,
                                        }));
                                        retryForm.post(
                                            `/projects/${projectUuid}/executions/${executionUuid}/consolidation/retry`,
                                            {
                                                headers: {
                                                    'Idempotency-Key': retryKey,
                                                },
                                                preserveScroll: true,
                                            },
                                        );
                                    }}
                                >
                                    Nuevo intento
                                </Button>
                            )}
                        </AlertDescription>
                    </Alert>
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
                {(consolidation.can_backup || backupState) && (
                    <div className="space-y-2 rounded-md border p-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">
                                Respaldo de la copia integral
                            </span>
                            <Badge
                                variant={
                                    backupState === 'SUCCEEDED'
                                        ? 'default'
                                        : backupState === 'FAILED'
                                          ? 'destructive'
                                          : 'secondary'
                                }
                            >
                                {backupState === 'SUCCEEDED'
                                    ? 'Respaldada y verificada'
                                    : backupState === 'RUNNING'
                                      ? 'Respaldando… el destino sube la copia'
                                      : backupState === 'FAILED'
                                        ? 'Fallida'
                                        : 'Sin respaldo'}
                            </Badge>
                            {canControl &&
                                consolidation.can_backup &&
                                backupState !== 'RUNNING' && (
                                    <Button
                                        size="sm"
                                        className="ml-auto"
                                        onClick={() =>
                                            backupForm.post(
                                                `/projects/${projectUuid}/executions/${executionUuid}/consolidation/backup`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Respaldar en S3
                                    </Button>
                                )}
                        </div>
                        {consolidation.backup.location && (
                            <p className="font-mono text-xs break-all">
                                s3://{consolidation.backup.location.bucket}/
                                {consolidation.backup.location.key}
                            </p>
                        )}
                        {consolidation.backup.released !== null && (
                            <p className="text-muted-foreground text-xs">
                                Paquetes de origen liberados en el destino:{' '}
                                {consolidation.backup.released} (
                                {(
                                    (consolidation.backup.freed_bytes ?? 0) /
                                    1048576
                                ).toFixed(1)}{' '}
                                MiB). La copia integral se conserva en el
                                destino para su publicación.
                            </p>
                        )}
                        {consolidation.can_backup && (
                            <p className="text-muted-foreground text-xs">
                                Tómelo antes de finalizar: después del cierre el
                                proyecto queda en sólo lectura.
                            </p>
                        )}
                    </div>
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
                        {/* With several sources the stage is per source; only the total is shown here. */}
                        {report.stage
                            ? `Etapa ${report.stage} · `
                            : execution.status === 'REVIEW'
                              ? 'Exportación terminada · '
                              : ''}
                        cursos {report.completed_courses ?? '—'} de{' '}
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

    if (
        conflict.intervention &&
        conflict.allowed_decisions.includes('APPLY_ANSWERS') &&
        conflict.type === 'CATEGORY_MAP'
    ) {
        return (
            <CategoryMapIntervention
                projectUuid={projectUuid}
                executionUuid={executionUuid}
                conflict={conflict}
                disabled={disabled}
            />
        );
    }

    if (
        conflict.intervention &&
        conflict.allowed_decisions.includes('APPLY_ANSWERS') &&
        ['IDENTITY_REVIEW', 'THEME_SELECTION'].includes(conflict.type)
    ) {
        return conflict.type === 'IDENTITY_REVIEW' ? (
            <IdentityReview
                projectUuid={projectUuid}
                executionUuid={executionUuid}
                conflict={conflict}
                disabled={disabled}
            />
        ) : (
            <ThemeSelection
                projectUuid={projectUuid}
                executionUuid={executionUuid}
                conflict={conflict}
                disabled={disabled}
            />
        );
    }

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

/** What the Consolidator wrote for the server's console, out of the way of the web user. */
function ServerDetails({
    message,
    instructions,
    paths,
}: {
    message?: string | null;
    instructions?: string | null;
    paths?: string[];
}) {
    if (!message && !instructions && (paths ?? []).length === 0) {
        return null;
    }

    return (
        <details className="text-muted-foreground text-xs">
            <summary className="cursor-pointer">
                Detalles técnicos para el administrador del servidor
            </summary>
            <div className="mt-1 space-y-1">
                {message && <p className="break-words">{message}</p>}
                {instructions && (
                    <pre className="bg-background max-h-48 overflow-auto rounded p-2 whitespace-pre-wrap">
                        {instructions}
                    </pre>
                )}
                {paths && paths.length > 0 && (
                    <p className="font-mono break-all">
                        Archivos en el destino: {paths.join(', ')}
                    </p>
                )}
            </div>
        </details>
    );
}

const interventionTitles: Record<string, string> = {
    OAUTH2_CONFIGURATION: 'Configurar Google OAuth2 en el Moodle destino',
    PLUGIN_INTERVENTION: 'Plugins requeridos por los orígenes',
    IDENTITY_REVIEW: 'Revisar posibles identidades duplicadas',
    THEME_SELECTION: 'Elegir el theme del Moodle consolidado',
    CATEGORY_MAP: 'Revisar el mapa de categorías del destino',
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
            {(details.unknown ?? []).length > 0 && (
                <UnknownPlugins
                    projectUuid={projectUuid}
                    executionUuid={executionUuid}
                    conflict={conflict}
                    disabled={disabled}
                />
            )}
            {details.plugins_none && (
                <Alert>
                    <ShieldAlert />
                    <AlertTitle>
                        Los orígenes no requieren plugins adicionales
                    </AlertTitle>
                    <AlertDescription>
                        El Consolidador 8.0.0 no completa por sí solo esta etapa
                        cuando no hay plugins que instalar. «Continuar sin
                        plugins» reconstruye Moodle en el destino si hace falta,
                        revalida y sella el inventario vacío; después la
                        consolidación sigue automáticamente.
                    </AlertDescription>
                </Alert>
            )}
            <ServerDetails
                message={details.server_message}
                instructions={details.server_instructions}
                paths={details.review_paths}
            />
            <label className="flex gap-2 text-xs">
                <input
                    type="checkbox"
                    checked={confirmed}
                    onChange={(event) => setConfirmed(event.target.checked)}
                />
                {details.plugins_none
                    ? 'Entiendo que el destino continuará sin plugins adicionales'
                    : plugins
                      ? 'Revisé que los plugins instalados cubren los datos de los orígenes'
                      : 'Completé la intervención indicada en el Moodle destino'}
            </label>
            <div className="flex flex-wrap gap-2">
                {conflict.allowed_decisions.includes(
                    'CONTINUE_WITHOUT_PLUGINS',
                ) && (
                    <Button
                        disabled={disabled || processing || !confirmed}
                        onClick={() => decide('CONTINUE_WITHOUT_PLUGINS')}
                    >
                        Continuar sin plugins
                    </Button>
                )}
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

function ProposalFact({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex gap-1">
            <dt className="text-muted-foreground">{label}:</dt>
            <dd className="font-mono break-all">{value}</dd>
        </div>
    );
}

/**
 * Components the sources need and the Consolidator's catalog lacks: each one is
 * inspected on the destination from its Git repository and commit, and the
 * confirmed proposals are added to the catalog before revalidating.
 */
function UnknownPlugins({
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
    const details = conflict.intervention ?? {};
    const unknown = details.unknown ?? [];
    const proposals = details.proposals ?? {};
    const [sources, setSources] = useState<
        Record<string, { repository: string; commit: string }>
    >(() =>
        Object.fromEntries(
            unknown.map((component) => [
                component,
                {
                    repository:
                        details.inspections?.[component]?.repository ?? '',
                    commit: details.inspections?.[component]?.commit ?? '',
                },
            ]),
        ),
    );
    const [inspecting, setInspecting] = useState<string | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const { send, processing, errors, sent } = useInterventionAnswers(
        projectUuid,
        executionUuid,
        conflict,
    );

    if (sent) {
        return <AnswersSent />;
    }

    const ready = Object.keys(proposals);

    return (
        <div className="bg-background space-y-3 rounded-md border p-3">
            <p className="text-xs font-medium">
                Plugins que el catálogo del Consolidador no conoce
            </p>
            <p className="text-muted-foreground text-xs">
                Indique el repositorio Git público (GitHub o Bitbucket) y el
                commit exacto de cada uno. El destino lo inspecciona (versión,
                requisitos y huella del árbol). Si confirma la propuesta, se
                agrega al catálogo; el Consolidador lo instala y lo valida en
                staging, y después usted aprueba el inventario.
            </p>
            {unknown.map((component) => {
                const inspection = details.inspections?.[component];
                const proposal = proposals[component];
                return (
                    <div
                        key={component}
                        className="space-y-2 border-t pt-2 text-xs"
                    >
                        <p className="font-mono font-medium">{component}</p>
                        <div className="grid gap-2 sm:grid-cols-[2fr_1fr_auto]">
                            <Input
                                placeholder="https://github.com/organizacion/moodle-plugin.git"
                                value={sources[component]?.repository ?? ''}
                                onChange={(event) =>
                                    setSources((current) => ({
                                        ...current,
                                        [component]: {
                                            ...current[component],
                                            repository:
                                                event.target.value.trim(),
                                        },
                                    }))
                                }
                            />
                            <Input
                                placeholder="commit (40 caracteres)"
                                value={sources[component]?.commit ?? ''}
                                onChange={(event) =>
                                    setSources((current) => ({
                                        ...current,
                                        [component]: {
                                            ...current[component],
                                            commit: event.target.value
                                                .trim()
                                                .toLowerCase(),
                                        },
                                    }))
                                }
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={
                                    disabled ||
                                    inspecting !== null ||
                                    inspection?.state === 'RUNNING' ||
                                    !/^[0-9a-f]{40}$/.test(
                                        sources[component]?.commit ?? '',
                                    )
                                }
                                onClick={() =>
                                    router.post(
                                        `/projects/${projectUuid}/executions/${executionUuid}/conflicts/${conflict.id}/plugin-inspect`,
                                        {
                                            component,
                                            ...sources[component],
                                        },
                                        {
                                            preserveScroll: true,
                                            onStart: () =>
                                                setInspecting(component),
                                            onFinish: () => setInspecting(null),
                                        },
                                    )
                                }
                            >
                                Inspeccionar
                            </Button>
                        </div>
                        {inspection && (
                            <p
                                className={
                                    inspection.state === 'FAILED'
                                        ? 'text-red-600'
                                        : 'text-muted-foreground'
                                }
                            >
                                {inspection.state === 'RUNNING'
                                    ? 'Inspeccionando en el destino…'
                                    : (inspection.message ??
                                      (inspection.state === 'SUCCEEDED'
                                          ? 'Inspección completada.'
                                          : inspection.state))}
                            </p>
                        )}
                        {proposal && (
                            <dl className="grid gap-x-4 sm:grid-cols-2">
                                <ProposalFact
                                    label="Versión"
                                    value={String(proposal.version)}
                                />
                                <ProposalFact
                                    label="Release"
                                    value={proposal.release ?? '—'}
                                />
                                <ProposalFact
                                    label="Requiere Moodle"
                                    value={String(proposal.requires ?? '—')}
                                />
                                <ProposalFact
                                    label="Ruta"
                                    value={proposal.path ?? '—'}
                                />
                                <ProposalFact
                                    label="Huella del árbol"
                                    value={(proposal.tree_sha256 ?? '').slice(
                                        0,
                                        16,
                                    )}
                                />
                                <ProposalFact
                                    label="Dependencias"
                                    value={
                                        proposal.dependencies.join(', ') ||
                                        'ninguna'
                                    }
                                />
                            </dl>
                        )}
                    </div>
                );
            })}
            {ready.length > 0 && (
                <div className="space-y-2 border-t pt-2">
                    <label className="flex gap-2 text-xs">
                        <input
                            type="checkbox"
                            checked={confirmed}
                            onChange={(event) =>
                                setConfirmed(event.target.checked)
                            }
                        />
                        Revisé las propuestas: provienen del repositorio y
                        commit que indiqué
                    </label>
                    <InputError message={errors.promote ?? errors.conflict} />
                    <Button
                        size="sm"
                        disabled={disabled || processing || !confirmed}
                        onClick={() => send({ promote: ready, confirm: true })}
                    >
                        Agregar al catálogo y revalidar ({ready.length})
                    </Button>
                </div>
            )}
        </div>
    );
}

/** Sends the administrator's answers to a consolidation intervention. */
function useInterventionAnswers(
    projectUuid: string,
    executionUuid: string,
    conflict: ConflictData,
) {
    const [key] = useState(() => crypto.randomUUID());
    const [processing, setProcessing] = useState(false);
    const [answered, setAnswered] = useState<AnsweredConflict | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const send = (data: Record<string, unknown>) =>
        router.post(
            `/projects/${projectUuid}/executions/${executionUuid}/conflicts/${conflict.id}/answers`,
            { ...data, conflict_version: conflict.version } as never,
            {
                headers: { 'Idempotency-Key': `${key}-answers` },
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setErrors({});
                    setAnswered({ id: conflict.id, version: conflict.version });
                },
                onError: (found) => setErrors(found),
            },
        );

    return {
        send,
        processing,
        errors,
        sent: answersSentFor(answered, conflict),
    };
}

/** Shown once the answers are sent, and kept while the destination applies them. */
function AnswersSent({ summary }: { summary?: AnswerSummary | null }) {
    const parts = summary
        ? [
              summary.merge > 0 && `${summary.merge} unidas`,
              summary.keep_separate > 0 && `${summary.keep_separate} separadas`,
              summary.ignore > 0 && `${summary.ignore} sin cambios`,
              summary.theme && `apariencia «${summary.theme}»`,
              summary.promoted > 0 &&
                  `${summary.promoted} plugins agregados al catálogo`,
              (summary.categories ?? 0) > 0 &&
                  `${summary.categories} categorías en el mapa`,
              (summary.merged ?? 0) > 0 &&
                  `${summary.merged} con categorías unidas`,
          ].filter(Boolean)
        : [];

    return (
        <div
            role="status"
            className="rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm dark:border-emerald-900 dark:bg-emerald-950/30"
        >
            <p className="font-medium">Respuestas enviadas</p>
            {parts.length > 0 && <p>Decisiones: {parts.join(', ')}.</p>}
            <p className="text-muted-foreground">
                El destino las aplica y la consolidación continúa sola; el
                seguimiento muestra el avance. No necesita hacer nada más aquí.
            </p>
        </div>
    );
}

function IdentityFactsTable({ candidate }: { candidate: IdentityCandidate }) {
    return (
        <table className="w-full text-left text-xs">
            <thead>
                <tr className="text-muted-foreground">
                    <th className="w-28 py-1 font-normal">
                        <span className="sr-only">Dato</span>
                    </th>
                    <th className="py-1 font-medium">Cuenta 1</th>
                    <th className="py-1 font-medium">Cuenta 2</th>
                </tr>
            </thead>
            <tbody>
                {identityFacts(candidate).map((fact) => (
                    <tr key={fact.label} className="border-t align-top">
                        <th
                            scope="row"
                            className="text-muted-foreground py-1 pr-2 font-normal"
                        >
                            {fact.label}
                            {!fact.same && fact.label !== 'Origen' && (
                                <span className="sr-only"> (distinto)</span>
                            )}
                        </th>
                        {[fact.a, fact.b].map((value, position) => (
                            <td
                                key={position}
                                className={`py-1 pr-2 break-all ${
                                    fact.same || fact.label === 'Origen'
                                        ? ''
                                        : 'font-medium text-amber-800 dark:text-amber-300'
                                }`}
                            >
                                {value}
                            </td>
                        ))}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/**
 * The fuzzy identity candidates: for each pair of accounts that may be one
 * person, the administrator decides whether they are joined, kept apart or
 * left as they are, with a reason that stays in the audit.
 */
function IdentityReview({
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
    const candidates = conflict.intervention?.candidates ?? [];
    const [decisions, setDecisions] = useState<IdentityDecision[]>(() =>
        candidates.map(initialDecision),
    );
    const [bulkReason, setBulkReason] = useState('');
    const { send, processing, errors, sent } = useInterventionAnswers(
        projectUuid,
        executionUuid,
        conflict,
    );

    if (sent) {
        const count = (resolution: IdentityDecision['resolution']) =>
            decisions.filter((decision) => decision.resolution === resolution)
                .length;
        return (
            <AnswersSent
                summary={{
                    answered_at: null,
                    merge: count('MERGE'),
                    keep_separate: count('KEEP_SEPARATE'),
                    ignore: count('IGNORE'),
                    theme: null,
                    promoted: 0,
                }}
            />
        );
    }

    const update = (index: number, changes: Partial<IdentityDecision>) =>
        setDecisions((current) =>
            current.map((decision, position) =>
                position === index ? { ...decision, ...changes } : decision,
            ),
        );
    const gaps = decisions.map(decisionGap);
    const decided = gaps.filter((gap) => gap === null).length;
    const complete = decided === decisions.length;
    const evident = evidentCases(candidates, decisions);

    return (
        <div className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950/30">
            <p className="font-medium">{interventionTitles[conflict.type]}</p>
            <p className="text-muted-foreground">
                El Consolidador encontró {candidates.length}{' '}
                {candidates.length === 1 ? 'caso' : 'casos'} de dos cuentas, una
                de cada origen, que podrían ser de la misma persona. Antes de
                crear los usuarios en el Moodle consolidado, decida cada caso.
                Las diferencias entre las dos cuentas aparecen resaltadas.
            </p>
            <details className="bg-background rounded-md border p-2 text-xs">
                <summary className="cursor-pointer font-medium">
                    ¿Qué significa cada opción?
                </summary>
                <dl className="mt-2 space-y-2">
                    {Object.values(resolutionLabels).map((option) => (
                        <div key={option.label}>
                            <dt className="font-medium">{option.label}</dt>
                            <dd className="text-muted-foreground">
                                {option.help}
                            </dd>
                        </div>
                    ))}
                </dl>
            </details>
            <p className="font-medium" aria-live="polite">
                {decided} de {decisions.length} decididos
            </p>
            {evident.length > 0 && (
                <div className="bg-background space-y-2 rounded-md border p-3 text-xs">
                    <p className="font-medium">
                        {evident.length === 1
                            ? '1 caso tiene'
                            : `${evident.length} casos tienen`}{' '}
                        el mismo correo y el mismo documento, o el mismo usuario
                        y nombre
                    </p>
                    <p className="text-muted-foreground">
                        {evident.length === 1
                            ? 'Puede unirlo de una vez. Conserva'
                            : 'Puede unirlos todos de una vez. Cada uno conserva'}{' '}
                        la cuenta, el correo y el usuario de la primera cuenta;
                        después puede revisar o cambiar cualquiera.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Input
                            className="max-w-md"
                            aria-label="Motivo para unir los casos evidentes"
                            placeholder="Motivo (queda en la auditoría)"
                            value={bulkReason}
                            onChange={(event) =>
                                setBulkReason(event.target.value)
                            }
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={
                                disabled ||
                                bulkReason.trim().length < MIN_JUSTIFICATION
                            }
                            onClick={() =>
                                setDecisions((current) =>
                                    mergeEvident(current, evident, bulkReason),
                                )
                            }
                        >
                            {evident.length === 1
                                ? 'Unir el caso evidente'
                                : `Unir los ${evident.length} casos evidentes`}
                        </Button>
                    </div>
                </div>
            )}
            {candidates.map((candidate, index) => {
                const decision = decisions[index];
                const members = identityMembers(candidate);
                const reasons = matchReasons(candidate);
                const prefix = `decisions.${index}`;
                const gap = gaps[index];
                return (
                    <fieldset
                        key={candidate.group}
                        className="bg-background space-y-3 rounded-md border p-3"
                    >
                        <legend className="sr-only">Caso {index + 1}</legend>
                        <div className="flex flex-wrap items-center gap-2 text-xs">
                            <span className="font-medium">
                                Caso {index + 1} de {candidates.length}
                            </span>
                            <Badge variant="outline">
                                {confidenceLabel(candidate.confidence)}
                            </Badge>
                            {gap === null ? (
                                <Badge variant="secondary">Decidido</Badge>
                            ) : (
                                <span className="text-muted-foreground">
                                    Pendiente: {gap}
                                </span>
                            )}
                        </div>
                        {reasons.length > 0 && (
                            <p className="text-xs">
                                Se detectó porque {reasons.join(', ')}.
                            </p>
                        )}
                        <IdentityFactsTable candidate={candidate} />
                        <div
                            role="radiogroup"
                            aria-label={`Decisión del caso ${index + 1}`}
                            className="grid gap-2 text-xs sm:grid-cols-3"
                        >
                            {resolutionOptions.map(([value, option]) => (
                                <label
                                    key={value}
                                    className={`flex cursor-pointer gap-2 rounded-md border p-2 ${
                                        decision.resolution === value
                                            ? 'border-primary bg-primary/5'
                                            : ''
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name={`resolution-${candidate.group}`}
                                        value={value}
                                        aria-label={option.label}
                                        checked={decision.resolution === value}
                                        onChange={() =>
                                            update(index, { resolution: value })
                                        }
                                    />
                                    <span>
                                        <span className="block font-medium">
                                            {option.label}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {option.help}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        {keepsDuplicateEmail(candidate, decision) && (
                            <p className="text-xs text-amber-800 dark:text-amber-300">
                                Atención: las dos cuentas tienen el mismo correo
                                y quedarán separadas en el Moodle consolidado.
                                Si son la misma persona, elija «
                                {resolutionLabels.MERGE.label}».
                            </p>
                        )}
                        {decision.resolution === 'MERGE' && (
                            <div className="grid gap-2 text-xs sm:grid-cols-2">
                                <label className="space-y-1 sm:col-span-2">
                                    <span>Cuenta que se conserva</span>
                                    <select
                                        className="border-input bg-background w-full rounded-md border px-2 py-1"
                                        value={decision.canonical_target}
                                        onChange={(event) =>
                                            setDecisions((current) =>
                                                current.map((item, position) =>
                                                    position === index
                                                        ? chooseCanonical(
                                                              item,
                                                              members,
                                                              event.target
                                                                  .value,
                                                          )
                                                        : item,
                                                ),
                                            )
                                        }
                                    >
                                        {members.map((member) => (
                                            <option
                                                key={member.key}
                                                value={member.key}
                                            >
                                                {memberLabel(member)}
                                            </option>
                                        ))}
                                    </select>
                                    <span className="text-muted-foreground block">
                                        La otra cuenta se une a esta: sus cursos
                                        y calificaciones pasan a la cuenta que
                                        se conserva.
                                    </span>
                                </label>
                                <label className="space-y-1">
                                    <span>Correo de la cuenta unida</span>
                                    <Input
                                        value={decision.canonical_email}
                                        onChange={(event) =>
                                            update(index, {
                                                canonical_email:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                    <InputError
                                        message={
                                            errors[`${prefix}.canonical_email`]
                                        }
                                    />
                                </label>
                                <label className="space-y-1">
                                    <span>
                                        Usuario de Moodle de la cuenta unida
                                    </span>
                                    <Input
                                        value={decision.canonical_username}
                                        onChange={(event) =>
                                            update(index, {
                                                canonical_username:
                                                    event.target.value.toLowerCase(),
                                            })
                                        }
                                    />
                                    <InputError
                                        message={
                                            errors[
                                                `${prefix}.canonical_username`
                                            ] ??
                                            errors[`${prefix}.canonical_target`]
                                        }
                                    />
                                </label>
                                <label className="space-y-1 sm:col-span-2">
                                    <span>Inicio de sesión con Google</span>
                                    <select
                                        className="border-input bg-background w-full rounded-md border px-2 py-1"
                                        value={decision.oauth_policy}
                                        onChange={(event) =>
                                            update(index, {
                                                oauth_policy: event.target
                                                    .value as IdentityDecision['oauth_policy'],
                                            })
                                        }
                                    >
                                        <option value="canonical_account">
                                            Mantener el vínculo de la cuenta que
                                            se conserva
                                        </option>
                                        <option value="none">
                                            Quitarlo: la persona lo vuelve a
                                            vincular después
                                        </option>
                                    </select>
                                </label>
                            </div>
                        )}
                        <label className="block space-y-1 text-xs">
                            <span>Motivo de la decisión</span>
                            <Input
                                value={decision.justification}
                                placeholder="Por ejemplo: mismo documento y mismo nombre (queda en la auditoría)"
                                onChange={(event) =>
                                    update(index, {
                                        justification: event.target.value,
                                    })
                                }
                            />
                            <InputError
                                message={errors[`${prefix}.justification`]}
                            />
                        </label>
                        <ServerDetails
                            message={[
                                `Grupo ${candidate.group}`,
                                `confianza ${candidate.confidence ?? '—'}`,
                                `nombre ${candidate.name_similarity ?? '—'}`,
                                `correo ${candidate.email_similarity ?? '—'}`,
                                `identificador ${candidate.identifier_match ?? '—'}`,
                                candidate.evidence,
                                `cuentas ${members.map((member) => member.key).join(', ')}`,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        />
                    </fieldset>
                );
            })}
            <InputError message={errors.decisions ?? errors.conflict} />
            {!complete && (
                <div className="text-xs" aria-live="polite">
                    <p className="font-medium">
                        Para aplicar la revisión falta decidir{' '}
                        {decisions.length - decided} de {decisions.length}{' '}
                        casos:
                    </p>
                    <ul className="text-muted-foreground list-disc pl-5">
                        {gaps.map((gap, index) =>
                            gap === null ? null : (
                                <li key={candidates[index].group}>
                                    Caso {index + 1}: {gap}
                                </li>
                            ),
                        )}
                    </ul>
                </div>
            )}
            <Button
                disabled={disabled || processing || !complete}
                onClick={() =>
                    send({
                        decisions: decisions.map((decision) =>
                            decision.resolution === 'MERGE'
                                ? {
                                      ...decision,
                                      canonical_email: decision.canonical_email
                                          .trim()
                                          .toLowerCase(),
                                  }
                                : {
                                      group: decision.group,
                                      resolution: decision.resolution,
                                      justification: decision.justification,
                                  },
                        ),
                    })
                }
            >
                Aplicar revisión y continuar
            </Button>
        </div>
    );
}

/** The destination's global theme and, when a source lacks theme metadata, the legacy decision. */
function ThemeSelection({
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
    const details = conflict.intervention ?? {};
    const themes = details.themes ?? [];
    const legacy =
        (details.legacy_sources ?? []).length > 0 && !details.legacy_accepted;
    const [theme, setTheme] = useState(
        details.theme_selected && themes.includes(details.theme_selected)
            ? details.theme_selected
            : details.theme_default && themes.includes(details.theme_default)
              ? details.theme_default
              : (themes[0] ?? ''),
    );
    const [acceptLegacy, setAcceptLegacy] = useState(false);
    const { send, processing, errors, sent } = useInterventionAnswers(
        projectUuid,
        executionUuid,
        conflict,
    );

    if (sent) {
        return <AnswersSent />;
    }

    return (
        <div className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950/30">
            <p className="font-medium">{interventionTitles[conflict.type]}</p>
            {details.message && (
                <p className="text-muted-foreground text-xs">
                    {details.message}
                </p>
            )}
            <label className="block space-y-1">
                <span className="text-xs font-medium">
                    Theme global del destino
                </span>
                <select
                    className="border-input bg-background w-full rounded-md border px-2 py-1 text-sm"
                    value={theme}
                    onChange={(event) => setTheme(event.target.value)}
                >
                    {themes.map((name) => (
                        <option key={name} value={name}>
                            {name}
                            {name === details.theme_default
                                ? ' (predeterminado de la política)'
                                : ''}
                        </option>
                    ))}
                </select>
                <span className="text-muted-foreground block text-xs">
                    Los cursos conservan los themes que traen de sus orígenes
                    cuando el destino los tiene instalados.
                </span>
            </label>
            {legacy && (
                <div className="space-y-1 text-xs">
                    <p>
                        Estas fuentes se recolectaron sin la metadata avanzada
                        de themes:{' '}
                        {(details.legacy_sources ?? [])
                            .map(
                                (source) =>
                                    `${source.source} (${source.collector_version ?? '?'})`,
                            )
                            .join(', ')}
                        . Puede continuar en modo compatible, con advertencia, o
                        cancelar y recolectarlas de nuevo con el Recolector
                        7.4.2.
                    </p>
                    <label className="flex gap-2">
                        <input
                            type="checkbox"
                            checked={acceptLegacy}
                            onChange={(event) =>
                                setAcceptLegacy(event.target.checked)
                            }
                        />
                        Continuar con compatibilidad legacy
                    </label>
                </div>
            )}
            <InputError
                message={
                    errors.theme ?? errors.accept_legacy ?? errors.conflict
                }
            />
            <Button
                disabled={
                    disabled ||
                    processing ||
                    theme === '' ||
                    (legacy && !acceptLegacy)
                }
                onClick={() => send({ theme, accept_legacy: acceptLegacy })}
            >
                Aplicar y continuar
            </Button>
        </div>
    );
}

/** The destination category map, drawn and approved before any category is written. */
function CategoryMapIntervention({
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
    const details = conflict.intervention ?? {};
    const { send, processing, errors, sent } = useInterventionAnswers(
        projectUuid,
        executionUuid,
        conflict,
    );

    if (sent) {
        return <AnswersSent summary={details.answer_summary} />;
    }

    return (
        <div className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950/30">
            <p className="font-medium">{interventionTitles[conflict.type]}</p>
            {details.message && (
                <p className="text-muted-foreground text-xs">
                    {details.message}
                </p>
            )}
            <p className="text-xs">
                Así quedarán las categorías del Moodle consolidado. Una las
                categorías que representan lo mismo en varios orígenes, cambie
                nombres o ubicaciones y apruebe el mapa: el Consolidador crea
                las categorías y restaura cada curso en la suya.
            </p>
            <CategoryMapEditor
                details={details}
                disabled={disabled}
                processing={processing}
                errors={errors}
                onSubmit={(answer) => send(answer)}
            />
            <ServerDetails
                message={details.server_message}
                instructions={details.server_instructions}
                paths={details.review_paths}
            />
        </div>
    );
}

ExecutionShow.layout = {
    breadcrumbs: [
        { title: 'Proyectos', href: '/projects' },
        { title: 'Ejecución', href: '#' },
    ],
};
