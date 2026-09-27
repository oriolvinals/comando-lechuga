const FALLBACK_COLOR = '#c9b98a'; // hq-khaki

/**
 * A manager's real `primary_color` column, falling back to a neutral khaki
 * for a manager that hasn't had a color set yet.
 */
export function managerColor(primaryColor: string | null): string {
    return primaryColor ?? FALLBACK_COLOR;
}

/**
 * Subtle background tint behind a manager crest,
 * using the manager's real `primary_color` column — matches the ~10% opacity
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
