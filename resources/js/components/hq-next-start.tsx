import {
    HqStartMeter,
    HqStartOutcomeChip,
} from '@/components/hq-start-probability';
import { cn } from '@/lib/utils';
import type { PlayerNextStart, PlayerStatus } from '@/types/models';

/**
 * Small "XI" badge (mock `.t-chip.lime`) next to the start bar when
 * FútbolFantasy predicts the player in his next match's XI and the lineup
 * isn't confirmed yet — the confirmed outcome chip takes over once it is.
 */
function HqNextStartXiBadge() {
    return (
        <span
            title="En el XI probable de FútbolFantasy"
            className="inline-flex h-[15px] shrink-0 items-center border border-current bg-hq-lime/8 px-[4px] font-mono text-[9px] leading-none font-bold tracking-[0.06em] text-hq-lime uppercase"
        >
            XI
        </span>
    );
}

/**
 * A player's start probability (or confirmed outcome) for his team's next
 * match, as the shared 10-cell bar (the match list's) — with an "XI" badge
 * when he's in FútbolFantasy's probable XI — or, once confirmed, the
 * Titular / Suplente outcome chip. Nothing without data. Shared by the
 * manager roster and the team ficha's squad list.
 */
export function HqNextStart({
    start,
    status,
    className,
}: {
    start: PlayerNextStart | null;
    status: PlayerStatus;
    className?: string;
}) {
    if (start === null) {
        return null;
    }

    if (start.confirmed_starter !== null) {
        return (
            <HqStartOutcomeChip
                facts={start}
                className={cn('self-start', className)}
            />
        );
    }

    return (
        <span
            className={cn(
                'inline-flex flex-wrap items-center gap-1.5',
                className,
            )}
        >
            <HqStartMeter
                probability={start.probability}
                status={status}
                size="sm"
                muted={start.is_stale}
                fetchedAt={start.fetched_at}
            />
            {start.predicted_starter && <HqNextStartXiBadge />}
        </span>
    );
}
