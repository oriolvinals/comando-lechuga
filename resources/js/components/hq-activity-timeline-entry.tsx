import {
    describeActivityBody,
    isFavorableDifference,
    TYPE_BAR_CLASSES,
    TYPE_LABELS,
} from '@/components/activity-card';
import { formatCurrency, formatRelativeTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SeasonActivity } from '@/types/models';

interface HqActivityTimelineEntryProps {
    activity: SeasonActivity;
}

/**
 * Simplified activity row for narrow contexts (the team ficha's activity
 * rail) — a colored accent bar replaces the crest+photo pair ActivityCard
 * uses, but the value keeps ActivityCard's exact language: a khaki chip for
 * the amount, lime/red for the difference.
 */
export function HqActivityTimelineEntry({
    activity,
}: HqActivityTimelineEntryProps) {
    return (
        <div className="flex gap-2.5 border-b border-hq-ink py-2.5 last:border-b-0">
            <div
                className={cn(
                    'w-[3px] shrink-0 rounded-sm',
                    TYPE_BAR_CLASSES[activity.type],
                )}
            />
            <div className="min-w-0 flex-1">
                <span className="font-mono text-[8.5px] font-bold tracking-wide text-hq-moss uppercase">
                    {TYPE_LABELS[activity.type]}
                </span>
                <p className="mt-0.5 text-[11.5px] leading-snug text-hq-paper/90">
                    {describeActivityBody(activity)}
                </p>
            </div>
            {activity.amount !== null ? (
                <div className="flex shrink-0 flex-col items-end gap-1">
                    <time
                        dateTime={activity.occurred_at}
                        className="font-mono text-[8.5px] text-hq-moss-dim"
                    >
                        {formatRelativeTime(activity.occurred_at)}
                    </time>
                    <span className="hq-tag-cut inline-block bg-hq-khaki px-1.5 py-0.5 font-mono text-[10px] font-bold text-hq-ink">
                        {formatCurrency(activity.amount)}
                    </span>
                    {activity.value_difference !== null && (
                        <p
                            className={cn(
                                'font-mono text-[8.5px] font-bold',
                                isFavorableDifference(activity)
                                    ? 'text-hq-lime'
                                    : 'text-hq-live',
                            )}
                        >
                            {activity.value_difference >= 0 ? '+' : ''}
                            {formatCurrency(activity.value_difference)}
                        </p>
                    )}
                </div>
            ) : (
                <time
                    dateTime={activity.occurred_at}
                    className="shrink-0 self-start font-mono text-[8.5px] text-hq-moss-dim"
                >
                    {formatRelativeTime(activity.occurred_at)}
                </time>
            )}
        </div>
    );
}
