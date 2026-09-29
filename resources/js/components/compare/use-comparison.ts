import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    COMPARE_MAX,
    rememberCompareView,
    rememberedCompareView,
    replaceCompare,
} from '@/lib/compare-selection';
import { compare as playersCompare } from '@/routes/players';
import type { CompareView, ComparedPlayer } from '@/types/models';

/**
 * The comparator's selection and view live in the URL (the shareable link).
 * Every change is a partial reload with `replace: true`, starting from the
 * current props, so quick successive changes never mix two selections.
 */
export function useComparison({
    ids,
    view,
    players,
}: {
    ids: number[];
    view: CompareView;
    players: ComparedPlayer[];
}) {
    // A link without `vista` opens the remembered view straight away (no SSR here, so `window` is safe).
    const [activeView, setActiveView] = useState<CompareView>(() =>
        new URLSearchParams(window.location.search).has('vista')
            ? view
            : (rememberedCompareView() ?? view),
    );
    const focusAfterLoad = useRef<string | null>(null);
    const normalized = useRef(false);
    const opening = useRef(true);

    const visit = (
        nextIds: number[],
        nextView: CompareView,
        only: string[],
    ) => {
        router.get(
            playersCompare.url(),
            {
                ids: nextIds.length > 0 ? nextIds.join(',') : undefined,
                vista: nextView,
            },
            {
                only,
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onSuccess: () => {
                    const selector = focusAfterLoad.current;
                    focusAfterLoad.current = null;

                    if (selector) {
                        requestAnimationFrame(() =>
                            document
                                .querySelector<HTMLElement>(selector)
                                ?.focus({ preventScroll: true }),
                        );
                    }
                },
            },
        );
    };

    // The tray and the lists show the comparator's players once you go back —
    // except when the page opens with none (a stale link, or no ids at all):
    // that must not wipe a selection the user is still building.
    useEffect(() => {
        if (opening.current) {
            opening.current = false;

            if (players.length === 0) {
                return;
            }
        }

        replaceCompare(
            players.map((player) => ({
                id: player.id,
                name: player.name,
                image: player.image,
            })),
        );
    }, [players]);

    // Once per visit: drop invalid ids from the URL, and open the remembered view when the link has none.
    useEffect(() => {
        if (normalized.current) {
            return;
        }

        normalized.current = true;
        const params = new URLSearchParams(window.location.search);
        const nextView = activeView;

        if (
            params.get('ids') !== (ids.join(',') || null) ||
            params.get('vista') !== nextView
        ) {
            visit(ids, nextView, ['ids', 'view']);
        }
    }, [ids, activeView]);

    return {
        view: activeView,
        setView: (next: CompareView) => {
            if (next === activeView) {
                return;
            }

            setActiveView(next);
            rememberCompareView(next);
            visit(ids, next, ['view']);
        },
        add: (id: number, focusSelector?: string) => {
            if (ids.includes(id) || ids.length >= COMPARE_MAX) {
                return;
            }

            focusAfterLoad.current = focusSelector ?? null;
            visit([...ids, id], activeView, ['ids', 'players', 'view']);
        },
        replace: (index: number, id: number, focusSelector?: string) => {
            if (ids.includes(id)) {
                return;
            }

            focusAfterLoad.current = focusSelector ?? null;
            visit(
                ids.map((current, position) =>
                    position === index ? id : current,
                ),
                activeView,
                ['ids', 'players', 'view'],
            );
        },
        remove: (index: number) =>
            visit(
                ids.filter((_, position) => position !== index),
                activeView,
                ['ids', 'players', 'view'],
            ),
    };
}
