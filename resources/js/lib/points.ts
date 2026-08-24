/**
 * Color tier for a single match/jornada score — six tiers, always a real
 * color (no neutral/black tier), and dedicated azure/violet tones that
 * don't collide with the position-tag colors (`hq-med`, `hq-def`).
 */
export function matchPointsBadgeClass(points: number): string {
    if (points < 0) {
        return 'bg-hq-live/20 text-hq-live';
    }

    if (points < 5) {
        return 'bg-hq-gold/20 text-hq-gold';
    }

    if (points < 9) {
        return 'bg-hq-lime/15 text-hq-lime';
    }

    if (points < 14) {
        return 'bg-hq-azure/20 text-hq-azure';
    }

    return 'bg-hq-violet/20 text-hq-violet';
}

/**
 * Color tier for a DAZN (Marca) score — same six-tier palette as
 * {@link matchPointsBadgeClass}, but scaled to DAZN's fixed 0–4 range
 * instead of the lechuga points scale.
 */
export function daznPointsBadgeClass(points: number): string {
    if (points < 1) {
        return 'bg-hq-live/20 text-hq-live';
    }

    if (points < 2) {
        return 'bg-hq-gold/20 text-hq-gold';
    }

    if (points < 3) {
        return 'bg-hq-lime/15 text-hq-lime';
    }

    if (points < 4) {
        return 'bg-hq-azure/20 text-hq-azure';
    }

    return 'bg-hq-violet/20 text-hq-violet';
}
