import { Check, Plus } from 'lucide-react';
import type { CSSProperties, MouseEvent } from 'react';
import { HqTooltip } from '@/components/hq-tooltip';
import {
    COMPARE_MAX,
    COMPARE_SLOT_COLORS,
    toggleCompare,
    useCompareSelection,
} from '@/lib/compare-selection';
import type { CompareEntry } from '@/lib/compare-selection';
import { cn } from '@/lib/utils';

interface HqCompareToggleProps {
    player: CompareEntry;
    className?: string;
}

/**
 * The "comparar" box of a player row (mock `.cmpbtn`). It never lets the
 * row's own click (open the ficha) fire, and it is disabled — with a tooltip
 * saying why — once three other players are chosen.
 */
export function HqCompareToggle({ player, className }: HqCompareToggleProps) {
    const selection = useCompareSelection();
    const slot = selection.findIndex((entry) => entry.id === player.id);
    const selected = slot >= 0;
    const full = !selected && selection.length >= COMPARE_MAX;

    const handleClick = (event: MouseEvent<HTMLButtonElement>) => {
        event.stopPropagation();
        event.preventDefault();

        if (!full) {
            toggleCompare(player);
        }
    };

    const button = (
        <button
            type="button"
            onClick={handleClick}
            aria-pressed={selected}
            aria-disabled={full || undefined}
            aria-label={`Comparar ${player.name}`}
            style={
                selected
                    ? ({ '--slot': COMPARE_SLOT_COLORS[slot] } as CSSProperties)
                    : undefined
            }
            className={cn(
                'relative flex size-8 shrink-0 items-center justify-center before:absolute before:-inset-1.5 before:content-[""]',
                full ? 'cursor-not-allowed opacity-40' : 'cursor-pointer',
                className,
            )}
        >
            <span
                className={cn(
                    'flex size-[22px] items-center justify-center border transition-colors',
                    selected
                        ? 'border-(--slot) bg-(--slot) text-hq-ink'
                        : 'border-hq-border-bright text-hq-moss hover:border-hq-lime hover:text-hq-lime',
                )}
            >
                {selected ? (
                    <Check
                        aria-hidden="true"
                        className="size-3.5"
                        strokeWidth={3}
                    />
                ) : (
                    <Plus
                        aria-hidden="true"
                        className="size-3"
                        strokeWidth={2.4}
                    />
                )}
            </span>
        </button>
    );

    return full ? (
        <HqTooltip label="Máximo 3 jugadores">{button}</HqTooltip>
    ) : (
        button
    );
}
