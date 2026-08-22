import { POSITION_ABBREVIATIONS } from '@/lib/player-labels';
import { cn } from '@/lib/utils';
import type { PlayerPosition } from '@/types/models';

/**
 * Canonical position colors for the Comando visual language. Matches the
 * official LaLiga Fantasy app (naranja/lila/azul/oro) — don't redefine
 * these per page, use this component everywhere a position is shown.
 */
const POSITION_COLOR_CLASSES: Record<PlayerPosition, string> = {
    goalkeeper: 'border-hq-por/40 bg-hq-por/10 text-hq-por',
    defender: 'border-hq-def/40 bg-hq-def/10 text-hq-def',
    midfield: 'border-hq-med/40 bg-hq-med/10 text-hq-med',
    striker: 'border-hq-del/40 bg-hq-del/10 text-hq-del',
    coach: 'border-hq-border-strong bg-hq-border/40 text-hq-moss',
};

interface HqPositionTagProps {
    position: PlayerPosition;
    className?: string;
}

export function HqPositionTag({ position, className }: HqPositionTagProps) {
    return (
        <span
            className={cn(
                'border px-1.5 py-0.5 font-mono text-[9px] font-bold',
                POSITION_COLOR_CLASSES[position],
                className,
            )}
        >
            {POSITION_ABBREVIATIONS[position]}
        </span>
    );
}
