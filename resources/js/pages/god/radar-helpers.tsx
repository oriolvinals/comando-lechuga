import { formatNumber } from '@/lib/format';
import { managerColor } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import type { PayerLevel, RadarManager } from '@/types/models';

/** "212,9" — millions with one decimal, the unit printed by the caller. */
export function formatM(amount: number): string {
    return formatNumber(amount / 1_000_000);
}

/**
 * A manager as a square in their primary colour (khaki fallback) with their
 * initial inside, so near-identical colours stay distinguishable.
 * Payer levels: filled = sure, outline = maybe, dashed empty = can't pay.
 */
export function ManagerSquare({
    manager,
    size = 'sm',
    variant = 'plain',
}: {
    manager: Pick<RadarManager, 'name' | 'primary_color'>;
    size?: 'sm' | 'md';
    variant?: PayerLevel | 'plain';
}) {
    const color = managerColor(manager.primary_color);
    const filled = variant === 'plain' || variant === 'sure';

    return (
        <i
            aria-hidden="true"
            className={cn(
                'inline-flex shrink-0 items-center justify-center font-mono leading-none font-bold not-italic',
                size === 'sm' ? 'size-[14px] text-[9px]' : 'size-4 text-[10px]',
                filled &&
                    'text-hq-ink shadow-[0_0_0_1px_rgba(255,255,255,0.12)]',
                variant === 'maybe' && 'border-2 text-hq-paper',
                variant === 'no' &&
                    'border border-dashed border-hq-border-bright text-hq-moss-dim',
            )}
            style={
                filled
                    ? { backgroundColor: color }
                    : variant === 'maybe'
                      ? { borderColor: color }
                      : undefined
            }
        >
            {manager.name.trim().charAt(0).toUpperCase()}
        </i>
    );
}
