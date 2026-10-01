/**
 * How hard an upcoming match is, from the backend's 0–10 `difficulty`
 * (MatchDifficulty — 0 = easiest, 10 = hardest; rival strength, home/away
 * and, for the very next match, the rival's absences).
 */
export type RivalDifficultyLevel = 'hard' | 'mid' | 'easy';

/** Below this 0–10 difficulty a match is easy. */
export const RIVAL_DIFFICULTY_EASY_BELOW = 3.5;

/** From this 0–10 difficulty on a match is hard (in between, mid). */
export const RIVAL_DIFFICULTY_HARD_FROM = 6.5;

export function rivalDifficultyLevel(difficulty: number): RivalDifficultyLevel {
    if (difficulty < RIVAL_DIFFICULTY_EASY_BELOW) {
        return 'easy';
    }

    return difficulty < RIVAL_DIFFICULTY_HARD_FROM ? 'mid' : 'hard';
}

/** 1–5 lit segments for the gauge — more bars, harder match. */
export function rivalDifficultyBars(difficulty: number): number {
    return Math.min(5, Math.max(1, Math.round(difficulty / 2)));
}

const DIFFICULTY_FORMAT = new Intl.NumberFormat('es-ES', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

/** "7,4" — one decimal, comma separator. */
export function formatDifficulty(difficulty: number): string {
    return DIFFICULTY_FORMAT.format(difficulty);
}

export const RIVAL_DIFFICULTY_LABELS: Record<RivalDifficultyLevel, string> = {
    hard: 'difícil',
    mid: 'media',
    easy: 'fácil',
};

/** Solid fill per level — hard red, mid amber, easy lime. */
export const RIVAL_DIFFICULTY_BG_CLASSES: Record<RivalDifficultyLevel, string> =
    {
        hard: 'bg-hq-live',
        mid: 'bg-hq-amber',
        easy: 'bg-hq-lime',
    };

/** Text colour per level (same hues as the solid fill). */
export const RIVAL_DIFFICULTY_TEXT_CLASSES: Record<
    RivalDifficultyLevel,
    string
> = {
    hard: 'text-hq-live',
    mid: 'text-hq-amber',
    easy: 'text-hq-lime',
};

/** Tinted box per level (same hues as the solid fill) — the Equipos calendar cells. */
export const RIVAL_DIFFICULTY_TINT_CLASSES: Record<
    RivalDifficultyLevel,
    string
> = {
    hard: 'border-hq-live/45 bg-hq-live/[0.17]',
    mid: 'border-hq-amber/45 bg-hq-amber/[0.17]',
    easy: 'border-hq-lime/45 bg-hq-lime/[0.17]',
};

/**
 * MaxBidCalculator's rival ease (−1 hard … +1 easy) back on the 0–10
 * difficulty scale — the exact inverse of MatchDifficultyResult's
 * `rivalEase = (5 − difficulty) / 5`, one decimal.
 */
export function difficultyFromEase(ease: number): number {
    return Math.round((5 - 5 * ease) * 10) / 10;
}
