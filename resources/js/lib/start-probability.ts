import type {
    PlayerPosition,
    PlayerStatus,
    StartProbabilityEntry,
} from '@/types/models';

/** A probable starter below this is dimmed with a dashed frame on the pitch. */
export const DOUBT_THRESHOLD = 60;

/** A non-starter at or above this is listed under bench/doubts; the rest fold into "+N < 30 %". */
export const BENCH_THRESHOLD = 30;

/** A pitch badge at or above this turns lilac. */
export const SURE_STARTER_THRESHOLD = 90;

/**
 * sure ≥ 90 % (pitch badges only) · high ≥ 70 % · mid 40–69 % · low < 40 %
 * · out = injured/suspended per LaLiga Fantasy · none = FútbolFantasy gave no %.
 */
export type StartTone = 'sure' | 'high' | 'mid' | 'low' | 'out' | 'none';

export const START_TONE_TEXT_CLASSES: Record<StartTone, string> = {
    sure: 'text-hq-violet',
    high: 'text-hq-lime',
    mid: 'text-hq-gold',
    low: 'text-hq-moss-dim',
    out: 'text-hq-live',
    none: 'text-hq-led-off',
};

export const START_TONE_BG_CLASSES: Record<StartTone, string> = {
    sure: 'bg-hq-violet',
    high: 'bg-hq-lime',
    mid: 'bg-hq-gold',
    low: 'bg-hq-moss-dim',
    out: 'bg-hq-live',
    none: 'bg-hq-led-off',
};

export const START_TONE_BORDER_CLASSES: Record<StartTone, string> = {
    sure: 'border-hq-violet',
    high: 'border-hq-lime',
    mid: 'border-hq-gold',
    low: 'border-hq-moss-dim',
    out: 'border-hq-live',
    none: 'border-hq-led-off',
};

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

    if (probability >= 70) {
        return 'high';
    }

    return probability >= 40 ? 'mid' : 'low';
}

/** {@link startTone}, except that a pitch badge at ≥ 90 % turns lilac. */
export function pitchBadgeTone(
    probability: number | null,
    status: PlayerStatus,
): StartTone {
    const tone = startTone(probability, status);

    return tone === 'high' &&
        probability !== null &&
        probability >= SURE_STARTER_THRESHOLD
        ? 'sure'
        : tone;
}

/** What a start chip needs — shared by fixture entries and a roster player's next start. */
export type StartFacts = Pick<
    StartProbabilityEntry,
    'probability' | 'predicted_starter' | 'confirmed_starter'
>;

export type StartOutcome = 'starter' | 'bench' | 'surprise' | 'dropped';

/**
 * Titular / Suplente once confirmed — "surprise" when he starts outside
 * FútbolFantasy's probable XI, "dropped" when he was in it and doesn't.
 * Null while the lineup isn't confirmed.
 */
export function startOutcome(facts: StartFacts): StartOutcome | null {
    if (facts.confirmed_starter === null) {
        return null;
    }

    if (facts.confirmed_starter && !facts.predicted_starter) {
        return 'surprise';
    }

    if (!facts.confirmed_starter && facts.predicted_starter) {
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

/**
 * A probable XI on HqMatchPitch's landscape pitch: the local side attacks
 * right, the guest side is mirrored. Lines come from the fantasy position;
 * with four or more defenders the full-backs step up a little.
 */
export function landscapeSlots(
    starters: StartProbabilityEntry[],
    side: 'local' | 'guest',
): StartPitchSlot[] {
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

/** Height (% from the top) of each line on the vertical half pitch, attacking up. */
const HALF_PITCH_TOP: Record<PitchLine, number> = {
    goalkeeper: 88,
    defender: 67,
    midfield: 42,
    striker: 16,
};

/** A probable XI on the team ficha's vertical half pitch, attacking up. */
export function halfPitchSlots(
    starters: StartProbabilityEntry[],
): StartPitchSlot[] {
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
                top: HALF_PITCH_TOP[position] - (wide ? 4 : 0),
            };
        });
    });
}
