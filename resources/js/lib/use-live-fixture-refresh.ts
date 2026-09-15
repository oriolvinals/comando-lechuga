import { router } from '@inertiajs/react';
import { useEffect } from 'react';

const LIVE_REFRESH_INTERVAL_MS = 20_000;

/**
 * Silently re-fetches the given props on an interval while `isLive` is true —
 * matches the cadence of the backend's live match-data sync. Skips a tick
 * while the tab is hidden, and fires an extra reload right when the tab
 * regains focus so the view catches up immediately.
 *
 * `only` should be a stable reference (e.g. a module-level constant) — it's
 * read inside the effect, not tracked as a dependency, since a fresh array
 * literal on every render would otherwise restart the interval constantly.
 */
export function useLiveFixtureRefresh(isLive: boolean, only: string[]) {
    useEffect(() => {
        if (!isLive) {
            return;
        }

        const reload = () => {
            if (document.hidden) {
                return;
            }

            router.reload({ only, showProgress: false });
        };

        const interval = setInterval(reload, LIVE_REFRESH_INTERVAL_MS);
        document.addEventListener('visibilitychange', reload);

        return () => {
            clearInterval(interval);
            document.removeEventListener('visibilitychange', reload);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- `only` must be a stable reference, see doc comment above
    }, [isLive]);
}
