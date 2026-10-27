import { describe, expect, it } from 'vitest';
import type { RemoteDestinationProfile } from '@/components/remote-access-panel';
import { awaitingFollowUp } from './remote-follow-up';

const finished = '2026-10-25T19:00:00Z';
const now = Date.parse('2026-10-25T19:00:20Z');

const destination = (
    changes: Partial<RemoteDestinationProfile> = {},
): RemoteDestinationProfile => ({
    id: 'campus',
    ready: false,
    checked_at: '2026-10-25T18:40:00Z',
    check_error: null,
    blockers: [],
    warnings: [],
    detected: true,
    valid: true,
    profile_error: null,
    public_url: null,
    docker_version: null,
    memory_bytes: null,
    runtime_free_bytes: null,
    target_running: false,
    destination_written: false,
    in_use: false,
    target_volumes: null,
    copias_packages: null,
    created: null,
    preparation: null,
    copias: null,
    fetches: [],
    ...changes,
});

const preparation = {
    state: 'SUCCEEDED',
    launched_at: '2026-10-25T18:45:00Z',
    finished_at: finished,
    observed_at: finished,
    exit_code: 0,
    error_code: null,
    observe_error: null,
    last_error_line: null,
    markers: [],
    tail: '',
};

describe('awaitingFollowUp', () => {
    it('waits for the check after a preparation, then stops', () => {
        expect(awaitingFollowUp(destination({ preparation }), now)).toBe(true);
        expect(
            awaitingFollowUp(
                destination({
                    preparation,
                    checked_at: '2026-10-25T19:00:10Z',
                }),
                now,
            ),
        ).toBe(false);
    });

    it('waits for the copias listing after a transfer (2026-10-25: second package)', () => {
        const fetches = [
            {
                source_package_uuid: 'p-2',
                state: 'SUCCEEDED',
                finished_at: finished,
            },
        ];
        const listedBefore = {
            listed_at: '2026-10-25T18:50:00Z',
            write_locked: false,
            packages: [],
        };
        expect(
            awaitingFollowUp(
                destination({ fetches, copias: listedBefore }),
                now,
            ),
        ).toBe(true);
        expect(
            awaitingFollowUp(
                destination({
                    fetches,
                    copias: {
                        ...listedBefore,
                        listed_at: '2026-10-25T19:00:05Z',
                    },
                }),
                now,
            ),
        ).toBe(false);
    });

    it('gives up after the window so a failed follow-up does not poll forever', () => {
        const later = Date.parse('2026-10-25T19:05:00Z');
        expect(awaitingFollowUp(destination({ preparation }), later)).toBe(
            false,
        );
    });
});
