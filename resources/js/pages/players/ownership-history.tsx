import {
    TYPE_BAR_CLASSES,
    TYPE_COLORS,
    TYPE_LABELS,
} from '@/components/activity-helpers';
import { HqManagerChip } from '@/components/hq-manager-chip';
import { formatCurrency } from '@/lib/format';
import type { OwnershipSegment } from '@/lib/ownership-timeline';
import { cn } from '@/lib/utils';

function formatDayMonth(isoDate: string): string {
    return new Intl.DateTimeFormat('es-ES', {
        day: 'numeric',
        month: 'short',
        timeZone: 'Europe/Madrid',
    }).format(new Date(isoDate));
}

/**
 * The season's ownership changes, newest first (mock `.ownlist`): each deal
 * that started an ownership segment — type and date, seller → buyer, and the
 * amount paid. Built from the same timeline as the value chart's band.
 */
export function OwnershipHistory({
    segments,
}: {
    segments: OwnershipSegment[];
}) {
    const deals = segments
        .flatMap((segment) =>
            segment.startedBy === null
                ? []
                : [{ segment, origin: segment.startedBy }],
        )
        .reverse();

    if (deals.length === 0) {
        return (
            <p className="px-3.5 py-3.5 font-mono text-xs text-hq-moss sm:px-4">
                Sin traspasos registrados esta temporada.
            </p>
        );
    }

    return (
        <ol>
            {deals.map(({ segment, origin }, index) => {
                const joined = origin.type === 'joined_league';

                return (
                    <li
                        key={index}
                        className="flex items-center gap-3 border-b border-hq-border py-2.5 pr-3.5 sm:pr-4"
                    >
                        <span
                            aria-hidden="true"
                            className={cn(
                                'w-1 self-stretch',
                                TYPE_BAR_CLASSES[origin.type],
                            )}
                        />
                        <div className="min-w-0 flex-1">
                            <p
                                className={cn(
                                    'font-mono text-[10.5px] leading-none font-bold tracking-[0.06em] uppercase',
                                    TYPE_COLORS[origin.type],
                                )}
                            >
                                {joined
                                    ? 'Llegó con la plantilla inicial'
                                    : TYPE_LABELS[origin.type]}
                                {segment.from !== null && (
                                    <span className="text-hq-moss-dim">
                                        {' '}
                                        · {formatDayMonth(segment.from)}
                                    </span>
                                )}
                            </p>
                            <div className="mt-1.5 flex min-w-0 flex-wrap items-center gap-1.5 font-mono text-xs text-hq-moss-dim">
                                {!joined && (
                                    <>
                                        <HqManagerChip
                                            manager={origin.seller}
                                        />
                                        <span aria-label="a">→</span>
                                    </>
                                )}
                                <HqManagerChip
                                    manager={segment.seasonManager}
                                />
                            </div>
                        </div>
                        {origin.amount !== null && (
                            <span className="shrink-0 bg-hq-khaki px-1.5 py-1 font-mono text-[11.5px] leading-none font-bold whitespace-nowrap text-[#16140c] tabular-nums">
                                {formatCurrency(origin.amount)}
                            </span>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
