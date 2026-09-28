import type { Fixture, FixtureState } from '@/types/models';

export const COUNTDOWN_THRESHOLD_MS = 2 * 60 * 60 * 1000;

const HOUR_MS = 60 * 60 * 1000;

/** How long before kickoff the match ficha starts refreshing itself. */
const REFRESH_BEFORE_KICKOFF_MS = HOUR_MS;

/**
 * How long after kickoff the match ficha keeps refreshing itself when the
 * match isn't live: about 2 h of match plus 1 h after the final whistle
 * (there's no stored end time).
 */
const REFRESH_AFTER_KICKOFF_MS = 3 * HOUR_MS;

const LIVE_STATES: FixtureState[] = ['first_half', 'half_time', 'second_half'];

export function isLiveFixtureState(state: FixtureState): boolean {
    return LIVE_STATES.includes(state);
}

/**
 * Whether the match ficha should keep reloading its props: always while the
 * fixture is live, else from 1 h before kickoff to 3 h after it. Never for a
 * postponed fixture.
 */
export function isInFixtureRefreshWindow(
    fixture: Pick<Fixture, 'date' | 'state'>,
    now: number,
): boolean {
    if (fixture.state === 'postponed') {
        return false;
    }

    if (isLiveFixtureState(fixture.state)) {
        return true;
    }

    const kickoff = new Date(fixture.date).getTime();

    return (
        now >= kickoff - REFRESH_BEFORE_KICKOFF_MS &&
        now <= kickoff + REFRESH_AFTER_KICKOFF_MS
    );
}

export const FIXTURE_STATE_LABELS: Record<FixtureState, string> = {
    scheduled: '',
    first_half: '1ª PARTE',
    half_time: 'DESCANSO',
    second_half: '2ª PARTE',
    finished: 'FINALIZADO',
    postponed: 'APLAZADO',
};

/**
 * The line shown under the state label: the kickoff date for a finished
 * fixture, the live match clock during either half, and nothing during
 * half-time. Returns null when there's nothing to show.
 *
 * Doesn't handle 'scheduled' — the caller already renders a countdown or
 * the date up top for that state (see COUNTDOWN_THRESHOLD_MS), so the
 * secondary line there is the caller's call, not this formatter's.
 *
 * `formatDate` is left to the caller since different layouts use different
 * date formats (e.g. with or without the weekday name).
 */
export function formatFixtureSecondaryText(
    state: FixtureState,
    isoDate: string,
    displayClock: string | null,
    formatDate: (isoDate: string) => string,
): string | null {
    switch (state) {
        case 'finished':
            return formatDate(isoDate);
        case 'first_half':
        case 'second_half':
            return displayClock;
        case 'scheduled':
        case 'half_time':
        case 'postponed':
            return null;
    }
}
