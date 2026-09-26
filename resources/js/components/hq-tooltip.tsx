import type { ReactNode } from 'react';
import { useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';

interface HqTooltipProps {
    label: ReactNode;
    children: ReactNode;
    className?: string;
    /** Tailwind border-color class for the tooltip bubble — defaults to lime. */
    borderClassName?: string;
    /**
     * Lets `label` wrap onto multiple lines within a max width, instead of
     * staying on one line (the default, used by every existing caller —
     * a short date or a single figure).
     */
    wrap?: boolean;
}

interface Position {
    x: number;
    y: number;
}

/**
 * A hover tooltip for small inline targets (a badge, a result square)
 * rendered via a portal into document.body — like the one in
 * hq-player-value-chart.tsx — rather than positioned relative to its
 * trigger. A `position: relative` CSS-only tooltip gets clipped whenever
 * its trigger sits inside an `overflow-x-auto` ancestor (e.g. the
 * standings table's scroll wrapper), since that also clips the Y axis.
 * The portal escapes that entirely.
 */
export function HqTooltip({
    label,
    children,
    className,
    borderClassName = 'border-hq-lime',
    wrap = false,
}: HqTooltipProps) {
    const [position, setPosition] = useState<Position | null>(null);
    const triggerRef = useRef<HTMLSpanElement>(null);

    const show = () => {
        const rect = triggerRef.current?.getBoundingClientRect();

        if (!rect) {
            return;
        }

        setPosition({ x: rect.left + rect.width / 2, y: rect.top });
    };

    const hide = () => setPosition(null);

    return (
        <span
            ref={triggerRef}
            className={cn('inline-flex', className)}
            onMouseEnter={show}
            onMouseLeave={hide}
            onFocus={show}
            onBlur={hide}
        >
            {children}
            {position &&
                createPortal(
                    <div
                        className={cn(
                            'pointer-events-none fixed z-[999] -translate-x-1/2 -translate-y-[calc(100%+8px)] rounded border bg-hq-panel-alt px-2.5 py-1.5 font-mono text-[11px] text-hq-paper shadow-lg',
                            wrap
                                ? 'max-w-xs text-left whitespace-normal'
                                : 'whitespace-nowrap',
                            borderClassName,
                        )}
                        style={{ left: position.x, top: position.y }}
                    >
                        {label}
                    </div>,
                    document.body,
                )}
        </span>
    );
}
