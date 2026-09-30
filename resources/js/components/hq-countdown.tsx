import { useEffect } from 'react';
import { formatUnlockCountdown, formatMatchDateTime } from '@/lib/format';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';

interface HqCountdownProps {
    /** ISO moment the countdown reaches zero. */
    target: string;
    /** Called once when it reaches zero (e.g. reload the page's props). */
    onElapsed?: () => void;
    className?: string;
}

/** A live "2 d 14 h 03 min" countdown; the exact moment is in the tooltip. */
export function HqCountdown({
    target,
    onElapsed,
    className,
}: HqCountdownProps) {
    const now = useNow(1000);
    const remaining = new Date(target).getTime() - now;
    const elapsed = remaining <= 0;

    useEffect(() => {
        if (elapsed) {
            onElapsed?.();
        }
    }, [elapsed, onElapsed]);

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
