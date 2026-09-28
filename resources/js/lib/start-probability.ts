import type {
    PlayerPosition,
    PlayerStatus,
    StartProbabilityEntry,
    StartProbabilityTeamBlock,
} from '@/types/models';

/** A non-starter at or above this is listed under bench/doubts; the rest fold into "+N < 30 %". */
export const BENCH_THRESHOLD = 30;

/** A start % at or above this turns lilac (sure starter). */
export const SURE_STARTER_THRESHOLD = 90;

/**
 * sure ≥ 90 % (lilac) · high 70–89 % (lime) · low < 70 % (gold) · out =
 * injured/suspended per LaLiga Fantasy (red — a status, never a %) · none =
 * FútbolFantasy gave no %.
 */
export type StartTone = 'sure' | 'high' | 'low' | 'out' | 'none';

export const START_TONE_TEXT_CLASSES: Record<StartTone, string> = {
    sure: 'text-hq-violet',
    high: 'text-hq-lime',
    low: 'text-hq-gold',
    out: 'text-hq-live',
    none: 'text-hq-led-off',
};

export const START_TONE_BG_CLASSES: Record<StartTone, string> = {
    sure: 'bg-hq-violet',
    high: 'bg-hq-lime',
    low: 'bg-hq-gold',
    out: 'bg-hq-live',
    none: 'bg-hq-led-off',
};

export const START_TONE_BORDER_CLASSES: Record<StartTone, string> = {
    sure: 'border-hq-violet',
    high: 'border-hq-lime',
    low: 'border-hq-gold',
    out: 'border-hq-live',
    none: 'border-hq-led-off',
};

/** Each tone's raw colour, for {@link HqStartMeter}'s half-lit cell gradient. */
export const START_TONE_COLOR_VARS: Record<StartTone, string> = {
    sure: 'var(--color-hq-violet)',
    high: 'var(--color-hq-lime)',
    low: 'var(--color-hq-gold)',
    out: 'var(--color-hq-live)',
    none: 'var(--color-hq-led-off)',
};

/**
 * Full and half-lit cells for {@link HqStartMeter}'s 10-cell bar, at
 * half-cell (5 %) precision — e.g. 75 % is 7 full cells plus one half.
 */
export function meterFill(probability: number | null): {
    full: number;
    half: boolean;
} {
    if (probability === null) {
        return { full: 0, half: false };
    }

    const rounded = Math.round(probability / 5) * 5;

    return { full: Math.floor(rounded / 10), half: rounded % 10 === 5 };
}

/** Injured or suspended per LaLiga Fantasy — our status is the truth, never FútbolFantasy's flags. */
export function isUnavailable(status: PlayerStatus): boolean {
    return status === 'injured' || status === 'suspended';
}

export function startTone(
    probability: number | null,
    status: PlayerStatus,
): StartTone {
    if (isUnavailable(status)) {
        return 'out';
    }

    if (probability === null) {
        return 'none';
    }

    if (probability >= SURE_STARTER_THRESHOLD) {
        return 'sure';
    }

    return probability >= 70 ? 'high' : 'low';
}

/** What a start chip needs — shared by fixture entries and a roster player's next start. */
export type StartFacts = Pick<
    StartProbabilityEntry,
    'probability' | 'predicted_starter' | 'confirmed_starter'
>;

export type StartOutcome = 'starter' | 'bench' | 'surprise' | 'dropped';

/**
 * Titular / Suplente once confirmed — "surprise" when he starts outside
 * FútbolFantasy's probable XI, "dropped" when he was in it and doesn't. Both
 * require that FútbolFantasy actually predicted him (`probability !== null`);
 * without a prediction to compare against — a failed or unlinked team page,
 * or a lineup confirmed before we ever saw a % — he's plain starter/bench.
 * Null while the lineup isn't confirmed.
 */
export function startOutcome(facts: StartFacts): StartOutcome | null {
    if (facts.confirmed_starter === null) {
        return null;
    }

    const hadPrediction = facts.probability !== null;

    if (hadPrediction && facts.confirmed_starter && !facts.predicted_starter) {
        return 'surprise';
    }

    if (hadPrediction && !facts.confirmed_starter && facts.predicted_starter) {
        return 'dropped';
    }

    return facts.confirmed_starter ? 'starter' : 'bench';
}

/** In the XI shown: the confirmed one once there is one, else FútbolFantasy's probable XI. */
export function isShownStarter(facts: StartFacts, confirmed: boolean): boolean {
    return confirmed
        ? facts.confirmed_starter === true
        : facts.predicted_starter;
}

const POSITION_ORDER: Record<PlayerPosition, number> = {
    goalkeeper: 0,
    defender: 1,
    midfield: 2,
    striker: 3,
    coach: 4,
};

function byProbability(
    a: StartProbabilityEntry,
    b: StartProbabilityEntry,
): number {
    return (b.probability ?? -1) - (a.probability ?? -1);
}

export interface StartSplit {
    /** The XI shown, by line then %. */
    starters: StartProbabilityEntry[];
    /** Bench and doubts at ≥ 30 % — once confirmed, also the probable starters who were dropped. */
    bench: StartProbabilityEntry[];
    /** Everyone else under 30 %. */
    rest: StartProbabilityEntry[];
    /** Bajas: injured or suspended per our status, whatever % FF still gives them. */
    out: StartProbabilityEntry[];
}

/** One team's players split for display. Coaches are left out. */
export function splitStartEntries(
    players: StartProbabilityEntry[],
    confirmed: boolean,
): StartSplit {
    const squad = players.filter((entry) => entry.player.position !== 'coach');
    const starters = squad
        .filter((entry) => isShownStarter(entry, confirmed))
        .sort(
            (a, b) =>
                POSITION_ORDER[a.player.position] -
                    POSITION_ORDER[b.player.position] || byProbability(a, b),
        );
    const others = squad.filter((entry) => !isShownStarter(entry, confirmed));
    const out = others
        .filter((entry) => isUnavailable(entry.player.status))
        .sort(byProbability);
    const available = others.filter(
        (entry) => !isUnavailable(entry.player.status),
    );
    const bench = available
        .filter(
            (entry) =>
                (confirmed && entry.predicted_starter) ||
                (entry.probability ?? 0) >= BENCH_THRESHOLD,
        )
        .sort(
            (a, b) =>
                Number(b.predicted_starter) - Number(a.predicted_starter) ||
                byProbability(a, b),
        );
    const rest = available
        .filter((entry) => !bench.includes(entry))
        .sort(byProbability);

    return { starters, bench, rest, out };
}

/**
 * The side's formation tag: worldcup26's as is once it confirms the
 * lineup, FútbolFantasy's probable-XI reading while it hasn't, and none
 * once FF alone confirms (that reading approximates the probable XI, not
 * the confirmed one).
 */
export function formationLabel(
    block: Pick<StartProbabilityTeamBlock, 'formation' | 'confirmed_source'>,
): string | null {
    if (block.formation === null) {
        return null;
    }

    if (block.confirmed_source === 'worldcup26') {
        return block.formation;
    }

    return block.confirmed_source === null ? block.formation : null;
}

/** Mean % of the players that have one, rounded — null when none has. */
export function averageProbability(
    entries: StartProbabilityEntry[],
): number | null {
    const known = entries.flatMap((entry) =>
        entry.probability === null ? [] : [entry.probability],
    );

    if (known.length === 0) {
        return null;
    }

    return Math.round(
        known.reduce((sum, value) => sum + value, 0) / known.length,
    );
}

/** Whole days since FútbolFantasy was last read. */
export function dataAgeDays(fetchedAt: string, now: number): number {
    return Math.floor((now - Date.parse(fetchedAt)) / 86_400_000);
}

/** "hace 5 min" / "hace 3 h" / "hace 2 días". */
export function formatDataAge(fetchedAt: string, now: number): string {
    const minutes = Math.max(
        1,
        Math.round((now - Date.parse(fetchedAt)) / 60_000),
    );

    if (minutes < 60) {
        return `hace ${minutes} min`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `hace ${hours} h`;
    }

    const days = Math.round(hours / 24);

    return `hace ${days} ${days === 1 ? 'día' : 'días'}`;
}

/**
 * "Datos de hace 19 h" — the tooltip text for any start-probability visual
 * (the 10-cell bar, a pitch token's % badge, a % figure…), from
 * {@link formatDataAge}.
 */
export function dataAgeTooltipLabel(fetchedAt: string, now: number): string {
    return `Datos de ${formatDataAge(fetchedAt, now)}`;
}

type PitchLine = Exclude<PlayerPosition, 'coach'>;

const PITCH_LINES: PitchLine[] = [
    'goalkeeper',
    'defender',
    'midfield',
    'striker',
];

/** A player's spot on a pitch, in % of its width (left) and height (top). */
export interface StartPitchSlot {
    entry: StartProbabilityEntry;
    left: number;
    top: number;
}

/** A line's players with the strongest % in the middle (alternating either side of it). */
function centreOut(entries: StartProbabilityEntry[]): StartProbabilityEntry[] {
    const ordered: StartProbabilityEntry[] = [];

    [...entries].sort(byProbability).forEach((entry, index) => {
        if (index % 2 === 0) {
            ordered.unshift(entry);
        } else {
            ordered.push(entry);
        }
    });

    return ordered;
}

/** Depth (% of the width from the own goal) of each line on the landscape pitch. */
const LANDSCAPE_DEPTH: Record<PitchLine, number> = {
    goalkeeper: 6,
    defender: 18.5,
    midfield: 31.5,
    striker: 43.5,
};

/** A worldcup26 position's line — mirrors `App\Enums\MatchPositionLine::fromWorldcup26Text`. */
export type MatchLine =
    | 'goalkeeper'
    | 'defender'
    | 'defensive_midfielder'
    | 'midfielder'
    | 'attacking_midfielder'
    | 'forward'
    | 'unknown';

export function matchPositionLine(text: string): MatchLine {
    if (text.includes('Goalkeeper')) {
        return 'goalkeeper';
    }

    if (text.includes('Back') || text.includes('Defender')) {
        return 'defender';
    }

    if (text.includes('Defensive Midfielder')) {
        return 'defensive_midfielder';
    }

    if (text.includes('Attacking Midfielder')) {
        return 'attacking_midfielder';
    }

    if (text.includes('Midfielder')) {
        return 'midfielder';
    }

    return text.includes('Forward') ? 'forward' : 'unknown';
}

/**
 * A worldcup26 position's flank from the player's own point of view, 0
 * (left) to 4 (right) — mirrors `App\Enums\MatchPositionSide`.
 */
export function matchPositionSideOrder(text: string): number {
    const hasCenter = text.includes('Center');
    const hasLeft = text.includes('Left');
    const hasRight = text.includes('Right');

    if (hasCenter) {
        return hasLeft ? 1 : hasRight ? 3 : 2;
    }

    return hasLeft ? 0 : hasRight ? 4 : 2;
}

const MIDFIELD_LINES: MatchLine[] = [
    'defensive_midfielder',
    'midfielder',
    'attacking_midfielder',
];

/** Same per-player spacing along a line as the confirmed-lineup pitches (FixturesController / TeamsController). */
const LINE_STEP = 76 / 3;

/** Depth of the goalkeeper, the back line and the front line on a pitch; midfield lines split the gap evenly. */
interface LineAnchors {
    goalkeeper: number;
    defender: number;
    forward: number;
}

/** A starter's spot by real match role: depth along the anchors, across from his own left flank (0–100). */
interface RoleSpot {
    entry: StartProbabilityEntry;
    depth: number;
    across: number;
}

/**
 * The XI by each starter's `pitch_position`, laid out like a confirmed
 * lineup: one line per match line, midfield lines evenly between the back
 * and front lines, each line ordered by flank and spread with the same
 * step. Null unless every starter has a position — then the caller falls
 * back to the fantasy-position layout.
 */
function roleSpots(
    starters: StartProbabilityEntry[],
    anchors: LineAnchors,
): RoleSpot[] | null {
    if (
        starters.length === 0 ||
        starters.some((entry) => entry.pitch_position === null)
    ) {
        return null;
    }

    const placed = starters.map((entry) => {
        const text = entry.pitch_position ?? '';
        const line = matchPositionLine(text);

        return {
            entry,
            line: line === 'unknown' ? 'midfielder' : line,
            side: matchPositionSideOrder(text),
        };
    });
    const midfieldLines = MIDFIELD_LINES.filter((line) =>
        placed.some((spot) => spot.line === line),
    );
    const midfieldStep =
        (anchors.forward - anchors.defender) / (midfieldLines.length + 1);
    const depthOf = (line: MatchLine): number => {
        if (
            line === 'goalkeeper' ||
            line === 'defender' ||
            line === 'forward'
        ) {
            return anchors[line];
        }

        return (
            anchors.defender + midfieldStep * (midfieldLines.indexOf(line) + 1)
        );
    };

    const lines = [...new Set(placed.map((spot) => spot.line))];

    return lines.flatMap((lineName) => {
        const line = placed
            .filter((spot) => spot.line === lineName)
            .sort(
                (a, b) =>
                    a.side - b.side ||
                    byProbability(a.entry, b.entry) ||
                    a.entry.player.id - b.entry.player.id,
            );
        const step =
            line.length <= 1 ? 0 : Math.min(LINE_STEP, 76 / (line.length - 1));
        const start = 50 - (step * (line.length - 1)) / 2;

        return line.map((mate, index) => ({
            entry: mate.entry,
            depth: depthOf(mate.line),
            across: line.length <= 1 ? 50 : start + index * step,
        }));
    });
}

/** Where the landscape pitch puts each line when the XI has real positions. */
const LANDSCAPE_ANCHORS: LineAnchors = {
    goalkeeper: LANDSCAPE_DEPTH.goalkeeper,
    defender: LANDSCAPE_DEPTH.defender,
    forward: LANDSCAPE_DEPTH.striker,
};

/**
 * A probable XI on HqMatchPitch's landscape pitch: the local side attacks
 * right (his left flank along the top, like HqMatchPitch), the guest side
 * is mirrored. With every starter's `pitch_position` known the XI is drawn
 * by real role like a confirmed lineup; otherwise lines come from the
 * fantasy position, and with four or more defenders the full-backs step up
 * a little.
 */
export function landscapeSlots(
    starters: StartProbabilityEntry[],
    side: 'local' | 'guest',
): StartPitchSlot[] {
    const spots = roleSpots(starters, LANDSCAPE_ANCHORS);

    if (spots !== null) {
        return spots.map(({ entry, depth, across }) =>
            side === 'local'
                ? { entry, left: depth, top: across }
                : { entry, left: 100 - depth, top: 100 - across },
        );
    }

    return PITCH_LINES.flatMap((position) => {
        const line = centreOut(
            starters.filter((entry) => entry.player.position === position),
        );

        return line.map((entry, index) => {
            const wide =
                position === 'defender' &&
                line.length >= 4 &&
                (index === 0 || index === line.length - 1);
            const depth = LANDSCAPE_DEPTH[position] + (wide ? 3.5 : 0);
            const across = 9 + ((index + 1) / (line.length + 1)) * 82;

            return side === 'local'
                ? { entry, left: depth, top: across }
                : { entry, left: 100 - depth, top: 100 - across };
        });
    });
}

/**
 * Depth (% from the top) of the goalkeeper, back line and front line on the
 * team ficha's portrait pitch when a starter's real match role is known —
 * the exact same anchors the backend gives a confirmed lineup's own
 * `pitch_top` (`TeamsController::PITCH_ROW_ANCHOR`), so a probable XI lands
 * on the identical rows a confirmed one later would.
 */
const PORTRAIT_ROLE_ANCHORS: LineAnchors = {
    goalkeeper: 6,
    defender: 28,
    forward: 74,
};

/**
 * Fallback depth (% from the top) by fantasy position, when a starter's
 * real match role isn't known — matches `HqLineupPitch`'s own fantasy-row
 * fallback (`ROWS`) so the two pitches still land on the same lines.
 */
const PORTRAIT_POSITION_TOP: Record<PitchLine, number> = {
    goalkeeper: 5,
    defender: 27,
    midfield: 50,
    striker: 73,
};

/**
 * A probable XI's spots on the team ficha's portrait pitch (`HqLineupPitch`'s
 * own `aspect-[280/430]`), attacking down with the goalkeeper at the top —
 * so, as on the team ficha's confirmed pitch, each flank is seen from the
 * goalkeeper: the player's right is the screen's left. By real role when
 * every starter's `pitch_position` is known (landing on the exact rows a
 * confirmed lineup would), else by fantasy position.
 */
export function halfPitchSlots(
    starters: StartProbabilityEntry[],
): StartPitchSlot[] {
    const spots = roleSpots(starters, PORTRAIT_ROLE_ANCHORS);

    if (spots !== null) {
        return spots.map(({ entry, depth, across }) => ({
            entry,
            left: 100 - across,
            top: depth,
        }));
    }

    return PITCH_LINES.flatMap((position) => {
        const line = centreOut(
            starters.filter((entry) => entry.player.position === position),
        );

        return line.map((entry, index) => {
            const wide =
                position === 'defender' &&
                line.length >= 4 &&
                (index === 0 || index === line.length - 1);

            return {
                entry,
                left: ((index + 1) / (line.length + 1)) * 100,
                top: PORTRAIT_POSITION_TOP[position] + (wide ? 4 : 0),
            };
        });
    });
}
