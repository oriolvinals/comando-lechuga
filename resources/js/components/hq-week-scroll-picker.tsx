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
        <HqScrollRow contentClassName="gap-1 px-1 py-1" showProgress={false}>
            {Array.from({ length: maxWeek }, (_, index) => index + 1).map(
                (weekNumber) => {
                    const isLive =
                        liveMatchday && weekNumber === playedThroughWeek;
                    const isSelected = weekNumber === week;
                    const progress = weekProgress[String(weekNumber)] ?? 'none';
                    const points = weekPoints?.[weekNumber];

                    return (
                        <button
                            key={weekNumber}
                            ref={isSelected ? selectedRef : undefined}
                            type="button"
                            aria-pressed={isSelected}
                            aria-label={`Jornada ${weekNumber}`}
                            onClick={() => onChange(weekNumber)}
                            className={cn(
                                'relative flex h-[52px] w-[52px] shrink-0 cursor-pointer flex-col items-center justify-center gap-[3px] border',
                                isLive
                                    ? 'border-hq-live'
                                    : isSelected
                                      ? 'border-hq-paper shadow-[inset_0_0_0_1px_var(--color-hq-paper)]'
                                      : 'border-hq-border hover:border-hq-border-bright',
                                isLive &&
                                    isSelected &&
                                    'shadow-[inset_0_0_0_1px_var(--color-hq-paper)]',
                                weekPoints
                                    ? points !== undefined
                                        ? teamFormBadgeClass(points)
                                        : 'text-hq-paper/35'
                                    : progress === 'all'
                                      ? 'bg-hq-lime/8 text-hq-lime'
                                      : progress === 'partial'
                                        ? 'bg-hq-gold/10 text-hq-gold'
                                        : 'text-hq-paper/35',
                            )}
                        >
                            <span className="font-mono text-[9.5px] leading-none font-semibold tracking-[0.06em] opacity-85">
                                {weekPoints ? `J${weekNumber}` : 'J'}
                            </span>
                            <span
                                className={cn(
                                    'font-dot leading-none font-black',
                                    weekPoints ? 'text-base' : 'text-[19px]',
                                )}
                            >
                                {weekPoints ? (points ?? '—') : weekNumber}
                            </span>
                            {isLive && (
                                <span className="absolute -top-[3px] -right-[3px] h-[7px] w-[7px] animate-hq-pulse rounded-full bg-hq-live" />
                            )}
                        </button>
                    );
                },
            )}
        </HqScrollRow>
    );
}
