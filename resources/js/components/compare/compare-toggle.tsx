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
    /**
     * `row` (lists): a 32 px button tinted with the player's slot colour.
     * `card` (home market): a bare 22 px box on the card corner with a 44 px
     * hit area, lime when chosen (mock `_mercado-comparar`).
     */
    variant?: 'row' | 'card';
    className?: string;
}

/**
 * The "comparar" box of a player row (mock `.cmpbtn`). It never lets the
 * row's own click (open the ficha) fire, and it is disabled — with a tooltip
 * saying why — once three other players are chosen.
 */
export function HqCompareToggle({
    player,
    variant = 'row',
    className,
}: HqCompareToggleProps) {
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
                    ? ({
                          '--slot':
                              variant === 'card'
                                  ? 'var(--color-hq-lime)'
                                  : COMPARE_SLOT_COLORS[slot],
                      } as CSSProperties)
                    : undefined
            }
            className={cn(
                'relative flex shrink-0 items-center justify-center before:absolute before:content-[""]',
                variant === 'card'
                    ? 'size-[22px] before:-inset-[11px]'
                    : 'size-8 before:-inset-1.5',
                full ? 'cursor-not-allowed opacity-40' : 'cursor-pointer',
                className,
            )}
        >
            <span
                className={cn(
                    'flex size-[22px] items-center justify-center border transition-colors motion-reduce:transition-none',
                    selected
                        ? 'border-(--slot) bg-(--slot) text-hq-ink'
                        : cn(
                              'border-hq-border-bright text-hq-moss',
                              !full &&
                                  'hover:border-hq-lime hover:text-hq-lime',
                              variant === 'card' && 'bg-hq-ink',
                          ),
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
