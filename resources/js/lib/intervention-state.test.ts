import { describe, expect, it } from 'vitest';
import {
    answersSentFor,
    conflictSignature,
    interventionPanel,
    stoppedAgainAfter,
} from './intervention-state';
import type {
    InterventionConflict,
    InterventionEvent,
    InterventionExecution,
} from './intervention-state';

const identity = (
    changes: Partial<InterventionConflict> = {},
): InterventionConflict => ({
    id: 7,
    key: 'consolidator.05b-readiness.41',
    status: 'RESOLVED',
    version: 2,
    resolved_at: '2026-10-24T15:00:00Z',
    intervention: {
        stage: '05b-readiness',
        answer_summary: { merge: 1, keep_separate: 0, ignore: 0 },
    },
    ...changes,
});

const theme = (
    changes: Partial<InterventionConflict> = {},
): InterventionConflict => ({
    id: 8,
    key: 'consolidator.05b-readiness.42',
    status: 'OPEN',
    version: 1,
    resolved_at: null,
    intervention: { stage: '05b-readiness' },
    ...changes,
});

const execution = (
    conflicts: InterventionConflict[],
    status = 'WAITING_USER_ACTION',
): InterventionExecution => ({
    status,
    consolidation: { stage: '05b-readiness' },
    conflicts,
});

const event = (
    sequence: number,
    type: string,
    payload: Record<string, unknown> | null = null,
): InterventionEvent => ({
    sequence,
    type,
    payload,
    created_at: `2026-10-24T15:00:${String(sequence).padStart(2, '0')}Z`,
});

const answeredEvents = [
    event(10, 'consolidator.waiting_user_action'),
    event(11, 'consolidator.intervention_opened'),
    event(12, 'conflict.resolved', {
        conflict_key: 'consolidator.05b-readiness.41',
    }),
];

describe('answersSentFor', () => {
    it('holds for the conflict and version that were answered only', () => {
        const sent = { id: 7, version: 1 };

        expect(answersSentFor(sent, { id: 7, version: 1 })).toBe(true);
        expect(answersSentFor(sent, { id: 7, version: 2 })).toBe(false);
        expect(answersSentFor(sent, { id: 8, version: 1 })).toBe(false);
        expect(answersSentFor(null, { id: 7, version: 1 })).toBe(false);
    });
});

describe('interventionPanel', () => {
    it('confirms the answers while the destination applies them', () => {
        expect(
            interventionPanel(execution([identity()]), answeredEvents),
        ).toEqual({ kind: 'answered', conflict: identity() });
        expect(
            interventionPanel(execution([identity()], 'RUNNING'), [
                ...answeredEvents,
                event(13, 'consolidator.progress'),
            ]).kind,
        ).toBe('answered');
    });

    it('drops the old confirmation once the Consolidator stops again (2026-10-24)', () => {
        // Same stage, still waiting, the theme conflict not read yet.
        const stoppedAgain = [
            ...answeredEvents,
            event(13, 'consolidator.progress'),
            event(14, 'consolidator.waiting_user_action'),
        ];

        expect(
            interventionPanel(execution([identity()]), stoppedAgain),
        ).toEqual({ kind: 'preparing' });
    });

    it('shows the next form as soon as its conflict arrives', () => {
        const opened = [
            ...answeredEvents,
            event(14, 'consolidator.waiting_user_action'),
            event(15, 'consolidator.intervention_opened'),
        ];

        expect(
            interventionPanel(execution([identity(), theme()]), opened),
        ).toEqual({ kind: 'open', conflicts: [theme()] });
    });

    it('never confirms an answer older than the newest conflict', () => {
        const confirmed = theme({ status: 'RESOLVED', version: 2 });

        expect(
            interventionPanel(execution([identity(), confirmed], 'RUNNING'), [
                ...answeredEvents,
            ]).kind,
        ).toBe('none');
    });

    it('keeps the confirmation of another stage out', () => {
        expect(
            interventionPanel(
                {
                    ...execution([identity()], 'RUNNING'),
                    consolidation: { stage: '06-aplicar-usuarios' },
                },
                answeredEvents,
            ).kind,
        ).toBe('none');
    });

    it('waits for the first intervention while it is being read', () => {
        expect(
            interventionPanel(execution([]), [
                event(1, 'consolidator.waiting_user_action'),
            ]).kind,
        ).toBe('preparing');
    });

    it('says nothing for executions outside a consolidation or already closed', () => {
        expect(
            interventionPanel(
                { status: 'WAITING_USER_ACTION', conflicts: [] },
                [],
            ).kind,
        ).toBe('none');
        expect(
            interventionPanel(execution([identity()], 'REVIEW'), answeredEvents)
                .kind,
        ).toBe('none');
    });
});

describe('stoppedAgainAfter', () => {
    it('falls back to the resolution time when its event is not on screen', () => {
        const later = {
            ...event(90, 'consolidator.waiting_user_action'),
            created_at: '2026-10-24T15:05:00Z',
        };
        const earlier = {
            ...later,
            created_at: '2026-10-24T14:59:00Z',
        };

        expect(stoppedAgainAfter(identity(), [later])).toBe(true);
        expect(stoppedAgainAfter(identity(), [earlier])).toBe(false);
        expect(
            stoppedAgainAfter(identity({ resolved_at: null }), [later]),
        ).toBe(false);
    });
});

describe('conflictSignature', () => {
    it('changes when a conflict opens, resolves or gets a new version', () => {
        const before = conflictSignature([identity()]);

        expect(conflictSignature([identity(), theme()])).not.toBe(before);
        expect(conflictSignature([identity({ version: 3 })])).not.toBe(before);
        expect(conflictSignature([identity()])).toBe(before);
    });
});
