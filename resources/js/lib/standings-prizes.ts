/**
 * Fixed prize (in €) per final league position, confirmed with the league —
 * the same every season regardless of team count, so it lives here rather
 * than in the database.
 */
const STANDINGS_PRIZES: Record<number, number | null> = {
    1: 200,
    2: 100,
    3: 50,
    4: null,
    5: -10,
    6: -30,
    7: -40,
};

export function standingsPrize(position: number): number | null {
    return STANDINGS_PRIZES[position] ?? null;
}

export function standingsPrizeClass(prize: number | null): string {
    if (prize === null) {
        return 'text-hq-moss-dim';
    }

    return prize > 0 ? 'text-hq-lime' : 'text-hq-live';
}

export function standingsPrizeText(prize: number | null): string {
    if (prize === null) {
        return '—';
    }

    return `${prize > 0 ? '+' : ''}${prize} €`;
}
