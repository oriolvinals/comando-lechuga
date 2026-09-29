import type { ReactNode } from 'react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';

export interface TipAnchor {
    left: number;
    right: number;
    top: number;
    bottom: number;
    /** Beside the anchor instead of above it (view C cells, on screens wider than 560 px). */
    side?: boolean;
}

interface TipState {
    content: ReactNode;
    anchor: () => TipAnchor | null;
    owner: Element;
}

interface ChartTooltipApi {
    show: (
        content: ReactNode,
        anchor: () => TipAnchor | null,
        owner: Element,
    ) => void;
    hide: (owner?: Element) => void;
}

const ChartTooltipContext = createContext<ChartTooltipApi | null>(null);

/**
 * One interactive tooltip for the three views (mock `.cmptip`): fixed
 * position, clamped to the viewport, following hover, keyboard focus and
 * touch — a tap shows it and the next tap elsewhere hides it.
 */
export function HqChartTooltip({ children }: { children: ReactNode }) {
    const [tip, setTip] = useState<TipState | null>(null);
    const bubbleRef = useRef<HTMLDivElement>(null);

    const place = useCallback(() => {
        const bubble = bubbleRef.current;
        const rect = tip?.anchor();

        if (
            !bubble ||
            !rect ||
            rect.bottom < 0 ||
            rect.top > window.innerHeight
        ) {
            if (bubble) {
                bubble.style.visibility = 'hidden';
            }

            return;
        }

        const width = bubble.offsetWidth;
        const height = bubble.offsetHeight;
        let x = Math.max(
            8,
            Math.min(
                window.innerWidth - width - 8,
                (rect.left + rect.right) / 2 - width / 2,
            ),
        );
        let y = rect.top - height - 10;

        if (y < 8) {
            y = Math.min(window.innerHeight - height - 8, rect.bottom + 10);
        }

        if (rect.side && window.innerWidth > 560) {
            x =
                rect.right + 10 + width > window.innerWidth - 8
                    ? rect.left - width - 10
                    : rect.right + 10;
            y = Math.max(
                8,
                Math.min(
                    window.innerHeight - height - 8,
                    (rect.top + rect.bottom) / 2 - height / 2,
                ),
            );
        }

        bubble.style.left = `${Math.round(x)}px`;
        bubble.style.top = `${Math.round(y)}px`;
        bubble.style.visibility = 'visible';
    }, [tip]);

    // Before paint, so a new bubble never flashes at its previous spot.
    useLayoutEffect(() => {
        if (tip) {
            place();
        }
    }, [tip, place]);

    useEffect(() => {
        if (!tip) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setTip(null);
            }
        };
        const onPointerDown = (event: globalThis.PointerEvent) => {
            if (
                !(event.target instanceof Element) ||
                !event.target.closest(
                    '[data-cmp-tip],[data-hl-slot],[data-cmp-chart]',
                )
            ) {
                setTip(null);
            }
        };

        window.addEventListener('scroll', place, {
            passive: true,
            capture: true,
        });
        window.addEventListener('resize', place);
        window.addEventListener('keydown', onKeyDown);
        document.addEventListener('pointerdown', onPointerDown, true);

        return () => {
            window.removeEventListener('scroll', place, { capture: true });
            window.removeEventListener('resize', place);
            window.removeEventListener('keydown', onKeyDown);
            document.removeEventListener('pointerdown', onPointerDown, true);
        };
    }, [tip, place]);

    const api = useMemo<ChartTooltipApi>(
        () => ({
            show: (content, anchor, owner) =>
                setTip({ content, anchor, owner }),
            hide: (owner) =>
                setTip((currentTip) =>
                    owner === undefined || currentTip?.owner === owner
                        ? null
                        : currentTip,
                ),
        }),
        [],
    );

    return (
        <ChartTooltipContext.Provider value={api}>
            {children}
            {tip &&
                createPortal(
                    <div
                        ref={bubbleRef}
                        aria-hidden="true"
                        className="pointer-events-none invisible fixed top-0 left-0 z-[90] flex max-w-[min(300px,calc(100vw-16px))] min-w-[150px] flex-col border border-hq-border-bright bg-hq-ink px-[11px] pt-[9px] pb-2.5 font-mono text-[11px] leading-[1.35] text-hq-paper shadow-[0_14px_30px_rgba(0,0,0,0.62)]"
                    >
                        {tip.content}
                    </div>,
                    document.body,
                )}
        </ChartTooltipContext.Provider>
    );
}

export function useChartTooltip(): ChartTooltipApi {
    const api = useContext(ChartTooltipContext);

    if (!api) {
        throw new Error('useChartTooltip needs <HqChartTooltip>.');
    }

    return api;
}

/** `data-hl` on the view root dims every other player's `[data-slot]` to 30 % (rules in app.css). */
export function useSlotHighlight() {
    const [highlighted, setHighlighted] = useState<number | null>(null);

    const bind = (slot: number) => ({
        'data-hl-slot': slot,
        onPointerEnter: () => setHighlighted(slot),
        onPointerLeave: () => setHighlighted(null),
        onFocus: () => setHighlighted(slot),
        onBlur: () => setHighlighted(null),
    });

    return {
        highlighted,
        setHighlighted,
        bind,
        rootProps: {
            className: 'cmp-view',
            'data-hl': highlighted ?? undefined,
        },
    };
}

/** One row of a tooltip: slot colour, name, value and an optional extra (rank, % …); the hovered one in bold. */
export function TipRow({
    slotColor,
    name,
    value,
    extra,
    me,
}: {
    slotColor: string;
    name: string;
    value: string;
    extra?: string;
    me?: boolean;
}) {
    return (
        <span
            className={`grid grid-cols-[4px_minmax(0,1fr)_auto_auto] items-center gap-2 ${me ? 'text-hq-paper' : 'text-hq-moss'}`}
        >
            <i
                className="min-h-3 self-stretch"
                style={{ background: slotColor }}
            />
            <span className="truncate font-sans text-[11px] font-extrabold uppercase">
                {name}
            </span>
            <b className="text-right font-bold tabular-nums">{value}</b>
            {extra !== undefined && (
                <em className="min-w-[3ch] text-right text-hq-moss-dim not-italic tabular-nums">
                    {extra}
                </em>
            )}
        </span>
    );
}
