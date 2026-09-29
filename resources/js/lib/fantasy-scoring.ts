import type { PlayerPosition } from '@/types/models';

/** The four outfield roles the official scoring table varies by — coaches don't score. */
export type ScoringPosition = Exclude<PlayerPosition, 'coach'>;

export const SCORING_POSITIONS: ScoringPosition[] = [
    'goalkeeper',
    'defender',
    'midfield',
    'striker',
];

/** A value that's either the same for every position, or spelled out per position. */
type PerPosition<T> = T | Record<ScoringPosition, T>;

export interface ScoringRule {
    label: string;
    /** Short clarifier shown under the label (e.g. "pase clave"). */
    sub?: string;
    /** Points earned. Omitted for the DAZN row, which uses `range` instead. */
    points?: PerPosition<number>;
    /** How many of the action it takes to earn `points` — e.g. 2 for "cada 2". */
    every?: PerPosition<number>;
    /** Free-form range shown instead of a fixed value (the DAZN row). */
    range?: string;
}

export interface ScoringRuleGroup {
    group: string;
    rules: ScoringRule[];
}

/**
 * The official LaLiga Fantasy scoring table (single source of truth for the
 * app — see the fixture ficha's scoring legend dialog). Values that vary by
 * position are keyed by {@link ScoringPosition}; everything else applies to
 * every position alike.
 */
export const SCORING_RULE_GROUPS: ScoringRuleGroup[] = [
    {
        group: 'Minutos',
        rules: [
            { label: 'Juega menos de 60', points: 1 },
            { label: 'Juega 60 o más', points: 2 },
        ],
    },
    {
        group: 'Ataque',
        rules: [
            {
                label: 'Gol',
                points: {
                    goalkeeper: 6,
                    defender: 6,
                    midfield: 5,
                    striker: 4,
                },
            },
            { label: 'Asistencia de gol', points: 3 },
            { label: 'Asistencia sin gol', sub: 'pase clave', points: 1 },
        ],
    },
    {
        group: 'Defensa',
        rules: [
            {
                label: 'Portería a cero',
                sub: 'más de 60 minutos',
                points: {
                    goalkeeper: 4,
                    defender: 3,
                    midfield: 2,
                    striker: 1,
                },
            },
            {
                label: 'Goles encajados',
                every: 2,
                points: {
                    goalkeeper: -2,
                    defender: -2,
                    midfield: -1,
                    striker: -1,
                },
            },
            { label: 'Paradas', every: 2, points: 1 },
        ],
    },
    {
        group: 'Penaltis y tarjetas',
        rules: [
            { label: 'Penalti provocado', points: 2 },
            { label: 'Penalti parado', points: 5 },
            { label: 'Penalti fallado', points: -2 },
            { label: 'Penalti cometido', points: -2 },
            { label: 'Tarjeta amarilla', points: -1 },
            { label: 'Doble amarilla', points: -1 },
            { label: 'Tarjeta roja', points: -3 },
        ],
    },
    {
        group: 'Bonus',
        rules: [
            { label: 'Tiros a puerta', every: 2, points: 1 },
            { label: 'Regates', every: 2, points: 1 },
            { label: 'Balones al área', every: 2, points: 1 },
            { label: 'Recuperaciones', every: 5, points: 1 },
            { label: 'Despejes', every: 3, points: 1 },
            {
                label: 'Pérdidas de balón',
                every: {
                    goalkeeper: 8,
                    defender: 8,
                    midfield: 10,
                    striker: 12,
                },
                points: -1,
            },
        ],
    },
    {
        group: 'DAZN',
        rules: [
            {
                label: 'Nota DAZN',
                sub: 'según la valoración del partido',
                range: '0 a 4',
            },
        ],
    },
];

function resolve<T>(
    value: PerPosition<T> | undefined,
    position: ScoringPosition,
): T | undefined {
    if (value === undefined) {
        return undefined;
    }

    return typeof value === 'object'
        ? (value as Record<ScoringPosition, T>)[position]
        : value;
}

/** The point value of `rule` for `position` — `undefined` for a `range` row (e.g. DAZN). */
export function scoringPointsForPosition(
    rule: ScoringRule,
    position: ScoringPosition,
): number | undefined {
    return resolve(rule.points, position);
}

/** The "cada N" frequency of `rule` for `position`, when it has one. */
export function scoringFrequencyForPosition(
    rule: ScoringRule,
    position: ScoringPosition,
): number | undefined {
    return resolve(rule.every, position);
}
