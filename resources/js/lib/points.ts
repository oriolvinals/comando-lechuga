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
 * Same six-tier palette as {@link matchPointsBadgeClass}, at a less
 * translucent background — for the fixture pitch token, where the badge
 * sits directly on the player's photo and needs more contrast than the
 * standard tier background gives it. Scoped to that one context so it
 * doesn't change every other place matchPointsBadgeClass is used.
 */
export function matchPointsBadgeClassOnPhoto(points: number): string {
    if (points < 0) {
        return 'bg-hq-live/35 text-hq-live';
    }

    if (points < 5) {
        return 'bg-hq-gold/35 text-hq-gold';
    }

    if (points < 9) {
        return 'bg-hq-lime/30 text-hq-lime';
    }

    if (points < 14) {
        return 'bg-hq-azure/35 text-hq-azure';
    }

    return 'bg-hq-violet/35 text-hq-violet';
}

/**
 * Formats a points total with an explicit sign — `+12` for zero or positive,
 * `-5` for negative (the number's own minus, not a doubled `+-5`).
 */
export function formatSignedPoints(points: number): string {
    return points >= 0 ? `+${points}` : `${points}`;
}

/**
 * Color tier for a season team's weekly points total (the "forma" column in
 * the home standings) — same six-color palette as {@link matchPointsBadgeClass}
 * but scaled to a team's aggregate score across its whole lineup instead of
 * one player's.
 */
export function teamFormBadgeClass(points: number): string {
    if (points < 0) {
        return 'bg-hq-live/20 text-hq-live';
    }

    if (points < 31) {
        return 'bg-hq-gold/20 text-hq-gold';
    }

    if (points < 56) {
        return 'bg-hq-lime/15 text-hq-lime';
    }

    if (points < 91) {
        return 'bg-hq-azure/20 text-hq-azure';
    }

    return 'bg-hq-violet/20 text-hq-violet';
}

/**
 * Text-only color tier for a team's weekly points total — same breakpoints
 * as {@link teamFormBadgeClass}, for contexts (chart labels) that don't want
 * the badge background.
 */
export function teamFormTextClass(points: number): string {
    if (points < 0) {
        return 'text-hq-live';
    }

    if (points < 31) {
        return 'text-hq-gold';
    }

    if (points < 56) {
        return 'text-hq-lime';
    }

    if (points < 91) {
        return 'text-hq-azure';
    }

    return 'text-hq-violet';
}

/**
 * Bar-fill color tier for a team's weekly points total — same breakpoints as
 * {@link teamFormBadgeClass}, at a stronger opacity suited to a chart bar
 * rather than a small badge.
 */
export function teamFormBarClass(points: number): string {
    if (points < 0) {
        return 'bg-hq-live/50';
    }

    if (points < 31) {
        return 'bg-hq-gold/50';
    }

    if (points < 56) {
        return 'bg-hq-lime/40';
    }

    if (points < 91) {
        return 'bg-hq-azure/50';
    }

    return 'bg-hq-violet/50';
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
