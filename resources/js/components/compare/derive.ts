import { describeMarketTrend } from '@/components/hq-market-trend-icon';
import { resolveClauseStatus } from '@/lib/clause-status';
import type { ClauseStatus } from '@/lib/clause-status';
import { formatAverage, formatDecimal, formatMillions } from '@/lib/format';
import { STATUS_LABELS } from '@/lib/player-labels';
import { formatDifficulty, rivalDifficultyLevel } from '@/lib/rival-difficulty';
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
    /** Null = no lineup row that jornada ("NC"), unless `pending`. */
    score: ComparedPlayerScore | null;
    /** No lineup row yet and his team's match that jornada is still scheduled or in play: not NC, and left out of the form, the averages and the winners. */
    pending: boolean;
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
    /** The past jornadas without the pending ones. */
    settledWeeks: WeekCell[];
    /** The last three settled jornadas. */
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
    /** His match of jornada currentWeek (the "Rival J{N}" rows); null when his team doesn't play it. */
    nextSlot: NextFixtureSlot | null;
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
    const pendingWeeks = new Set(player.pending_weeks);
    const weeks: WeekCell[] = [];

    for (let week = 1; week < currentWeek; week++) {
        const score = byWeek.get(week) ?? null;

        weeks.push({
            week,
            score,
            pending: score === null && pendingWeeks.has(week),
        });
    }

    const settled = weeks.filter((cell) => !cell.pending);
    const last3 = settled.slice(-3);
    const played = player.scores.filter((score) => score.minutes > 0);
    const minutes = player.scores.reduce(
        (sum, score) => sum + score.minutes,
        0,
    );
    const possibleMinutes = settled.length * 90;
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
        settledWeeks: settled,
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
        nextSlot:
            upcoming.find((slot) => slot.week_number === currentWeek) ?? null,
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

/** The figure the "Jornada a jornada" row shows in each week. */
export type WeekMetric = 'points' | 'dazn' | 'minutes';

/** A jornada's figure: null = didn't play (minutes still count 0'), and DAZN counts official ratings only. */
export function weekValue(
    score: ComparedPlayerScore | null,
    metric: WeekMetric,
): number | null {
    if (!score) {
        return null;
    }

    if (metric === 'minutes') {
        return score.minutes;
    }

    if (score.minutes === 0) {
        return null;
    }

    return metric === 'dazn' ? score.dazn_points : score.points;
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

/** Tomorrow's forecast change: "+3 %", "−1,2 %". */
function formatForecastPct(value: number): string {
    return `${value.toLocaleString('es-ES', { maximumFractionDigits: 1, signDisplay: 'always' })} %`;
}

/** % change of each snapshot over the first one. */
export function percentSeries(history: [string, number][]): number[] {
    const base = history[0]?.[1] ?? 0;

    return base > 0
        ? history.map(([, value]) => (value / base - 1) * 100)
        : history.map(() => 0);
}

/** Lower-cased and accent-stripped, for accent-insensitive matching. */
export function fold(text: string): string {
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

/** One "En la liga" track. `league` returns null for a row outside the "toda la liga" population. */
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
            player: (player) => (player.points > 0 ? player.points : null),
            format: (value) => String(value),
        },
        {
            key: 'average',
            label: 'Media',
            note: 'puntos por partido',
            scale: 'linear',
            league: (row) => (row.points > 0 ? row.average_points : null),
            player: (player) =>
                player.points > 0 ? player.average_points : null,
            format: formatAverage,
        },
        {
            key: 'ppm',
            label: 'Pts / M€',
            note: 'puntos por millón de valor',
            scale: 'sqrt',
            league: (row) => (row.points > 0 ? row.ppm : null),
            player: (player) =>
                player.points > 0
                    ? (player.points_per_million?.value ?? null)
                    : null,
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

/** One league player in a track's dot cloud. */
export interface TrackDot {
    row: LeagueCloudRow;
    value: number;
    /** 0–100 along the track. */
    x: number;
    /** 0–26 inside the cloud. */
    y: number;
}

export interface LeagueTrack {
    metric: TrackMetric;
    /** The population: the league without the compared players, plus their live values. */
    values: number[];
    domain: [number, number];
    median: number | null;
    dots: TrackDot[];
    playerValues: (number | null)[];
    best: number | null;
}

/**
 * Every "En la liga" track. The league rows are cached for minutes, so the
 * compared players are dropped from them and count with their live figures
 * instead (in the population, the rank, the median and the domain).
 */
export function leagueTracks(
    league: LeagueCloudRow[],
    players: ComparedPlayer[],
    derived: DerivedPlayer[],
    currentWeek: number,
    scope: TrackScope,
): LeagueTrack[] {
    const positions = players.map((player) => player.position);
    const comparedIds = new Set(players.map((player) => player.id));
    const others = league.filter((row) => !comparedIds.has(row.id));

    return trackMetrics(currentWeek).map((metric, trackIndex) => {
        const playerValues = players.map((player, index) =>
            metric.player(player, derived[index]),
        );
        const live = playerValues.filter(
            (value): value is number => value !== null,
        );
        const values = [
            ...trackValues(others, metric, scope, positions),
            ...live,
        ];
        const domain: [number, number] =
            metric.fixed ??
            (values.length === 0
                ? [0, 1]
                : [Math.min(...values), Math.max(...values)]);
        const dots: TrackDot[] = [];

        for (const row of others) {
            const value = metric.league(row);

            if (
                value === null ||
                (scope === 'position' && !positions.includes(row.position))
            ) {
                continue;
            }

            dots.push({
                row,
                value,
                x: trackPosition(value, domain, metric.scale),
                y: 3 + jitter(row.id, trackIndex) * 17,
            });
        }

        return {
            metric,
            values,
            domain,
            median: medianOf(values),
            dots,
            playerValues,
            best: metric.noBest ? null : winner(playerValues),
        };
    });
}

/** A compared player's place on a track: "N.º de M", null without a value. */
export function trackRank(
    track: LeagueTrack | undefined,
    playerIndex: number,
): { rank: number; of: number } | null {
    const value = track?.playerValues[playerIndex] ?? null;

    return track && value !== null
        ? { rank: rankIn(track.values, value), of: track.values.length }
        : null;
}

export type VerdictLens = 'buy' | 'sell' | 'start';

export interface VerdictReason {
    /** Bold opening ("En el mercado a 5,2 M€"). */
    lead: string;
    rest: string;
}

/** The coloured square of a text evidence ("Cómo conseguirlo", "Propiedad"): go, locked, not possible, or a warning. */
export type VerdictTone = 'ok' | 'lock' | 'no' | 'warn';

export interface VerdictEvidenceRow {
    label: string;
    hint: string | null;
    texts: string[];
    values: (number | null)[] | null;
    lowerIsBetter: boolean;
    /** Fichar/Alinear mark the best; Vender marks the worst signal (the lowest value) in red. */
    mark: 'best' | 'worst' | null;
    /** Text evidence only: a status square per player. */
    tones?: VerdictTone[];
}

export interface Verdict {
    title: string;
    /** Player indexes, best score first. */
    order: number[];
    /** Players ruled out for this lens (can't be bought now / won't play). */
    out: boolean[];
    /** Null when even the top score is ruled out ("Ninguno"). */
    recommended: number | null;
    reasons: VerdictReason[];
    rows: VerdictEvidenceRow[];
}

type ScoredLens = Omit<Verdict, 'order' | 'recommended'> & {
    scores: number[];
};

/** Min–max among the compared players (mock D `norm`): missing = 0, all equal = 0,5. */
export function normalizeAmong(
    values: (number | null)[],
    lowerIsBetter = false,
): number[] {
    const present = values.filter((value): value is number => value !== null);

    if (present.length === 0) {
        return values.map(() => 0);
    }

    const low = Math.min(...present);
    const high = Math.max(...present);

    return values.map((value) => {
        if (value === null) {
            return 0;
        }

        if (high === low) {
            return 0.5;
        }

        const normalized = (value - low) / (high - low);

        return lowerIsBetter ? 1 - normalized : normalized;
    });
}

function canBuy(item: DerivedPlayer): boolean {
    return item.acquire.kind === 'market' || item.acquire.kind === 'clause';
}

function lockLeftOf(item: DerivedPlayer, now: number): string | null {
    return item.acquire.until ? formatLockLeft(item.acquire.until, now) : null;
}

function acquireText(
    item: DerivedPlayer,
    player: ComparedPlayer,
    now: number,
): string {
    const amount =
        item.acquire.amount === null
            ? '—'
            : formatMillions(item.acquire.amount);

    switch (item.acquire.kind) {
        case 'market':
            return `Mercado · ${amount}`;
        case 'clause':
            return `Cláusula abierta · ${amount}${player.owner ? ` · de ${player.owner.name}` : ''}`;
        case 'locked': {
            const left = lockLeftOf(item, now);

            return `Bloqueada · ${amount}${left ? ` · se abre en ${left}` : ''}`;
        }
        case 'shielded':
            return `Blindado · ${amount}`;
        case 'owned':
            return 'Con dueño';
        default:
            return 'Libre · no está en el mercado';
    }
}

/** Fichar: can he be bought now? Vender: is he exposed (listed, open clause) or safe (locked, shielded)? */
function acquireTone(kind: AcquireKind, lens: 'buy' | 'sell'): VerdictTone {
    if (lens === 'buy') {
        if (kind === 'market' || kind === 'clause') {
            return 'ok';
        }

        return kind === 'locked' || kind === 'shielded' ? 'lock' : 'no';
    }

    if (kind === 'market' || kind === 'clause') {
        return 'warn';
    }

    return kind === 'locked' || kind === 'shielded' ? 'ok' : 'no';
}

function isFalling(player: ComparedPlayer): boolean {
    return player.trend !== null && !describeMarketTrend(player.trend).rising;
}

function startText(item: DerivedPlayer): string {
    if (item.startTone === 'out') {
        return 'Baja';
    }

    return item.startProbability === null ? '—' : `${item.startProbability} %`;
}

/** Tomorrow's forecast change (%) of each player, null without one (always null outside god mode). */
function forecastValuesOf(players: ComparedPlayer[]): (number | null)[] {
    return players.map((player) => player.forecast?.change_pct ?? null);
}

/** The «Mañana» evidence row, only when at least one compared player has a forecast. */
function forecastRows(
    forecastValues: (number | null)[],
    mark: 'best' | 'worst',
): VerdictEvidenceRow[] {
    if (!forecastValues.some((value) => value !== null)) {
        return [];
    }

    return [
        {
            label: 'Mañana',
            hint: 'previsión',
            texts: forecastValues.map((value) =>
                value === null ? '—' : formatForecastPct(value),
            ),
            values: forecastValues,
            lowerIsBetter: false,
            mark,
        },
    ];
}

function buyLens(
    players: ComparedPlayer[],
    derived: DerivedPlayer[],
    now: number,
): ScoredLens {
    const ppmValues = players.map(
        (player) => player.points_per_million?.value ?? null,
    );
    const riseValues = players.map(
        (player) => player.value_trend_30d?.multiple ?? null,
    );
    const ppm = normalizeAmong(ppmValues);
    const average = normalizeAmong(
        players.map((player) => player.average_points),
    );
    const rise = normalizeAmong(riseValues);
    // 0–10 difficulty: the lowest average is the easiest calendar.
    const easyCalendar = normalizeAmong(
        derived.map((item) => item.nextAverageDifficulty),
        true,
    );
    const forecastValues = forecastValuesOf(players);
    const risesTomorrow = normalizeAmong(forecastValues);

    return {
        title: 'Para fichar',
        scores: players.map(
            (_, index) =>
                (canBuy(derived[index]) ? 1 : -2) +
                0.9 * ppm[index] +
                average[index] +
                0.6 * rise[index] +
                0.4 * easyCalendar[index] +
                0.5 * risesTomorrow[index],
        ),
        out: derived.map((item) => !canBuy(item)),
        reasons: players.map((player, index) => {
            const item = derived[index];

            if (canBuy(item)) {
                return {
                    lead: `${item.acquire.kind === 'market' ? 'En el mercado' : 'Cláusula abierta'} a ${formatMillions(item.acquire.amount ?? 0)}`,
                    rest: `${player.points_per_million ? `${formatDecimal(player.points_per_million.value)} pts/M€ · ` : ''}media ${formatAverage(player.average_points)}`,
                };
            }

            if (item.acquire.kind === 'locked') {
                const left = lockLeftOf(item, now);

                return {
                    lead: 'Cláusula bloqueada',
                    rest: left ? `${left} más` : '',
                };
            }

            return item.acquire.kind === 'free'
                ? { lead: 'Libre', rest: 'pero hoy no está en el mercado' }
                : { lead: 'No se puede fichar ahora', rest: '' };
        }),
        rows: [
            {
                label: 'Cómo conseguirlo',
                hint: 'y cuánto cuesta',
                texts: players.map((player, index) =>
                    acquireText(derived[index], player, now),
                ),
                values: null,
                lowerIsBetter: false,
                mark: null,
                tones: derived.map((item) =>
                    acquireTone(item.acquire.kind, 'buy'),
                ),
            },
            {
                label: 'Pts / M€',
                hint: null,
                texts: ppmValues.map((value) =>
                    value === null ? '—' : formatDecimal(value),
                ),
                values: ppmValues,
                lowerIsBetter: false,
                mark: 'best',
            },
            {
                label: 'Media',
                hint: 'por partido',
                texts: players.map((player) =>
                    formatAverage(player.average_points),
                ),
                values: players.map((player) => player.average_points),
                lowerIsBetter: false,
                mark: 'best',
            },
            {
                label: 'Subida 30 días',
                hint: null,
                texts: riseValues.map((value) =>
                    value === null ? '—' : `×${formatDecimal(value)}`,
                ),
                values: riseValues,
                lowerIsBetter: false,
                mark: 'best',
            },
            {
                label: 'Próximos 3',
                hint: 'dificultad media',
                texts: derived.map((item) =>
                    item.nextAverageDifficulty === null
                        ? '—'
                        : formatDifficulty(item.nextAverageDifficulty),
                ),
                values: derived.map((item) => item.nextAverageDifficulty),
                lowerIsBetter: true,
                mark: 'best',
            },
            ...forecastRows(forecastValues, 'best'),
        ],
    };
}

function sellLens(
    players: ComparedPlayer[],
    derived: DerivedPlayer[],
    currentWeek: number,
    now: number,
): ScoredLens {
    const startValues = derived.map((item) =>
        item.startTone === 'out' ? 0 : item.startProbability,
    );
    const dropping = normalizeAmong(
        players.map((player) => player.difference),
        true,
    );
    // 0–10 difficulty: the highest average is the hardest calendar.
    const hardCalendar = normalizeAmong(
        derived.map((item) => item.nextAverageDifficulty),
    );
    const lowStart = normalizeAmong(startValues, true);
    const badForm = normalizeAmong(
        derived.map((item) => item.last3Points),
        true,
    );
    const forecastValues = forecastValuesOf(players);
    const fallsTomorrow = normalizeAmong(forecastValues, true);

    return {
        title: 'Vender antes',
        scores: players.map(
            (player, index) =>
                1.2 * dropping[index] +
                0.7 * hardCalendar[index] +
                lowStart[index] +
                0.8 * badForm[index] +
                (isFalling(player) ? 0.6 : 0) +
                0.8 * fallsTomorrow[index],
        ),
        out: players.map(() => false),
        reasons: players.map((player, index) => {
            const item = derived[index];
            const bits: string[] = [];

            if (player.trend !== null) {
                bits.push(
                    describeMarketTrend(player.trend).label.toLowerCase(),
                );
            }

            if (
                item.nextAverageDifficulty !== null &&
                rivalDifficultyLevel(item.nextAverageDifficulty) === 'hard'
            ) {
                bits.push('calendario difícil');
            }

            if (item.startTone === 'low' || item.startTone === 'out') {
                bits.push(
                    `titularidad ${item.startTone === 'out' ? 'nula' : `${item.startProbability} %`}`,
                );
            }

            if (player.difference < 0) {
                return {
                    lead: `Pierde ${formatMillions(Math.abs(player.difference))} hoy`,
                    rest: bits.join(' · '),
                };
            }

            return bits.length > 0
                ? { lead: '', rest: bits.join(' · ') }
                : { lead: 'Sin señales de venta', rest: '' };
        }),
        rows: [
            {
                label: 'Valor hoy',
                hint: 'sin ganador',
                texts: players.map((player) => formatMillions(player.value)),
                values: null,
                lowerIsBetter: false,
                mark: null,
            },
            {
                label: 'Tendencia',
                hint: 'cambio de hoy',
                texts: players.map((player) =>
                    player.trend
                        ? describeMarketTrend(player.trend).label
                        : '—',
                ),
                values: players.map((player) => player.difference),
                lowerIsBetter: false,
                mark: 'worst',
            },
            {
                label: 'Últimas 3',
                hint: 'puntos · minutos',
                texts: derived.map(
                    (item) => `${item.last3Points} pts · ${item.last3Minutes}'`,
                ),
                values: derived.map((item) => item.last3Points),
                lowerIsBetter: false,
                mark: 'worst',
            },
            {
                label: `Titularidad J${currentWeek}`,
                hint: null,
                texts: derived.map(startText),
                values: startValues,
                lowerIsBetter: false,
                mark: 'worst',
            },
            {
                label: 'Propiedad',
                hint: 'cláusula',
                texts: players.map((player, index) =>
                    acquireText(derived[index], player, now),
                ),
                values: null,
                lowerIsBetter: false,
                mark: null,
                tones: derived.map((item) =>
                    acquireTone(item.acquire.kind, 'sell'),
                ),
            },
            ...forecastRows(forecastValues, 'worst'),
        ],
    };
}

function rivalText(slot: NextFixtureSlot): string {
    const details = [
        slot.rival_position !== null ? `${slot.rival_position}.º` : null,
        slot.difficulty !== null
            ? `dificultad ${formatDifficulty(slot.difficulty)}`
            : null,
    ].filter((detail): detail is string => detail !== null);

    return `${slot.is_home ? 'vs' : '@'} ${slot.opponent.main_name}${details.length > 0 ? ` (${details.join(', ')})` : ''}`;
}

function startLens(
    players: ComparedPlayer[],
    derived: DerivedPlayer[],
    currentWeek: number,
): ScoredLens {
    const rivalValues = derived.map(
        (item) => item.nextSlot?.difficulty ?? null,
    );
    const start = normalizeAmong(
        derived.map((item) =>
            item.startTone === 'out' ? null : item.startProbability,
        ),
    );
    // 0–10 difficulty: the lowest is the easiest rival.
    const easyRival = normalizeAmong(rivalValues, true);
    const form = normalizeAmong(derived.map((item) => item.last3Points));
    const dazn = normalizeAmong(derived.map((item) => item.daznAverage));

    return {
        title: `Para alinear en J${currentWeek}`,
        scores: players.map(
            (_, index) =>
                (derived[index].startTone === 'out' ? -5 : 0) +
                1.4 * start[index] +
                0.8 * easyRival[index] +
                form[index] +
                0.6 * dazn[index],
        ),
        out: derived.map((item) => item.startTone === 'out'),
        reasons: players.map((player, index) => {
            const item = derived[index];
            const next = item.nextSlot;

            if (item.startTone === 'out') {
                return { lead: STATUS_LABELS[player.status], rest: 'no juega' };
            }

            return {
                lead:
                    item.startProbability === null
                        ? 'Sin %'
                        : `${item.startProbability} % titular`,
                rest: `${next ? `${rivalText(next)} · ` : ''}${item.last3Points} pts en 3 jornadas`,
            };
        }),
        rows: [
            {
                label: `Titularidad J${currentWeek}`,
                hint: 'FútbolFantasy',
                texts: derived.map(startText),
                values: derived.map((item) =>
                    item.startTone === 'out' ? -1 : item.startProbability,
                ),
                lowerIsBetter: false,
                mark: 'best',
            },
            {
                label: `Rival J${currentWeek}`,
                hint: 'dificultad 0–10',
                texts: derived.map((item) => {
                    const next = item.nextSlot;

                    if (!next) {
                        return '—';
                    }

                    return `${next.is_home ? '' : '@ '}${next.opponent.short_name} · ${next.difficulty !== null ? formatDifficulty(next.difficulty) : '—'}`;
                }),
                values: rivalValues,
                lowerIsBetter: true,
                mark: 'best',
            },
            {
                label: 'Forma',
                hint: 'últimas 3',
                texts: derived.map((item) => `${item.last3Points} pts`),
                values: derived.map((item) => item.last3Points),
                lowerIsBetter: false,
                mark: 'best',
            },
            {
                label: 'Media DAZN',
                hint: 'oficial',
                texts: derived.map((item) =>
                    item.daznAverage === null
                        ? '—'
                        : formatAverage(item.daznAverage),
                ),
                values: derived.map((item) =>
                    item.daznAverage === null
                        ? null
                        : Math.round(item.daznAverage * 10) / 10,
                ),
                lowerIsBetter: false,
                mark: 'best',
            },
        ],
    };
}

/**
 * God mode only: which of the compared players to buy, sell or field (spec §5b,
 * mock D `lensDef`). Every signal is min–max among the compared players, and
 * difficulty is the 0–10 scale where lower is easier. «Mañana» (the value
 * forecast) weighs +0,5 in Fichar and +0,8 in Vender.
 */
export function verdict(
    lens: VerdictLens,
    players: ComparedPlayer[],
    derived: DerivedPlayer[],
    currentWeek: number,
    now: number,
): Verdict {
    const { scores, ...result } =
        lens === 'buy'
            ? buyLens(players, derived, now)
            : lens === 'sell'
              ? sellLens(players, derived, currentWeek, now)
              : startLens(players, derived, currentWeek);
    const order = players
        .map((_, index) => index)
        .sort((a, b) => scores[b] - scores[a]);
    const top = order.at(0);

    return {
        ...result,
        order,
        recommended: top === undefined || result.out[top] ? null : top,
    };
}
