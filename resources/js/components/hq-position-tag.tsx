import { POSITION_ABBREVIATIONS, POSITION_LABELS } from '@/lib/player-labels';
import { cn } from '@/lib/utils';
import type { PlayerPosition } from '@/types/models';

/**
 * Canonical position colors for the Comando visual language. Matches the
 * official LaLiga Fantasy app (naranja/lila/azul/oro) — don't redefine
 * these per page, use this component everywhere a position is shown.
 */
const POSITION_COLOR_CLASSES: Record<PlayerPosition, string> = {
    goalkeeper: 'bg-hq-por/10 text-hq-por',
    defender: 'bg-hq-def/10 text-hq-def',
    midfield: 'bg-hq-med/10 text-hq-med',
    striker: 'bg-hq-del/10 text-hq-del',
    coach: 'bg-hq-ent/10 text-hq-ent',
};

interface HqPositionTagProps {
    position: PlayerPosition;
    className?: string;
}

/** POR / DEF / MED / DEL / ENT in a 1px frame of the position colour. */
export function HqPositionTag({ position, className }: HqPositionTagProps) {
    return (
        <span
            title={POSITION_LABELS[position]}
            className={cn(
                'inline-flex shrink-0 items-center justify-center border border-current px-[5px] py-[3px] font-mono text-[10px] leading-none font-bold tracking-[0.04em]',
                POSITION_COLOR_CLASSES[position],
                className,
            )}
        >
            {POSITION_ABBREVIATIONS[position]}
        </span>
    );
}
