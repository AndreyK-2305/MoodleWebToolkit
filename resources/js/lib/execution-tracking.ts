export type SequencedEvent = {
    sequence: number;
};

export type ExecutionIdentity = {
    uuid: string;
    last_event_sequence: number;
    checkpoints: { id: number; validated: boolean }[];
};

export type TrackingScopeReplacement<T extends SequencedEvent> = {
    uuid: string;
    events: T[];
    cursor: number;
};

export function initialTrackingCursor(
    execution: Pick<ExecutionIdentity, 'last_event_sequence'>,
    events: SequencedEvent[],
): number {
    return events.at(-1)?.sequence ?? execution.last_event_sequence;
}

export function mergeSequencedEvents<T extends SequencedEvent>(
    current: T[],
    incoming: T[],
): T[] {
    const indexed = new Map(
        [...current, ...incoming].map((event) => [event.sequence, event]),
    );

    return [...indexed.values()].sort(
        (left, right) => left.sequence - right.sequence,
    );
}

export function responseBelongsToExecution(
    expectedUuid: string,
    response: { execution: Pick<ExecutionIdentity, 'uuid'> },
): boolean {
    return response.execution.uuid === expectedUuid;
}

export function realtimeLiveState(current: boolean, event: string): boolean {
    if (event === 'subscribed') {
        return true;
    }

    if (event === 'connected') {
        return current;
    }

    return false;
}

export function executionScopeReplacement<T extends SequencedEvent>(
    currentUuid: string,
    nextExecution: Pick<ExecutionIdentity, 'uuid' | 'last_event_sequence'>,
    initialEvents: T[],
): TrackingScopeReplacement<T> | null {
    if (currentUuid === nextExecution.uuid) {
        return null;
    }

    return {
        uuid: nextExecution.uuid,
        events: [...initialEvents],
        cursor: initialTrackingCursor(nextExecution, initialEvents),
    };
}

export function validatedCheckpointId(
    execution: Pick<ExecutionIdentity, 'checkpoints'>,
): number | null {
    return (
        execution.checkpoints.find((checkpoint) => checkpoint.validated)?.id ??
        null
    );
}

export function resumePayload(
    execution: Pick<ExecutionIdentity, 'checkpoints'>,
): { checkpoint_id: number } | null {
    const checkpointId = validatedCheckpointId(execution);

    return checkpointId === null ? null : { checkpoint_id: checkpointId };
}
