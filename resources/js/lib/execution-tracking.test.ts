import { describe, expect, it } from 'vitest';
import {
    executionScopeReplacement,
    initialTrackingCursor,
    mergeSequencedEvents,
    realtimeLiveState,
    responseBelongsToExecution,
    resumePayload,
} from './execution-tracking';

describe('execution tracking isolation', () => {
    it('uses a checkpoint received after the screen was opened', () => {
        const running = {
            checkpoints: [],
        };
        const failedAfterCatchUp = {
            checkpoints: [{ id: 42, validated: true }],
        };

        expect(resumePayload(running)).toBeNull();
        expect(resumePayload(failedAfterCatchUp)).toEqual({
            checkpoint_id: 42,
        });
    });

    it('replaces the previous execution events and high cursor', () => {
        const replacement = executionScopeReplacement(
            'failed-execution',
            { uuid: 'resumed-execution', last_event_sequence: 1 },
            [{ sequence: 1 }],
        );

        expect(replacement).toEqual({
            uuid: 'resumed-execution',
            events: [{ sequence: 1 }],
            cursor: 1,
        });
        expect(
            executionScopeReplacement(
                'resumed-execution',
                { uuid: 'resumed-execution', last_event_sequence: 2 },
                [{ sequence: 1 }, { sequence: 2 }],
            ),
        ).toBeNull();
        expect(initialTrackingCursor({ last_event_sequence: 9 }, [])).toBe(9);
    });

    it('rejects a late response from the previous execution', () => {
        expect(
            responseBelongsToExecution('new-execution', {
                execution: { uuid: 'old-execution' },
            }),
        ).toBe(false);
    });

    it('deduplicates and orders recovery inside one execution', () => {
        expect(
            mergeSequencedEvents(
                [{ sequence: 1 }, { sequence: 2 }],
                [{ sequence: 2 }, { sequence: 4 }, { sequence: 3 }],
            ).map((event) => event.sequence),
        ).toEqual([1, 2, 3, 4]);
    });

    it('reflects websocket disconnection and resubscription', () => {
        expect(realtimeLiveState(true, 'disconnected')).toBe(false);
        expect(realtimeLiveState(true, 'connecting')).toBe(false);
        expect(realtimeLiveState(true, 'unavailable')).toBe(false);
        expect(realtimeLiveState(true, 'failed')).toBe(false);
        expect(realtimeLiveState(false, 'subscribed')).toBe(true);
    });
});
