import type { CSSProperties } from 'react';
import {
    describeActivityBody,
    isFavorableDifference,
    TYPE_BAR_CLASSES,
    TYPE_COLORS,
    TYPE_LABELS,
    TYPE_TINT_VARS,
} from '@/components/activity-helpers';
import { HqTooltip } from '@/components/hq-tooltip';
import {
    formatCurrency,
    formatFullDateTime,
    formatRelativeTime,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Activity } from '@/types/models';

interface HqActivityTimelineEntryProps {
    activity: Activity;
}

/**
 * One activity log line ("ae" in the mock): a type-coloured accent rule, the
 * type label with its relative time (full date on hover/focus), the body with
 * linked manager/player names, and — when there is money involved — the
 * khaki amount chip plus the price-vs-value difference (lime when it
 * favoured the manager, red otherwise). A faint type tint washes in from the
 * left. Rows stack on 1px rules; a parent may lay them out in columns.
 */
export function HqActivityTimelineEntry({
    activity,
}: HqActivityTimelineEntryProps) {
    const differenceLabel =
        activity.type === 'sale'
            ? 'Precio de venta frente a su valor ese día'
            : 'Precio pagado frente a su valor ese día';

    return (
        <div
            style={
                {
                    '--tint': `color-mix(in srgb, ${TYPE_TINT_VARS[activity.type]} 8%, transparent)`,
                } as CSSProperties
            }
            className="grid h-full grid-cols-[4px_minmax(0,1fr)_auto] items-start gap-x-3 border-b border-hq-border bg-linear-to-r from-(--tint) to-transparent to-45% py-2.5 pr-3 sm:grid-cols-[4px_92px_minmax(0,1fr)_auto] sm:pr-4"
        >
            <span
                aria-hidden="true"
                className={cn(
                    '-my-2.5 self-stretch sm:row-span-1',
                    'row-span-2',
                    TYPE_BAR_CLASSES[activity.type],
                )}
            />
            <div
                className={cn(
                    'pl-2.5 font-mono text-[10.5px] leading-[1.25] font-bold tracking-[0.07em] uppercase sm:pl-3',
                    TYPE_COLORS[activity.type],
                )}
            >
                {TYPE_LABELS[activity.type]}
                <HqTooltip
                    label={formatFullDateTime(activity.occurred_at)}
                    focusable
                    className="ml-2 cursor-help sm:mt-1 sm:ml-0 sm:flex"
                >
                    <time
                        dateTime={activity.occurred_at}
                        className="font-medium tracking-[0.02em] text-hq-moss-dim normal-case"
                    >
                        {formatRelativeTime(activity.occurred_at)}
                    </time>
                </HqTooltip>
            </div>
            <p className="col-start-2 mt-1 min-w-0 pl-2.5 text-[13px] leading-[1.4] text-hq-paper/90 sm:col-start-3 sm:row-start-1 sm:mt-0 sm:pl-0 sm:text-[13.5px] [&_a]:font-bold [&_a]:text-hq-khaki [&_a:hover]:text-hq-paper">
                {describeActivityBody(activity)}
            </p>
            <div className="col-start-3 row-span-2 row-start-1 flex flex-col items-end gap-1 sm:col-start-4 sm:row-span-1">
                {activity.amount !== null && (
                    <span className="inline-block bg-hq-khaki px-1.5 py-1 font-mono text-[11.5px] leading-none font-bold whitespace-nowrap text-hq-ink">
                        {formatCurrency(activity.amount)}
                    </span>
                )}
                {activity.amount !== null &&
                    activity.value_difference !== null && (
                        <HqTooltip
                            label={differenceLabel}
                            tone={
                                isFavorableDifference(activity) ? 'lime' : 'neg'
                            }
                            focusable
                        >
                            <span
                                className={cn(
                                    'font-mono text-[11px] leading-none font-bold whitespace-nowrap',
                                    isFavorableDifference(activity)
                                        ? 'text-hq-lime'
                                        : 'text-hq-neg',
                                )}
                            >
                                {activity.value_difference >= 0 ? '+' : ''}
                                {formatCurrency(activity.value_difference)}
                            </span>
                        </HqTooltip>
                    )}
            </div>
        </div>
    );
}
