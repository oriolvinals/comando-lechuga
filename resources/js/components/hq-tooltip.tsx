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

    return (
        <span
            ref={triggerRef}
            className={cn('inline-flex', className)}
            onMouseEnter={show}
            onMouseLeave={() => setPosition(null)}
        >
            {children}
            {position &&
                createPortal(
                    <div
                        className={cn(
                            'pointer-events-none fixed z-[999] -translate-x-1/2 -translate-y-[calc(100%+8px)] rounded border bg-hq-panel-alt px-2.5 py-1.5 font-mono text-[11px] whitespace-nowrap text-hq-paper shadow-lg',
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
