import type { OwnershipActivity, SeasonActivityType, SeasonManager } from '@/types/models';

export interface OwnershipSegmentOrigin {
    type: SeasonActivityType;
    amount: number | null;
}

export interface OwnershipSegment {
    from: string | null;
    to: string | null;
    seasonManager: SeasonManager | null;
    /** The signing/sale/buyout that put the player in this state — `null` for the opening segment, which predates any captured activity. */
    startedBy: OwnershipSegmentOrigin | null;
}

/**
 * Reconstructs who owned a player over time from their chronological
 * signing/sale/buyout activity. `signing` and `buyout` both hand ownership
 * to the activity's source manager (see ActivityCard's phrasing: "{source}
 * fichó/pagó la cláusula ... a {target}"); `sale` returns the player to the
 * free market. The final segment is forced to `currentOwner` (the real
 * ManagerPlayer row) rather than the reconstructed state, since a
 * player's ownership can predate the activity log.
 *
 * A player can also have ownership *before* the earliest recorded activity —
 * either because they were on a manager's squad when that manager joined the
 * league (no activity at all), or because the first recorded activity is
 * itself a sale/buyout that implies an unrecorded prior owner (e.g. a buyout
 * only tells us who bought the player, not who held them before). We infer
 * that "leading owner" from the first activity's type/fields, then fall back
 * to `teamJoinedAt[leadingOwner.id]` (that manager's own `joined_league` date)
 * as the start of their ownership, with a free-market segment before it —
 * rather than crediting the manager with owning the player back through the
 * whole chart's date range. That inferred segment is tagged `startedBy: {
 * type: 'joined_league' }` so the UI can call out that the player came in
 * with the manager's original squad rather than via a recorded signing.
 */
export function buildOwnershipTimeline(
    activities: OwnershipActivity[],
    currentOwner: SeasonManager | null,
    teamJoinedAt: Record<string, string>,
): OwnershipSegment[] {
    const segments: OwnershipSegment[] = [];
    const first = activities[0] ?? null;

    const leadingOwner: SeasonManager | null =
        first === null
            ? currentOwner
            : first.type === 'sale'
              ? first.source_season_manager
              : first.type === 'buyout'
                ? first.target_season_manager
                : null;
    const leadingEnd = first?.occurred_at ?? null;

    if (leadingOwner === null) {
        segments.push({ from: null, to: leadingEnd, seasonManager: null, startedBy: null });
    } else {
        const joinedAt = teamJoinedAt[leadingOwner.id] ?? null;

        if (joinedAt !== null) {
            segments.push({ from: null, to: joinedAt, seasonManager: null, startedBy: null });
            segments.push({
                from: joinedAt,
                to: leadingEnd,
                seasonManager: leadingOwner,
                startedBy: { type: 'joined_league', amount: null },
            });
        } else {
            segments.push({ from: null, to: leadingEnd, seasonManager: leadingOwner, startedBy: null });
        }
    }

    for (let index = 0; index < activities.length; index++) {
        const activity = activities[index];
        const isLast = index === activities.length - 1;
        const owner = isLast ? currentOwner : activity.type === 'sale' ? null : activity.source_season_manager;

        segments.push({
            from: activity.occurred_at,
            to: activities[index + 1]?.occurred_at ?? null,
            seasonManager: owner,
            startedBy: { type: activity.type, amount: activity.amount },
        });
    }

    return segments;
}

const LOCAL_DATE_FORMATTER = new Intl.DateTimeFormat('sv-SE', {
    timeZone: 'Europe/Madrid',
});

/**
 * Day-granularity key (YYYY-MM-DD, league-local calendar day) for an ISO
 * instant. `PlayerMarket` only has one value per day, so a transfer is
 * attributed to its whole calendar day rather than its exact minute — a
 * player sold at 01:39 on the 22nd already reads as free for the 22nd's
 * data point, not still owned until that precise timestamp.
 */
function localDateKey(isoString: string): string {
    return LOCAL_DATE_FORMATTER.format(new Date(isoString));
}

/** Whether `dateIso` falls on the same league-local day the segment began (i.e. the day of its signing/sale/buyout). */
export function isSegmentStart(segment: OwnershipSegment, dateIso: string): boolean {
    return segment.from !== null && localDateKey(segment.from) === localDateKey(dateIso);
}

export function segmentAtDate(
    segments: OwnershipSegment[],
    dateIso: string,
): OwnershipSegment | null {
    const dateKey = localDateKey(dateIso);

    for (let index = segments.length - 1; index >= 0; index--) {
        const segment = segments[index];

        if (segment.from === null || localDateKey(segment.from) <= dateKey) {
            return segment;
        }
    }

    return null;
}

export function ownerAtDate(
    segments: OwnershipSegment[],
    dateIso: string,
): SeasonManager | null {
    return segmentAtDate(segments, dateIso)?.seasonManager ?? null;
}
