import { Ban, CircleHelp, Cross, UserX } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { STATUS_LABELS, STATUS_SHORT_LABELS } from '@/lib/player-labels';
import { cn } from '@/lib/utils';
import type { PlayerStatus } from '@/types/models';

type UnavailableStatus = Exclude<PlayerStatus, 'ok'>;

const STATUS_ICONS: Record<UnavailableStatus, LucideIcon> = {
    injured: Cross,
    suspended: Ban,
    doubtful: CircleHelp,
    out_of_league: UserX,
};

const STATUS_TONE_CLASSES: Record<UnavailableStatus, string> = {
    injured: 'bg-hq-live/10 text-hq-live',
    suspended: 'bg-hq-live/10 text-hq-live',
    doubtful: 'bg-hq-gold/10 text-hq-gold',
    out_of_league: 'bg-hq-olive/10 text-hq-olive',
};

interface HqStatusBadgeProps {
    status: PlayerStatus;
    /** `short` ("Lesión") for rows and cards, `long` ("Lesionado") for the player ficha. */
    variant?: 'short' | 'long';
    className?: string;
}

/**
 * A player's availability tag: icon + label in a 1px frame of its colour.
 * Renders nothing for an available (`ok`) player.
 */
export function HqStatusBadge({
    status,
    variant = 'short',
    className,
}: HqStatusBadgeProps) {
    if (status === 'ok') {
        return null;
    }

    const Icon = STATUS_ICONS[status];

    return (
        <span
            title={STATUS_LABELS[status]}
            className={cn(
                'inline-flex shrink-0 items-center gap-1 border border-current px-[5px] py-[3px] font-mono text-[10px] leading-none font-bold tracking-[0.04em] whitespace-nowrap uppercase',
                STATUS_TONE_CLASSES[status],
                className,
            )}
        >
            <Icon
                aria-hidden="true"
                className="size-[11px]"
                strokeWidth={2.5}
            />
            {variant === 'long'
                ? STATUS_LABELS[status]
                : STATUS_SHORT_LABELS[status]}
        </span>
    );
}
