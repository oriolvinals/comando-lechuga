import { router } from '@inertiajs/react';
import { Columns3 } from 'lucide-react';
import {
    compareWith,
    COMPARE_MAX,
    useCompareSelection,
} from '@/lib/compare-selection';
import type { CompareEntry } from '@/lib/compare-selection';
import { cn } from '@/lib/utils';
import { index as playersIndex } from '@/routes/players';

interface HqCompareButtonProps {
    player: CompareEntry;
    /** Called right before leaving for the players list when there is no second player yet (the modal closes itself). */
    onWaiting?: () => void;
    className?: string;
}

/**
 * "Comparar" on the player ficha and the jornada modal: adds the player
 * (taking the last slot when all three are used) and opens the comparator
 * once there are two; with no other player chosen yet, it goes to the
 * players list, where the tray asks for another one.
 */
export function HqCompareButton({
    player,
    onWaiting,
    className,
}: HqCompareButtonProps) {
    const selection = useCompareSelection();
    const others = selection.filter((entry) => entry.id !== player.id).length;
    const label =
        others > 0
            ? `Comparar (${Math.min(others + 1, COMPARE_MAX)})`
            : 'Comparar';

    return (
        <button
            type="button"
            onClick={() => {
                if (compareWith(player) === 'waiting') {
                    onWaiting?.();
                    router.visit(playersIndex().url);
                }
            }}
            className={cn(
                'inline-flex h-11 cursor-pointer items-center justify-center gap-2 border border-hq-border-strong px-3 font-mono text-[11px] font-bold tracking-[0.06em] text-hq-moss uppercase transition-colors hover:border-hq-lime hover:text-hq-lime sm:h-9',
                className,
            )}
        >
            <Columns3 aria-hidden="true" className="size-3.5" />
            {label}
        </button>
    );
}
