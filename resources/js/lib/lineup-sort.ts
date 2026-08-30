import type { FixtureLineupEntry, PlayerPosition } from '@/types/models';

// worldcup26 tags every bench player's raw `position` as "Substitute" — it
// doesn't distinguish goalkeeper/defender/etc. for subs — so ordering by
// real position has to come from the linked Player's own (Fantasy) position
// instead. An unresolved entry has no Player, so no position to sort by; it
// sorts after every resolved one.
const POSITION_ORDER: Record<PlayerPosition, number> = {
    goalkeeper: 0,
    defender: 1,
    midfield: 2,
    striker: 3,
    coach: 4,
};

export function byPlayerPositionThenJersey(a: FixtureLineupEntry, b: FixtureLineupEntry): number {
    const orderA = a.player ? POSITION_ORDER[a.player.position] : 5;
    const orderB = b.player ? POSITION_ORDER[b.player.position] : 5;

    if (orderA !== orderB) {
        return orderA - orderB;
    }

    return Number(a.jersey) - Number(b.jersey);
}
