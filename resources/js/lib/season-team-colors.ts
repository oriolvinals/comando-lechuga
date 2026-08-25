const FALLBACK_COLOR = '#c9b98a'; // hq-khaki

/**
 * A team's real `primary_color` column, falling back to a neutral khaki for
 * a team that hasn't had a color set yet.
 */
export function teamColor(primaryColor: string | null): string {
    return primaryColor ?? FALLBACK_COLOR;
}

/**
 * Subtle background tint behind a crest inside an `hq-crest-cut` block,
 * using the team's real `primary_color` column — matches the ~10% opacity
 * convention used elsewhere in the app (`bg-hq-lime/10`, etc.). Returns
 * `undefined` until the field is actually populated, so the block's own
 * `bg-hq-border` class keeps showing through.
 */
export function crestTintStyle(
    primaryColor: string | null,
): { backgroundColor: string } | undefined {
    if (!primaryColor) {
        return undefined;
    }

    return { backgroundColor: `${primaryColor}1a` };
}

/**
 * Sets the `--hq-card-tint` custom property an `hq-card-cut` block reads,
 * washing its background with the team's real `primary_color` column.
 * Returns `undefined` until the field is populated, so the block keeps its
 * default neutral panel background.
 */
export function cardTintStyle(
    primaryColor: string | null,
): { '--hq-card-tint': string } | undefined {
    if (!primaryColor) {
        return undefined;
    }

    return { '--hq-card-tint': `${primaryColor}26` };
}
