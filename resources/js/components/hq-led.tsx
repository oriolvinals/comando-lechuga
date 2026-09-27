import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type HqLedTone = 'paper' | 'lime' | 'gold' | 'amber' | 'live' | 'off';

const TONE_CLASSES: Record<HqLedTone, string> = {
    paper: 'text-hq-paper',
    lime: 'text-hq-lime',
    gold: 'text-hq-gold',
    amber: 'text-hq-amber',
    live: 'text-hq-live',
    off: 'text-hq-led-off',
};

const GLOW_CLASSES: Partial<Record<HqLedTone, string>> = {
    lime: '[text-shadow:0_0_12px_rgba(196,255,61,0.35)]',
    gold: '[text-shadow:0_0_12px_rgba(232,193,74,0.35)]',
    amber: '[text-shadow:0_0_12px_rgba(255,181,71,0.35)]',
    live: '[text-shadow:0_0_12px_rgba(255,77,94,0.4)]',
};

interface HqLedProps {
    children: ReactNode;
    tone?: HqLedTone;
    /** Soft phosphor halo — for the one hero number of a block (scoreboard, bid). */
    glow?: boolean;
    className?: string;
    title?: string;
}

/**
 * A dot-matrix (Doto) number. Reserved for scoreboards, big point totals,
 * countdowns and the max bid — everything else stays in Chivo Mono. Size it
 * with a text-* utility at the call site.
 */
export function HqLed({
    children,
    tone = 'paper',
    glow = false,
    className,
    title,
}: HqLedProps) {
    return (
        <span
            title={title}
            className={cn(
                'font-dot leading-[0.9] font-black tracking-[0.02em] tabular-nums',
                TONE_CLASSES[tone],
                glow && GLOW_CLASSES[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}
