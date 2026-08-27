import { usePage } from '@inertiajs/react';
import { useLayoutEffect, useRef } from 'react';
import { HqScrollRow } from '@/components/hq-scroll-row';
import { teamFormBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import type { WeekProgressMap } from '@/types/models';

interface HqWeekScrollPickerProps {
    week: number;
    maxWeek: number;
    playedThroughWeek: number;
    weekProgress: WeekProgressMap;
    onChange: (week: number) => void;
    /**
     * When provided, each tile shows this manager's points for that week
     * (colored by form, same scale as the home standings) instead of the
     * week's fixture progress — e.g. the team ficha's lineup-by-week picker.
     */
    weekPoints?: Record<number, number>;
}

/**
 * Horizontal jornada picker for the home page. Color reflects how far along
 * that jornada actually is (none/partial/all fixtures finished), and the
 * live jornada (if any) pulses red, like the navbar's live indicator.
 */
export function HqWeekScrollPicker({
    week,
    maxWeek,
    playedThroughWeek,
    weekProgress,
    onChange,
    weekPoints,
}: HqWeekScrollPickerProps) {
    const { liveMatchday } = usePage().props;
    const selectedRef = useRef<HTMLButtonElement>(null);
    const isFirstRender = useRef(true);

    useLayoutEffect(() => {
        const button = selectedRef.current;
        const scroller = button?.parentElement;

        if (!button || !scroller) {
            return;
        }

        // Center the button within its horizontal scroll row only. We can't
        // use `button.scrollIntoView()` here: it walks every scrollable
        // ancestor, including the page itself, and on mobile this picker
        // often sits below the fold on first paint — that scrolled the whole
        // page down on load instead of just sliding the row.
        //
        // First mount jumps instantly — otherwise it visibly animates from
        // the start on every page load. A later change (the picker stays
        // mounted across `preserveState` navigations) scrolls smoothly
        // instead, since that's a deliberate interaction.
        scroller.scrollTo({
            left:
                button.offsetLeft -
                scroller.clientWidth / 2 +
                button.offsetWidth / 2,
            behavior: isFirstRender.current ? 'instant' : 'smooth',
        });
        isFirstRender.current = false;
    }, [week]);

    return (
        <HqScrollRow contentClassName="px-1 py-1" showProgress={false}>
            {Array.from({ length: maxWeek }, (_, index) => index + 1).map(
                (weekNumber) => {
                    const isLive =
                        liveMatchday && weekNumber === playedThroughWeek;
                    const progress = weekProgress[String(weekNumber)] ?? 'none';
                    const points = weekPoints?.[weekNumber];

                    return (
                        <button
                            key={weekNumber}
                            ref={weekNumber === week ? selectedRef : undefined}
                            type="button"
                            onClick={() => onChange(weekNumber)}
                            className={cn(
                                'relative flex h-14 w-14 shrink-0 cursor-pointer flex-col items-center justify-center border font-mono',
                                isLive
                                    ? 'border-hq-live'
                                    : weekNumber === week
                                      ? 'border-hq-paper'
                                      : 'border-hq-border hover:border-hq-border-strong',
                                weekPoints
                                    ? points !== undefined
                                        ? teamFormBadgeClass(points)
                                        : 'text-hq-paper/30'
                                    : progress === 'all'
                                      ? 'bg-hq-lime/15 text-hq-lime'
                                      : progress === 'partial'
                                        ? 'bg-hq-gold/15 text-hq-gold'
                                        : 'text-hq-paper/30',
                            )}
                        >
                            <span className="text-[10px] font-bold opacity-80">
                                {weekPoints ? `J${weekNumber}` : 'J'}
                            </span>
                            <span className="font-display text-lg leading-none">
                                {weekPoints
                                    ? (points ?? '—')
                                    : weekNumber}
                            </span>
                            {isLive && (
                                <span className="absolute -top-1 -right-1 h-2 w-2 animate-pulse rounded-full bg-hq-live" />
                            )}
                        </button>
                    );
                },
            )}
        </HqScrollRow>
    );
}
