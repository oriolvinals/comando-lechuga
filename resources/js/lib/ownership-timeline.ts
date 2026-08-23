import type { OwnershipActivity, SeasonActivityType, SeasonTeam } from '@/types/models';

export interface OwnershipSegmentOrigin {
    type: SeasonActivityType;
    amount: number | null;
}

export interface OwnershipSegment {
    from: string | null;
    to: string | null;
    seasonTeam: SeasonTeam | null;
    /** The signing/sale/buyout that put the player in this state — `null` for the opening segment, which predates any captured activity. */
    startedBy: OwnershipSegmentOrigin | null;
}

/**
 * Reconstructs who owned a player over time from their chronological
 * signing/sale/buyout activity. `signing` and `buyout` both hand ownership
 * to the activity's source team (see ActivityCard's phrasing: "{source}
 * fichó/pagó la cláusula ... a {target}"); `sale` returns the player to the
 * free market. The final segment is forced to `currentOwner` (the real
 * SeasonTeamPlayer row) rather than the reconstructed state, since a
 * player's ownership can predate the activity log (e.g. an initial squad
 * assignment with no captured signing event).
 */
export function buildOwnershipTimeline(
    activities: OwnershipActivity[],
    currentOwner: SeasonTeam | null,
): OwnershipSegment[] {
    const segments: OwnershipSegment[] = [];
    let segmentStart: string | null = null;
    let owner: SeasonTeam | null = null;
    let startedBy: OwnershipSegmentOrigin | null = null;

    for (const activity of activities) {
        segments.push({ from: segmentStart, to: activity.occurred_at, seasonTeam: owner, startedBy });
        segmentStart = activity.occurred_at;
        owner = activity.type === 'sale' ? null : activity.source_season_team;
        startedBy = { type: activity.type, amount: activity.amount };
    }

    segments.push({ from: segmentStart, to: null, seasonTeam: currentOwner, startedBy });

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
): SeasonTeam | null {
    return segmentAtDate(segments, dateIso)?.seasonTeam ?? null;
}
