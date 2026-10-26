import type { RemoteDestinationProfile } from '@/components/remote-access-panel';

/** How long the panel waits for the follow-up of a finished operation. */
export const FOLLOW_UP_WINDOW_MS = 3 * 60_000;

const before = (value: string | null | undefined, limit: string) =>
    !value || Date.parse(value) < Date.parse(limit);

const recent = (finished: string, now: number) =>
    now - Date.parse(finished) < FOLLOW_UP_WINDOW_MS;

/**
 * Whether a finished operation's follow-up is still on its way: after a
 * preparation the destination is checked again, after a transfer copias/ is
 * listed again. Both run over SSM after the state turns SUCCEEDED; in the
 * 2026-10-25 test the panel stopped reading before they arrived and the
 * profile and the copias list needed a manual reload.
 */
export function awaitingFollowUp(
    destination: RemoteDestinationProfile,
    now: number,
): boolean {
    const preparation = destination.preparation;
    if (
        preparation?.state === 'SUCCEEDED' &&
        preparation.finished_at &&
        recent(preparation.finished_at, now) &&
        before(destination.checked_at, preparation.finished_at)
    ) {
        return true;
    }

    return destination.fetches.some(
        (fetch) =>
            fetch.state === 'SUCCEEDED' &&
            !!fetch.finished_at &&
            recent(fetch.finished_at, now) &&
            before(destination.copias?.listed_at, fetch.finished_at),
    );
}
