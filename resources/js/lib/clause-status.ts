export type ClauseStatus = 'open' | 'locked' | 'shielded';

/**
 * Which of the three clause states a player's ownership is in right now —
 * shared by every place that shows clause status (the player ficha's
 * OwnedStatus card, the team ficha's roster rows) so the branching logic
 * can't drift between them.
 */
export function resolveClauseStatus(
    shielded: boolean,
    buyoutClauseLockedUntil: string,
    now: number,
): ClauseStatus {
    if (shielded) {
        return 'shielded';
    }

    if (new Date(buyoutClauseLockedUntil).getTime() > now) {
        return 'locked';
    }

    return 'open';
}
