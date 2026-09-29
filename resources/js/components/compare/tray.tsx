import { router } from '@inertiajs/react';
import { ArrowRight, Plus, User, X } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useEffect, useRef, useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import {
    COMPARE_MAX,
    COMPARE_SLOT_COLORS,
    clearCompare,
    compareUrl,
    removeFromCompare,
    useCompareSelection,
} from '@/lib/compare-selection';
import { cn } from '@/lib/utils';

/**
 * Where focus lands once the tray empties: the given element or, when the
 * page has none, its h1 (made programmatically focusable), so focus is
 * never dropped onto the body.
 */
function focusFallback(id: string | undefined): void {
    const target =
        (id !== undefined ? document.getElementById(id) : null) ??
        document.querySelector<HTMLElement>('main h1') ??
        document.querySelector<HTMLElement>('h1');

    if (target === null) {
        return;
    }

    if (!target.hasAttribute('tabindex') && target.tabIndex < 0) {
        target.setAttribute('tabindex', '-1');
    }

    target.focus({ preventScroll: true });
}

interface HqCompareTrayProps {
    /** Element to focus once the tray empties (the players search on the list); the page h1 otherwise. */
    focusFallbackId?: string;
}

/**
 * The fixed bottom tray of the comparator's entry (mock D `.tray`): up to
 * three chosen players, each with its own ×, "Vaciar" and "Comparar N →".
 * Hidden without a selection; above the phone bottom bar. Removing a player
 * moves focus to the next × or, when none is left, to `focusFallbackId`
 * or the page h1.
 */
export function HqCompareTray({ focusFallbackId }: HqCompareTrayProps) {
    const selection = useCompareSelection();
    const [announcement, setAnnouncement] = useState('');
    const removeRefs = useRef<(HTMLButtonElement | null)[]>([]);
    const focusAfterRemove = useRef<number | null>(null);
    const announced = useRef(false);

    useEffect(() => {
        if (focusAfterRemove.current === null) {
            return;
        }

        const index = Math.min(focusAfterRemove.current, selection.length - 1);
        focusAfterRemove.current = null;

        if (index >= 0) {
            removeRefs.current[index]?.focus({ preventScroll: true });

            return;
        }

        focusFallback(focusFallbackId);
    }, [selection, focusFallbackId]);

    useEffect(() => {
        // The first render only restores a saved selection: nothing to announce.
        if (!announced.current) {
            announced.current = true;

            return;
        }

        setAnnouncement(
            selection.length > 0
                ? `${selection.length} de ${COMPARE_MAX} en el comparador: ${selection.map((entry) => entry.name).join(', ')}`
                : 'Comparador vacío',
        );
    }, [selection]);

    const remove = (index: number, id: number) => {
        focusAfterRemove.current = index;
        removeFromCompare(id);
    };

    return (
        <>
            <p className="sr-only" aria-live="polite">
                {announcement}
            </p>
            {selection.length > 0 && (
                <>
                    <div aria-hidden="true" className="h-16" />
                    <div
                        role="region"
                        aria-label="Jugadores para comparar"
                        className="fixed inset-x-0 bottom-[calc(53px+env(safe-area-inset-bottom))] z-[60] border-t border-hq-border-bright bg-hq-ink/96 backdrop-blur-[6px] lg:bottom-0"
                    >
                        <div className="mx-auto flex max-w-[1440px] items-center gap-1.5 px-2.5 py-2 sm:gap-3 sm:px-4">
                            <span className="hidden hq-label sm:inline">
                                Comparador
                            </span>
                            <ul className="flex min-w-0 gap-1 sm:gap-2">
                                {Array.from(
                                    { length: COMPARE_MAX },
                                    (_, index) => {
                                        const entry = selection[index];

                                        if (!entry) {
                                            return (
                                                <li
                                                    key={`empty-${index}`}
                                                    aria-hidden="true"
                                                    className="hidden size-9 items-center justify-center border border-dashed border-hq-border-strong text-hq-led-off sm:flex"
                                                >
                                                    <Plus className="size-3" />
                                                </li>
                                            );
                                        }

                                        return (
                                            <li
                                                key={entry.id}
                                                style={
                                                    {
                                                        '--slot':
                                                            COMPARE_SLOT_COLORS[
                                                                index
                                                            ],
                                                    } as CSSProperties
                                                }
                                                className="flex min-w-0 items-center border border-l-2 border-hq-border-strong border-l-(--slot) bg-hq-panel"
                                            >
                                                <EntityImage
                                                    src={entry.image}
                                                    alt=""
                                                    fallback={User}
                                                    shape="square"
                                                    className="ml-1 size-7 shrink-0 rounded-none object-cover object-top sm:ml-0 sm:size-8"
                                                />
                                                <b className="hidden max-w-[16ch] truncate px-2 text-xs font-extrabold text-hq-paper uppercase sm:block">
                                                    {entry.name}
                                                </b>
                                                <button
                                                    ref={(element) => {
                                                        removeRefs.current[
                                                            index
                                                        ] = element;
                                                    }}
                                                    type="button"
                                                    onClick={() =>
                                                        remove(index, entry.id)
                                                    }
                                                    aria-label={`Quitar ${entry.name}`}
                                                    className="flex h-11 w-9 cursor-pointer items-center justify-center self-stretch border-l border-hq-border text-hq-moss-dim hover:bg-hq-panel-alt hover:text-hq-live sm:h-auto sm:w-8"
                                                >
                                                    <X
                                                        aria-hidden="true"
                                                        className="size-3.5"
                                                    />
                                                </button>
                                            </li>
                                        );
                                    },
                                )}
                            </ul>
                            {selection.length === 1 && (
                                <span className="min-w-0 flex-1 truncate font-mono text-[11px] text-hq-moss">
                                    Añade otro jugador para comparar
                                </span>
                            )}
                            <button
                                type="button"
                                onClick={() => {
                                    clearCompare();
                                    focusFallback(focusFallbackId);
                                }}
                                className="ml-auto hidden h-11 cursor-pointer items-center px-3 font-mono text-[11px] font-bold tracking-[0.06em] text-hq-moss uppercase hover:text-hq-paper min-[480px]:inline-flex sm:h-9"
                            >
                                Vaciar
                            </button>
                            <button
                                type="button"
                                disabled={selection.length < 2}
                                title={
                                    selection.length < 2
                                        ? 'Elige al menos 2 jugadores'
                                        : undefined
                                }
                                onClick={() =>
                                    router.visit(
                                        compareUrl(
                                            selection.map((entry) => entry.id),
                                        ),
                                    )
                                }
                                className={cn(
                                    'inline-flex h-11 shrink-0 items-center gap-2 px-3.5 font-mono text-[11.5px] font-bold tracking-[0.06em] uppercase sm:h-9',
                                    selection.length < 2
                                        ? 'cursor-not-allowed border border-hq-border-strong text-hq-led-off'
                                        : 'ml-auto cursor-pointer bg-hq-lime text-hq-ink hover:brightness-110 min-[480px]:ml-0',
                                )}
                            >
                                Comparar {selection.length}
                                <ArrowRight
                                    aria-hidden="true"
                                    className="size-3.5"
                                    strokeWidth={2.6}
                                />
                            </button>
                        </div>
                    </div>
                </>
            )}
        </>
    );
}
