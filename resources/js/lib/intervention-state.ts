/**
 * What the actions panel shows for a consolidation intervention: the open
 * forms, the confirmation of the answers just sent, or a notice that the
 * Consolidator is preparing the next decision.
 *
 * A confirmation belongs to the conflict (id and version) it answered. In the
 * 2026-10-24 test the identity review stayed confirmed while the Execution
 * already waited on the theme selection: both stop on 05b-readiness, and the
 * new conflict is read from the destination after the Execution waits.
 */

export type AnsweredConflict = { id: number; version: number };

export type InterventionConflict = {
    id: number;
    key: string;
    status: string;
    version: number;
    resolved_at: string | null;
    intervention?: {
        stage?: string | null;
        answer_summary?: unknown;
    } | null;
};

export type InterventionExecution<
    C extends InterventionConflict = InterventionConflict,
> = {
    status: string;
    consolidation?: { stage?: string | null } | null;
    conflicts: C[];
};

export type InterventionEvent = {
    sequence: number;
    type: string;
    payload: Record<string, unknown> | null;
    created_at: string | null;
};

export type InterventionPanel<C extends InterventionConflict> =
    | { kind: 'open'; conflicts: C[] }
    | { kind: 'answered'; conflict: C }
    | { kind: 'preparing' }
    | { kind: 'none' };

/** Events that mean the Consolidator stopped again after an answer. */
const NEXT_STOP_EVENTS = [
    'consolidator.waiting_user_action',
    'consolidator.intervention_opened',
    'consolidator.continue_blocked',
];

const ACTIVE_STATUSES = ['QUEUED', 'RUNNING', 'WAITING_USER_ACTION'];

/** The answers sent from a form apply to that conflict and version only. */
export function answersSentFor(
    sent: AnsweredConflict | null,
    conflict: Pick<InterventionConflict, 'id' | 'version'>,
): boolean {
    return (
        sent !== null &&
        sent.id === conflict.id &&
        sent.version === conflict.version
    );
}

const resolvedSequence = (
    conflict: InterventionConflict,
    events: InterventionEvent[],
): number | null => {
    const found = events.findLast(
        (event) =>
            event.type === 'conflict.resolved' &&
            event.payload?.conflict_key === conflict.key,
    );
    return found?.sequence ?? null;
};

/** Whether the Consolidator stopped again after this conflict was resolved. */
export function stoppedAgainAfter(
    conflict: InterventionConflict,
    events: InterventionEvent[],
): boolean {
    const sequence = resolvedSequence(conflict, events);
    if (sequence !== null) {
        return events.some(
            (event) =>
                event.sequence > sequence &&
                NEXT_STOP_EVENTS.includes(event.type),
        );
    }
    // The resolution fell outside the events kept on screen: compare times.
    const resolvedAt = Date.parse(conflict.resolved_at ?? '');
    return (
        !Number.isNaN(resolvedAt) &&
        events.some(
            (event) =>
                NEXT_STOP_EVENTS.includes(event.type) &&
                Date.parse(event.created_at ?? '') > resolvedAt,
        )
    );
}

export function interventionPanel<C extends InterventionConflict>(
    execution: InterventionExecution<C>,
    events: InterventionEvent[],
): InterventionPanel<C> {
    const open = execution.conflicts.filter(
        (conflict) => conflict.status === 'OPEN',
    );
    if (open.length > 0) {
        return { kind: 'open', conflicts: open };
    }
    if (!ACTIVE_STATUSES.includes(execution.status)) {
        return { kind: 'none' };
    }

    const newest = execution.conflicts.reduce<C | null>(
        (last, conflict) =>
            last === null || conflict.id > last.id ? conflict : last,
        null,
    );
    if (
        newest?.intervention?.answer_summary &&
        (execution.consolidation?.stage ?? null) ===
            (newest.intervention.stage ?? null) &&
        !stoppedAgainAfter(newest, events)
    ) {
        return { kind: 'answered', conflict: newest };
    }

    // Waiting with nothing to answer yet: the next intervention is being read from the destination.
    if (execution.consolidation && execution.status === 'WAITING_USER_ACTION') {
        const lastStop = Math.max(
            0,
            ...events
                .filter((event) => NEXT_STOP_EVENTS.includes(event.type))
                .map((event) => event.sequence),
        );
        const lastResolution = Math.max(
            0,
            ...events
                .filter((event) => event.type === 'conflict.resolved')
                .map((event) => event.sequence),
        );
        if (lastStop > lastResolution || newest === null) {
            return { kind: 'preparing' };
        }
    }

    return { kind: 'none' };
}

/** Identifies the conflicts a snapshot holds, to notice one the page props lack. */
export function conflictSignature(
    conflicts: Pick<InterventionConflict, 'id' | 'status' | 'version'>[],
): string {
    // Independent of the order the snapshot lists them in.
    return [...conflicts]
        .sort((left, right) => left.id - right.id)
        .map(
            (conflict) =>
                `${conflict.id}:${conflict.status}:${conflict.version}`,
        )
        .join('|');
}
