import type { FixtureState } from '@/types/models';

const LIVE_STATES: FixtureState[] = ['first_half', 'half_time', 'second_half'];

export function isLiveFixtureState(state: FixtureState): boolean {
    return LIVE_STATES.includes(state);
}

export const FIXTURE_STATE_LABELS: Record<FixtureState, string> = {
    scheduled: '',
    first_half: '1ª PARTE',
    half_time: 'DESCANSO',
    second_half: '2ª PARTE',
    finished: 'FINAL',
};
