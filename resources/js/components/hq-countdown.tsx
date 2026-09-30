import { useEffect } from 'react';
import { formatUnlockCountdown, formatMatchDateTime } from '@/lib/format';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';

/** Over an hour away the text only shows minutes, so a 30 s tick is enough. */
const COARSE_TICK_MS = 30_000;
const SECONDS_SHOWN_BELOW_MS = 3600 * 1000;

interface HqCountdownProps {
    /** ISO moment the countdown reaches zero. */
    target: string;
    /** Called once when it reaches zero (e.g. reload the page's props). */
    onElapsed?: () => void;
    className?: string;
}

function CountdownTime({
    target,
    remaining,
    className,
}: {
    target: string;
    remaining: number;
    className?: string;
}) {
    return (
        <time
            dateTime={target}
            title={formatMatchDateTime(target)}
            className={cn(
                'font-mono whitespace-nowrap tabular-nums',
                className,
            )}
        >
            {formatUnlockCountdown(remaining)}
        </time>
    );
}

/** The last hour: ticks every second (the text shows seconds) and reports zero. */
function SecondsCountdown({ target, onElapsed, className }: HqCountdownProps) {
    const now = useNow(1000);
    const remaining = new Date(target).getTime() - now;
    const elapsed = remaining <= 0;

    useEffect(() => {
        if (elapsed) {
            onElapsed?.();
        }
    }, [elapsed, onElapsed]);

    return (
        <CountdownTime
            target={target}
            remaining={remaining}
            className={className}
        />
    );
}

/**
 * A live "2 d 14 h 03 min" countdown; the exact moment is in the tooltip.
 * Ticks every 30 s while over an hour away (minutes only), then every second,
 * so a long list of countdowns doesn't run one 1 s interval each.
 */
export function HqCountdown({
    target,
    onElapsed,
    className,
}: HqCountdownProps) {
    const coarseNow = useNow(COARSE_TICK_MS);
    const remaining = new Date(target).getTime() - coarseNow;

    if (remaining <= SECONDS_SHOWN_BELOW_MS + COARSE_TICK_MS) {
        return (
            <SecondsCountdown
                target={target}
                onElapsed={onElapsed}
                className={className}
            />
        );
    }

    return (
        <CountdownTime
            target={target}
            remaining={remaining}
            className={className}
        />
    );
}
