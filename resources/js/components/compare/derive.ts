import { resolveClauseStatus } from '@/lib/clause-status';
import type { ClauseStatus } from '@/lib/clause-status';
import { formatAverage, formatDecimal, formatMillions } from '@/lib/format';
import { startTone } from '@/lib/start-probability';
import type { StartTone } from '@/lib/start-probability';
import type {
    ComparedPlayer,
    ComparedPlayerScore,
    LeagueCloudRow,
    NextFixtureSlot,
    PlayerPosition,
} from '@/types/models';

export interface WeekCell {
    week: number;
    /** Null = no lineup row that jornada ("NC"). */
    score: ComparedPlayerScore | null;
}

export type AcquireKind =
    'market' | 'clause' | 'locked' | 'shielded' | 'owned' | 'free';

/** What it costs to get him today, and how (mock `derive().acquire`). */
export interface Acquire {
    kind: AcquireKind;
    amount: number | null;
    /** Listing expiry, clause lock or shield end. */
    until: string | null;
}

export interface DerivedPlayer {
    /** J1 … J(currentWeek − 1), oldest first. */
    weeks: WeekCell[];
    last3: WeekCell[];
    last3Points: number;
    last3Minutes: number;
    starts: number;
    minutes: number;
    /** Share of the possible minutes so far (0–100), null in jornada 1. */
    minutesShare: number | null;
    /** Mean of official DAZN ratings only (dazn_points), null without one. */
    daznAverage: number | null;
    clauseState: ClauseStatus | null;
    upcoming: NextFixtureSlot[];
    /** Mean 0–10 difficulty of the next fixtures that have one (lower = easier). */
    nextAverageDifficulty: number | null;
    nextAverageRivalPosition: number | null;
    nextHomeCount: number;
    startProbability: number | null;
    startTone: StartTone;
    acquire: Acquire;
}

/** Same rule as LeagueCloud::startValue(): confirmed lineup 100/0, else the %, else null. */
export function startProbabilityOf(player: ComparedPlayer): number | null {
    const start = player.next_start;

    if (start === null) {
        return null;
    }

    if (start.confirmed_starter !== null) {
        return start.confirmed_starter ? 100 : 0;
    }

    return start.probability;
}

function mean(values: number[]): number | null {
    return values.length === 0
        ? null
        : values.reduce((sum, value) => sum + value, 0) / values.length;
}

function acquireOf(
    player: ComparedPlayer,
    clauseState: ClauseStatus | null,
): Acquire {
    if (player.listing) {
        return {
            kind: 'market',
            amount: player.listing.sale_price,
            until: player.listing.expires_at,
        };
    }

    if (player.clause && clauseState === 'open') {
        return { kind: 'clause', amount: player.clause.amount, until: null };
    }

    if (player.clause && clauseState === 'locked') {
        return {
            kind: 'locked',
            amount: player.clause.amount,
            until: player.clause.locked_until,
        };
    }

    if (player.clause && clauseState === 'shielded') {
        return {
            kind: 'shielded',
            amount: player.clause.amount,
            until: player.clause.shielded_until,
        };
    }

    return { kind: player.owner ? 'owned' : 'free', amount: null, until: null };
}

export function derivePlayer(
    player: ComparedPlayer,
    currentWeek: number,
    now: number,
): DerivedPlayer {
    const byWeek = new Map(
        player.scores.map((score) => [score.week_number, score]),
    );
    const weeks: WeekCell[] = [];

    for (let week = 1; week < currentWeek; week++) {
        weeks.push({ week, score: byWeek.get(week) ?? null });
    }

    const last3 = weeks.slice(-3);
    const played = player.scores.filter((score) => score.minutes > 0);
    const minutes = player.scores.reduce(
        (sum, score) => sum + score.minutes,
        0,
    );
    const possibleMinutes = (currentWeek - 1) * 90;
    const upcoming = player.next_fixtures.filter(
        (slot): slot is NextFixtureSlot => slot !== null,
    );
    const difficulties = upcoming
        .map((slot) => slot.difficulty)
        .filter(
            (difficulty): difficulty is number =>
                typeof difficulty === 'number',
        );
    const clauseState = player.clause
        ? resolveClauseStatus(
              player.clause.shielded,
              player.clause.locked_until,
              now,
          )
        : null;
    const startProbability = startProbabilityOf(player);

    return {
        weeks,
        last3,
        last3Points: last3.reduce(
            (sum, cell) => sum + (cell.score?.points ?? 0),
            0,
        ),
        last3Minutes: last3.reduce(
            (sum, cell) => sum + (cell.score?.minutes ?? 0),
            0,
        ),
        starts: player.scores.filter(
            (score) => score.starter && score.minutes > 0,
        ).length,
        minutes,
        minutesShare:
            possibleMinutes > 0
                ? Math.round((minutes / possibleMinutes) * 100)
                : null,
        daznAverage: mean(
            played
                .map((score) => score.dazn_points)
                .filter((value): value is number => value !== null),
        ),
        clauseState,
        upcoming,
        nextAverageDifficulty: mean(difficulties),
        nextAverageRivalPosition: mean(
            upcoming
                .map((slot) => slot.rival_position)
                .filter((position): position is number => position !== null),
        ),
        nextHomeCount: upcoming.filter((slot) => slot.is_home).length,
        startProbability,
        startTone: startTone(startProbability, player.status),
        acquire: acquireOf(player, clauseState),
    };
}

/** Index of the unique best value — null on a tie or with fewer than two values (kit.js `winner`). */
export function winner(
    values: (number | null)[],
    lowerIsBetter = false,
): number | null {
    let best: number | null = null;
    let index: number | null = null;
    let tie = false;
    let count = 0;

    values.forEach((value, position) => {
        if (value === null || Number.isNaN(value)) {
            return;
        }

        count++;

        if (best === null || (lowerIsBetter ? value < best : value > best)) {
            best = value;
            index = position;
            tie = false;
        } else if (value === best) {
            tie = true;
        }
    });

    return count < 2 || tie ? null : index;
}

/** "9d 18h" or "18h"; null once it is over. */
export function formatLockLeft(iso: string, now: number): string | null {
    const ms = Date.parse(iso) - now;

    if (ms <= 0) {
        return null;
    }

    const days = Math.floor(ms / 86_400_000);
    const hours = Math.floor((ms % 86_400_000) / 3_600_000);

    return days > 0 ? `${days}d ${hours}h` : `${hours}h`;
}

/** "2d 07h" or "07:12:09" — a market listing's time left. */
export function formatCountdown(iso: string, now: number): string {
    const ms = Date.parse(iso) - now;

    if (ms <= 0) {
        return '00:00:00';
    }

    const pad = (value: number) => String(value).padStart(2, '0');
    const days = Math.floor(ms / 86_400_000);
    const hours = Math.floor((ms % 86_400_000) / 3_600_000);
    const minutes = Math.floor((ms % 3_600_000) / 60_000);
    const seconds = Math.floor((ms % 60_000) / 1000);

    return days > 0
        ? `${days}d ${pad(hours)}h`
        : `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
}

/** "+3,2 %", "−1 %", "0 %". */
export function formatPercentChange(value: number): string {
    const rounded = Math.round(value * 10) / 10;
    const sign = rounded > 0 ? '+' : rounded < 0 ? '−' : '';

    return `${sign}${String(Math.abs(rounded)).replace('.', ',')} %`;
}

/** % change of each snapshot over the first one. */
export function percentSeries(history: [string, number][]): number[] {
    const base = history[0]?.[1] ?? 0;

    return base > 0
        ? history.map(([, value]) => (value / base - 1) * 100)
        : history.map(() => 0);
}

function fold(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
}

/** Accent-insensitive name match, or a team short-name prefix (kit.js `search`). League rows come sorted by points. */
export function searchLeague(
    league: LeagueCloudRow[],
    query: string,
    excludeIds: number[],
    limit: number,
): LeagueCloudRow[] {
    const needle = fold(query.trim());

    return league
        .filter((row) => !excludeIds.includes(row.id))
        .filter(
            (row) =>
                needle === '' ||
                fold(row.name).includes(needle) ||
                fold(row.team_short).startsWith(needle),
        )
        .slice(0, limit);
}

/** Six of the same position with the closest value to the base player; the top scorers without one. */
export function suggestPlayers(
    league: LeagueCloudRow[],
    base: ComparedPlayer | null,
    excludeIds: number[],
): LeagueCloudRow[] {
    if (base === null) {
        return searchLeague(league, '', excludeIds, 8);
    }

    return league
        .filter(
            (row) =>
                !excludeIds.includes(row.id) && row.position === base.position,
        )
        .sort(
            (a, b) =>
                Math.abs(a.value - base.value) - Math.abs(b.value - base.value),
        )
        .slice(0, 6);
}

export type TrackScale = 'linear' | 'sqrt' | 'log';
export type TrackScope = 'all' | 'position';

/** One strip of view B. `league` returns null for a row outside the "toda la liga" population. */
export interface TrackMetric {
    key: 'points' | 'average' | 'ppm' | 'start' | 'rise' | 'value';
    label: string;
    note: string;
    scale: TrackScale;
    noBest?: boolean;
    fixed?: [number, number];
    league: (row: LeagueCloudRow) => number | null;
    player: (player: ComparedPlayer, derived: DerivedPlayer) => number | null;
    format: (value: number) => string;
}

export function trackMetrics(currentWeek: number): TrackMetric[] {
    return [
        {
            key: 'points',
            label: 'Puntos',
            note: 'total de la temporada',
            scale: 'linear',
            league: (row) => (row.points > 0 ? row.points : null),
            player: (player) => player.points,
            format: (value) => String(value),
        },
        {
            key: 'average',
            label: 'Media',
            note: 'puntos por partido',
            scale: 'linear',
            league: (row) => (row.points > 0 ? row.average_points : null),
            player: (player) => player.average_points,
            format: formatAverage,
        },
        {
            key: 'ppm',
            label: 'Pts / M€',
            note: 'puntos por millón de valor',
            scale: 'sqrt',
            league: (row) => (row.points > 0 ? row.ppm : null),
            player: (player) => player.points_per_million?.value ?? null,
            format: formatDecimal,
        },
        {
            key: 'start',
            label: `Titularidad J${currentWeek}`,
            note: 'probabilidad FútbolFantasy',
            scale: 'linear',
            fixed: [0, 100],
            league: (row) => row.start_probability,
            player: (_, derived) =>
                derived.startTone === 'out' ? 0 : derived.startProbability,
            format: (value) => `${value} %`,
        },
        {
            key: 'rise',
            label: 'Subida 30 días',
            note: 'valor hoy ÷ hace 30 días',
            scale: 'log',
            league: (row) =>
                row.value_trend_30d !== null && row.value_trend_30d > 0
                    ? row.value_trend_30d
                    : null,
            player: (player) => {
                const multiple = player.value_trend_30d?.multiple ?? null;

                return multiple !== null && multiple > 0 ? multiple : null;
            },
            format: (value) => `×${formatDecimal(value)}`,
        },
        {
            key: 'value',
            label: 'Valor',
            note: 'sin ganador: caro no es mejor',
            scale: 'sqrt',
            noBest: true,
            league: (row) => (row.value > 0 ? row.value : null),
            player: (player) => (player.value > 0 ? player.value : null),
            format: formatMillions,
        },
    ];
}

/** The population's values for a track: the whole league, or only the compared players' positions. */
export function trackValues(
    league: LeagueCloudRow[],
    metric: TrackMetric,
    scope: TrackScope,
    positions: PlayerPosition[],
): number[] {
    return league
        .filter((row) => scope === 'all' || positions.includes(row.position))
        .map(metric.league)
        .filter((value): value is number => value !== null);
}

export function rankIn(values: number[], value: number): number {
    return values.filter((other) => other > value).length + 1;
}

export function beatsPercent(values: number[], value: number): number {
    return values.length === 0
        ? 0
        : Math.floor(
              (values.filter((other) => other < value).length / values.length) *
                  100,
          );
}

export function medianOf(values: number[]): number | null {
    if (values.length === 0) {
        return null;
    }

    const sorted = [...values].sort((a, b) => a - b);

    return sorted[Math.floor(sorted.length / 2)];
}

export function scaleOf(scale: TrackScale): (value: number) => number {
    if (scale === 'sqrt') {
        return (value) => Math.sqrt(Math.max(0, value));
    }

    if (scale === 'log') {
        return (value) => Math.log(Math.max(0.05, value));
    }

    return (value) => value;
}

/** 0–100 along the track. */
export function trackPosition(
    value: number,
    [low, high]: [number, number],
    scale: TrackScale,
): number {
    const transform = scaleOf(scale);
    const span = transform(high) - transform(low) || 1;

    return Math.max(
        0,
        Math.min(100, ((transform(value) - transform(low)) / span) * 100),
    );
}

/** Stable pseudo-random 0–1 for a dot's vertical jitter (mock `hash`). */
export function jitter(id: number, salt: number): number {
    const x = Math.sin((id + salt) * 9301 + 49297) * 233280;

    return x - Math.floor(x);
}
