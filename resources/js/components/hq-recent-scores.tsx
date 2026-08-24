import { matchPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';

interface HqRecentScoresProps {
    scores: (number | null)[];
    className?: string;
    size?: 'md' | 'sm';
}

const SIZE_CLASSES: Record<'md' | 'sm', string> = {
    md: 'h-8 w-8 text-[13px]',
    sm: 'h-6 w-6 text-[11px]',
};

/** Points for the last 3 played matches — a dash where the player has no match history yet. */
export function HqRecentScores({
    scores,
    className,
    size = 'md',
}: HqRecentScoresProps) {
    return (
        <div className={cn('flex shrink-0 gap-1', className)}>
            {scores.map((points, index) => (
                <span
                    key={index}
                    className={cn(
                        'flex shrink-0 items-center justify-center border font-mono font-bold',
                        SIZE_CLASSES[size],
                        points !== null
                            ? matchPointsBadgeClass(points)
                            : 'border-dashed border-hq-border-strong text-hq-moss-dim',
                    )}
                >
                    {points ?? '–'}
                </span>
            ))}
        </div>
    );
}
