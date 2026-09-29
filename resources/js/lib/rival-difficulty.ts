/**
 * How hard an upcoming match is, from the backend's 0–10 `difficulty`
 * (MatchDifficulty — 0 = easiest, 10 = hardest; rival strength, home/away
 * and, for the very next match, the rival's absences).
 */
export type RivalDifficultyLevel = 'hard' | 'mid' | 'easy';

export function rivalDifficultyLevel(difficulty: number): RivalDifficultyLevel {
    if (difficulty < 3.5) {
        return 'easy';
    }

    return difficulty < 6.5 ? 'mid' : 'hard';
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
