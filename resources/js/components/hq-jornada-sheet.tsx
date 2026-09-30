import { router } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { HqPlayerStatsModal } from '@/components/hq-player-stats-modal';
import type { HqPlayerStatsEntry } from '@/components/hq-player-stats-modal';
import { show as fixturesShow } from '@/routes/fixtures';
import { jornada as playerJornada } from '@/routes/players';
import type { PlayerFichaScore, PlayerJornadaSheet } from '@/types/models';

interface JornadaSheetContextValue {
    /** Opens the sheet with an entry the page already holds (the player ficha). */
    openEntry: (entry: HqPlayerStatsEntry) => void;
    /** Fetches one lineup row's sheet (`players.jornada`) and opens it — for lists that only carry the points. */
    openMatch: (playerId: number, fixtureId: number) => void;
    /** Whether that player's match sheet is being fetched right now. */
    isLoading: (playerId: number, fixtureId: number) => boolean;
}

const JornadaSheetContext = createContext<JornadaSheetContextValue | null>(
    null,
);

/** The jornada sheet of a ficha-shaped score: the club he played that match for, never his current one. */
export function fichaScoreEntry(
    player: HqPlayerStatsEntry['player'],
    score: PlayerFichaScore,
): HqPlayerStatsEntry {
    return {
        player,
        team: score.team,
        points: score.points ?? 0,
        dazn: score.starter || score.subbed_in ? score : undefined,
        stats: score.stats ?? {},
        lineupManager: score.lineup_manager,
        subMinute:
            score.sub_minute === null
                ? null
                : {
                      minute: score.sub_minute,
                      direction: score.subbed_out ? 'out' : 'in',
                  },
        fixture: score.fixture,
    };
}

function matchKey(playerId: number, fixtureId: number): string {
    return `${playerId}:${fixtureId}`;
}

/**
 * Owns the one app-wide jornada sheet (HqPlayerStatsModal), so any per-jornada
 * score can open it — "cualquier puntuación de jornada abre la Ficha de la
 * jornada". Fetched sheets are cached for the visit; the sheet closes on
 * navigation, since it lives in the persistent layout. A failed fetch falls
 * back to that match's own page.
 */
export function JornadaSheetProvider({ children }: PropsWithChildren) {
    const [entry, setEntry] = useState<HqPlayerStatsEntry | null>(null);
    const [loadingKey, setLoadingKey] = useState<string | null>(null);
    const cache = useRef(new Map<string, HqPlayerStatsEntry>());
    const latestRequest = useRef<string | null>(null);

    useEffect(
        () =>
            router.on('navigate', () => {
                latestRequest.current = null;
                setLoadingKey(null);
                setEntry(null);
            }),
        [],
    );

    const openMatch = useCallback((playerId: number, fixtureId: number) => {
        const key = matchKey(playerId, fixtureId);
        const cached = cache.current.get(key);

        if (cached) {
            setEntry(cached);

            return;
        }

        latestRequest.current = key;
        setLoadingKey(key);

        fetch(playerJornada({ player: playerId, fixture: fixtureId }).url, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                return response.json() as Promise<PlayerJornadaSheet>;
            })
            .then((sheet) => {
                const loaded = fichaScoreEntry(sheet.player, sheet.score);
                cache.current.set(key, loaded);

                if (latestRequest.current === key) {
                    setEntry(loaded);
                }
            })
            .catch(() => {
                if (latestRequest.current === key) {
                    router.visit(fixturesShow(fixtureId).url);
                }
            })
            .finally(() => {
                if (latestRequest.current === key) {
                    latestRequest.current = null;
                    setLoadingKey(null);
                }
            });
    }, []);

    const value = useMemo<JornadaSheetContextValue>(
        () => ({
            openEntry: setEntry,
            openMatch,
            isLoading: (playerId, fixtureId) =>
                loadingKey === matchKey(playerId, fixtureId),
        }),
        [openMatch, loadingKey],
    );

    return (
        <JornadaSheetContext.Provider value={value}>
            {children}
            <HqPlayerStatsModal entry={entry} onClose={() => setEntry(null)} />
        </JornadaSheetContext.Provider>
    );
}

/** The app-wide jornada sheet — null outside {@link JornadaSheetProvider}, where scores stay plain. */
export function useJornadaSheet(): JornadaSheetContextValue | null {
    return useContext(JornadaSheetContext);
}
