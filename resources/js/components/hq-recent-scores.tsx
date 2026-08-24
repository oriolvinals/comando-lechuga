import { matchPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';

interface HqRecentScoresProps {
    scores: (number | null)[];
    className?: string;
}

/** Points for the last 3 played matches — a dash where the player has no match history yet. */
export function HqRecentScores({ scores, className }: HqRecentScoresProps) {
    return (
        <div className={cn('flex shrink-0 gap-1', className)}>
            {scores.map((points, index) => (
                <span
                    key={index}
                    className={cn(
                        'flex h-8 w-8 shrink-0 items-center justify-center border font-mono text-[13px] font-bold',
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
