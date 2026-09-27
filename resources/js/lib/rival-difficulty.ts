/**
 * How hard an upcoming rival is, from the backend's `difficulty` score
 * (LeagueStandings::difficulty — −1 for the table leader, 0 mid table, +1 for
 * the last team; the same scale the max bid model weighs rivals with).
 */
export type RivalDifficultyLevel = 'hard' | 'mid' | 'easy';

export function rivalDifficultyLevel(difficulty: number): RivalDifficultyLevel {
    if (difficulty <= -0.45) {
        return 'hard';
    }

    return difficulty < 0.45 ? 'mid' : 'easy';
}

/** 1–5 lit segments for the gauge: 5 against the leader, 1 against the last team. */
export function rivalDifficultyBars(difficulty: number): number {
    return Math.max(1, Math.min(5, Math.round((1 - difficulty) * 2) + 1));
}

export const RIVAL_DIFFICULTY_LABELS: Record<RivalDifficultyLevel, string> = {
    hard: 'alta',
    mid: 'media',
    easy: 'baja',
};

/** Solid fill per level — hard red, mid amber, easy lime. */
export const RIVAL_DIFFICULTY_BG_CLASSES: Record<RivalDifficultyLevel, string> =
    {
        hard: 'bg-hq-live',
        mid: 'bg-hq-amber',
        easy: 'bg-hq-lime',
    };
