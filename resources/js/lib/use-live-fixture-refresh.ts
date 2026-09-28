import { router } from '@inertiajs/react';
import { useEffect } from 'react';

const LIVE_REFRESH_INTERVAL_MS = 20_000;

interface LiveFixtureRefreshOptions {
    /** Keep reloading on the interval while the tab is hidden too. */
    whileHidden?: boolean;
}

/**
 * Silently re-fetches the given props every 20 s while `isActive` is true —
 * matches the cadence of the backend's live match-data sync. It also reloads
 * right when the tab becomes visible again, so the view catches up at once.
 *
 * By default it skips a tick while the tab is hidden. With `whileHidden` it
 * keeps reloading in a background tab (the browser may still throttle the
 * timer there).
 *
 * `only` should be a stable reference (e.g. a module-level constant) — it's
 * read inside the effect, not tracked as a dependency, since a fresh array
 * literal on every render would otherwise restart the interval constantly.
 */
export function useLiveFixtureRefresh(
    isActive: boolean,
    only: string[],
    { whileHidden = false }: LiveFixtureRefreshOptions = {},
) {
    useEffect(() => {
        if (!isActive) {
            return;
        }

        const reload = () => router.reload({ only, showProgress: false });

        const onTick = () => {
            if (document.hidden && !whileHidden) {
                return;
            }

            reload();
        };

        const onVisibilityChange = () => {
            if (!document.hidden) {
                reload();
            }
        };

        const interval = setInterval(onTick, LIVE_REFRESH_INTERVAL_MS);
        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            clearInterval(interval);
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- `only` must be a stable reference, see doc comment above
    }, [isActive, whileHidden]);
}
