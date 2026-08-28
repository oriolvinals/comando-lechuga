import { formatSignedPoints, matchPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';

interface HqRecentScoresProps {
    scores: (number | null)[];
    /** Per-slot: whether a real finished fixture exists there — lets a null slot render as "not called up" instead of "no match history yet". */
    finished?: boolean[];
    /** Per-slot: was the player in this team's lineup that week? Omit entirely outside the team ficha, where the question doesn't apply. */
    used?: (boolean | null)[];
    className?: string;
    size?: 'md' | 'sm';
    /** Color tier for a slot's value — defaults to the per-player scale; pass {@link teamFormBadgeClass} for team-level totals. */
    badgeClass?: (points: number) => string;
    /** Appends one more slot for the jornada currently in progress — its points are still counting, unlike the rest. Omit (or null) when there's no live jornada. */
    live?: number | null;
}

const SIZE_CLASSES: Record<'md' | 'sm', string> = {
    md: 'h-8 w-8 text-[13px]',
    sm: 'h-6 w-6 text-[11px]',
};

/**
 * Points for the last 3 played matches. A null slot is either "no match
 * history yet" (a dash) or, when `finished` says a real fixture already
 * happened there, "not called up" (a dashed-red "NC"). An optional trailing
 * `live` slot marks the jornada currently in progress with a pulsing dot,
 * distinct from the finished ones — when present, it takes the place of the
 * oldest finished slot so the row stays at the same 3 total, rather than
 * growing to 4.
 */
export function HqRecentScores({
    scores,
    finished,
    used,
    className,
    size = 'md',
    badgeClass = matchPointsBadgeClass,
    live,
}: HqRecentScoresProps) {
    const hasLive = live !== undefined && live !== null;
    // Nulls only ever pad the end (see docblock below), so when there's
    // already a gap, drop that trailing null to make room for the live slot
    // instead of an oldest real value — only trim the oldest real entry once
    // the row is already full of real data.
    const trimStart = hasLive && scores[scores.length - 1] !== null;
    const visibleScores = hasLive
        ? trimStart
            ? scores.slice(1)
            : scores.slice(0, -1)
        : scores;
    const visibleFinished = hasLive
        ? trimStart
            ? finished?.slice(1)
            : finished?.slice(0, -1)
        : finished;
    const visibleUsed = hasLive
        ? trimStart
            ? used?.slice(1)
            : used?.slice(0, -1)
        : used;

    return (
        <div className={cn('flex shrink-0 gap-1', className)}>
            {visibleScores.map((points, index) => {
                const wasUsed = visibleUsed?.[index];
                const notCalledUp = points === null && visibleFinished?.[index];

                return (
                    <span
                        key={index}
                        className={cn(
                            'relative flex shrink-0 items-center justify-center border font-mono font-bold',
                            SIZE_CLASSES[size],
                            points !== null
                                ? badgeClass(points)
                                : notCalledUp
                                  ? 'border-dashed border-hq-live bg-hq-border-strong text-hq-live'
                                  : 'border-dashed border-hq-border-strong bg-hq-border-strong/40 text-hq-moss-dim',
                        )}
                    >
                        {points ?? (notCalledUp ? 'NC' : '–')}
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
            {hasLive && (
                <span
                    className={cn(
                        'relative flex shrink-0 items-center justify-center border font-mono font-bold',
                        SIZE_CLASSES[size],
                        badgeClass(live),
                    )}
                >
                    {formatSignedPoints(live)}
                    <span className="absolute -top-1.5 -right-1 h-1.5 w-1.5 animate-pulse rounded-full bg-hq-live" />
                </span>
            )}
        </div>
    );
}
