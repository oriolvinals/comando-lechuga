import type { FixtureState } from '@/types/models';

export const COUNTDOWN_THRESHOLD_MS = 2 * 60 * 60 * 1000;

const LIVE_STATES: FixtureState[] = ['first_half', 'half_time', 'second_half'];

export function isLiveFixtureState(state: FixtureState): boolean {
    return LIVE_STATES.includes(state);
}

export const FIXTURE_STATE_LABELS: Record<FixtureState, string> = {
    scheduled: '',
    first_half: '1ª PARTE',
    half_time: 'DESCANSO',
    second_half: '2ª PARTE',
    finished: 'FINALIZADO',
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
            return null;
    }
}
