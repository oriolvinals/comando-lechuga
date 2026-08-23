export function pointsBadgeClass(points: number): string {
    if (points >= 12) {
        return 'bg-hq-lime text-hq-ink';
    }

    if (points >= 6) {
        return 'bg-hq-lime/15 text-hq-lime';
    }

    if (points >= 3) {
        return 'bg-hq-gold/20 text-hq-gold';
    }

    if (points < 0) {
        return 'bg-hq-live text-white';
    }

    return 'bg-hq-border text-hq-moss';
}
