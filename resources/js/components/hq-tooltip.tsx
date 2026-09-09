import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface HqTooltipProps {
    label: ReactNode;
    children: ReactNode;
    className?: string;
}

/**
 * A lightweight, CSS-only hover tooltip (no mouse-tracking JS, unlike the
 * portal-based one in hq-player-value-chart.tsx) for small inline targets —
 * a badge, a result square — where a simple `group-hover` reveal is enough.
 */
export function HqTooltip({ label, children, className }: HqTooltipProps) {
    return (
        <span className={cn('group relative inline-flex', className)}>
            {children}
            <span className="pointer-events-none absolute bottom-full left-1/2 z-30 mb-2 -translate-x-1/2 translate-y-1 rounded border border-hq-lime bg-hq-panel-alt px-2.5 py-1.5 font-mono text-[11px] whitespace-nowrap text-hq-paper opacity-0 shadow-lg transition-all duration-100 group-hover:translate-y-0 group-hover:opacity-100">
                {label}
                <span className="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-hq-lime" />
            </span>
        </span>
    );
}
