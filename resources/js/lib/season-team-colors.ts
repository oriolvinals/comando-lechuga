/**
 * Stopgap crest-color lookup, keyed by `SeasonTeam.id` in this league's own
 * database. Extracted by hand from each crest PNG (see
 * docs/team-crest-colors.md) — not yet a `SeasonTeam` column. Swap this for
 * `seasonTeam.crest_color` once that field exists; until then this is the
 * only place these values live.
 */
const SEASON_TEAM_COLORS: Record<number, string> = {
    1: '#8a0607', // Cruza FC
    2: '#021025', // CID F.C
    3: '#ecb21c', // Gauchitos F.C
    4: '#0355f9', // DukeBlack9
    5: '#441d70', // DUBI F.C
    6: '#571a78', // Ariobretxa
    7: '#0a97a4', // planuky
};

const FALLBACK_COLOR = '#c9b98a'; // hq-khaki

export function seasonTeamColor(seasonTeamId: number): string {
    return SEASON_TEAM_COLORS[seasonTeamId] ?? FALLBACK_COLOR;
}
