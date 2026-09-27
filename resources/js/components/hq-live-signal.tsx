import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

interface HqLiveSignalProps {
    /** Phone header: no rules, no second line. */
    compact?: boolean;
    className?: string;
}

/**
 * The shell's live pill: a dot and "J{week} · Online/Offline" — red and
 * pulsing while any match of the matchday is being played.
 */
export function HqLiveSignal({
    compact = false,
    className,
}: HqLiveSignalProps) {
    const { season, liveMatchday } = usePage().props;

    return (
        <span
            className={cn(
                'flex items-center font-mono leading-[1.2] font-semibold tracking-[0.08em] whitespace-nowrap uppercase',
                compact ? 'gap-[7px] text-[10.5px]' : 'gap-2.5 text-[11px]',
                liveMatchday ? 'text-hq-live' : 'text-hq-moss-dim',
                className,
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'size-[7px] shrink-0 rounded-full',
                    liveMatchday
                        ? 'animate-hq-pulse bg-hq-live'
                        : 'bg-hq-led-off',
                )}
            />
            <span>
                <b className="font-bold text-hq-khaki">
                    J{season.current_week}
                </b>
                <span className={cn(compact && 'max-[419px]:sr-only')}>
                    {' '}
                    · {liveMatchday ? 'Online' : 'Offline'}
                </span>
                {liveMatchday && !compact && (
                    <small className="mt-0.5 block text-[11px] font-medium tracking-[0.04em] text-hq-moss-dim normal-case">
                        jornada en juego
                    </small>
                )}
            </span>
        </span>
    );
}
