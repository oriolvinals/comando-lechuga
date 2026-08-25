import { matchPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';

interface HqRecentScoresProps {
    scores: (number | null)[];
    /** Per-slot: was the player in this team's lineup that week? Omit entirely outside the team ficha, where the question doesn't apply. */
    used?: (boolean | null)[];
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
    used,
    className,
    size = 'md',
}: HqRecentScoresProps) {
    return (
        <div className={cn('flex shrink-0 gap-1', className)}>
            {scores.map((points, index) => {
                const wasUsed = used?.[index];

                return (
                    <span
                        key={index}
                        className={cn(
                            'relative flex shrink-0 items-center justify-center border font-mono font-bold',
                            SIZE_CLASSES[size],
                            points !== null
                                ? matchPointsBadgeClass(points)
                                : 'border-dashed border-hq-border-strong text-hq-moss-dim',
                        )}
                    >
                        {points ?? '–'}
                        {wasUsed !== undefined && wasUsed !== null && (
                            <span
                                className={cn(
                                    'absolute -bottom-1.5 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full',
                                    wasUsed
                                        ? 'bg-hq-lime'
                                        : 'border border-hq-border-strong',
                                )}
                            />
                        )}
                    </span>
                );
            })}
        </div>
    );
}
