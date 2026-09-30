import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { COMPARE_MAX, replaceCompare } from '@/lib/compare-selection';
import { compare as playersCompare } from '@/routes/players';
import type { ComparedPlayer } from '@/types/models';

/**
 * The comparator's selection lives in the URL (the shareable link). Every
 * change is a partial reload with `replace: true`, starting from the current
 * props, so quick successive changes never mix two selections.
 */
export function useComparison({
    ids,
    players,
}: {
    ids: number[];
    players: ComparedPlayer[];
}) {
    const focusAfterLoad = useRef<string | null>(null);
    const normalized = useRef(false);
    const opening = useRef(true);

    const visit = (nextIds: number[], only: string[]) => {
        router.get(
            playersCompare.url(),
            { ids: nextIds.length > 0 ? nextIds.join(',') : undefined },
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

    // Once per visit: drop invalid ids, and an old link's `vista`, from the URL.
    useEffect(() => {
        if (normalized.current) {
            return;
        }

        normalized.current = true;
        const params = new URLSearchParams(window.location.search);

        if (
            params.get('ids') !== (ids.join(',') || null) ||
            params.has('vista')
        ) {
            visit(ids, ['ids']);
        }
    }, [ids]);

    return {
        add: (id: number, focusSelector?: string) => {
            if (ids.includes(id) || ids.length >= COMPARE_MAX) {
                return;
            }

            focusAfterLoad.current = focusSelector ?? null;
            visit([...ids, id], ['ids', 'players']);
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
                ['ids', 'players'],
            );
        },
        remove: (index: number) =>
            visit(
                ids.filter((_, position) => position !== index),
                ['ids', 'players'],
            ),
    };
}
