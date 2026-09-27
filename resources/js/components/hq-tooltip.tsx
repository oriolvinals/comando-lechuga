import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';

export type HqTooltipTone = 'lime' | 'neg' | 'live' | 'gold' | 'amber';

const TONE_BORDER_CLASSES: Record<HqTooltipTone, string> = {
    lime: 'border-hq-lime',
    neg: 'border-hq-neg',
    live: 'border-hq-live',
    gold: 'border-hq-gold',
    amber: 'border-hq-amber',
};

/** Keeps the bubble this far from the viewport edges. */
const VIEWPORT_MARGIN = 8;
/** Gap between the trigger and the bubble. */
const TRIGGER_GAP = 10;

interface HqTooltipProps {
    label: ReactNode;
    children: ReactNode;
    className?: string;
    /** Accent of the bubble's 1px frame. */
    tone?: HqTooltipTone;
    /** Tailwind border-color class for the bubble — overrides `tone`. */
    borderClassName?: string;
    /**
     * Lets `label` wrap onto multiple lines within a max width, instead of
     * staying on one line (the default — a short date or a single figure).
     */
    wrap?: boolean;
    /** Makes the trigger itself keyboard-focusable (for non-interactive triggers such as a glyph). */
    focusable?: boolean;
}

interface Anchor {
    centerX: number;
    top: number;
    bottom: number;
}

/**
 * A hover/focus tooltip for small inline targets, rendered via a portal into
 * document.body rather than positioned relative to its trigger: a CSS-only
 * tooltip gets clipped whenever its trigger sits inside an `overflow-x-auto`
 * ancestor (e.g. a table's scroll wrapper), since that also clips the Y axis.
 *
 * It sits above the trigger, flips below when there is no room, and is
 * clamped horizontally inside the viewport.
 */
export function HqTooltip({
    label,
    children,
    className,
    tone = 'lime',
    borderClassName,
    wrap = false,
    focusable = false,
}: HqTooltipProps) {
    const [anchor, setAnchor] = useState<Anchor | null>(null);
    const triggerRef = useRef<HTMLSpanElement>(null);

    const show = () => {
        const rect = triggerRef.current?.getBoundingClientRect();

        if (!rect) {
            return;
        }

        setAnchor({
            centerX: rect.left + rect.width / 2,
            top: rect.top,
            bottom: rect.bottom,
        });
    };

    const hide = () => setAnchor(null);

    useEffect(() => {
        if (!anchor) {
            return;
        }

        const hideOnScroll = () => setAnchor(null);

        window.addEventListener('scroll', hideOnScroll, {
            capture: true,
            passive: true,
        });

        return () =>
            window.removeEventListener('scroll', hideOnScroll, {
                capture: true,
            });
    }, [anchor]);

    /** Places the bubble once its real size is known — straight on the DOM node, no re-render. */
    const placeBubble = (bubble: HTMLDivElement | null) => {
        if (!bubble || !anchor) {
            return;
        }

        const halfWidth = bubble.offsetWidth / 2;
        const centerX = Math.max(
            VIEWPORT_MARGIN + halfWidth,
            Math.min(
                window.innerWidth - VIEWPORT_MARGIN - halfWidth,
                anchor.centerX,
            ),
        );
        const fitsAbove =
            anchor.top - bubble.offsetHeight - TRIGGER_GAP >= VIEWPORT_MARGIN;

        bubble.style.left = `${centerX - halfWidth}px`;
        bubble.style.top = fitsAbove
            ? `${anchor.top - bubble.offsetHeight - TRIGGER_GAP}px`
            : `${anchor.bottom + TRIGGER_GAP}px`;
        bubble.style.visibility = 'visible';
    };

    return (
        <span
            ref={triggerRef}
            tabIndex={focusable ? 0 : undefined}
            className={cn('inline-flex', className)}
            onMouseEnter={show}
            onMouseLeave={hide}
            onFocus={show}
            onBlur={hide}
        >
            {children}
            {anchor &&
                createPortal(
                    <div
                        ref={placeBubble}
                        role="tooltip"
                        className={cn(
                            'pointer-events-none invisible fixed top-0 left-0 z-[999] border bg-hq-panel-alt px-2.5 py-[7px] font-mono text-[11.5px] leading-[1.45] font-medium text-hq-paper normal-case shadow-[0_10px_24px_rgba(0,0,0,0.55)]',
                            wrap
                                ? 'max-w-[300px] text-left whitespace-normal'
                                : 'whitespace-nowrap',
                            borderClassName ?? TONE_BORDER_CLASSES[tone],
                        )}
                    >
                        {label}
                    </div>,
                    document.body,
                )}
        </span>
    );
}
