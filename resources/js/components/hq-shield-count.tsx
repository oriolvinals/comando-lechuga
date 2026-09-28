import { Lock } from 'lucide-react';
import { HqTooltip } from '@/components/hq-tooltip';
import { cn } from '@/lib/utils';

/** Shields (blindajes) every manager gets per jornada — mirrors ManagerShields::PER_WEEK. */
export const SHIELDS_PER_WEEK = 2;

type HqShieldCountSize = 'sm' | 'md';

const SIZE_CLASSES: Record<HqShieldCountSize, { text: string; icon: string }> =
    {
        sm: { text: 'text-[10px]', icon: 'h-2.5 w-2.5' },
        md: { text: 'text-xs', icon: 'h-3 w-3' },
    };

interface HqShieldCountProps {
    /** Shields the manager used in the jornada. */
    used: number;
    /** `sm` (10px) for the points chart and the cards, `md` for the standings. */
    size?: HqShieldCountSize;
    /** Off when a surrounding tooltip already describes the count (the points chart's columns). */
    withTooltip?: boolean;
    /** Off inside a row that is itself a link — no focus stop nested in an anchor. */
    focusable?: boolean;
    className?: string;
}

/** "2 de 2 blindajes disponibles · 0 usados" — the count's tooltip text. */
export function shieldCountLabel(used: number): string {
    const remaining = Math.max(0, SHIELDS_PER_WEEK - used);

    return `${remaining} de ${SHIELDS_PER_WEEK} blindajes disponibles · ${used} ${used === 1 ? 'usado' : 'usados'}`;
}

/**
 * The shields a manager has left in a jornada (mock `.sh`): a lilac lock and
 * `remaining/2`, dimmed once none are left.
 */
export function HqShieldCount({
    used,
    size = 'sm',
    withTooltip = true,
    focusable = true,
    className,
}: HqShieldCountProps) {
    const remaining = Math.max(0, SHIELDS_PER_WEEK - used);
    const label = shieldCountLabel(used);
    const count = (
        <span
            role="img"
            aria-label={label}
            className={cn(
                'inline-flex items-center gap-[3px] font-mono leading-none font-bold text-hq-violet tabular-nums',
                SIZE_CLASSES[size].text,
                remaining === 0 && 'opacity-45',
                !withTooltip && className,
            )}
        >
            <Lock
                aria-hidden="true"
                strokeWidth={2.4}
                className={cn('shrink-0', SIZE_CLASSES[size].icon)}
            />
            {remaining}/{SHIELDS_PER_WEEK}
        </span>
    );

    if (!withTooltip) {
        return count;
    }

    return (
        <HqTooltip
            label={label}
            borderClassName="border-hq-violet"
            focusable={focusable}
            className={className}
        >
            {count}
        </HqTooltip>
    );
}
